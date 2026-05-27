import io
import os

import pytest
from PIL import Image


# In a container/test env, the ONNX models are only present after the Plan 04-03
# Dockerfile build. When the models are absent, the startup warmup logs a warning
# and the sessions remain None; /health reports `degraded` + `not_loaded`.
MODELS_PRESENT = os.path.exists("/app/models/birefnet/onnx/model_fp16.onnx")


def test_health_schema(client):
    r = client.get("/health")
    assert r.status_code == 200
    body = r.json()
    assert body["status"] in {"ok", "degraded"}
    assert "models" in body
    m = body["models"]
    # birefnet + isnet keys are now part of the enriched payload (Plan 04-03).
    assert set(m.keys()) >= {"clip", "birefnet", "isnet", "stable_diffusion"}
    assert m["clip"]["status"] in {"loaded", "lazy"}
    assert m["clip"]["dim"] == 512
    assert "name" in m["clip"]
    assert m["birefnet"]["status"] in {"loaded", "not_loaded"}
    assert m["stable_diffusion"]["status"] == "not_loaded"


def test_embed_endpoint_still_works(client, rgb_image):
    """Smoke regression: /embed must not be broken by the refactor."""
    r = client.post("/embed", files={"file": ("test.png", rgb_image, "image/png")})
    assert r.status_code == 200
    body = r.json()
    assert "embedding" in body
    assert len(body["embedding"]) == 512


def test_avif_codec_registered():
    """import pillow_avif at module load must register AVIF codec."""
    img = Image.new("RGB", (16, 16), color=(0, 0, 0))
    buf = io.BytesIO()
    img.save(buf, "AVIF")
    assert buf.tell() > 0


@pytest.mark.skipif(not MODELS_PRESENT, reason="ONNX models not present (Plan 04-03 Dockerfile build required)")
def test_health_includes_birefnet_status(client):
    r = client.get("/health")
    m = r.json()["models"]
    assert m["birefnet"]["status"] == "loaded"
    assert "inflight" in m["birefnet"]
    assert "last_inference_ms" in m["birefnet"]
    assert m["isnet"]["status"] == "loaded"


def test_health_degraded_when_birefnet_not_loaded(client, monkeypatch):
    import core.bgremove_models as m
    monkeypatch.setattr(m, "_birefnet_session", None)
    r = client.get("/health")
    assert r.json()["status"] == "degraded"


def test_health_inflight_zero_at_idle(client):
    r = client.get("/health")
    assert r.json()["models"]["birefnet"]["inflight"] == 0
