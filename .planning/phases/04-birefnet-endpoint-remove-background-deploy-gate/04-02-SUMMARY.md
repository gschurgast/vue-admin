---
phase: 04
plan: 02
subsystem: embedder-bgremove-endpoint
tags: [phase-04, wave-1, embedder, python, fastapi, onnx, birefnet, isnet, asyncio]
requires:
  - Phase 04 Plan 01 (xfail stubs + fixtures + mock_birefnet_session)
  - Phase 02 (core/image_utils.decode_image)
  - onnxruntime==1.22.0 (pinned in requirements.txt, installed at runtime via pip)
provides:
  - POST /img/remove-background endpoint (BiRefNet primary + isnet fallback, sync)
  - core.bgremove_state.lock (asyncio.Lock) + inflight counter + last_inference_ms (D-14)
  - core.bgremove_models.{run_birefnet, run_isnet} ORT session singletons + preprocessing
  - core.log_json.log_event() helper (Datadog-compatible structured logs)
  - scripts/download_models.py (huggingface_hub + urllib, invoked by Plan 04-03 Dockerfile)
affects:
  - embedder/app.py (include_router img_remove_background)
  - embedder/tests/test_remove_background.py (xfail → 12 passing)
  - embedder/tests/conftest.py (mock_birefnet_session also patches router module)
tech-stack:
  added:
    - onnxruntime 1.22.0 (installed in running container; image rebuild deferred to Plan 04-03)
  patterns:
    - "async with lock + set_inflight(+1)/-1 finally pattern for inference serialization"
    - "loop.run_in_executor(None, run_*, img) for CPU-bound ORT calls (Pitfall 2)"
    - "asyncio.wait_for(coro, timeout=5.0) + opt-in isnet fallback on TimeoutError (D-04/D-05)"
    - "monkeypatch both source module + importing module (mock fixture covers post-`from X import Y`)"
key-files:
  created:
    - embedder/core/bgremove_state.py
    - embedder/core/bgremove_models.py
    - embedder/core/log_json.py
    - embedder/scripts/download_models.py
    - embedder/routers/img_remove_background.py
  modified:
    - embedder/app.py
    - embedder/tests/test_remove_background.py
    - embedder/tests/conftest.py
decisions:
  - "Mask resize on output uses Lanczos for upscale to original dims (D-08); compose via img.convert('RGB').putalpha(mask) to handle paletted/LA modes (Pitfall 4)"
  - "InferenceSession created lazily via singleton (not eagerly at startup) — keeps Plan 04-02 testable without ONNX weights present; eager warmup will move into Plan 04-03 startup hook"
  - "mock_birefnet_session patches BOTH core.bgremove_models AND routers.img_remove_background (the router does `from … import run_birefnet`, so name binding is taken at import time)"
metrics:
  duration: ~25 min
  completed_date: 2026-05-27
  tasks_completed: 2
  files_created: 5
  files_modified: 3
---

# Phase 04 Plan 02: BiRefNet Endpoint Implementation Summary

Wave 1 lights up the actual BiRefNet endpoint — `POST /img/remove-background` now runs end-to-end against mocked ORT sessions, with all 12 Wave 0 stubs flipped from xfail to passing, no Phase 2 regression, and the four core modules (state, models, log helper, downloader script) checked in and importable.

## What Was Built

**`embedder/core/bgremove_state.py`** — three module-level singletons that together implement the D-14 inflight model: a single `asyncio.Lock()` shared by BiRefNet and isnet inference paths (one inference per process at a time), a `threading.Lock`-guarded inflight counter readable from any thread (so /health and the threadpool callbacks stay consistent), and a `last_inference_ms` cell updated in the request `finally` block.

**`embedder/core/bgremove_models.py`** — ORT `InferenceSession` singletons with FP16-aware preprocessing. BiRefNet path: ImageNet mean/std normalisation, 1024×1024 BILINEAR resize, sigmoid post-processing. isnet path: mean=0.5/std=1.0 normalisation, linear min/max post-processing (NOT sigmoid — Research Assumption A1 codified). Both `run_*` functions take a `PIL.Image.Image` and return an `L` mask resized back to the input size via Lanczos. `inter_op_num_threads=1` reinforces D-14 single-inference at the ORT level; CPU EP only (D-01).

