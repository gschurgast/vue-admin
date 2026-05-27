"""Structured JSON logging to stdout (Datadog-compatible)."""
from __future__ import annotations

import json
import logging
import sys
import time

_log = logging.getLogger("embedder")


def log_event(event: str, **fields) -> None:
    payload = {"event": event, "ts": time.time(), **fields}
    sys.stdout.write(json.dumps(payload, default=str) + "\n")
    sys.stdout.flush()
