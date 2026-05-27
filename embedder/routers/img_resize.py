"""POST /img/resize — Pillow ImageOps fit/pad/thumbnail."""
from __future__ import annotations

from typing import Literal, Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import Image, ImageOps
from pydantic import BaseModel, Field, ValidationError, model_validator

from core.image_utils import decode_image, image_response

router = APIRouter()


class ResizeParams(BaseModel):
    width: Optional[int] = Field(None, gt=0, le=8000)
    height: Optional[int] = Field(None, gt=0, le=8000)
    mode: Literal["fit", "cover", "contain"] = "fit"
    upscale: bool = False

    @model_validator(mode="after")
    def _at_least_one_dim(self):
        if self.width is None and self.height is None:
            raise ValueError("At least one of width/height must be provided.")
        return self


@router.post("/img/resize")
async def resize(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p = ResizeParams.model_validate_json(params)
    except (ValidationError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    raw = await image.read()
    img = decode_image(raw)
    src_w, src_h = img.size
    target_w = p.width or src_w
    target_h = p.height or src_h

    if not p.upscale:
        target_w = min(target_w, src_w)
        target_h = min(target_h, src_h)

    if p.mode == "cover":
        result = ImageOps.fit(img, (target_w, target_h), Image.Resampling.LANCZOS)
    elif p.mode == "contain":
        result = ImageOps.pad(img, (target_w, target_h), Image.Resampling.LANCZOS)
    else:  # fit
        img.thumbnail((target_w, target_h), Image.Resampling.LANCZOS)
        result = img

    fmt = (img.format or "PNG").lower()
    return image_response(result, fmt)
