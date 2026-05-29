"""POST /img/symmetry — Pillow horizontal/vertical mirror (flip)."""
from __future__ import annotations

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from PIL import ImageOps
from pydantic import BaseModel, Field, ValidationError
from typing import Literal

from core.image_utils import decode_image, image_response

router = APIRouter()


class SymmetryParams(BaseModel):
    # 'horizontal' = miroir gauche/droite (ImageOps.mirror)
    # 'vertical'   = miroir haut/bas (ImageOps.flip)
    axis: Literal["horizontal", "vertical"] = Field(..., description="Symmetry axis")


@router.post("/img/symmetry")
async def symmetry(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p = SymmetryParams.model_validate_json(params)
    except (ValidationError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    raw = await image.read()
    img = decode_image(raw)

    result = ImageOps.mirror(img) if p.axis == "horizontal" else ImageOps.flip(img)

    fmt = (img.format or "PNG").lower()
    return image_response(result, fmt)