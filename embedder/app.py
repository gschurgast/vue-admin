"""
Image embedding microservice — CLIP ViT-B/32 (sentence-transformers).

Exposes a POST /embed endpoint that accepts a raw image (multipart
or binary body) and returns a 512-dim L2-normalised vector. Because the
vectors are normalised, cosine similarity is just a dot product, which
matches the pgvector `<=>` operator after the (1 - distance) transform.

The model is loaded once at startup (~5-10 s) and reused. No network call
leaves this container.

Phase 2: extends the service with classical image transformation endpoints
(resize, crop, rotate, format-convert, add-background) — see core/image_utils.py.
"""
from __future__ import annotations

import io
import logging
import os
from enum import Enum
from typing import List

import pillow_avif  # noqa: F401  — registers AVIF codec via import side-effect
from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, UnidentifiedImageError
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "clip-ViT-B-32")
EMBEDDING_DIM = 512

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("embedder")

app = FastAPI(title="Asset Embedder", version="2.0.0")

# Phase 2 — classical image transformation routers
from routers import (  # noqa: E402
    img_resize,
    img_crop,
    img_rotate,
    img_format_convert,
    img_add_background,
)

app.include_router(img_resize.router)
app.include_router(img_crop.router)
app.include_router(img_rotate.router)
app.include_router(img_format_convert.router)
app.include_router(img_add_background.router)

# Loaded on the first request OR at boot via the startup hook below.
_model: SentenceTransformer | None = None


class ModelStatus(str, Enum):
    loaded = "loaded"
    lazy = "lazy"
    not_loaded = "not_loaded"
    failed = "failed"


def get_model() -> SentenceTransformer:
    global _model
    if _model is None:
        log.info("Loading CLIP model %s ...", MODEL_NAME)
        _model = SentenceTransformer(MODEL_NAME)
        log.info("Model loaded.")
    return _model


@app.on_event("startup")
def _warmup() -> None:
    # Pre-load synchronously so the first /embed call is fast.
    get_model()


@app.get("/health")
def health() -> dict:
    clip_status = ModelStatus.loaded if _model is not None else ModelStatus.lazy
    return {
        "status": "ok",
        "models": {
            "clip": {
                "status": clip_status.value,
                "name": MODEL_NAME,
                "dim": EMBEDDING_DIM,
            },
            "birefnet": {"status": ModelStatus.not_loaded.value},
            "stable_diffusion": {"status": ModelStatus.not_loaded.value},
        },
    }


def _embed_image(raw: bytes) -> List[float]:
    try:
        img = Image.open(io.BytesIO(raw)).convert("RGB")
    except UnidentifiedImageError as exc:
        raise HTTPException(status_code=400, detail=f"Not a valid image: {exc}")

    model = get_model()
    vec = model.encode(img, normalize_embeddings=True, convert_to_numpy=True)
    return vec.astype("float32").tolist()


@app.post("/embed")
async def embed(file: UploadFile = File(...)) -> dict:
    raw = await file.read()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty file.")
    embedding = _embed_image(raw)
    return {
        "embedding": embedding,
        "model": MODEL_NAME,
        "dim": len(embedding),
    }
