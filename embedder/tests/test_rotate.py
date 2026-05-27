import io
import json

from PIL import Image


def _post(client, image_bytes, params, mime="image/png"):
    return client.post(
        "/img/rotate",
        data={"params": json.dumps(params)},
        files={"image": ("t.png", image_bytes, mime)},
    )


def test_rotate_90_swaps_dimensions(client, rgb_image):
    r = _post(client, rgb_image, {"angle": 90, "expand": True})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (150, 200)


def test_rotate_180_no_expand_keeps_dimensions(client, rgb_image):
    r = _post(client, rgb_image, {"angle": 180, "expand": False})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (200, 150)


def test_rotate_rgb_fillcolor_applied(client, rgb_image):
    r = _post(client, rgb_image, {"angle": 45, "expand": True, "background": "#FF0000"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content)).convert("RGB")
    px = out.getpixel((0, 0))
    assert px == (255, 0, 0), f"expected red corner, got {px}"


def test_rotate_rgba_transparent_bg(client, rgba_image):
    r = _post(client, rgba_image, {"angle": 45, "expand": True})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.mode == "RGBA"
    px = out.getpixel((0, 0))
    assert px[3] == 0, f"expected alpha=0, got alpha={px[3]}"


def test_rotate_invalid_hex_color_422(client, rgb_image):
    r = _post(client, rgb_image, {"angle": 30, "background": "#GGGGGG"})
    assert r.status_code == 422


def test_rotate_rejects_svg(client, svg_bytes):
    r = _post(client, svg_bytes, {"angle": 90}, mime="image/svg+xml")
    assert r.status_code == 422
