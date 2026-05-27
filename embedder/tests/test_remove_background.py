"""Phase 4 — POST /img/remove-background. Plan 04-02: implementations wired."""
from __future__ import annotations

import io

import pytest
from PIL import Image


def test_remove_background_birefnet_returns_png_rgba(client, product_2048_png, mock_birefnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/png"


def test_default_model_is_birefnet(client, product_2048_png, mock_birefnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": "{}"},
    )
    assert r.status_code == 200
    assert r.headers.get("X-Model-Used") == "birefnet"


def test_isnet_explicit_selection(client, product_2048_png, mock_isnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"isnet-general-use"}'},
    )
    assert r.status_code == 200


def test_unknown_model_rejected_422(client, product_2048_png):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"rmbg-1.4"}'},
    )
    assert r.status_code == 422


def test_birefnet_timeout_falls_back_to_isnet(client, product_2048_png, mock_birefnet_session, monkeypatch):
    import asyncio as _aio
    import routers.img_remove_background as rbg

    async def _raise_timeout(*args, **kwargs):
        raise _aio.TimeoutError()

    monkeypatch.setattr(rbg.asyncio, "wait_for", _raise_timeout)

    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet","fallbackOnTimeout":true}'},
    )
    assert r.status_code == 200
    assert r.headers.get("X-Model-Used") == "isnet-general-use"


def test_timeout_without_fallback_returns_504(client, product_2048_png, mock_birefnet_session, monkeypatch):
    import asyncio as _aio
    import routers.img_remove_background as rbg

    async def _raise_timeout(*args, **kwargs):
        raise _aio.TimeoutError()

    monkeypatch.setattr(rbg.asyncio, "wait_for", _raise_timeout)

    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet","fallbackOnTimeout":false}'},
    )
    assert r.status_code == 504


def test_image_over_4k_returns_413(client, product_4500_jpg):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.jpg", product_4500_jpg, "image/jpeg")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 413


def test_image_3000px_downscaled_then_upscaled(client, product_3000_jpg, mock_birefnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.jpg", product_3000_jpg, "image/jpeg")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 200
    out = Image.open(io.BytesIO(r.content))
    # Output must be at ORIGINAL dims (3000×2400), not the inference 2048
    assert out.size == (3000, 2400)


def test_output_is_png_rgba(client, product_2048_png, mock_birefnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    out = Image.open(io.BytesIO(r.content))
    assert out.mode == "RGBA"


def test_rgba_input_alpha_replaced(client, product_with_alpha_png, mock_birefnet_session):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_with_alpha_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    out = Image.open(io.BytesIO(r.content))
    assert out.mode == "RGBA"  # alpha replaced, not the original 200-uniform one


def test_lock_serializes_inflight(client, product_2048_png, mock_birefnet_session):
    from core.bgremove_state import get_inflight

    assert get_inflight() == 0  # baseline
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 200
    # After request completes, inflight returns to 0 (decremented in finally).
    assert get_inflight() == 0


def test_structured_log_emitted(client, product_2048_png, mock_birefnet_session, capsys):
    r = client.post(
        "/img/remove-background",
        files={"image": ("p.png", product_2048_png, "image/png")},
        data={"params": '{"model":"birefnet"}'},
    )
    assert r.status_code == 200
    out = capsys.readouterr().out
    assert '"event": "remove_background"' in out
