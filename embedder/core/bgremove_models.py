"""Lazy-load ONNX sessions and run inference. CPU only (D-01).

Two models are supported:
- BiRefNet (model_fp16.onnx) — ImageNet normalization, sigmoid post-processing.
- isnet-general-use (rembg) — mean=0.5/std=1.0 normalization, linear min/max
  post-processing (NOT sigmoid). See Research Assumption A1.

Both ORT InferenceSession instances are module-level singletons created on
first use. They MUST be called via loop.run_in_executor() to avoid blocking
the asyncio loop (Pitfall 2).
"""
from __future__ import annotations

import logging
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image

MODELS_DIR = Path("/app/models")
BIREFNET_PATH = MODELS_DIR / "birefnet" / "onnx" / "model_fp16.onnx"
ISNET_PATH = MODELS_DIR / "isnet" / "isnet-general-use.onnx"

# BiRefNet expects 1024x1024 with ImageNet normalization.
BIREFNET_SIZE = 1024
_BIREFNET_MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32).reshape(1, 3, 1, 1)
_BIREFNET_STD = np.array([0.229, 0.224, 0.225], dtype=np.float32).reshape(1, 3, 1, 1)

# isnet-general-use (rembg) — mean=0.5 std=1.0
ISNET_SIZE = 1024
_ISNET_MEAN = 0.5
_ISNET_STD = 1.0

_birefnet_session: ort.InferenceSession | None = None
_isnet_session: ort.InferenceSession | None = None

log = logging.getLogger("embedder")


def _make_session(path: Path) -> ort.InferenceSession:
    so = ort.SessionOptions()
    so.inter_op_num_threads = 1  # we serialize via asyncio.Lock (D-14)
    return ort.InferenceSession(str(path), so, providers=["CPUExecutionProvider"])


def get_birefnet() -> ort.InferenceSession:
    global _birefnet_session
    if _birefnet_session is None:
        log.info("Loading BiRefNet ONNX from %s", BIREFNET_PATH)
        _birefnet_session = _make_session(BIREFNET_PATH)
    return _birefnet_session


def get_isnet() -> ort.InferenceSession:
    global _isnet_session
    if _isnet_session is None:
        log.info("Loading isnet ONNX from %s", ISNET_PATH)
        _isnet_session = _make_session(ISNET_PATH)
    return _isnet_session


def _detect_input_dtype(sess: ort.InferenceSession) -> np.dtype:
    # A2: model_fp16.onnx accepts float16 inputs; FP32 model accepts float32.
    type_str = sess.get_inputs()[0].type  # e.g. 'tensor(float16)'
    return np.float16 if "float16" in type_str else np.float32


def _preprocess_birefnet(img: Image.Image) -> np.ndarray:
    sess = get_birefnet()
    dtype = _detect_input_dtype(sess)
    resized = img.convert("RGB").resize((BIREFNET_SIZE, BIREFNET_SIZE), Image.Resampling.BILINEAR)
    arr = np.asarray(resized, dtype=np.float32) / 255.0
    arr = arr.transpose(2, 0, 1)[None, ...]  # NCHW
    arr = (arr - _BIREFNET_MEAN) / _BIREFNET_STD
    return arr.astype(dtype)


def _preprocess_isnet(img: Image.Image) -> np.ndarray:
    sess = get_isnet()
    dtype = _detect_input_dtype(sess)
    resized = img.convert("RGB").resize((ISNET_SIZE, ISNET_SIZE), Image.Resampling.BILINEAR)
    arr = np.asarray(resized, dtype=np.float32) / 255.0
    arr = arr.transpose(2, 0, 1)[None, ...]  # NCHW
    arr = (arr - _ISNET_MEAN) / _ISNET_STD  # mean=0.5 std=1.0
    return arr.astype(dtype)


def _sigmoid(x: np.ndarray) -> np.ndarray:
    return 1.0 / (1.0 + np.exp(-x.astype(np.float32)))


def _postprocess_birefnet(raw_output: np.ndarray, dst_size: tuple[int, int]) -> Image.Image:
    # BiRefNet: sigmoid on the raw logit.
    m = raw_output.squeeze()
    m = _sigmoid(m)
    m = (m * 255.0).clip(0, 255).astype(np.uint8)
    mask = Image.fromarray(m, mode="L")
    return mask.resize(dst_size, Image.Resampling.LANCZOS)


def _postprocess_isnet(raw_output: np.ndarray, dst_size: tuple[int, int]) -> Image.Image:
    # isnet rembg: linear min/max normalisation (NOT sigmoid). See Assumption A1.
    m = raw_output.squeeze().astype(np.float32)
    mn, mx = float(m.min()), float(m.max())
    if mx - mn < 1e-6:
        arr = np.zeros_like(m, dtype=np.uint8)
    else:
        arr = ((m - mn) / (mx - mn) * 255.0).clip(0, 255).astype(np.uint8)
    mask = Image.fromarray(arr, mode="L")
    return mask.resize(dst_size, Image.Resampling.LANCZOS)


def run_birefnet(img: Image.Image) -> Image.Image:
    """CPU-bound. Call ONLY via loop.run_in_executor(None, run_birefnet, img) (Pitfall 2)."""
    sess = get_birefnet()
    x = _preprocess_birefnet(img)
    input_name = sess.get_inputs()[0].name
    out = sess.run(None, {input_name: x})[0]
    return _postprocess_birefnet(out, img.size)


def run_isnet(img: Image.Image) -> Image.Image:
    """CPU-bound. Call ONLY via loop.run_in_executor(None, run_isnet, img) (Pitfall 2)."""
    sess = get_isnet()
    x = _preprocess_isnet(img)
    input_name = sess.get_inputs()[0].name
    out = sess.run(None, {input_name: x})[0]
    return _postprocess_isnet(out, img.size)
