---
phase: 04
plan: 03
subsystem: embedder-docker-health
tags: [phase-04, wave-2, embedder, docker, dockerfile, health, multi-stage, models, birefnet, isnet, onnx]
requires:
  - Phase 04 Plan 02 (scripts/download_models.py, core.bgremove_models, core.bgremove_state)
  - BuildKit (docker compose with DOCKER_BUILDKIT=1)
  - Network access to HuggingFace Hub + GitHub releases at build time
provides:
  - Self-contained embedder image with BiRefNet FP16 + isnet ONNX baked in (~638 MB total models)
  - Enriched /health exposing models.birefnet.{status,model,inflight,last_inference_ms} + models.isnet.{status}
  - status: degraded transition when birefnet not loaded OR inflight > 4 (D-11)
  - Startup hook pre-loading BiRefNet + isnet ORT sessions (warmup)
  - HEALTHCHECK start-period bumped 15s -> 30s for two extra ORT sessions
  - --workers 1 documented in CMD (Pitfall 7: asyncio.Lock is per-process)
affects:
  - embedder/Dockerfile (multi-stage rewrite)
  - embedder/app.py (enriched /health + warmup pre-loads BiRefNet/isnet)
  - embedder/tests/test_health.py (5 tests passing + skipif on missing models)
  - embedder/tests/conftest.py (TestClient as context manager so startup hooks fire)
tech-stack:
  added: []
  patterns:
    - "Multi-stage Dockerfile with dedicated model-downloader stage + BuildKit cache mount on /root/.cache/huggingface"
    - "Lazy-import in /health handler keeps endpoint resilient if core.bgremove_models fails to import"
    - "TestClient context manager (with TestClient(app) as c) triggers FastAPI startup hooks in tests"
key-files:
  created:
    - .planning/phases/04-birefnet-endpoint-remove-background-deploy-gate/04-03-SUMMARY.md
  modified:
    - embedder/Dockerfile
    - embedder/app.py
    - embedder/tests/test_health.py
    - embedder/tests/conftest.py
decisions:
  - "model-downloader stage invokes scripts/download_models.py (single source of truth for repo IDs and URLs, reused both at build and for dev re-downloads)"
  - "Models copied to /app/models in final stage (matches MODELS_DIR constant in core.bgremove_models)"
  - "TestClient fixture switched to context manager — FastAPI startup hooks (warmup ORT) only run with explicit context manager use"
  - "test_health.py uses skipif(MODELS_PRESENT) rather than xfail because the Dockerfile build is the gate; in containers without /app/models the test gracefully skips"
metrics:
  duration: ~15 min
  completed_date: 2026-05-27
  tasks_completed: 2
  files_created: 1
  files_modified: 4
---

# Phase 04 Plan 03: Dockerfile multi-stage + /health enrichi Summary

Wave 2 finalise l'image Docker embedder pour Phase 4 : modèles BiRefNet FP16 + isnet ONNX bakés dans l'image (~638 MB total), `/health` enrichi avec status par modèle + inflight + degraded transition, startup hook qui précharge les deux sessions ORT, et `--workers 1` documenté.

## What Was Built

**Dockerfile multi-stage (embedder/Dockerfile).** Réécrit avec deux stages :
- Stage `model-downloader` : installe `huggingface_hub`, copie `scripts/download_models.py` puis l'exécute. BuildKit cache mount `--mount=type=cache,target=/root/.cache/huggingface` évite de re-télécharger entre rebuilds (Pitfall 6).
- Stage final : `COPY --from=model-downloader /models /app/models` (BGREMOVE-03 / D-15). Reprend les couches Phase 2 (libgomp1, requirements, CLIP warmup, requirements-dev, COPY app/core/routers/tests, scripts ajouté). `HEALTHCHECK --start-period=30s` (bumpé depuis 15s pour laisser le temps au chargement des deux sessions ORT). `CMD` documente `--workers 1` (Pitfall 7 : asyncio.Lock per-process).

Image build OK : `model_fp16.onnx` + `isnet-general-use.onnx` présents dans `/app/models`, 638 MB total. Build idempotent (cache mount accelère les rebuilds).

**/health enrichi (embedder/app.py).** Handler `health()` étendu :
- Lazy-import de `core.bgremove_models._birefnet_session`, `_isnet_session` et `core.bgremove_state.{get_inflight, get_last_ms}` — résilient si bgremove_models échoue à l'import (la route reste up).
- Calcule `status = "ok" if (birefnet_loaded and inflight <= 4) else "degraded"` (D-11).
- Retourne `models.birefnet.{status, model: "birefnet-general-fp16", inflight, last_inference_ms}` + `models.isnet.{status}` + conserve `models.clip` et `models.stable_diffusion` (compat A6).

**Startup warmup (embedder/app.py).** `_warmup()` appelle maintenant `get_birefnet()` ET `get_isnet()` après `get_model()` (CLIP). Encadré par `try/except Exception` : un défaut de fichier en dev ne kill pas le conteneur, /health retournera `degraded` à la place.

**Tests test_health.py.** Passe de 3 + 2 xfail à 6 tests verts :
- 3 tests Phase 2 conservés (`test_health_schema`, `test_embed_endpoint_still_works`, `test_avif_codec_registered`)
- `test_health_includes_birefnet_status` — passe maintenant que les sessions sont chargées (skipif si modèles absents)
- `test_health_degraded_when_birefnet_not_loaded` — patch `_birefnet_session=None` puis assert `status=="degraded"`
- `test_health_inflight_zero_at_idle` (nouveau) — assertion idle counter
- `test_health_schema` mis à jour pour accepter le superset (clé `isnet` présente, status `loaded`|`not_loaded`, `ok`|`degraded`)

