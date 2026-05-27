import pytest
from fastapi import HTTPException
from core.image_utils import decode_image, content_type_for_format, image_response


def test_decode_rgb_png(rgb_image):
    img = decode_image(rgb_image)
    assert img.size == (200, 150)
    assert img.mode in ("RGB", "RGBA", "P")


def test_decode_rejects_svg(svg_bytes):
    with pytest.raises(HTTPException) as exc:
        decode_image(svg_bytes)
    assert exc.value.status_code == 422
    assert "SVG" in exc.value.detail


def test_decode_rejects_corrupt(corrupt_bytes):
    with pytest.raises(HTTPException) as exc:
        decode_image(corrupt_bytes)
    assert exc.value.status_code == 422


def test_decode_rejects_empty():
    with pytest.raises(HTTPException) as exc:
        decode_image(b"")
    assert exc.value.status_code == 422


def test_decode_rejects_huge(huge_image_bytes):
    with pytest.raises(HTTPException) as exc:
        decode_image(huge_image_bytes)
    assert exc.value.status_code == 422
    assert "MPx" in exc.value.detail or "trop grande" in exc.value.detail


def test_decode_applies_exif_orientation_6(exif_rot6_jpeg):
    img = decode_image(exif_rot6_jpeg)
    # Source: 100x200 portrait with Orientation=6 (rotate 90° CW)
    # After exif_transpose: 200x100 landscape.
    assert img.size == (200, 100), f"expected (200,100) after EXIF transpose, got {img.size}"


def test_content_type_jpg_normalised_to_jpeg():
    assert content_type_for_format("jpg") == "image/jpeg"
    assert content_type_for_format("jpeg") == "image/jpeg"
    assert content_type_for_format("png") == "image/png"
    assert content_type_for_format("webp") == "image/webp"
    assert content_type_for_format("avif") == "image/avif"


def test_image_response_sets_processing_time_header(rgb_image):
    img = decode_image(rgb_image)
    resp = image_response(img, "png")
    assert resp.media_type == "image/png"
    assert "x-processing-time" in {k.lower() for k in resp.headers.keys()}
    assert resp.body
