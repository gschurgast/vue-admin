"""Download BiRefNet + isnet ONNX models at Docker build time.

Invoked from Dockerfile model-downloader stage. Idempotent; uses
huggingface_hub for BiRefNet and direct GitHub release URL for isnet.
"""
from __future__ import annotations

import os
import sys
import urllib.request
from pathlib import Path

from huggingface_hub import snapshot_download

MODELS_DIR = Path(os.environ.get("MODELS_DIR", "/models"))

BIREFNET_REPO = "onnx-community/BiRefNet-ONNX"
BIREFNET_PATTERNS = ["onnx/model_fp16.onnx", "config.json"]

ISNET_URL = "https://github.com/danielgatis/rembg/releases/download/v0.0.0/isnet-general-use.onnx"


def download_birefnet() -> None:
    target = MODELS_DIR / "birefnet"
    target.mkdir(parents=True, exist_ok=True)
    snapshot_download(
        repo_id=BIREFNET_REPO,
        local_dir=str(target),
        allow_patterns=BIREFNET_PATTERNS,
    )
    print(f"[download_models] BiRefNet OK -> {target}")


def download_isnet() -> None:
    target = MODELS_DIR / "isnet"
    target.mkdir(parents=True, exist_ok=True)
    out = target / "isnet-general-use.onnx"
    if out.exists() and out.stat().st_size > 100_000_000:
        print(f"[download_models] isnet already present ({out.stat().st_size} B), skip")
        return
    print(f"[download_models] Downloading isnet from {ISNET_URL} ...")
    urllib.request.urlretrieve(ISNET_URL, out)
    print(f"[download_models] isnet OK -> {out}")


if __name__ == "__main__":
    download_birefnet()
    download_isnet()
    sys.exit(0)
