import io
import json

from PIL import Image


def _post(client, image_bytes, params, mime="image/png"):
    return client.post(
        "/img/crop",
        data={"params": json.dumps(params)},
        files={"image": ("t.png", image_bytes, mime)},
    )


def _square_image(size=(300, 100), mode="RGB", color=(0, 0, 0)) -> bytes:
    img = Image.new(mode, size, color)
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue()


def test_absolute_crop(client, rgb_image):
    r = _post(client, rgb_image, {"x": 10, "y": 10, "width": 50, "height": 50})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (50, 50)


def test_absolute_crop_out_of_bounds_422(client, rgb_image):
    r = _post(client, rgb_image, {"x": 0, "y": 0, "width": 9999, "height": 9999})
    assert r.status_code == 422


def test_ratio_center_square(client, rgb_image):
    # 200x150 source, ratio 1.0 → wider than 1:1 → crop sideways → 150x150 centered
    r = _post(client, rgb_image, {"aspectRatio": 1.0, "anchor": "center"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (150, 150)


def test_ratio_anchor_top(client):
    src = _square_image((200, 200))
    r = _post(client, src, {"aspectRatio": 16 / 9, "anchor": "top"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    # 200x200, ratio 16/9 ≈ 1.777 → new_h = 200/1.777 ≈ 112
    assert out.size in {(200, 112), (200, 113)}


def test_ratio_anchor_left(client):
    src = _square_image((300, 100))
    r = _post(client, src, {"aspectRatio": 1.0, "anchor": "left"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (100, 100)


def test_crop_rejects_svg(client, svg_bytes):
    r = _post(client, svg_bytes, {"x": 0, "y": 0, "width": 5, "height": 5}, mime="image/svg+xml")
    assert r.status_code == 422