**`embedder/core/log_json.py`** — six-line helper emitting `json.dumps()` lines to stdout with `event` + `ts` + arbitrary kwargs. Drop-in Datadog ingest.

**`embedder/scripts/download_models.py`** — Plan 04-03 Dockerfile entrypoint. `huggingface_hub.snapshot_download('onnx-community/BiRefNet-ONNX', allow_patterns=['onnx/model_fp16.onnx','config.json'])` for BiRefNet, `urllib.request.urlretrieve` for the isnet GitHub release. Idempotent (skips isnet if already > 100 MB).

**`embedder/routers/img_remove_background.py`** — FastAPI router applying Research Pattern 1 verbatim. Pydantic `RemoveBgParams(model: Literal["birefnet","isnet-general-use"]="birefnet", fallbackOnTimeout: bool=False)`; ValidationError → 422. `decode_image` handles EXIF/50 MPx/SVG. Hard cap `max(img.size) > 4096` → 413 before any inference. Auto-downscale to 2048 long-edge on a `img.copy()` (Pitfall 3: avoid in-place mutation). Inference inside `async with lock:` + `set_inflight(±1)`, wrapped in `loop.run_in_executor(None, run_birefnet, inference_img)`. `asyncio.wait_for(..., 5.0)` per D-05; TimeoutError + `fallbackOnTimeout=True` → rerun isnet in the same lock window with `X-Model-Used: isnet-general-use`; TimeoutError + `fallbackOnTimeout=False` → 504. Mask is Lanczos-upscaled to the original dims, then `img.convert("RGB").putalpha(mask_full)` composes the final RGBA (D-08/D-09 + Pitfall 4: convert before putalpha to support P/1/LA modes). Output is PNG with `X-Render-Duration-Ms` and `X-Model-Used` headers and a `{"event":"remove_background", model, latency_ms, image_dims, fallback_used}` JSON log line.

**`embedder/app.py`** — single-line wiring: `img_remove_background` added to the grouped `from routers import …` and `app.include_router(img_remove_background.router)`. /health stays unchanged in this plan (Plan 04-03 enriches it).

**Tests** — `tests/test_remove_background.py` lost its module-level `pytestmark = pytest.mark.xfail` and now exercises 12 behaviors: PNG RGBA round-trip, default-is-birefnet, explicit isnet, 422 on unknown enum, fallback-to-isnet on `wait_for` TimeoutError (monkeypatched), 504 without fallback, 413 above 4K, 3000×2400 stays at 3000×2400 in output (downscale→inference→upscale), RGBA mode output, alpha-replaced on RGBA input, inflight returns to 0 after request, structured JSON log emitted to stdout. `tests/conftest.py`'s `mock_birefnet_session` was extended to patch the names already imported into `routers.img_remove_background` — without that the patch was a no-op (rebinding `core.bgremove_models.run_birefnet` doesn't reach the local-scope reference the router obtained via `from … import run_birefnet`).

## Verification

```
$ docker compose exec -T embedder pytest -m "not integration_ml" tests/test_remove_background.py -v
12 passed in 12.29s

$ docker compose exec -T embedder pytest -m "not integration_ml" \
    tests/test_decode_image.py tests/test_resize.py tests/test_crop.py \
    tests/test_rotate.py tests/test_format_convert.py tests/test_add_background.py \
    tests/test_health.py
55 passed, 2 xfailed in 1.24s   # 2 xfailed = Plan 04-03 enriched /health stubs

$ docker compose exec -T embedder python -c "from core.bgremove_state import lock, get_inflight, get_last_ms; \
    assert get_inflight()==0 and get_last_ms() is None; print('OK')"
OK

$ docker compose exec -T embedder python -c "from core.log_json import log_event; \
    log_event('test_event', foo='bar')"
{"event": "test_event", "ts": 1779892052.97, "foo": "bar"}
```

