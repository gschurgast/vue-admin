---
phase: 04-birefnet-endpoint-remove-background-deploy-gate
reviewed: 2026-05-27T00:00:00Z
depth: standard
files_reviewed: 28
files_reviewed_list:
  - api/config/services.yaml
  - api/src/EventListener/TransformationHashListener.php
  - api/src/Service/AssetTransformation/StepHandler/RemoveBackgroundHandler.php
  - api/src/Service/AssetTransformation/StepParams/RemoveBackgroundStepParams.php
  - api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php
  - api/src/Service/AssetTransformation/TransformationLookup.php
  - api/tests/Integration/AssetTransformation/WarningsDerivationTest.php
  - api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php
  - api/tests/Unit/Service/AssetTransformation/StepHandler/RemoveBackgroundHandlerTest.php
  - api/tests/Unit/Service/AssetTransformation/StepParams/RemoveBackgroundStepParamsTest.php
  - api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php
  - api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php
  - embedder/Dockerfile
  - embedder/app.py
  - embedder/bin/bench_bgremove.sh
  - embedder/core/bgremove_models.py
  - embedder/core/bgremove_state.py
  - embedder/core/log_json.py
  - embedder/pytest.ini
  - embedder/requirements.txt
  - embedder/routers/img_remove_background.py
  - embedder/scripts/download_models.py
  - embedder/tests/conftest.py
  - embedder/tests/test_health.py
  - embedder/tests/test_remove_background.py
  - embedder/tests/test_remove_background_e2e.py
findings:
  critical: 0
  warning: 3
  info: 6
  total: 9
status: issues_found
---

# Phase 04: Code Review Report

**Reviewed:** 2026-05-27
**Depth:** standard
**Files Reviewed:** 28 (incl. supporting context files; 26 in explicit scope)
**Status:** issues_found

## Summary

Phase 04 adds the synchronous BiRefNet `remove_background` endpoint to the
embedder, wires the corresponding `RemoveBackgroundStepHandler` and DTO into
the Symfony orchestrator, surfaces a new `remove-background-requires-png`
warning, and removes the async-only gating from `TransformationLookup`.

Quality is solid overall: the contracts are tight (strict-fields DTO, async
serialisation lock, EXIF + 4K + 50 MPx defences, idempotent POST justifying
retries, all-404 leak guards). Tests are thorough at unit, integration and
opt-in `integration_ml` tiers, and the structured-log assertion guards
observability.

Findings concentrate on:

- **Supply-chain hardening** of the model download step (no checksum).
- **Production image bloat** (dev requirements installed in prod stage).
- **Minor concurrency / accessor hygiene** in `bgremove_state` and `app.py`.
- **Bench script percentile arithmetic** that over-reports p95.

No critical security or correctness issues were found.

## Warnings

### WR-01: Model artefact downloaded without checksum verification

**File:** `embedder/scripts/download_models.py:20-43`
**Issue:** `isnet-general-use.onnx` is fetched from a GitHub release tarball
over HTTPS, then the only integrity check is a `> 100_000_000` byte size
guard (line 38). A compromise of the upstream `danielgatis/rembg` release
asset (or any TLS-terminating intermediary that re-signs with a trusted CA in
a build environment) would silently inject a tampered ONNX model into the
production image. BiRefNet via `huggingface_hub` is in the same situation
(no `--revision`/commit pin, no `--check-files`).
**Fix:** Pin a known-good commit SHA + verify file hashes:

```python
# isnet
EXPECTED_SHA256 = "<sha256 from a trusted build>"
urllib.request.urlretrieve(ISNET_URL, out)
import hashlib
h = hashlib.sha256(out.read_bytes()).hexdigest()
if h != EXPECTED_SHA256:
    raise SystemExit(f"isnet checksum mismatch: {h}")

# birefnet — pin a revision
snapshot_download(
    repo_id=BIREFNET_REPO,
    revision="<commit-sha-from-HF>",
    local_dir=str(target),
    allow_patterns=BIREFNET_PATTERNS,
)
```

### WR-02: Dev dependencies installed into the production embedder image

**File:** `embedder/Dockerfile:37-38`
**Issue:** `COPY requirements-dev.txt . && pip install -r requirements-dev.txt`
runs in the **final** stage (not in a separate test stage). The shipped
production container therefore contains pytest, mocks, fixtures, etc.,
bloating the image, increasing attack surface, and shipping `tests/` (copied
at line 47) to prod. The Dockerfile also has no multi-target separation
between `prod` and `test`.
**Fix:** Move dev installs and `tests/` copy into a dedicated test stage:

```dockerfile
FROM base AS test
COPY requirements-dev.txt .
RUN pip install --no-cache-dir -r requirements-dev.txt
COPY tests ./tests
COPY pytest.ini .
# ... (rest)

FROM base AS prod
# no requirements-dev, no tests/
COPY app.py core routers scripts ./
```

