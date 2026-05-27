import io
import json

from PIL import Image


def _post(client, image_bytes, params, mime="image/png"):
    return client.post(
        "/img/format-convert",
        data={"params": json.dumps(params)},
        files={"image": ("t.png", image_bytes, mime)},
    )


def test_to_png(client, jpeg_image):
    r = _post(client, jpeg_image, {"format": "png"}, mime="image/jpeg")
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/png"
    out = Image.open(io.BytesIO(r.content))
    assert out.format == "PNG"


def test_to_jpeg_quality(client, rgb_image):
    r_hi = _post(client, rgb_image, {"format": "jpeg", "quality": 95})
    r_lo = _post(client, rgb_image, {"format": "jpeg", "quality": 30})
    assert r_hi.status_code == 200 and r_lo.status_code == 200
    assert r_hi.headers["content-type"] == "image/jpeg"
    assert len(r_lo.content) < len(r_hi.content), "lower quality should give smaller file"


def test_jpg_alias_normalised_to_image_jpeg(client, rgb_image):
    r = _post(client, rgb_image, {"format": "jpg"})
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/jpeg"


def test_to_webp(client, rgb_image):
    r = _post(client, rgb_image, {"format": "webp", "quality": 80})
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/webp"
    out = Image.open(io.BytesIO(r.content))
    assert out.format == "WEBP"


def test_to_avif(client, rgb_image):
    r = _post(client, rgb_image, {"format": "avif", "quality": 50})
    assert r.status_code == 200, f"AVIF failed: {r.status_code} {r.text[:200]}"
    assert r.headers["content-type"] == "image/avif"
    out = Image.open(io.BytesIO(r.content))
    assert out.format == "AVIF"


def test_rgba_to_jpeg_flattens_on_white(client, rgba_image):
    r = _post(client, rgba_image, {"format": "jpeg", "quality": 90})
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/jpeg"
    assert r.headers.get("x-alpha-flattened") == "true"
    out = Image.open(io.BytesIO(r.content))
    assert out.format == "JPEG"
    assert out.mode == "RGB"


def test_rgb_to_jpeg_no_flatten_header(client, rgb_image):
    r = _post(client, rgb_image, {"format": "jpeg"})
    assert r.status_code == 200
    assert "x-alpha-flattened" not in {k.lower() for k in r.headers.keys()}


def test_rgba_to_webp_preserves_alpha(client, rgba_image):
    r = _post(client, rgba_image, {"format": "webp"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.format == "WEBP"


def test_rgba_to_png_preserves_alpha(client, rgba_image):
    r = _post(client, rgba_image, {"format": "png"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert "A" in out.mode


def test_quality_out_of_range_422(client, rgb_image):
    r = _post(client, rgb_image, {"format": "jpeg", "quality": 0})
    assert r.status_code == 422
    r2 = _post(client, rgb_image, {"format": "jpeg", "quality": 101})
    assert r2.status_code == 422


def test_unsupported_format_422(client, rgb_image):
    r = _post(client, rgb_image, {"format": "bmp"})
    assert r.status_code == 422


def test_format_convert_rejects_svg(client, svg_bytes):
    r = _post(client, svg_bytes, {"format": "png"}, mime="image/svg+xml")
    assert r.status_code == 422
