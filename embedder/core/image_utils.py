"""Shared image helpers — decode, encode, MIME mapping.

SECURITY (per IMGSVC-08, IMGSVC-09, IMGSVC-10):
- Image.open() is LAZY: .size is available BEFORE pixel decoding.
- We check w*h > 50 MPx FIRST, then exif_transpose (which RETURNS a new Image —
  we MUST reassign), then .load() to actually materialize pixels.
- SVG is rejected (raster endpoints only).
- We DO NOT touch Image.MAX_IMAGE_PIXELS globally because /embed uses Pillow too.
"""
from __future__ import annotations

import io
import time
from typing import Optional

from fastapi import HTTPException, Response
from PIL import Image, ImageOps, UnidentifiedImageError

MAX_PIXELS: int = 50_000_000  # 50 megapixels

CONTENT_TYPES = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "webp": "image/webp",
    "avif": "image/avif",
}


def _looks_like_svg(raw: bytes) -> bool:
    head = raw.lstrip()[:512].lower()
    return b"<svg" in head or (head.startswith(b"<?xml") and b"<svg" in head)


def decode_image(raw: bytes) -> Image.Image:
    if not raw:
        raise HTTPException(status_code=422, detail="Empty image payload.")
    if _looks_like_svg(raw):
        raise HTTPException(
            status_code=422,
            detail="SVG non supporté par les endpoints de transformation raster.",
        )
    try:
        img = Image.open(io.BytesIO(raw))
    except UnidentifiedImageError as exc:
        raise HTTPException(status_code=422, detail=f"Fichier image non reconnu : {exc}")
    except Exception as exc:  # pragma: no cover - defensive
        raise HTTPException(status_code=422, detail=f"Image illisible : {exc}")

    # .size is populated by open() WITHOUT decoding pixels.
    w, h = img.size
    if w * h > MAX_PIXELS:
        raise HTTPException(
            status_code=422,
            detail=f"Image trop grande ({w}x{h} = {w * h / 1e6:.1f} MPx). Maximum : 50 MPx.",
        )

    # exif_transpose returns a NEW Image — must reassign.
    img = ImageOps.exif_transpose(img)
    img.load()
    return img


def content_type_for_format(fmt: str) -> str:
    return CONTENT_TYPES.get(fmt.lower(), "application/octet-stream")


def image_response(
    img: Image.Image,
    fmt: str,
    quality: Optional[int] = None,
    extra_headers: Optional[dict] = None,
) -> Response:
    t0 = time.perf_counter()
    buf = io.BytesIO()
    save_kwargs: dict = {}
    if quality is not None:
        save_kwargs["quality"] = quality
    pillow_fmt = fmt.upper().replace("JPG", "JPEG")
    img.save(buf, format=pillow_fmt, **save_kwargs)
    elapsed_ms = (time.perf_counter() - t0) * 1000
    headers = {"X-Processing-Time": f"{elapsed_ms:.1f}ms"}
    if extra_headers:
        headers.update(extra_headers)
    return Response(
        content=buf.getvalue(),
        media_type=content_type_for_format(fmt),
        headers=headers,
    )
