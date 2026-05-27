"""Phase 4 — E2E heavy tests with REAL BiRefNet ONNX (~1GB).

RUN ONLY VIA `pytest -m integration_ml`. Skipped by default.
Wave 0 stubs — Plan 04-02 implements the endpoint; Plan 04-03 wires
deploy-gate latency assertions.
"""
from __future__ import annotations

import io
import time

import pytest

pytestmark = pytest.mark.integration_ml


def test_birefnet_real_inference(client, product_2048_png):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 200
    # Real BiRefNet ⇒ non-trivial alpha mask
    from PIL import Image
    import numpy as np
    out = Image.open(io.BytesIO(r.content))
    alpha = np.array(out.split()[3])
    # At least 5% pixels are transparent (mask is non-degenerate)
    assert (alpha < 16).mean() >= 0.05


def test_birefnet_latency_p95(client, product_2048_png):
    durations = []
    for _ in range(10):
        t0 = time.perf_counter()
        r = client.post(
            "/img/remove-background",
            files={"image": ("p.png", product_2048_png, "image/png")},
            data={"params": '{"model":"birefnet"}'},
        )
        assert r.status_code == 200
        durations.append((time.perf_counter() - t0) * 1000)
    durations.sort()
    p95 = durations[int(0.95 * len(durations)) - 1]
    # D-13 checklist item 4: p95 < 3000ms
    assert p95 < 3000.0, f"p95 latency {p95:.0f}ms > 3000ms"
