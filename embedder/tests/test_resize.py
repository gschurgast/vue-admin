import io
import json

from PIL import Image


def _post_resize(client, image_bytes, params_dict, mime="image/png", filename="t.png"):
    return client.post(
        "/img/resize",
        data={"params": json.dumps(params_dict)},
        files={"image": (filename, image_bytes, mime)},
    )


def test_fit_preserves_ratio(client, rgb_image):
    r = _post_resize(client, rgb_image, {"width": 100, "height": 100, "mode": "fit"})
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/png"
    out = Image.open(io.BytesIO(r.content))
    assert out.size[0] <= 100 and out.size[1] <= 100
    # Source 200x150 has ratio 4:3 → fit into 100x100 → 100x75
    assert out.size == (100, 75)


def test_cover_exact_size(client, rgb_image):
    r = _post_resize(client, rgb_image, {"width": 100, "height": 100, "mode": "cover"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (100, 100)


def test_contain_padding(client, rgb_image):
    r = _post_resize(client, rgb_image, {"width": 300, "height": 300, "mode": "contain", "upscale": True})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    assert out.size == (300, 300)


def test_no_upscale(client, rgb_image):
    r = _post_resize(client, rgb_image, {"width": 1000, "height": 1000, "mode": "fit"})
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    # Source 200x150, upscale default false → returned at most 200x150
    assert out.size == (200, 150)


def test_processing_time_header(client, rgb_image):
    r = _post_resize(client, rgb_image, {"width": 50, "mode": "fit"})
    assert r.status_code == 200
    assert "x-processing-time" in {k.lower() for k in r.headers.keys()}


def test_missing_dimensions_422(client, rgb_image):
    r = _post_resize(client, rgb_image, {"mode": "fit"})
    assert r.status_code == 422


def test_resize_rejects_svg(client, svg_bytes):
    r = _post_resize(client, svg_bytes, {"width": 50, "height": 50, "mode": "fit"},
                     mime="image/svg+xml", filename="t.svg")
    assert r.status_code == 422
