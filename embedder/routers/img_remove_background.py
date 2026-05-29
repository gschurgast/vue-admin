"""POST /img/remove-background — BiRefNet primary + isnet-general-use fallback (Phase 4, D-17)."""
from __future__ import annotations

import asyncio
import io
import os
import time
from typing import Literal

from fastapi import APIRouter, File, Form, HTTPException, Response, UploadFile
from PIL import Image
from pydantic import BaseModel, ValidationError

from core.image_utils import decode_image
from core.bgremove_models import run_birefnet, run_isnet
from core.bgremove_state import lock, set_inflight, set_last_ms
from core.log_json import log_event

router = APIRouter()

MAX_DIM = 4096            # D-07
DOWNSCALE_LONG_EDGE = 2048
# D-05: 5s hard cap en prod. Override via env BIREFNET_TIMEOUT_S pour dev CPU sous-dimensionné.
BIREFNET_TIMEOUT_S = float(os.environ.get("BIREFNET_TIMEOUT_S", "5.0"))


class RemoveBgParams(BaseModel):
    model: Literal["birefnet", "isnet-general-use"] = "birefnet"
    fallbackOnTimeout: bool = False


@router.post("/img/remove-background")
async def remove_background(image: UploadFile = File(...), params: str = Form(...)):
    try:
        p = RemoveBgParams.model_validate_json(params)
    except (ValidationError, ValueError) as exc:
        raise HTTPException(status_code=422, detail=str(exc))

    raw = await image.read()
    img = decode_image(raw)  # EXIF + 50 MPx + SVG reject (Phase 2)

    # D-07: 4K hard cap (stricter than the 50 MPx ceiling for this endpoint).
    if max(img.size) > MAX_DIM:
        raise HTTPException(status_code=413, detail=f"Image > {MAX_DIM}px long edge.")

    # D-07 + Pitfall 3: copy BEFORE thumbnail (in-place mutation otherwise).
    orig_size = img.size
    inference_img = img.copy()
    if max(orig_size) > DOWNSCALE_LONG_EDGE:
        inference_img.thumbnail(
            (DOWNSCALE_LONG_EDGE, DOWNSCALE_LONG_EDGE),
            Image.Resampling.LANCZOS,
        )

    fallback_used = False
    t0 = time.perf_counter()
    loop = asyncio.get_running_loop()

    async with lock:
        set_inflight(+1)
        try:
            if p.model == "birefnet":
                try:
                    mask = await asyncio.wait_for(
                        loop.run_in_executor(None, run_birefnet, inference_img),
                        timeout=BIREFNET_TIMEOUT_S,
                    )
                except asyncio.TimeoutError:
                    if not p.fallbackOnTimeout:
                        raise HTTPException(status_code=504, detail="BiRefNet timeout (>5s)")
                    mask = await loop.run_in_executor(None, run_isnet, inference_img)
                    fallback_used = True
            else:  # isnet-general-use explicit
                mask = await loop.run_in_executor(None, run_isnet, inference_img)
        finally:
            set_inflight(-1)
            latency_ms = int((time.perf_counter() - t0) * 1000)
            set_last_ms(latency_ms)

    # D-08: upscale mask to original dims, then compose RGBA.
    mask_full = mask.resize(orig_size, Image.Resampling.LANCZOS)
    # Pitfall 4: force RGB to handle paletted / 1-bit / LA modes safely.
    rgba = img.convert("RGB")
    rgba.putalpha(mask_full)  # D-09: replaces any prior alpha

    log_event(
        "remove_background",
        model=p.model,
        latency_ms=latency_ms,
        image_dims=f"{orig_size[0]}x{orig_size[1]}",
        fallback_used=fallback_used,
    )

    buf = io.BytesIO()
    rgba.save(buf, "PNG")
    return Response(
        content=buf.getvalue(),
        media_type="image/png",
        headers={
            "X-Render-Duration-Ms": str(latency_ms),
            "X-Model-Used": "isnet-general-use" if fallback_used else p.model,
        },
    )
