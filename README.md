# AIOps Observability Lab

Laravel API with ML-ready telemetry, Prometheus metrics, Grafana monitoring, and controlled anomaly traffic generator.

## Setup

1. Clone the repository
2. Start the services:
   ```bash
   docker-compose up --build
   ```
3. Access:
   - API: http://localhost:8000
   - Prometheus: http://localhost:9090
   - Grafana: http://localhost:3000 (admin/admin)

## API Endpoints

- `GET /api/normal` - Normal response
- `GET /api/slow` - 2 second delay
- `GET /api/slow?hard=1` - 5-7 second delay (TIMEOUT_ERROR if >4s)
- `GET /api/error` - System error
- `GET /api/random` - Random success/failure
- `GET /api/db` - Database query
- `GET /api/db?fail=1` - Database error
- `POST /api/validate` - JSON validation
- `GET /api/metrics` - Prometheus metrics

## Traffic Generator

Run the anomaly traffic generator:
```bash
python traffic_generator.py
```

This generates ~3000 requests over 10 minutes with a 2-minute error spike anomaly.

## Log Export

Export structured logs:
```bash
php export_logs.php
```

## Deliverables

- GitHub repo with complete implementation
- `storage/logs/aiops.log` - Raw logs
- `logs.json` - Exported structured logs (≥1500 entries, ≥100 errors)
- `ground_truth.json` - Anomaly metadata
- Docker setup for monitoring stack
- Grafana dashboard JSON (to be created)
