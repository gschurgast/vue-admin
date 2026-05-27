import io
import pytest
from PIL import Image
from fastapi.testclient import TestClient
from app import app


@pytest.fixture
def client():
    return TestClient(app)


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
