#!/usr/bin/env python3

import requests
import time
import random
import json
from datetime import datetime, timedelta
import threading

BASE_URL = "http://localhost:8000/api"

# Distribution percentages
DISTRIBUTION = {
    "normal": 70,
    "slow": 15,
    "slow_hard": 5,
    "error": 5,
    "db": 3,
    "validate": 2
}

# Anomaly: Error spike
ANOMALY_TYPE = "error_spike"
ANOMALY_DURATION_MINUTES = 2
BASE_LOAD_MINUTES = 10  # 8-12 minutes, pick 10

def generate_payload():
    """Generate validation payload, 50% invalid"""
    if random.random() < 0.5:
        # Invalid
        return {
            "email": "invalid-email",
            "age": 10  # too young
        }
    else:
        # Valid
        return {
            "email": f"user{random.randint(1,1000)}@example.com",
            "age": random.randint(18, 60)
        }

def make_request(endpoint, payload=None):
    """Make a request to the API"""
    url = f"{BASE_URL}/{endpoint}"
    headers = {"Content-Type": "application/json"} if payload else {}

    try:
        if payload:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
        else:
            response = requests.get(url, timeout=30)
        return response.status_code, response.elapsed.total_seconds()
    except requests.exceptions.RequestException as e:
        return 500, 30.0  # timeout

def run_traffic():
    """Run the traffic generator"""
    start_time = datetime.now()
    anomaly_start = start_time + timedelta(minutes=BASE_LOAD_MINUTES // 2)
    anomaly_end = anomaly_start + timedelta(minutes=ANOMALY_DURATION_MINUTES)

    ground_truth = {
        "anomaly_start_iso": anomaly_start.isoformat(),
        "anomaly_end_iso": anomaly_end.isoformat(),
        "anomaly_type": ANOMALY_TYPE,
        "expected_behavior": "Error rate spikes from 5% to 35-50% during anomaly window"
    }

    with open("ground_truth.json", "w") as f:
        json.dump(ground_truth, f, indent=2)

    print(f"Starting traffic generation at {start_time}")
    print(f"Anomaly window: {anomaly_start} to {anomaly_end}")
    print(f"Ground truth saved to ground_truth.json")

    total_requests = 0
    end_time = start_time + timedelta(minutes=BASE_LOAD_MINUTES)

    while datetime.now() < end_time:
        current_time = datetime.now()
        is_anomaly = anomaly_start <= current_time <= anomaly_end

        # Choose endpoint based on distribution
        rand = random.randint(1, 100)
        if rand <= DISTRIBUTION["normal"]:
            endpoint = "normal"
        elif rand <= DISTRIBUTION["normal"] + DISTRIBUTION["slow"]:
            endpoint = "slow"
        elif rand <= DISTRIBUTION["normal"] + DISTRIBUTION["slow"] + DISTRIBUTION["slow_hard"]:
            endpoint = "slow?hard=1"
        elif rand <= DISTRIBUTION["normal"] + DISTRIBUTION["slow"] + DISTRIBUTION["slow_hard"] + DISTRIBUTION["error"]:
            if is_anomaly:
                endpoint = "error"  # Spike during anomaly
            else:
                endpoint = "error"
        elif rand <= DISTRIBUTION["normal"] + DISTRIBUTION["slow"] + DISTRIBUTION["slow_hard"] + DISTRIBUTION["error"] + DISTRIBUTION["db"]:
            endpoint = "db"
        else:
            endpoint = "validate"
            payload = generate_payload()

        if endpoint == "validate":
            status, latency = make_request(endpoint, payload)
        else:
            status, latency = make_request(endpoint)

        total_requests += 1

        # Small delay to not overwhelm
        time.sleep(0.1)

        if total_requests % 100 == 0:
            print(f"Requests sent: {total_requests}")

    print(f"Traffic generation complete. Total requests: {total_requests}")

if __name__ == "__main__":
    run_traffic()