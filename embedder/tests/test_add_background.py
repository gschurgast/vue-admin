import io
import json

from PIL import Image


def _post(client, image_bytes, params, mime="image/png", bg_bytes=None, bg_mime="image/png"):
    data = {"params": json.dumps(params)}
    files = {"image": ("t.png", image_bytes, mime)}
    if bg_bytes is not None:
        files["background_image"] = ("bg.png", bg_bytes, bg_mime)
    return client.post("/img/add-background", data=data, files=files)


def _rgba(size=(100, 100), color=(0, 255, 0, 128)) -> bytes:
    img = Image.new("RGBA", size, color)
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue()


# ============ type=color ============

def test_color_red_under_rgba(client):
    src = _rgba((50, 50), color=(0, 255, 0, 128))
    r = _post(client, src, {"type": "color", "color": "#FF0000"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content)).convert("RGB")
    px = out.getpixel((25, 25))
    assert px[0] > 0 and px[1] > 0, f"expected red+green blend, got {px}"
    assert out.mode == "RGB"


def test_color_white_under_fully_transparent(client):
    img = Image.new("RGBA", (50, 50), (0, 0, 0, 0))
    buf = io.BytesIO()
    img.save(buf, "PNG")
    src = buf.getvalue()
    r = _post(client, src, {"type": "color", "color": "#FFFFFF"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content)).convert("RGB")
    assert out.getpixel((25, 25)) == (255, 255, 255)


def test_color_on_rgb_unchanged(client, rgb_image):
    r = _post(client, rgb_image, {"type": "color", "color": "#000000"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    src = Image.open(io.BytesIO(rgb_image))
    assert out.size == src.size


def test_invalid_hex_color_422(client, rgba_image):
    r = _post(client, rgba_image, {"type": "color", "color": "#GGGGGG"})
    assert r.status_code == 422


def test_unknown_type_422(client, rgba_image):
    r = _post(client, rgba_image, {"type": "gradient"})
    assert r.status_code == 422


def test_missing_type_422(client, rgba_image):
    r = _post(client, rgba_image, {"color": "#FF0000"})
    assert r.status_code == 422


def test_url_field_rejected_ssrf(client, rgba_image):
    """Anti-SSRF deep defense: any url-like field is rejected at top level."""
    r = _post(client, rgba_image, {"type": "color", "color": "#FF0000", "url": "http://internal"})
    assert r.status_code == 422


def test_addbg_rejects_svg(client, svg_bytes):
    r = _post(client, svg_bytes, {"type": "color", "color": "#FFFFFF"}, mime="image/svg+xml")
    assert r.status_code == 422


# ============ type=asset (double multipart) ============

def test_asset_multipart_blue_bg_under_green(client):
    src_img = Image.new("RGBA", (100, 100), (0, 255, 0, 200))
    buf = io.BytesIO()
    src_img.save(buf, "PNG")
    src = buf.getvalue()

    bg_img = Image.new("RGB", (200, 200), (0, 0, 255))
    bbuf = io.BytesIO()
    bg_img.save(bbuf, "JPEG")
    bg = bbuf.getvalue()

    r = _post(client, src, {"type": "asset", "assetId": 42}, bg_bytes=bg, bg_mime="image/jpeg")
    assert r.status_code == 200, f"got {r.status_code}: {r.text[:200]}"
    out = Image.open(io.BytesIO(r.content)).convert("RGB")
    assert out.size == (100, 100)
    px = out.getpixel((50, 50))
    assert px[2] > 0, f"expected blue contribution, got {px}"


def test_asset_missing_background_image_422(client, rgba_image):
    r = _post(client, rgba_image, {"type": "asset", "assetId": 42})
    assert r.status_code == 422
    assert "background_image" in r.text


def test_asset_invalid_assetid_422(client, rgba_image):
    bg = Image.new("RGB", (50, 50), (0, 0, 0))
    bbuf = io.BytesIO()
    bg.save(bbuf, "PNG")
    bg_bytes = bbuf.getvalue()
    r = _post(client, rgba_image, {"type": "asset", "assetId": 0}, bg_bytes=bg_bytes)
    assert r.status_code == 422


def test_asset_svg_background_rejected(client, rgba_image, svg_bytes):
    r = _post(
        client, rgba_image,
        {"type": "asset", "assetId": 42},
        bg_bytes=svg_bytes, bg_mime="image/svg+xml",
    )
    assert r.status_code == 422


def test_asset_huge_background_rejected(client, rgba_image, huge_image_bytes):
    r = _post(
        client, rgba_image,
        {"type": "asset", "assetId": 42},
        bg_bytes=huge_image_bytes,
    )
    assert r.status_code == 422
