"""Module-level singletons for the bg-remove endpoint (Phase 4, D-14).

The asyncio.Lock serializes inference across BiRefNet and isnet (single GPU/CPU
inference path per process). The inflight counter is read by /health and must
remain consistent even across threadpool callbacks — hence the threading.Lock.
"""
from __future__ import annotations

import asyncio
import threading

# Single asyncio.Lock shared by BiRefNet + isnet inference paths (D-14).
lock = asyncio.Lock()

_inflight_lock = threading.Lock()
_inflight: int = 0
_last_inference_ms: int | None = None


def set_inflight(delta: int) -> None:
    global _inflight
    with _inflight_lock:
        _inflight += delta


def get_inflight() -> int:
    with _inflight_lock:
        return _inflight


def set_last_ms(ms: int) -> None:
    global _last_inference_ms
    _last_inference_ms = ms


def get_last_ms() -> int | None:
    return _last_inference_ms
