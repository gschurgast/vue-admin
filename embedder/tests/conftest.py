from __future__ import annotations

import io
from pathlib import Path

import pytest
from PIL import Image
from fastapi.testclient import TestClient
from app import app

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.fixture
def client():
    # Use as a context manager so FastAPI startup hooks fire (Plan 04-03 warmup
    # pre-loads BiRefNet/isnet sessions so /health reports `loaded`).
    with TestClient(app) as c:
        yield c


def _read_bytes(name: str) -> bytes:
    return (FIXTURES / name).read_bytes()


@pytest.fixture
def product_2048_png() -> bytes:
    return _read_bytes("product_2048.png")


@pytest.fixture
def product_3000_jpg() -> bytes:
    return _read_bytes("product_3000.jpg")


@pytest.fixture
def product_4500_jpg() -> bytes:
    return _read_bytes("product_4500.jpg")


@pytest.fixture
def product_with_alpha_png() -> bytes:
    return _read_bytes("product_with_alpha.png")


@pytest.fixture
def mock_birefnet_session(monkeypatch):
    """Replace run_birefnet with a trivial mask returning uniform 128.

    Used by tests that exercise routing/multipart/headers without loading 1GB
    of ONNX weights. Skips the test gracefully until Plan 04-02 creates the
    `core.bgremove_models` module.
    """
    def _fake(img: Image.Image) -> Image.Image:
        return Image.new("L", img.size, color=128)

    try:
        import core.bgremove_models as m
        monkeypatch.setattr(m, "run_birefnet", _fake)
        monkeypatch.setattr(m, "run_isnet", _fake)
    except ImportError:
        pytest.skip("core.bgremove_models not yet implemented (Plan 04-02)")
    # Also patch the names already imported into the router module (Plan 04-02).
    try:
        import routers.img_remove_background as r
        monkeypatch.setattr(r, "run_birefnet", _fake)
        monkeypatch.setattr(r, "run_isnet", _fake)
    except ImportError:
        pass
    return _fake


@pytest.fixture
def mock_isnet_session(mock_birefnet_session):
    # alias — same fake under the hood
    return mock_birefnet_session


@pytest.fixture
def rgb_image() -> bytes:
    img = Image.new("RGB", (200, 150), color=(255, 0, 0))
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue()


@pytest.fixture
def rgba_image() -> bytes:
    img = Image.new("RGBA", (200, 200), color=(0, 255, 0, 128))
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue()


@pytest.fixture
def jpeg_image() -> bytes:
    img = Image.new("RGB", (300, 200), color=(0, 0, 255))
    buf = io.BytesIO()
    img.save(buf, "JPEG", quality=90)
    return buf.getvalue()


@pytest.fixture
def webp_image() -> bytes:
    img = Image.new("RGB", (150, 150), color=(128, 128, 128))
    buf = io.BytesIO()
    img.save(buf, "WEBP")
    return buf.getvalue()


@pytest.fixture
def exif_rot6_jpeg() -> bytes:
    """JPEG 100x200 (portrait) with EXIF Orientation=6.
    After exif_transpose, the image should be 200x100 landscape."""
    img = Image.new("RGB", (100, 200), color=(255, 255, 0))
    buf = io.BytesIO()
    exif = img.getexif()
    exif[0x0112] = 6  # Orientation tag
    img.save(buf, "JPEG", exif=exif.tobytes())
    return buf.getvalue()


@pytest.fixture
def svg_bytes() -> bytes:
    return b'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'


@pytest.fixture
def huge_image_bytes() -> bytes:
    """PNG with > 50 MPx (grayscale to keep memory under control during test)."""
    img = Image.new("L", (8000, 7000), color=128)  # 56 MPx
    buf = io.BytesIO()
    img.save(buf, "PNG", compress_level=1)
    return buf.getvalue()


@pytest.fixture
def corrupt_bytes() -> bytes:
    return b"this is not an image, just garbage bytes \x00\x01\x02"
