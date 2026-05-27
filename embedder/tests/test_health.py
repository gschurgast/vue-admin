import io

from PIL import Image


def test_health_schema(client):
    r = client.get("/health")
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok"
    assert "models" in body
    m = body["models"]
    assert set(m.keys()) >= {"clip", "birefnet", "stable_diffusion"}
    assert m["clip"]["status"] in {"loaded", "lazy"}
    assert m["clip"]["dim"] == 512
    assert "name" in m["clip"]
    assert m["birefnet"]["status"] == "not_loaded"
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
