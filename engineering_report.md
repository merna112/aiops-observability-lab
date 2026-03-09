# AIOps Observability Lab - Engineering Report

## Log Schema Design

Each log entry follows a stable JSON schema with the following fields:

- `request_id`: UUID for request correlation
- `method`: HTTP method (GET, POST)
- `path`: Request path (e.g., /api/normal)
- `status_code`: HTTP status code
- `latency_ms`: Response time in milliseconds
- `client_ip`: Client IP address
- `user_agent`: User agent string
- `query`: Query string (null if none)
- `payload_size_bytes`: Request body size in bytes
- `response_size_bytes`: Response body size in bytes (null for errors)
- `route_name`: Laravel route name (unknown if not defined)
- `severity`: info or error
- `build_version`: Application version from .env
- `host`: Server hostname
- `error_category`: Error type (VALIDATION_ERROR, DATABASE_ERROR, SYSTEM_ERROR, TIMEOUT_ERROR, UNKNOWN) - null for successful requests
- `error_message`: Error message (only for errors)
- `timestamp`: Log timestamp

**Why each field exists:**
- Correlation: `request_id` enables tracing requests across services
- Performance: `latency_ms` for anomaly detection
- Context: `client_ip`, `user_agent` provide request context
- Payload tracking: Size fields help detect unusual payloads
- Categorization: `error_category` enables ML-based error classification
- Stability: All fields present with nulls ensures consistent parsing

## Metrics Design

### Counters
- `http_requests_total{method, path, status}`: Total requests by endpoint and status
- `http_errors_total{method, path, error_category}`: Errors by type

**Why these labels:**
- `method` and `path`: Essential for per-endpoint analysis
- `status`: Standard HTTP status tracking
- `error_category`: Enables error type analysis (VALIDATION vs DB vs SYSTEM)

### Histogram
- `http_request_duration_seconds{method, path}` with buckets: 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf

**Why these buckets:**
- Cover typical API response times (50ms to 10s)
- Allow P50/P95/P99 calculations for SLA monitoring
- +Inf bucket catches outliers

**Why no label explosion:**
- No `request_id` or dynamic values in labels
- Fixed set of endpoints prevents cardinality explosion

## Anomaly Design

### Chosen Anomaly: Error Rate Spike
- **Type**: Error spike on `/api/error` endpoint
- **Duration**: Exactly 2 minutes
- **Spike**: From 5% to 35-50% of total requests
- **Visibility in Grafana**:
  - Error rate panel shows clear spike during window
  - Error category breakdown shows SYSTEM_ERROR increase
  - Request rate remains stable, isolating the anomaly
- **Ground truth**: `ground_truth.json` contains exact timestamps and expected behavior

### Why This Design
- **Controlled**: Easy to implement in traffic generator
- **Obvious**: Clear spike in dashboards
- **Realistic**: Simulates real-world error rate anomalies
- **Measurable**: Easy to verify in logs and metrics

## Implementation Notes

### Telemetry Middleware
- Try-catch wrapper captures all exceptions
- Timeout classification: Any request >4000ms marked as TIMEOUT_ERROR
- Metrics excluded for `/metrics` endpoint to prevent self-monitoring
- Stable schema ensures ML-ready data

### Error Categorization
- Centralized in middleware for consistency
- Covers all required categories: VALIDATION_ERROR, DATABASE_ERROR, TIMEOUT_ERROR, SYSTEM_ERROR, UNKNOWN

### Traffic Generator
- Python script with configurable distribution
- Ground truth output for evaluation
- Realistic timing with small delays between requests

### Monitoring Stack
- Prometheus scrape interval: 15s (reasonable for API monitoring)
- Grafana dashboard with key panels for anomaly detection
- Docker networking ensures service communication

## Validation Evidence

### Timeout Classification
Logs show `status_code=200` with `error_category="TIMEOUT_ERROR"` during `/api/slow?hard=1` requests exceeding 4000ms.

### Metrics Correctness
- Counters increase monotonically
- Histogram quantiles usable for P50/P95/P99 calculations
- No label explosion (fixed endpoint set)

### Anomaly Visibility
- Traffic generator creates clear error spike
- Grafana panels show anomaly window clearly
- Ground truth provides exact timing for verification

This implementation provides production-like telemetry suitable for ML-based anomaly detection and incident triage.