**conftest.py fix.** Fixture `client` switchée vers context manager (`with TestClient(app) as c: yield c`). Le TestClient FastAPI ne déclenche les `on_event("startup")` que via context manager — sans cela, `_warmup()` n'était jamais appelé en tests et `birefnet.status` restait `not_loaded`.

## Verification

```
$ docker compose exec -T embedder python -c "import urllib.request,json; print(json.dumps(json.loads(urllib.request.urlopen('http://localhost:8000/health').read()), indent=2))"
{
  "status": "ok",
  "models": {
    "clip": {"status": "loaded", "name": "clip-ViT-B-32", "dim": 512},
    "birefnet": {"status": "loaded", "model": "birefnet-general-fp16", "inflight": 0, "last_inference_ms": null},
    "isnet": {"status": "loaded"},
    "stable_diffusion": {"status": "not_loaded"}
  }
}

$ docker compose exec -T embedder pytest -m "not integration_ml" tests/test_health.py -v
6 passed, 2 warnings in 2.61s

$ docker compose exec -T embedder pytest -m "not integration_ml"
70 passed, 2 deselected, 2 warnings in 15.55s

$ docker compose run --rm embedder ls /app/models/birefnet/onnx/
model_fp16.onnx

$ docker compose run --rm embedder ls /app/models/isnet/
isnet-general-use.onnx

$ docker compose run --rm embedder du -sh /app/models
638M /app/models

$ docker compose ps embedder
antigravity-embedder-1   antigravity-embedder   Up 20 seconds (healthy)
```

All Task 1 + Task 2 acceptance grep criteria pass: `syntax=docker/dockerfile:1.6`, `FROM base AS model-downloader`, `COPY --from=model-downloader /models /app/models`, `python /tmp/dl/download_models.py`, `mount=type=cache,target=/root/.cache/huggingface`, `CMD [..."--workers", "1"...]`, `start-period=30s`, `birefnet-general-fp16`, `status...degraded`, `from core.bgremove_state import get_inflight, get_last_ms`, `from core.bgremove_models import get_birefnet, get_isnet`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug fix] FastAPI startup hooks did not fire in TestClient by default**
- **Found during:** Task 2 test run after rebuild
- **Issue:** Le test `test_health_includes_birefnet_status` (skipif disabled because models present) échouait sur `assert m["birefnet"]["status"] == "loaded"` car le startup hook `_warmup()` (qui appelle `get_birefnet()`/`get_isnet()`) ne se déclenche dans `TestClient(app)` qu'à condition d'utiliser le context manager. Sans `with`, les sessions ORT restent `None` côté test, /health renvoie `not_loaded` malgré les modèles présents dans l'image.
- **Fix:** Modifié `conftest.py::client` pour utiliser `with TestClient(app) as c: yield c`. Les startup events se déclenchent normalement en début de fixture, shutdown en fin.
- **Files modified:** `embedder/tests/conftest.py`
- **Commit:** 801f2cd

**2. [Rule 2 - Critical correctness] test_health_schema asserted strict birefnet status: not_loaded**
- **Found during:** Task 2 first pass
- **Issue:** Le test existant Phase 2 affirmait `m["birefnet"]["status"] == "not_loaded"` — incompatible avec la nouvelle réalité (sessions préchargées au startup). Sans correction, ce test casserait toute exécution post-Plan 04-03.
- **Fix:** Élargi l'assertion à `m["birefnet"]["status"] in {"loaded", "not_loaded"}` et ajouté `isnet` à la liste des clés requises (superset compatible avec A6 — le contrat existant reste vrai, on accepte juste les deux états).
- **Files modified:** `embedder/tests/test_health.py`
- **Commit:** 801f2cd

## Authentication Gates

None.

## Known Stubs

None. Tous les stubs Plan 04-01 sont maintenant verts (12 dans test_remove_background.py via Plan 04-02 + 2 dans test_health.py via Plan 04-03 + le test inflight idle). Le smoke E2E `test_remove_background_e2e.py` reste sous le marker `integration_ml` (gated par `pytest -m integration_ml`) pour le D-13 item 4 — c'est by-design (smoke ops, pas Wave 2).

## Commits

| Hash    | Type | Message                                                                       |
|---------|------|-------------------------------------------------------------------------------|
| 063c5e8 | feat | Dockerfile multi-stage with BiRefNet+isnet baked in                           |
| 801f2cd | feat | enrich /health with birefnet + isnet + inflight + degraded                    |

## Self-Check: PASSED

- FOUND: embedder/Dockerfile (multi-stage with model-downloader + COPY --from + start-period=30s + --workers 1)
- FOUND: embedder/app.py (lazy-import in /health, warmup pre-loads BiRefNet+isnet)
- FOUND: embedder/tests/test_health.py (6 tests, superset schema, inflight idle test)
- FOUND: embedder/tests/conftest.py (TestClient as context manager)
- FOUND: /app/models/birefnet/onnx/model_fp16.onnx in image
- FOUND: /app/models/isnet/isnet-general-use.onnx in image
- FOUND: commit 063c5e8
- FOUND: commit 801f2cd
- VERIFIED: docker compose ps embedder -> Up (healthy)
- VERIFIED: GET /health returns birefnet.loaded + isnet.loaded + inflight=0
- VERIFIED: POST /embed still works (CLIP regression check, dim=512)
- VERIFIED: pytest -m "not integration_ml" -> 70 passed 0 failed