All Task 1 + Task 2 acceptance grep criteria verified passing (lock declaration, threading.Lock, CPUExecutionProvider, inter_op_num_threads=1, BIREFNET_PATH/ISNET_PATH, Lanczos, snapshot_download, HF repo, isnet URL, include_router, async with lock, run_in_executor, wait_for, MAX_DIM, DOWNSCALE_LONG_EDGE, Literal enum, set_inflight(+1), img.convert("RGB"), inference_img = img.copy()).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug fix] mock_birefnet_session was a no-op against router-imported names**
- **Found during:** Task 2 first test run
- **Issue:** The Plan 04-01 fixture `monkeypatch.setattr(core.bgremove_models, "run_birefnet", _fake)` patches the source module attribute, but `routers.img_remove_background` does `from core.bgremove_models import run_birefnet`. Once the router module is loaded, `run_birefnet` inside the router is a local binding; rebinding the source attribute has no effect. All 9 tests using `mock_birefnet_session` failed with `onnxruntime…NO_SUCHFILE`.
- **Fix:** Extended `mock_birefnet_session` to additionally `monkeypatch.setattr(routers.img_remove_background, "run_birefnet", _fake)` (and isnet). Wrapped in `try/except ImportError` so collection stays clean if 04-02 hasn't landed yet.
- **Files modified:** `embedder/tests/conftest.py`
- **Commit:** a91a6a1

**2. [Rule 2 — Critical correctness] onnxruntime not installed in running container**
- **Found during:** Task 1 import smoke test
- **Issue:** Plan 04-01 declared the `onnxruntime==1.22.0` pin in `requirements.txt` but deferred the actual install to a future image rebuild. `core.bgremove_models` cannot import without ORT. Plan 04-02 needs the import to succeed (even though every test uses a mock, the import path is exercised at module load).
- **Fix:** Ran `docker compose exec -T embedder pip install "onnxruntime==1.22.0"` to install ORT in the running container. The pin in `requirements.txt` is already in place from Plan 04-01, so a future rebuild (Plan 04-03 Dockerfile rewrite) will pick it up declaratively.
- **Files modified:** none on disk (runtime container only); `requirements.txt` already correct from Plan 04-01.
- **Commit:** — (no source change; install is operational only and will be made permanent by the Plan 04-03 rebuild)

**3. [Rule 1 — Plan-vs-reality reconciliation] Plan expected 11 passed + 1 xfailed, achieved 12 passed**
- **Found during:** Task 2 verification
- **Issue:** Plan 04-02 prescribed keeping `test_birefnet_session_loads` as an xfail (to be fixed by Plan 04-03 enriching /health). But Plan 04-01 SUMMARY documents that test was removed pre-merge as a deduplicate of `test_health_includes_birefnet_status` (which already lives in `test_health.py`). So the test no longer exists in `test_remove_background.py`.
- **Fix:** Removed the module-level `pytestmark = pytest.mark.xfail` entirely (no per-test xfail needed). All 12 remaining tests pass. The Plan 04-03 enriched-/health behavior is still covered by the xfail stubs in `test_health.py` (`test_health_includes_birefnet_status` + `test_health_status_degraded_*`).
- **Files modified:** `embedder/tests/test_remove_background.py`
- **Commit:** a91a6a1

## Authentication Gates

None.

## Known Stubs

None for Plan 04-02 itself. The Plan 04-03 enriched-/health stubs remain in `tests/test_health.py` as designed; they'll go green when Plan 04-03 extends the `/health` payload.

## Commits

| Hash    | Type | Message                                                       |
|---------|------|---------------------------------------------------------------|
| 9d7b894 | feat | add bgremove core modules + download_models script             |
| a91a6a1 | feat | wire POST /img/remove-background endpoint                      |

## Self-Check: PASSED

- FOUND: embedder/core/log_json.py
- FOUND: embedder/core/bgremove_state.py
- FOUND: embedder/core/bgremove_models.py
- FOUND: embedder/scripts/download_models.py
- FOUND: embedder/routers/img_remove_background.py
- FOUND: embedder/app.py (include_router img_remove_background present)
- FOUND: embedder/tests/test_remove_background.py (module xfail removed, 12 passing)
- FOUND: embedder/tests/conftest.py (router-module patch added)
- FOUND: commit 9d7b894
- FOUND: commit a91a6a1
