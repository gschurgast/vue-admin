"""POST /img/add-background — composite an image over a background.

Two modes:
  - type=color: solid color (#RRGGBB) — single multipart field 'image' + params
  - type=asset: background bytes provided as SECOND multipart field 'background_image'.
    The PHP orchestrator (Phase 3) reads bytes from Flysystem and sends them inline.
    The 'assetId' in params is for log traceability ONLY — never used for fetch.
    NO URL is ever accepted in the schema — anti-SSRF by construction.
"""
from __future__ import annotations

import json
import logging
from typing import Literal, Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import Image
from pydantic import BaseModel, Field, ValidationError

from core.image_utils import decode_image, image_response

router = APIRouter()
log = logging.getLogger("embedder")


class BgColor(BaseModel):
    type: Literal["color"]
    color: str = Field(pattern=r"^#[0-9A-Fa-f]{6}$")


class BgAsset(BaseModel):
    type: Literal["asset"]
    assetId: int = Field(gt=0)


def _parse_hex(s: str) -> tuple[int, int, int]:
    s = s.lstrip("#")
    return (int(s[0:2], 16), int(s[2:4], 16), int(s[4:6], 16))


def _composite_color(img: Image.Image, color_hex: str) -> Image.Image:
    """Place img over solid color. If img has no alpha, return as-is."""
    if img.mode != "RGBA":
        return img
    color = _parse_hex(color_hex)
    bg = Image.new("RGB", img.size, color)
    bg.paste(img, mask=img.split()[3])
    return bg


def _composite_asset(img: Image.Image, bg_img: Image.Image) -> Image.Image:
    """Resize bg_img to match img.size, paste img on top using alpha if present."""
    bg = bg_img.resize(img.size, Image.Resampling.LANCZOS).convert("RGB")
    if img.mode == "RGBA":
        bg.paste(img, mask=img.split()[3])
    else:
        bg.paste(img)
    return bg


@router.post("/img/add-background")
async def add_background(
    image: UploadFile = File(...),
    params: str = Form(...),
    background_image: Optional[UploadFile] = File(None),
):
    try:
        p_raw = json.loads(params)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=422, detail=f"Invalid JSON params: {exc}")

    if not isinstance(p_raw, dict) or "type" not in p_raw:
        raise HTTPException(status_code=422, detail="Missing 'type' in params.")

    # Anti-SSRF deep defense: reject any URL-like field at top level.
    for forbidden in ("url", "src", "href"):
        if forbidden in p_raw:
            raise HTTPException(
                status_code=422,
                detail=f"Field '{forbidden}' is not allowed (anti-SSRF).",
            )

    t = p_raw["type"]
    raw = await image.read()
    img = decode_image(raw)

    if t == "color":
        try:
            pc = BgColor.model_validate(p_raw)
        except ValidationError as exc:
            raise HTTPException(status_code=422, detail=exc.errors())
        result = _composite_color(img, pc.color)
    elif t == "asset":
        try:
            pa = BgAsset.model_validate(p_raw)
        except ValidationError as exc:
            raise HTTPException(status_code=422, detail=exc.errors())
        if background_image is None:
            raise HTTPException(
                status_code=422,
                detail="background_image multipart field required for type=asset.",
            )
        bg_raw = await background_image.read()
        bg_img = decode_image(bg_raw)
        log.info(
            "add_background type=asset assetId=%d bg_size=%s img_size=%s",
            pa.assetId, bg_img.size, img.size,
        )
        result = _composite_asset(img, bg_img)
    else:
        raise HTTPException(status_code=422, detail=f"Unknown type: {t}")

    fmt = (img.format or "PNG").lower()
    return image_response(result, fmt)
