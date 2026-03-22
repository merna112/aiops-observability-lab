"""Lab Work 3: ML Anomaly Detection for AIOps.

Builds request-level dataset and window-level features from telemetry,
trains an Isolation Forest on normal-only windows, predicts anomalies,
and exports required deliverables.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Dict, Tuple

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
import numpy as np
import pandas as pd
from sklearn.ensemble import IsolationForest


def load_inputs(logs_path: Path, ground_truth_path: Path) -> Tuple[pd.DataFrame, Dict[str, str]]:
    logs_raw = json.loads(logs_path.read_text(encoding="utf-8"))
    if not isinstance(logs_raw, list) or not logs_raw:
        raise ValueError("logs.json is empty or malformed.")

    logs = pd.DataFrame(logs_raw)
    logs["timestamp"] = pd.to_datetime(logs["timestamp"], errors="coerce")
    logs = logs.dropna(subset=["timestamp"]).sort_values("timestamp").reset_index(drop=True)

    if "path" not in logs.columns:
        logs["path"] = "unknown"
    if "latency_ms" not in logs.columns:
        logs["latency_ms"] = 0.0
    if "status_code" not in logs.columns:
        logs["status_code"] = 0
    if "error_category" not in logs.columns:
        logs["error_category"] = "NONE"

    logs["endpoint"] = logs["path"].fillna("unknown").astype(str)
    logs["latency"] = pd.to_numeric(logs["latency_ms"], errors="coerce").fillna(0.0)
    logs["status_code"] = pd.to_numeric(logs["status_code"], errors="coerce").fillna(0).astype(int)
    logs["error_category"] = logs["error_category"].fillna("NONE").astype(str)
    logs["is_error"] = (logs["status_code"] >= 500).astype(int)

    gt = json.loads(ground_truth_path.read_text(encoding="utf-8"))
    if not isinstance(gt, dict):
        raise ValueError("ground_truth.json is malformed.")

    return logs, gt


def _mode_or_default(series: pd.Series, default: str) -> str:
    series = series.dropna()
    if series.empty:
        return default
    mode = series.mode(dropna=True)
    if mode.empty:
        return default
    return str(mode.iloc[0])


def build_aiops_dataset(logs: pd.DataFrame, dataset_window_seconds: int) -> pd.DataFrame:
    logs_idx = logs.set_index("timestamp").sort_index()
    win = f"{dataset_window_seconds}s"

    grouped = (
        logs_idx.groupby([pd.Grouper(freq=win), "endpoint"]) 
        .agg(
            req_count=("endpoint", "count"),
            err_count=("is_error", "sum"),
            latency=("latency", "mean"),
            error_category=("error_category", lambda s: _mode_or_default(s[s != "NONE"], "NONE")),
        )
    )

    time_index = pd.date_range(
        logs_idx.index.min().floor(win),
        logs_idx.index.max().ceil(win),
        freq=win,
    )
    endpoints = sorted(logs_idx["endpoint"].dropna().astype(str).unique().tolist())
    full_index = pd.MultiIndex.from_product([time_index, endpoints], names=["timestamp", "endpoint"])

    dataset = grouped.reindex(full_index)
    dataset["req_count"] = dataset["req_count"].fillna(0)
    dataset["err_count"] = dataset["err_count"].fillna(0)
    dataset["latency"] = dataset["latency"].fillna(0.0)
    dataset["error_category"] = dataset["error_category"].fillna("NONE")

    dataset["request_rate"] = dataset["req_count"] / dataset_window_seconds
    dataset["error_rate"] = np.where(dataset["req_count"] > 0, dataset["err_count"] / dataset["req_count"], 0.0)

    dataset = dataset.reset_index()[
        ["timestamp", "endpoint", "latency", "error_rate", "request_rate", "error_category"]
    ]
    return dataset


def build_window_features(logs: pd.DataFrame, window_seconds: int) -> pd.DataFrame:
    df = logs.copy().set_index("timestamp").sort_index()
    win = f"{window_seconds}s"

    agg = df.resample(win).agg(
        total_requests=("endpoint", "count"),
        avg_latency=("latency", "mean"),
        max_latency=("latency", "max"),
        latency_std=("latency", "std"),
        errors_per_window=("is_error", "sum"),
    )

    endpoint_counts = (
        df.groupby([pd.Grouper(freq=win), "endpoint"]).size().rename("count").reset_index()
    )
    endpoint_max = endpoint_counts.groupby("timestamp")["count"].max().rename("endpoint_max")
    dominant_endpoint = (
        endpoint_counts.sort_values(["timestamp", "count"], ascending=[True, False])
        .drop_duplicates(subset=["timestamp"])
        .set_index("timestamp")["endpoint"]
        .rename("dominant_endpoint")
    )

    feats = agg.join(endpoint_max, how="left").join(dominant_endpoint, how="left")
    feats = feats.fillna({"total_requests": 0, "errors_per_window": 0, "endpoint_max": 0})
    feats["request_rate"] = feats["total_requests"] / window_seconds
    feats["error_rate"] = np.where(
        feats["total_requests"] > 0,
        feats["errors_per_window"] / feats["total_requests"],
        0.0,
    )
    feats["endpoint_frequency"] = np.where(
        feats["total_requests"] > 0,
        feats["endpoint_max"] / feats["total_requests"],
        0.0,
    )

    feats["avg_latency"] = feats["avg_latency"].fillna(0.0)
    feats["max_latency"] = feats["max_latency"].fillna(0.0)
    feats["latency_std"] = feats["latency_std"].fillna(0.0)
    feats["dominant_endpoint"] = feats["dominant_endpoint"].fillna("NO_TRAFFIC")

    return feats.reset_index()


def fit_and_predict(
    window_df: pd.DataFrame,
    gt: Dict[str, str],
    contamination: float,
    random_state: int,
    score_quantile: float,
) -> pd.DataFrame:
    anomaly_start = pd.to_datetime(gt["anomaly_start_iso"]).tz_convert("UTC").tz_localize(None)
    anomaly_end = pd.to_datetime(gt["anomaly_end_iso"]).tz_convert("UTC").tz_localize(None)

    train_mask = window_df["timestamp"] < anomaly_start
    train_df = window_df.loc[train_mask].copy()
    if train_df.empty:
        raise ValueError("No pre-anomaly normal period available for training.")

    feature_cols = [
        "avg_latency",
        "max_latency",
        "request_rate",
        "error_rate",
        "latency_std",
        "errors_per_window",
        "endpoint_frequency",
    ]

    model = IsolationForest(
        n_estimators=300,
        contamination=contamination,
        random_state=random_state,
    )
    model.fit(train_df[feature_cols])

    train_scores = -model.score_samples(train_df[feature_cols])
    threshold = float(np.quantile(train_scores, score_quantile))

    scores = -model.score_samples(window_df[feature_cols])

    preds = window_df[["timestamp"]].copy()
    preds["anomaly_score"] = scores
    preds["is_anomaly"] = (preds["anomaly_score"] >= threshold).astype(int)

    preds["is_ground_truth"] = (
        (preds["timestamp"] >= anomaly_start) & (preds["timestamp"] <= anomaly_end)
    ).astype(int)

    return preds


def render_plots(window_df: pd.DataFrame, preds: pd.DataFrame, gt: Dict[str, str], out_dir: Path) -> None:
    anomaly_start = pd.to_datetime(gt["anomaly_start_iso"]).tz_convert("UTC").tz_localize(None)
    anomaly_end = pd.to_datetime(gt["anomaly_end_iso"]).tz_convert("UTC").tz_localize(None)

    merged = window_df.merge(preds[["timestamp", "is_anomaly"]], on="timestamp", how="left")
    anom = merged[merged["is_anomaly"] == 1]

    plt.figure(figsize=(14, 6))
    plt.plot(merged["timestamp"], merged["avg_latency"], color="#1f77b4", linewidth=1.5, label="avg_latency")
    plt.scatter(anom["timestamp"], anom["avg_latency"], color="#d62728", s=25, label="predicted anomaly")
    plt.axvspan(
        float(mdates.date2num(anomaly_start.to_pydatetime())),
        float(mdates.date2num(anomaly_end.to_pydatetime())),
        color="#ffcc80",
        alpha=0.35,
        label="ground truth window",
    )
    plt.title("Latency Timeline with Anomaly Points")
    plt.xlabel("Timestamp")
    plt.ylabel("Latency (ms)")
    plt.legend()
    plt.tight_layout()
    plt.savefig(out_dir / "latency_timeline.png", dpi=150)
    plt.close()

    plt.figure(figsize=(14, 6))
    plt.plot(merged["timestamp"], merged["error_rate"], color="#2ca02c", linewidth=1.5, label="error_rate")
    plt.scatter(anom["timestamp"], anom["error_rate"], color="#d62728", s=25, label="predicted anomaly")
    plt.axvspan(
        float(mdates.date2num(anomaly_start.to_pydatetime())),
        float(mdates.date2num(anomaly_end.to_pydatetime())),
        color="#ffcc80",
        alpha=0.35,
        label="ground truth window",
    )
    plt.title("Error Rate Timeline with Anomaly Points")
    plt.xlabel("Timestamp")
    plt.ylabel("Error Rate")
    plt.legend()
    plt.tight_layout()
    plt.savefig(out_dir / "error_rate_timeline.png", dpi=150)
    plt.close()


def compute_performance(preds: pd.DataFrame) -> Dict[str, float]:
    tp = int(((preds["is_anomaly"] == 1) & (preds["is_ground_truth"] == 1)).sum())
    fp = int(((preds["is_anomaly"] == 1) & (preds["is_ground_truth"] == 0)).sum())
    fn = int(((preds["is_anomaly"] == 0) & (preds["is_ground_truth"] == 1)).sum())

    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0

    return {
        "tp": tp,
        "fp": fp,
        "fn": fn,
        "precision": precision,
        "recall": recall,
        "f1": f1,
        "ground_truth_windows": int(preds["is_ground_truth"].sum()),
        "predicted_anomaly_windows": int(preds["is_anomaly"].sum()),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Lab 3 ML anomaly detection pipeline")
    parser.add_argument("--logs", default="logs.json", help="Path to logs.json")
    parser.add_argument("--ground-truth", default="ground_truth.json", help="Path to ground_truth.json")
    parser.add_argument("--window-seconds", type=int, default=60, choices=[30, 60], help="Window size")
    parser.add_argument(
        "--dataset-window-seconds",
        type=int,
        default=30,
        choices=[30, 60],
        help="Window size for aiops_dataset.csv observations",
    )
    parser.add_argument("--contamination", type=float, default=0.08, help="Isolation Forest contamination")
    parser.add_argument(
        "--score-quantile",
        type=float,
        default=0.9995,
        help="Quantile of normal-period scores used as anomaly threshold",
    )
    parser.add_argument("--random-state", type=int, default=42, help="Random seed")
    args = parser.parse_args()

    root = Path(__file__).resolve().parent
    logs_path = root / args.logs
    gt_path = root / args.ground_truth

    logs, gt = load_inputs(logs_path, gt_path)

    request_dataset = build_aiops_dataset(logs, args.dataset_window_seconds)
    if len(request_dataset) < 1500:
        raise ValueError(
            f"aiops_dataset.csv has {len(request_dataset)} observations; minimum is 1500."
        )
    request_dataset.to_csv(root / "aiops_dataset.csv", index=False)

    window_df = build_window_features(logs, args.window_seconds)
    preds = fit_and_predict(
        window_df,
        gt,
        contamination=args.contamination,
        random_state=args.random_state,
        score_quantile=args.score_quantile,
    )

    predictions_out = preds[["timestamp", "anomaly_score", "is_anomaly"]].copy()
    predictions_out.to_csv(root / "anomaly_predictions.csv", index=False)

    render_plots(window_df, preds, gt, root)

    perf = compute_performance(preds)
    perf_payload = {
        "dataset_window_seconds": args.dataset_window_seconds,
        "window_seconds": args.window_seconds,
        "model": "IsolationForest",
        "score_quantile": args.score_quantile,
        "training_windows": int(
            (
                window_df["timestamp"]
                < pd.to_datetime(gt["anomaly_start_iso"]).tz_convert("UTC").tz_localize(None)
            ).sum()
        ),
        "total_windows": int(len(window_df)),
        "metrics": perf,
    }
    (root / "lab3_metrics_summary.json").write_text(json.dumps(perf_payload, indent=2), encoding="utf-8")

    overlap = int(((preds["is_anomaly"] == 1) & (preds["is_ground_truth"] == 1)).sum())
    print(f"Observations in aiops_dataset.csv: {len(request_dataset)}")
    print(f"Windows scored: {len(predictions_out)}")
    print(f"Predicted anomaly windows: {perf['predicted_anomaly_windows']}")
    print(f"Ground-truth windows: {perf['ground_truth_windows']}")
    print(f"Overlap windows (detected in anomaly window): {overlap}")
    print(f"Precision: {perf['precision']:.3f} | Recall: {perf['recall']:.3f} | F1: {perf['f1']:.3f}")


if __name__ == "__main__":
    main()
