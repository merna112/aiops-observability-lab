# AIOps Observability Lab

A production-style observability experiment built using a Laravel API that emits ML-ready telemetry and integrates with a full monitoring stack (Prometheus + Grafana).

The system simulates realistic production behavior, generates structured logs, exposes RED metrics, and runs a controlled traffic experiment with anomaly injection to produce datasets suitable for AIOps analysis.

---
<img width="1015" height="554" alt="image" src="https://github.com/user-attachments/assets/67320dcf-849c-4a0c-9e41-46fb6f37660a" />

## System Architecture

The observability stack is deployed using Docker Compose and consists of four main components:

| Component | Technology | Role |
|---|---|---|
| API Service | Laravel 10 + PHP 8.2 | Handles requests and emits structured telemetry |
| Metrics Backend | Prometheus | Scrapes `/api/metrics` and stores time-series metrics |
| Visualization | Grafana | Displays monitoring dashboards |
| Traffic Generator | Python (aiohttp) | Generates controlled load and anomaly injection |

All services run on a shared Docker network.

---

## Key Features

### Structured Telemetry Logging
Each request generates a structured JSON log containing 17 standardized fields including:

- correlation ID (`request_id`)
- request latency (`latency_ms`)
- error category
- HTTP status
- request metadata
- build version
- host information

Logs are written to:

```
api/storage/logs/aiops.log
```

and later exported as a machine-learning dataset.

---

### Correlation IDs

Every request receives a unique `X-Request-Id`.

- If provided by the client → reused
- If missing → generated automatically (UUID v4)

This allows request tracing across distributed systems.

---

### Centralized Error Categorization

All failures are normalized into five categories:

| Category | Trigger |
|---|---|
| VALIDATION_ERROR | Request validation failure |
| DATABASE_ERROR | Database query failure |
| SYSTEM_ERROR | Runtime exceptions |
| TIMEOUT_ERROR | Latency greater than 4000ms |
| UNKNOWN | Unexpected errors |

This structure simplifies anomaly detection and ML analysis.

---

## API Endpoints

The API exposes multiple endpoints designed to simulate different behaviors.

| Endpoint | Description |
|---|---|
| `GET /api/normal` | Fast successful response |
| `GET /api/slow` | Delayed response (1–2 seconds) |
| `GET /api/slow?hard=1` | Heavy latency (5–7 seconds) |
| `GET /api/error` | Always throws a runtime exception |
| `GET /api/random` | Random mix of responses |
| `GET /api/db` | Executes a SQLite query |
| `GET /api/db?fail=1` | Simulated database failure |
| `POST /api/validate` | Request validation testing |
| `GET /api/metrics` | Prometheus metrics endpoint |
| `POST /api/anomaly-window?active=1|0` | Toggles anomaly ground-truth marker |

---

## Prometheus Metrics

Metrics are exposed through:

```
GET /api/metrics
```

### Available Metrics

| Metric | Type | Purpose |
|---|---|---|
| `http_requests_total` | Counter | Total requests handled |
| `http_errors_total` | Counter | Errors grouped by category |
| `http_request_duration_seconds` | Histogram | Latency distribution |
| `anomaly_window_active` | Gauge | Ground-truth anomaly marker |

Histogram buckets:

```
0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf
```

---

## Grafana Dashboard

The monitoring dashboard includes five observability panels:

1. Request rate per endpoint
2. Error rate percentage
3. Latency percentiles (P50 / P95 / P99)
4. Error category distribution
5. Anomaly window marker

Dashboard configuration is stored in:

```
grafana_dashboard.json
```

---

## Traffic Generator & Anomaly Experiment

The Python traffic generator simulates production load and injects controlled anomalies.

Run:

```bash
python traffic_generator.py
```

### Base Traffic Distribution

| Endpoint | Traffic Share |
|---|---|
| normal | 70% |
| slow | 15% |
| slow-hard | 5% |
| error | 5% |
| db | 3% |
| validate | 2% |

### Anomaly Window

During a **2-minute anomaly window**, the system injects an error spike:

```
/api/error increases from 5% → 40%
```

Ground truth timestamps are stored in:

```
ground_truth.json
```

---

## Dataset Export

After running the traffic generator, logs can be converted into a structured dataset.

Run:

```bash
php export_logs.php
```

This parses:

```
api/storage/logs/aiops.log
```

and exports:

```
logs.json
```

Schema validation ensures all 17 fields are present in every record.

---

## Running the Project

### Build and Start the Stack

```bash
docker-compose up --build
```

### Access Services

| Service | URL |
|---|---|
| API | http://localhost:8000 |
| Prometheus | http://localhost:9090 |
| Grafana | http://localhost:3000 |

Grafana credentials:

```
admin / admin
```

---

## Repository Structure

```
aiops-observability-lab
│
├── api/
│   └── storage/logs/aiops.log
│
├── docker-compose.yml
├── prometheus.yml
├── grafana_dashboard.json
├── traffic_generator.py
├── export_logs.php
│
├── logs.json
├── ground_truth.json
└── engineering_report.md
```

---

## Deliverables

The repository includes the following artifacts:

- Structured telemetry logs (`aiops.log`)
- ML dataset (`logs.json`)
- Ground truth anomaly dataset (`ground_truth.json`)
- Monitoring configuration (`prometheus.yml`)
- Grafana dashboard (`grafana_dashboard.json`)
- Traffic generator (`traffic_generator.py`)
- Engineering report (`engineering_report.md`)

---
