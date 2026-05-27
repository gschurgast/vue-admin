"""POST /img/crop — absolute (x,y,width,height) or aspectRatio+anchor."""
from __future__ import annotations

import json
from typing import Literal

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import Image
from pydantic import BaseModel, Field, ValidationError

from core.image_utils import decode_image, image_response

router = APIRouter()


class CropAbsolute(BaseModel):
    x: int = Field(ge=0)
    y: int = Field(ge=0)
    width: int = Field(gt=0)
    height: int = Field(gt=0)


class CropRatio(BaseModel):
    aspectRatio: float = Field(gt=0)
    anchor: Literal["center", "top", "bottom", "left", "right"] = "center"


def _crop_by_ratio(img: Image.Image, ratio: float, anchor: str) -> Image.Image:
    w, h = img.size
    if w / h > ratio:
        new_w = int(round(h * ratio))
        if anchor == "left":
            left = 0
        elif anchor == "right":
            left = w - new_w
        else:
            left = (w - new_w) // 2
        return img.crop((left, 0, left + new_w, h))
    else:
        new_h = int(round(w / ratio))
        if anchor == "top":
            top = 0
        elif anchor == "bottom":
            top = h - new_h
        else:
            top = (h - new_h) // 2
        return img.crop((0, top, w, top + new_h))


@router.post("/img/crop")
async def crop(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p_raw = json.loads(params)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=422, detail=f"Invalid JSON params: {exc}")

    raw = await image.read()
    img = decode_image(raw)
    w, h = img.size

    if "aspectRatio" in p_raw:
        try:
            pr = CropRatio.model_validate(p_raw)
        except ValidationError as exc:
            raise HTTPException(status_code=422, detail=exc.errors())
        result = _crop_by_ratio(img, pr.aspectRatio, pr.anchor)
    else:
        try:
            pa = CropAbsolute.model_validate(p_raw)
        except ValidationError as exc:
            raise HTTPException(status_code=422, detail=exc.errors())
        if pa.x + pa.width > w or pa.y + pa.height > h:
            raise HTTPException(
                status_code=422,
                detail=f"Crop out of bounds: source {w}x{h}, requested x+w={pa.x + pa.width} y+h={pa.y + pa.height}",
            )
        result = img.crop((pa.x, pa.y, pa.x + pa.width, pa.y + pa.height))

    fmt = (img.format or "PNG").lower()
    return image_response(result, fmt)
