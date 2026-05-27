# syntax=docker/dockerfile:1.6
FROM python:3.11-slim AS base

# System libs needed by Pillow & torch & ORT (image codecs, threading, OpenMP).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libgomp1 \
 && rm -rf /var/lib/apt/lists/*

# ---- Stage 1: model-downloader (Phase 4, BGREMOVE-03 / D-15) ---------
# Downloads BiRefNet + isnet ONNX weights at build time.
# BuildKit cache mount keeps the HF cache between rebuilds (Pitfall 6).
FROM base AS model-downloader
WORKDIR /tmp/dl

RUN pip install --no-cache-dir "huggingface_hub>=0.27,<1"

# The download script does both: huggingface_hub for BiRefNet + urllib for isnet.
COPY scripts/download_models.py /tmp/dl/download_models.py
ENV MODELS_DIR=/models
ENV HF_HOME=/root/.cache/huggingface
RUN --mount=type=cache,target=/root/.cache/huggingface \
    python /tmp/dl/download_models.py

# ---- Stage 2: final --------------------------------------------------
FROM base
WORKDIR /app

# Cache pip layer separately from the model layer.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Pre-download the CLIP weights at build time so the first /embed call
# does not have to fetch ~600 MB from Hugging Face at runtime.
ENV HF_HOME=/app/.cache/huggingface
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('clip-ViT-B-32')"

COPY requirements-dev.txt .
RUN pip install --no-cache-dir -r requirements-dev.txt

# Copy pre-downloaded ONNX models from stage 1 (BGREMOVE-03, D-15).
COPY --from=model-downloader /models /app/models

COPY app.py .
COPY core ./core
COPY routers ./routers
COPY scripts ./scripts
COPY tests ./tests
COPY pytest.ini .

EXPOSE 8000
# start-period bumped 15s->30s: two extra ORT sessions to load (BGREMOVE-04).
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD python -c "import urllib.request, sys; sys.exit(0 if urllib.request.urlopen('http://localhost:8000/health').status == 200 else 1)"

# --workers 1 is REQUIRED (Pitfall 7): asyncio.Lock is per-process; >1 worker
# would defeat D-14 serialisation and double the memory footprint.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