Build CI with `--target test`, ship with `--target prod`.

### WR-03: `set_last_ms` / `get_last_ms` mutate a module global without locking

**File:** `embedder/core/bgremove_state.py:31-37`
**Issue:** Unlike `_inflight` (guarded by `_inflight_lock`), the rebinding of
`_last_inference_ms` happens with no lock at all. While CPython's GIL makes
single-name rebinding atomic in practice (so this won't tear a value), the
asymmetry is confusing and silently breaks the moment someone switches to a
free-threaded interpreter (PEP 703) or runs the same module under a non-GIL
runtime. Concretely the reader at `app.py:98` could also observe a slightly
stale value relative to `inflight`.
**Fix:** Use the same lock for symmetry:

```python
def set_last_ms(ms: int) -> None:
    global _last_inference_ms
    with _inflight_lock:
        _last_inference_ms = ms

def get_last_ms() -> int | None:
    with _inflight_lock:
        return _last_inference_ms
```

## Info

### IN-01: `@app.on_event("startup")` is deprecated in modern FastAPI

**File:** `embedder/app.py:73`
**Issue:** Since FastAPI 0.93 the documented API is the `lifespan` context
manager; `on_event` still works but is marked deprecated and emits a warning
under recent uvicorn. Worth migrating before the next FastAPI bump.
**Fix:** Use `@asynccontextmanager` `lifespan = ...` then `FastAPI(lifespan=lifespan)`.

### IN-02: `/health` reaches into private module globals

**File:** `embedder/app.py:93`
**Issue:** `from core.bgremove_models import _birefnet_session, _isnet_session`
imports names prefixed with `_` (Python convention: private). The module
already exposes proper getters (`get_birefnet`, `get_isnet`) and could add a
non-loading `is_loaded()` helper to avoid leaking the implementation detail.
**Fix:** Add `birefnet_loaded()` / `isnet_loaded()` to `bgremove_models.py`:

```python
def birefnet_loaded() -> bool: return _birefnet_session is not None
def isnet_loaded() -> bool: return _isnet_session is not None
```

Then `app.py` calls those.

### IN-03: Variable named `rgba` actually holds an RGB image at assignment time

**File:** `embedder/routers/img_remove_background.py:81-82`
**Issue:** `rgba = img.convert("RGB")` then `rgba.putalpha(mask_full)`. The
name is correct only after `putalpha`. Reads as a small mental hiccup during
review.
**Fix:** Rename or do it in one line:

```python
out = img.convert("RGB")
out.putalpha(mask_full)
```

### IN-04: Bench script p95 index over-reports on tiny N

**File:** `embedder/bin/bench_bgremove.sh:35`
**Issue:** `P95_IDX=$(( (N * 95 + 99) / 100 - 1 ))`. For N=10 this evaluates
to 9 → the 10th (max) sample is reported as p95. Conventional p95 of 10
samples interpolates between samples 9 and 10 (or simply returns sample 9 →
index 8). The current formula makes the gate at line 54 strictly pessimistic
— possibly OK for a guardrail, but it should be documented or fixed.
**Fix:** Use the conventional nearest-rank: `P95_IDX=$(( (N * 95 - 1) / 100 ))`
(for N=10 → 8 → 9th sample), and add a comment that small N is
statistically meaningless (run `N=100` for a real measurement).

### IN-05: `usort()` over the steps collection is a defensive duplicate

**File:** `api/src/EventListener/TransformationHashListener.php:149-150`
**Issue:** Comment acknowledges that the OrderBy on the collection mapping
*should* be enough but sorts again "defensively". This duplicates ordering
logic and hides bugs in the OrderBy mapping. Consider an assertion in
non-prod (`assert($positions === $sortedPositions)`) so any deviation gets
noticed instead of silently masked.
**Fix:** Either drop the `usort` (trust the mapping + a unit test) or wrap
it in `if ($_ENV['APP_ENV'] !== 'prod') { assert(...); }`.

### IN-06: Retry strategy retries POST — relies on endpoint idempotency

**File:** `api/config/services.yaml:99-109`
**Issue:** `GenericRetryStrategy` retries `POST` on 5xx + transport (status
code 0). This is *intentional* because the embedder endpoints are
side-effect-free, but it is worth a code comment naming the invariant.
A future contributor adding a stateful POST to the embedder would silently
inherit at-least-once semantics.
**Fix:** Add an inline comment to `services.yaml` near the strategy:

```yaml
# POST is retried because every embedder endpoint is pure-function (no
# side effects). DO NOT introduce a stateful POST without first removing
# 'POST' from the retried verbs below.
```

---

_Reviewed: 2026-05-27_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
