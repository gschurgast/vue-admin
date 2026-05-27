"""POST /img/rotate — Pillow Image.rotate with expand + fillcolor."""
from __future__ import annotations

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import Image
from pydantic import BaseModel, ValidationError

from core.image_utils import decode_image, image_response

router = APIRouter()


class RotateParams(BaseModel):
    angle: float
    background: str = "#000000"
    expand: bool = True


def _parse_hex_color(s: str) -> tuple[int, int, int]:
    s = s.lstrip("#")
    if len(s) != 6:
        raise ValueError(f"Invalid hex color '{s}': must be 6 hex digits")
    try:
        return (int(s[0:2], 16), int(s[2:4], 16), int(s[4:6], 16))
    except ValueError as exc:
        raise ValueError(f"Invalid hex color '#{s}': {exc}")


@router.post("/img/rotate")
async def rotate(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p = RotateParams.model_validate_json(params)
    except (ValidationError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    raw = await image.read()
    img = decode_image(raw)

    if img.mode == "RGBA":
        result = img.rotate(p.angle, expand=p.expand, resample=Image.Resampling.BICUBIC)
    else:
        try:
            bg = _parse_hex_color(p.background)
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc))
        result = img.rotate(
            p.angle, expand=p.expand,
            resample=Image.Resampling.BICUBIC, fillcolor=bg,
        )

    fmt = (img.format or "PNG").lower()
    return image_response(result, fmt)
