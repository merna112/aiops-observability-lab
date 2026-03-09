# AIOps Observability Lab

Laravel API with ML-ready telemetry, Prometheus metrics, Grafana monitoring, and a controlled anomaly traffic generator.

## Setup

1. Build and run stack:
   ```bash
   docker-compose up --build
   ```
2. Services:
   - API: http://localhost:8000
   - Prometheus: http://localhost:9090
   - Grafana: http://localhost:3000 (admin/admin)

## API Endpoints

- `GET /api/normal`
- `GET /api/slow`
- `GET /api/slow?hard=1`
- `GET /api/error`
- `GET /api/random`
- `GET /api/db`
- `GET /api/db?fail=1`
- `POST /api/validate`
- `GET /api/metrics`
- `POST /api/anomaly-window?active=1|0`

## Traffic Generator

Run:
```bash
python traffic_generator.py
```

Behavior:
- 10-minute base load (>=3000 dispatched requests)
- Distribution target: 70/15/5/5/3/2 for normal/slow/slow-hard/error/db/validate
- Exact 2-minute anomaly window with error spike (~40%)
- Writes `ground_truth.json`

## Log Export

Run:
```bash
php export_logs.php
```

This parses `api/storage/logs/aiops.log` and exports a strict-schema `logs.json`.

## Deliverables Included

- `api/storage/logs/aiops.log`
- `logs.json`
- `ground_truth.json`
- `docker-compose.yml`
- `prometheus.yml`
- `grafana_dashboard.json`
- `traffic_generator.py`
- `engineering_report.md`
