#!/usr/bin/env python3

import concurrent.futures
import json
import random
import time
from datetime import datetime, timedelta
from typing import Optional, Tuple

import requests

BASE_URL = "http://localhost:8000/api"
TOTAL_DURATION_MINUTES = 10
ANOMALY_DURATION_MINUTES = 2
TARGET_TOTAL_REQUESTS = 3600
ANOMALY_TYPE = "error_spike"

# Base distribution percentages
BASE_DISTRIBUTION = {
    "normal": 70,
    "slow": 15,
    "slow_hard": 5,
    "error": 5,
    "db": 3,
    "validate": 2,
}

# Anomaly distribution percentages (error spike 35-50%)
ANOMALY_DISTRIBUTION = {
    "normal": 45,
    "slow": 10,
    "slow_hard": 2,
    "error": 40,
    "db": 2,
    "validate": 1,
}


def generate_validate_payload() -> dict:
    if random.random() < 0.5:
        return {
            "email": "invalid-email",
            "age": 10,
        }

    return {
        "email": f"user{random.randint(1, 100000)}@example.com",
        "age": random.randint(18, 60),
    }


def choose_endpoint(distribution: dict) -> Tuple[str, Optional[dict]]:
    roll = random.uniform(0, 100)
    cumulative = 0.0
    endpoint = "normal"
    for name, weight in distribution.items():
        cumulative += weight
        if roll <= cumulative:
            endpoint = name
            break

    if endpoint == "validate":
        return "validate", generate_validate_payload()
    if endpoint == "slow_hard":
        return "slow?hard=1", None
    if endpoint == "db":
        if random.random() < 0.35:
            return "db?fail=1", None
        return "db", None

    return endpoint, None


def make_request(session: requests.Session, endpoint: str, payload: Optional[dict] = None) -> Tuple[str, int, float]:
    url = f"{BASE_URL}/{endpoint}"
    try:
        if payload:
            response = session.post(url, json=payload, timeout=20)
        else:
            response = session.get(url, timeout=20)

        return endpoint, response.status_code, response.elapsed.total_seconds()
    except requests.RequestException:
        return endpoint, 0, 20.0


def set_anomaly_marker(session: requests.Session, active: int) -> None:
    try:
        session.post(f"{BASE_URL}/anomaly-window?active={active}", timeout=5)
    except requests.RequestException:
        pass


def run_traffic() -> None:
    start_time = datetime.now().astimezone()
    total_duration = timedelta(minutes=TOTAL_DURATION_MINUTES)
    anomaly_start = start_time + timedelta(minutes=(TOTAL_DURATION_MINUTES / 2) - 1)
    anomaly_end = anomaly_start + timedelta(minutes=ANOMALY_DURATION_MINUTES)
    end_time = start_time + total_duration

    ground_truth = {
        "anomaly_start_iso": anomaly_start.isoformat(),
        "anomaly_end_iso": anomaly_end.isoformat(),
        "anomaly_type": ANOMALY_TYPE,
        "expected_behavior": "Error rate spikes to about 40% during the anomaly window, causing a clear error-rate increase in Grafana and logs.",
    }

    with open("ground_truth.json", "w", encoding="utf-8") as f:
        json.dump(ground_truth, f, indent=2)

    print(f"Starting traffic generation at {start_time.isoformat()}")
    print(f"Anomaly window: {anomaly_start} to {anomaly_end}")
    print("Ground truth saved to ground_truth.json")

    request_interval = total_duration.total_seconds() / TARGET_TOTAL_REQUESTS
    futures = []
    counts = {}
    total_requests = 0
    anomaly_active = False

    session = requests.Session()
    with concurrent.futures.ThreadPoolExecutor(max_workers=60) as pool:
        while datetime.now().astimezone() < end_time:
            now = datetime.now().astimezone()
            in_anomaly = anomaly_start <= now < anomaly_end

            if in_anomaly and not anomaly_active:
                set_anomaly_marker(session, 1)
                anomaly_active = True
            if not in_anomaly and anomaly_active:
                set_anomaly_marker(session, 0)
                anomaly_active = False

            distribution = ANOMALY_DISTRIBUTION if in_anomaly else BASE_DISTRIBUTION
            endpoint, payload = choose_endpoint(distribution)
            futures.append(pool.submit(make_request, session, endpoint, payload))
            total_requests += 1

            if total_requests % 250 == 0:
                print(f"Requests dispatched: {total_requests}")

            time.sleep(request_interval)

        for future in concurrent.futures.as_completed(futures):
            endpoint, status, _latency = future.result()
            key = f"{endpoint}:{status}"
            counts[key] = counts.get(key, 0) + 1

    if anomaly_active:
        set_anomaly_marker(session, 0)

    print(f"Traffic generation complete. Total requests: {total_requests}")
    print("Top endpoint/status counts:")
    for key in sorted(counts, key=counts.get, reverse=True)[:15]:
        print(f"{key} -> {counts[key]}")


if __name__ == "__main__":
    run_traffic()
