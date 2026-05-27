"""POST /img/format-convert — output format conversion.

Supports PNG, JPEG (incl. alpha-flatten on white), WebP, AVIF.
AVIF codec is registered via `import pillow_avif` at app.py module load (Plan 02-01).
"""
from __future__ import annotations

from typing import Literal, Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import Image
from pydantic import BaseModel, Field, ValidationError

from core.image_utils import decode_image, image_response

router = APIRouter()


class FormatConvertParams(BaseModel):
    format: Literal["png", "jpg", "jpeg", "webp", "avif"]
    quality: Optional[int] = Field(None, ge=1, le=100)


def _flatten_on_white(img: Image.Image) -> Image.Image:
    """Composite an RGBA/LA/PA image over a white RGB background.
    Required before saving JPEG (no alpha channel)."""
    bg = Image.new("RGB", img.size, (255, 255, 255))
    if img.mode == "RGBA":
        bg.paste(img, mask=img.split()[3])
    else:
        rgba = img.convert("RGBA")
        bg.paste(rgba, mask=rgba.split()[3])
    return bg


@router.post("/img/format-convert")
async def format_convert(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p = FormatConvertParams.model_validate_json(params)
    except ValidationError as exc:
        raise HTTPException(status_code=422, detail=exc.errors())

    raw = await image.read()
    img = decode_image(raw)

    target_fmt = p.format.lower()
    pillow_target = "JPEG" if target_fmt in ("jpg", "jpeg") else target_fmt.upper()

    extra_headers = None
    if pillow_target == "JPEG" and img.mode in ("RGBA", "LA", "PA"):
        img = _flatten_on_white(img)
        extra_headers = {"X-Alpha-Flattened": "true"}
    elif pillow_target == "JPEG" and img.mode not in ("RGB", "L"):
        img = img.convert("RGB")
    elif pillow_target == "AVIF" and img.mode not in ("RGB", "RGBA"):
        img = img.convert("RGBA" if "A" in img.mode else "RGB")

    try:
        return image_response(img, target_fmt, quality=p.quality, extra_headers=extra_headers)
    except KeyError as exc:
        raise HTTPException(status_code=422, detail=f"Format non supporté : {exc}")
