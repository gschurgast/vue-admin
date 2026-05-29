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
    img_remove_background,
    img_symmetry,
)

app.include_router(img_resize.router)
app.include_router(img_crop.router)
app.include_router(img_rotate.router)
app.include_router(img_format_convert.router)
app.include_router(img_add_background.router)
app.include_router(img_remove_background.router)
app.include_router(img_symmetry.router)

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
    # Pre-load CLIP synchronously so the first /embed call is fast.
    get_model()
    # Pre-load ONNX sessions so /health reports `loaded` from t0 (D-11 + Pattern 4).
    # Failure to load is non-fatal: /health will report `degraded` and
    # POST /img/remove-background will surface a 500 on inference.
    try:
        from core.bgremove_models import get_birefnet, get_isnet
        get_birefnet()
        get_isnet()
    except Exception as exc:  # broad: don't kill the container on a missing file in dev
        log.warning("BiRefNet/isnet preload skipped: %s", exc)


@app.get("/health")
def health() -> dict:
    # Lazy import to keep startup order safe even if the bgremove
    # module fails to import (we still want /health to respond).
    try:
        from core.bgremove_models import _birefnet_session, _isnet_session
        from core.bgremove_state import get_inflight, get_last_ms
        birefnet_loaded = _birefnet_session is not None
        isnet_loaded = _isnet_session is not None
        inflight = get_inflight()
        last_ms = get_last_ms()
    except ImportError:
        birefnet_loaded = False
        isnet_loaded = False
        inflight = 0
        last_ms = None

    clip_status = ModelStatus.loaded if _model is not None else ModelStatus.lazy
    status = "ok" if (birefnet_loaded and inflight <= 4) else "degraded"

    return {
        "status": status,
        "models": {
            "clip": {
                "status": clip_status.value,
                "name": MODEL_NAME,
                "dim": EMBEDDING_DIM,
            },
            "birefnet": {
                "status": ModelStatus.loaded.value if birefnet_loaded else ModelStatus.not_loaded.value,
                "model": "birefnet-general-fp16",
                "inflight": inflight,
                "last_inference_ms": last_ms,
            },
            "isnet": {
                "status": ModelStatus.loaded.value if isnet_loaded else ModelStatus.not_loaded.value,
            },
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
