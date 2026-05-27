# Phase 04: BiRefNet Endpoint + remove_background — DEPLOY GATE — Research

**Researched:** 2026-05-27
**Domain:** ONNX Runtime inference (BiRefNet + isnet) intégré dans FastAPI ; orchestration sync côté Symfony
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (verbatim)

**Runtime & Modèles**
- **D-01:** Runtime ML = **ONNX Runtime** (`onnxruntime` CPU ~50 MB) — pas de PyTorch dans l'image embedder.
- **D-02:** Checkpoint BiRefNet shipper = **BiRefNet base general (DIS5K)** FP32/FP16. Pas la variante `massive`.
- **D-03:** Fallback léger = **isnet-general-use** (rembg, MIT), ONNX ~170 MB, pré-téléchargé au build.
- **D-14:** **asyncio.Lock global mono-process** autour de chaque inférence (BiRefNet ET isnet) — modèle non thread-safe au sens applicatif (sérialisation choisie pour stabilité mémoire/latence prévisible). `birefnet_inflight` reflète l'occupation.
- **D-15:** Modèles **pré-téléchargés au build Docker** (multi-stage). Aucun download au runtime. Image finale ~1.5 GB.

**Timeout & Fallback**
- **D-04:** Fallback `isnet-general-use` **uniquement** sur **timeout per-request BiRefNet**, et seulement si `fallbackOnTimeout: true`. Pas de fallback sur autre erreur.
- **D-05:** **Timeout per-step BiRefNet côté Python = 5 s hard** via `asyncio.wait_for`. Dépassement + `fallbackOnTimeout=true` → rerun isnet. Sinon → 504.
- **D-06:** Worst-case > 8 s = problème PHP (PipelineRunner cap 8 s). Python ne gère pas — son timeout interne 5 s < cap PHP 8 s.

**Préprocessing**
- **D-07:** Dimensions max = **4096×4096**. > 4 K → 413. Entre 2048² et 4096² → downscale auto à 2048 long-edge (Lanczos) AVANT inférence.
- **D-08:** Output upscalé à la dimension originale (masque alpha upscalé Lanczos, composé avec RGB original).
- **D-09:** Alpha pré-existant ignoré : BiRefNet recalcule depuis RGB.
- **D-10:** Sortie endpoint = **PNG RGBA strict**. Conversion vers WebP/AVIF/JPG via `format_convert` aval.

**Observabilité**
- **D-11:** `/health` enrichi (clip / birefnet / isnet ; status ok|degraded ; inflight ; last_inference_ms).
- **D-12:** Logs JSON structurés (Datadog), pas de Prometheus.
- **D-13:** Checklist Webfacto signoff DEPLOY GATE (6 items) — hard gate Phase 4 → Phase 5.

**Endpoint contract**
- **D-17:** `POST /img/remove-background` multipart `file`, params `model` + `fallbackOnTimeout`. Réponses 200/413/415/504/500.

**Côté PHP**
- **D-18:** `RemoveBackgroundHandler` étend `AbstractEmbedderStepHandler`. Endpoint `/img/remove-background`. Timeout HTTP PHP = 6 s. Pas de retry sur 504 ; retries 5xx + transport via `RetryableHttpClient`.

**Hard gate**
- **D-16:** Phase 4 → Phase 5 = hard gate, gated par D-13 signé.

### Claude's Discretion
- Choix exact de la version BiRefNet (commit hash / release tag) — checkpoints ONNX officiels.
- Stratégie multi-stage Dockerfile pour optimiser le cache.
- Format exact des logs structurés (clés JSON, niveau, stderr/stdout).
- Lib de téléchargement des modèles au build (`huggingface_hub` vs `wget`).

### Deferred Ideas (OUT OF SCOPE)
- `add_background type:ai_prompt` → Phase 6
- Path async 202 + Location → Phase 5
- Endpoint `/metrics` Prometheus → considéré Phase 7
- Quantization ONNX INT8 → optim future
- Warm-up Messenger → Phase 7
- Multi-process embedder (gunicorn workers) → non bloquant tant que < 3 s
- Cache masques BiRefNet par checksum → micro-optim
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| IMGSVC-06 | `POST /img/remove-background` — JSON `{ model?, fallbackOnTimeout? }` (défaut birefnet) | Endpoint Python multipart calqué sur `img_resize.py` / `img_add_background.py` ; modèle sélectionné via Pydantic `Literal["birefnet","isnet-general-use"]`. |
| BGREMOVE-01 | BiRefNet (MIT) comme modèle par défaut | `onnx-community/BiRefNet-ONNX` (HuggingFace, MIT) — confirmé licence MIT pour usage commercial. |
| BGREMOVE-02 | Enum supporté = `birefnet` (défaut) + `isnet-general-use` (fallback) | Pydantic `Literal` ; deux ORT `InferenceSession` chargés au démarrage. |
| BGREMOVE-03 | Modèles pré-téléchargés au build Docker (~1 GB ; pas de download au runtime) | Multi-stage Dockerfile : stage `model-downloader` télécharge via `huggingface_hub`, stage final `COPY --from=`. BuildKit cache mount pour ne pas re-télécharger à chaque rebuild de l'app layer. |
| BGREMOVE-04 | `asyncio.Lock` mono-process pour sérialiser les inférences | Module-level `asyncio.Lock()` partagé par BiRefNet+isnet ; le compteur `birefnet_inflight` est incrémenté hors-lock à l'entrée du handler et lu par `/health`. ORT inférence wrappée dans `run_in_executor` pour ne pas bloquer le loop. |
| BGREMOVE-05 | Latence cible < 3 s sur 2048² CPU ; fallback isnet si dépassement | Downscale auto à 2048 long-edge (D-07) + `asyncio.wait_for(5.0)` + fallback opt-in. |
| BGREMOVE-06 | `RemoveBackgroundHandler` PHP via `RetryableHttpClient` | Hérite `AbstractEmbedderStepHandler` (Plan 03-02). Tag automatique `app.step_handler`. Inverser `TransformationLookup::isAsyncStep()` pour `REMOVE_BACKGROUND`. |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- Toute la manipulation d'image **passe par Python** (perf + accès ML). Pas d'Imagine PHP. (Hors-scope rappel.)
- Convention Symfony entités : privées + getters/setters. Non pertinent ici (pas d'entité nouvelle).
- Convention DI Symfony : autoconfig via attributs. Le `RemoveBackgroundHandler` reprend le pattern Phase 3.
- Generated TS types : régénérer via `make generate-types` après tout changement de groupes de sérialisation. Phase 4 ne touche pas à l'API Platform (handler interne) → **probablement pas requis**, mais ajouter une vérification dans le plan.
- Tests : PHPUnit via `docker compose exec api ./vendor/bin/phpunit --testsuite=unit|integration`. Pytest via `docker compose exec embedder pytest`. (À confirmer dans `docker-compose.yml`.)
- Secrets : externaliser via env. Pour les modèles HF, **pas besoin de token** (`onnx-community/*` est public) — aucune variable secrète requise par cette phase.

## Summary

Phase 4 introduit l'unique step IA "lourd" du milestone v1 servi en sync : `remove_background`. Deux pièces logicielles à livrer + une checklist ops.

**Côté Python (embedder)** : un router FastAPI `img_remove_background.py` qui charge deux `onnxruntime.InferenceSession` au démarrage (BiRefNet base general + isnet-general-use), expose `POST /img/remove-background` avec sélection de modèle, sérialise les inférences via un `asyncio.Lock` module-level et envoie l'exécution CPU-bound dans `run_in_executor` pour ne pas geler le loop. Le timeout 5 s utilise `asyncio.wait_for`. Le préprocessing (downscale 2048 long-edge si > 2048², normalisation ImageNet pour BiRefNet et `mean=0.5/std=1.0` pour isnet, output mask sigmoid + resize Lanczos à l'original) reprend les conventions documentées par le repo upstream et le modèle ONNX HuggingFace.

**Côté PHP (api)** : un `RemoveBackgroundStepParams` (DTO readonly), un mapping dans `StepParamsFactory`, un `RemoveBackgroundHandler` qui hérite `AbstractEmbedderStepHandler` (4 lignes utiles), un paramètre de timeout dans `services.yaml`, et **l'inversion** de `TransformationLookup::isAsyncStep()` pour `REMOVE_BACKGROUND` — qui devient sync donc traversable par la route publique `/t/*`.

**Côté ops** : Dockerfile multi-stage (stage `model-downloader` qui `pip install huggingface_hub` + `snapshot_download` les deux modèles, stage final qui `COPY --from=model-downloader /models /app/models`), bump RAM container ≥ 3 GB, checklist signoff.

**Primary recommendation:** Utiliser `onnx-community/BiRefNet-ONNX` (model.onnx FP32 973 MB OU model_fp16.onnx 490 MB — recommander **FP16** pour rester sous l'enveloppe RAM, perte précision négligeable pour produit e-commerce) + `rembg` release `isnet-general-use.onnx` (~170 MB). Lock module-level + `run_in_executor` + `asyncio.wait_for(5.0)`. Aucun token HF requis.

## Standard Stack

### Core (à ajouter à `embedder/requirements.txt`)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `onnxruntime` | `1.22.x` (CPU) | Inférence ONNX | [VERIFIED: pypi.org/project/onnxruntime] CPU-only pip package, pas besoin de CUDA. Wheel `manylinux` ~50 MB. |
| `numpy` | `>=1.26,<3` | Tensors I/O | Dépendance transitive d'ORT et Pillow ; pinning explicite. |
| `huggingface_hub` | `>=0.27,<1` | `snapshot_download` au build | [CITED: huggingface.co/docs/huggingface_hub] Officiel pour télécharger des repos HF en build-time. |

**⚠️ Version warning [VERIFIED: github.com/microsoft/onnxruntime/issues/26261]** : ONNX Runtime 1.23+ a un bug de chargement de modèles avec external data qui affecte BiRefNet. **Pinner `onnxruntime==1.22.0`** (dernière 1.22.x stable connue compatible). À revérifier au moment du plan avec `pip index versions onnxruntime` et notes de release 1.24/1.25/1.26.

**Pas besoin de PyTorch** — `torch` reste utilisé par `sentence-transformers` (CLIP existant) mais BiRefNet/isnet sont uniquement ONNX. Aucun ajout côté torch.

**Pas besoin de `pillow_avif` supplémentaire** — déjà importé via `app.py`.

**Pas de `opencv-python`** — Pillow + numpy suffisent (resize Lanczos = `PIL.Image.resize(..., Image.Resampling.LANCZOS)`). Évite +120 MB d'image.

### Supporting (côté PHP — aucune nouvelle dep Composer)

Tous les composants existent déjà :
- `AbstractEmbedderStepHandler` (Phase 3)
- `embedder.client` (RetryableHttpClient, Phase 3)
- `StepParamsFactory` (Phase 3)
- Pattern `app.step_handler` tagué (Phase 3)

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `onnxruntime==1.22.0` | `onnxruntime` non pinné (1.26.0 latest) | Risque bug external-data #26261. À réévaluer si modèle ONNX bundle ses poids en interne (pas le cas pour BiRefNet 973 MB → external data probable). |
| `onnx-community/BiRefNet-ONNX` FP16 | FP32 (973 MB) | FP32 = +500 MB image, ~+5% précision marginale. **FP16 retenu** (D-02 ne contraint pas la précision exacte). |
| `huggingface_hub` snapshot_download | `wget` brut depuis GitHub Releases | HF officiel = retries auto, intégrité, chemins canoniques. `wget` simple mais URL fragile, pas de checksum. **`huggingface_hub` retenu**. |
| `asyncio.Lock` + `run_in_executor` | `ProcessPoolExecutor` (multi-process) | Multi-process = modèles chargés N fois (×500 MB) → exclus par RAM cap. **`asyncio.Lock` + thread executor retenu** (decisions D-14 + recommandation Webfacto deferred). |
| `structlog` | `json.dumps()` direct via logging | embedder utilise déjà `logging.basicConfig` + log.info() positional. Pour rester cohérent et léger : **`json.dumps()` dans un helper `core/log_json.py`** (pas de nouvelle dep). |

**Installation:**
```bash
# Ajouts à embedder/requirements.txt
onnxruntime==1.22.0
huggingface_hub>=0.27,<1
# numpy déjà transitive
```

**Version verification (à exécuter au moment du plan) :**
```bash
pip index versions onnxruntime  # confirmer 1.22.0 toujours dispo
pip show huggingface_hub        # confirmer ≥0.27
```

## Architecture Patterns

### Recommended File Layout

```
embedder/
├── routers/
│   └── img_remove_background.py    # NEW — endpoint + Pydantic params
├── core/
│   ├── image_utils.py              # existing (decode_image, image_response)
│   ├── bgremove_models.py          # NEW — load ORT sessions, run inference
│   ├── bgremove_state.py           # NEW — global Lock, inflight counter, last_inference_ms
│   └── log_json.py                 # NEW — small structured-log helper
├── scripts/
│   └── download_models.py          # NEW — invoked at build by Dockerfile stage
├── tests/
│   ├── fixtures/
│   │   └── product_2048.jpg        # NEW — small product photo for tests
│   └── test_remove_background.py   # NEW — endpoint smoke + 413 + 415 + 504
├── requirements.txt                # MODIFIED (+ onnxruntime, + huggingface_hub)
├── Dockerfile                      # MODIFIED (multi-stage avec model-downloader)
└── app.py                          # MODIFIED (include_router + /health enrichi)
```

```
api/src/Service/AssetTransformation/
├── StepHandler/
│   └── RemoveBackgroundHandler.php           # NEW (~25 lines, hérite Abstract)
├── StepParams/
│   ├── RemoveBackgroundStepParams.php        # NEW (readonly DTO)
│   └── StepParamsFactory.php                 # MODIFIED (+ REMOVE_BACKGROUND case)
└── TransformationLookup.php                  # MODIFIED (isAsyncStep retire REMOVE_BACKGROUND)
api/config/services.yaml                       # MODIFIED (timeout param + handler tag implicite)
```

### Pattern 1: Endpoint Python — squelette router

Calqué exactement sur `img_resize.py` / `img_add_background.py` pour cohérence.

```python
# embedder/routers/img_remove_background.py
"""POST /img/remove-background — BiRefNet primary + isnet-general-use fallback."""
from __future__ import annotations

import asyncio
import time
from typing import Literal, Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, ValidationError

from core.image_utils import decode_image, MAX_PIXELS
from core.bgremove_models import run_birefnet, run_isnet
from core.bgremove_state import lock, set_inflight, set_last_ms
from core.log_json import log_event

router = APIRouter()

MAX_DIM = 4096
DOWNSCALE_LONG_EDGE = 2048
BIREFNET_TIMEOUT_S = 5.0


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
    img = decode_image(raw)  # already enforces 50 MPx + EXIF transpose

    # D-07: 4K hard cap (sharper than the 50 MPx ceiling for this endpoint)
    if max(img.size) > MAX_DIM:
        raise HTTPException(status_code=413, detail=f"Image > {MAX_DIM}px long edge.")

    # D-07: auto-downscale to 2048 long-edge for inference (preserve orig for compose)
    orig_size = img.size
    inference_img = img.copy()
    if max(orig_size) > DOWNSCALE_LONG_EDGE:
        inference_img.thumbnail((DOWNSCALE_LONG_EDGE, DOWNSCALE_LONG_EDGE), Image.Resampling.LANCZOS)

    fallback_used = False
    t0 = time.perf_counter()

    async with lock:
        set_inflight(+1)
        try:
            chosen = p.model
            if chosen == "birefnet":
                try:
                    mask = await asyncio.wait_for(
                        asyncio.get_running_loop().run_in_executor(None, run_birefnet, inference_img),
                        timeout=BIREFNET_TIMEOUT_S,
                    )
                except asyncio.TimeoutError:
                    if not p.fallbackOnTimeout:
                        raise HTTPException(status_code=504, detail="BiRefNet timeout (>5s)")
                    mask = await asyncio.get_running_loop().run_in_executor(None, run_isnet, inference_img)
                    fallback_used = True
            else:  # isnet-general-use
                mask = await asyncio.get_running_loop().run_in_executor(None, run_isnet, inference_img)
        finally:
            set_inflight(-1)
            set_last_ms(int((time.perf_counter() - t0) * 1000))

    # D-08: upscale mask to original dims, compose RGBA
    mask_full = mask.resize(orig_size, Image.Resampling.LANCZOS)
    rgba = img.convert("RGB")
    rgba.putalpha(mask_full)

    log_event("remove_background", model=p.model, latency_ms=int((time.perf_counter()-t0)*1000),
              image_dims=f"{orig_size[0]}x{orig_size[1]}", fallback_used=fallback_used)

    buf = io.BytesIO()
    rgba.save(buf, "PNG")
    return Response(content=buf.getvalue(), media_type="image/png",
                    headers={"X-Render-Duration-Ms": str(int((time.perf_counter()-t0)*1000)),
                             "X-Model-Used": "isnet-general-use" if fallback_used else p.model})
```

### Pattern 2: ORT session loading + inference

```python
# embedder/core/bgremove_models.py
"""Lazy-load ONNX sessions and run inference. CPU only."""
from pathlib import Path
import logging
import numpy as np
import onnxruntime as ort
from PIL import Image

MODELS_DIR = Path("/app/models")
BIREFNET_PATH = MODELS_DIR / "birefnet" / "model_fp16.onnx"
ISNET_PATH = MODELS_DIR / "isnet" / "isnet-general-use.onnx"

# ImageNet stats (BiRefNet)
_BIREFNET_MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32).reshape(1, 3, 1, 1)
_BIREFNET_STD  = np.array([0.229, 0.224, 0.225], dtype=np.float32).reshape(1, 3, 1, 1)

# isnet stats (rembg): mean=0.5 std=1.0
_ISNET_MEAN = 0.5
_ISNET_STD  = 1.0

_birefnet_session: ort.InferenceSession | None = None
_isnet_session: ort.InferenceSession | None = None

log = logging.getLogger("embedder")


def get_birefnet() -> ort.InferenceSession:
    global _birefnet_session
    if _birefnet_session is None:
        log.info("Loading BiRefNet ONNX from %s", BIREFNET_PATH)
        so = ort.SessionOptions()
        # CPU intra-op threads: leave default (auto = cores). Inter-op = 1 (we serialize via Lock).
        so.inter_op_num_threads = 1
        _birefnet_session = ort.InferenceSession(str(BIREFNET_PATH), so, providers=["CPUExecutionProvider"])
    return _birefnet_session


def _preprocess_birefnet(img: Image.Image) -> np.ndarray:
    # BiRefNet expects 1024x1024 RGB normalized with ImageNet stats
    resized = img.convert("RGB").resize((1024, 1024), Image.Resampling.BILINEAR)
    arr = np.asarray(resized, dtype=np.float32) / 255.0  # HWC, 0..1
    arr = arr.transpose(2, 0, 1)[None, ...]              # 1xCxHxW
    arr = (arr - _BIREFNET_MEAN) / _BIREFNET_STD
    return arr.astype(np.float16)  # match FP16 model


def _postprocess_mask(raw_output: np.ndarray, dst_size: tuple[int, int]) -> Image.Image:
    # raw shape: (1, 1, 1024, 1024) for BiRefNet OR (1, 1, 1024, 1024) for isnet
    m = raw_output.squeeze()                            # 1024x1024
    # Sigmoid for BiRefNet; isnet rembg uses linear min/max norm — branch in caller
    m = 1.0 / (1.0 + np.exp(-m.astype(np.float32)))     # sigmoid
    m = (m * 255).clip(0, 255).astype(np.uint8)
    mask = Image.fromarray(m, mode="L")
    return mask.resize(dst_size, Image.Resampling.LANCZOS)


def run_birefnet(img: Image.Image) -> Image.Image:
    sess = get_birefnet()
    x = _preprocess_birefnet(img)
    input_name = sess.get_inputs()[0].name  # 'input_image' per HF model card
    out = sess.run(None, {input_name: x})[0]
    return _postprocess_mask(out, img.size)


def run_isnet(img: Image.Image) -> Image.Image:
    # Similar shape, different normalization + linear post-norm (not sigmoid).
    # Follow rembg post-processing: (out - min) / (max - min) * 255.
    ...
```

> **NOTE [ASSUMED]** : Le post-processing exact d'isnet-general-use diffère légèrement (min/max linear vs sigmoid). À confirmer en lisant `rembg/src/rembg/sessions/dis_general_use.py` lors de l'implémentation. Voir Assumptions Log A1.

### Pattern 3: Global state for `/health`

```python
# embedder/core/bgremove_state.py
"""Module-level singletons for the bg-remove endpoint."""
import asyncio
import threading

lock = asyncio.Lock()
_inflight_lock = threading.Lock()
_inflight: int = 0
_last_inference_ms: int | None = None


def set_inflight(delta: int) -> None:
    global _inflight
    with _inflight_lock:
        _inflight += delta


def get_inflight() -> int:
    with _inflight_lock:
        return _inflight


def set_last_ms(ms: int) -> None:
    global _last_inference_ms
    _last_inference_ms = ms


def get_last_ms() -> int | None:
    return _last_inference_ms
```

### Pattern 4: `/health` enrichi (modify `app.py`)

```python
@app.get("/health")
def health() -> dict:
    from core.bgremove_models import _birefnet_session, _isnet_session
    from core.bgremove_state import get_inflight, get_last_ms

    birefnet_loaded = _birefnet_session is not None
    isnet_loaded = _isnet_session is not None
    inflight = get_inflight()
    status = "ok" if birefnet_loaded and inflight <= 4 else "degraded"

    return {
        "status": status,
        "models": {
            "clip": {"status": "loaded" if _model else "lazy", "name": MODEL_NAME, "dim": 512},
            "birefnet": {
                "status": "loaded" if birefnet_loaded else "not_loaded",
                "model": "birefnet-general-fp16",
                "inflight": inflight,
                "last_inference_ms": get_last_ms(),
            },
            "isnet": {"status": "loaded" if isnet_loaded else "not_loaded"},
            "stable_diffusion": {"status": "not_loaded"},  # preserved for Phase 5
        },
    }
```

**⚠️ Compat** : la signature reste un superset du contrat actuel (clé `models.clip.*` préservée). Le test existant `test_health.py::test_health_schema` reste vert si on garde `m["clip"]["dim"] == 512` et `set(m.keys()) >= {"clip", "birefnet", "stable_diffusion"}`. Ajouter une assertion pour la nouvelle clé `isnet` (test à compléter).

### Pattern 5: PHP — DTO + Handler

```php
// api/src/Service/AssetTransformation/StepParams/RemoveBackgroundStepParams.php
final readonly class RemoveBackgroundStepParams
{
    public function __construct(
        #[Assert\Choice(choices: ['birefnet', 'isnet-general-use'])]
        public string $model = 'birefnet',
        public bool $fallbackOnTimeout = false,
    ) {}
}
```

```php
// api/src/Service/AssetTransformation/StepHandler/RemoveBackgroundHandler.php
final class RemoveBackgroundHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_remove_background_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::REMOVE_BACKGROUND;
    }

    protected function endpointPath(): string
    {
        return '/img/remove-background';
    }
}
```

```yaml
# api/config/services.yaml additions
parameters:
    embedder_default_rmbg_ms: '6000'   # D-18 : 6 s = 5 s Python + 1 s marge réseau
    transformations.embedder_timeout_remove_background_ms: '%env(int:default:embedder_default_rmbg_ms:EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS)%'
```

Pas besoin de définir le handler en YAML : `#[AutoconfigureTag('app.step_handler')]` sur l'interface fait le tagging automatiquement (vérifié en Phase 3).

### Pattern 6: Inverser `TransformationLookup::isAsyncStep()`

```diff
 private function isAsyncStep(TransformationStep $step): bool
 {
     $type = $step->getType();
-    if ($type === StepType::REMOVE_BACKGROUND) {
-        return true;
-    }
     if ($type === StepType::ADD_BACKGROUND) {
         $params = $step->getParams();
         return ($params['type'] ?? null) === 'ai_prompt';
     }
     return false;
 }
```

**Test sensible** : le plan doit prévoir de mettre à jour les tests Phase 3 qui asserent `REMOVE_BACKGROUND → 404`. Grep : `git grep -n REMOVE_BACKGROUND api/tests`.

### Anti-Patterns to Avoid

- **Recharger `InferenceSession` à chaque requête** — coût constant ~3 s. Doit être module-level et chargé au startup hook.
- **`threading.Lock` au lieu d'`asyncio.Lock`** sur le handler async — bloque le loop pendant l'attente. Si on veut un thread-lock, c'est `run_in_executor` qui le gère implicitement par contention sur le ThreadPool, mais expliciter via `asyncio.Lock` autour du `run_in_executor` est plus lisible **et** garde le compteur `inflight` cohérent.
- **`Image.thumbnail` sur l'image originale** sans copy — mutation in-place. Toujours `img.copy().thumbnail(...)` (sinon on perd l'image originale pour la composition finale).
- **Sigmoid + min/max norm appliqués au mauvais modèle** — BiRefNet = sigmoid ; isnet rembg = min/max linear. Brancher dans `run_isnet` séparément, pas dans `_postprocess_mask`.
- **`uvicorn --workers N` > 1** — N copies des modèles en RAM. Garder 1 worker (lock mono-process valide seulement à 1 worker).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Décodage image avec validation 50 MPx + EXIF transpose | Re-coder dans le router | `core/image_utils.decode_image()` (Phase 2) | Déjà testé, gère SVG reject, EXIF, MAX_PIXELS. |
| HTTP multipart vers embedder | Re-coder client | `AbstractEmbedderStepHandler` + `embedder.client` (Phase 3) | Retry + scoping + multipart déjà câblés. |
| Téléchargement modèle build-time | `wget` + checksum manuel | `huggingface_hub.snapshot_download(repo_id, local_dir=...)` | Retries auto, intégrité, idempotent. |
| Resize Lanczos | OpenCV | `PIL.Image.resize(..., Image.Resampling.LANCZOS)` | Pillow déjà présent, économise 120 MB d'image. |
| Sérialisation logs JSON | structlog full stack | `json.dumps()` + helper `log_json.py` | Pas de dep nouvelle, format Datadog identique. |
| Compteur `inflight` thread-safe | atomique custom | `threading.Lock` autour d'un int module-level | Python int += n'est pas atomique sous GIL contention `run_in_executor`. |

**Key insight:** Tout le code "boilerplate" (multipart, retries, validation image, lock sémantique) existe déjà via Phase 2 + Phase 3. Phase 4 = ~150 lignes Python neuves + ~40 lignes PHP neuves.

## Common Pitfalls

### Pitfall 1: ORT 1.23+ external-data load failure
**What goes wrong:** `pip install onnxruntime` (latest = 1.26.0 au 2026-05) refuse de charger `model_fp16.onnx` à cause d'un changement shape-inference dans 1.23+.
**Why:** BiRefNet ONNX a des poids en "external data" (fichier séparé `.data` ou inlined > seuil 2 GB).
**How to avoid:** Pinner `onnxruntime==1.22.0` dans `requirements.txt`. Documenter dans `embedder/README.md`.
**Warning signs:** `InvalidGraph: Load model from … failed: shape inference …` au startup.
**Source:** [VERIFIED: github.com/microsoft/onnxruntime/issues/26261]

### Pitfall 2: `asyncio.Lock` ne protège pas vraiment l'inférence
**What goes wrong:** Si on appelle directement `sess.run(...)` dans la coroutine **sans** `run_in_executor`, le loop est bloqué pendant 2-3 s ; les autres requêtes (y compris `/health`) ne répondent plus.
**Why:** ORT `sess.run` est CPU-bound bloquant ; ne libère pas le GIL pour des opérations Python utilisateur.
**How to avoid:** Toujours `await loop.run_in_executor(None, sess.run, ...)`. Le `asyncio.Lock` reste utile pour sérialiser les `wait_for` proprement et garder `inflight` à 1.
**Warning signs:** `/health` qui timeout pendant qu'une inférence tourne.

### Pitfall 3: Mutation `Image.thumbnail` in-place
**What goes wrong:** Après le downscale, on perd les dimensions originales → mask upscalé incorrect.
**Why:** `thumbnail` muter l'image et retourne `None`.
**How to avoid:** `inference_img = img.copy(); inference_img.thumbnail((2048,2048), LANCZOS)`. Garder `img` intact pour le compose.
**Warning signs:** Image de sortie en 2048×… au lieu de la dim originale.

### Pitfall 4: PNG paletted / 1-bit / LA mode crash sur `putalpha`
**What goes wrong:** Pillow refuse `putalpha` sur certains modes exotiques (`P`, `1`, `I;16`).
**How to avoid:** Forcer `img.convert("RGB").putalpha(mask)`. La convert garantit le mode.
**Warning signs:** `ValueError: image has wrong mode`.

### Pitfall 5: GIF/WebP animé — UnboundLocalError sur `img.format`
**What goes wrong:** Animated GIF → seul frame[0] est exposé par Pillow ; `decode_image` ne reject pas mais le résultat est faux.
**How to avoid (research recommandation, optionnel)** : refuser explicitement si `getattr(img, "is_animated", False)` → 415. À ajouter au plan ou laisser passer (frame[0] est cohérent avec Phase 2).
**Warning signs:** Sortie d'une seule frame d'un GIF — comportement non documenté mais acceptable v1.

### Pitfall 6: HuggingFace download timeout au build
**What goes wrong:** `snapshot_download` peut traîner 5-10 min sur 1 GB de poids ; CI runners avec proxy étroit échouent.
**How to avoid:** 
1. Stage séparé `model-downloader` → si `RUN snapshot_download` échoue, on relance juste ce stage.
2. BuildKit cache mount `RUN --mount=type=cache,target=/root/.cache/huggingface` pour ne pas re-télécharger entre rebuilds.
3. `HF_HUB_ENABLE_HF_TRANSFER=1` + `pip install hf_transfer` accélère le download ~3-5× (optionnel).
**Warning signs:** Builds CI à 12 min vs 4 min attendu ; timeouts intermittents.

### Pitfall 7: `uvicorn --workers > 1` en prod
**What goes wrong:** asyncio.Lock est par-process. 2 workers = 2 inférences simultanées → OOM probable (2 × 500 MB).
**How to avoid:** Documenter `--workers 1` explicitement dans le `CMD`. Le scaling horizontal passe par plusieurs replicas de container, pas par workers internes. (Le `CMD` actuel n'a pas de `--workers` → default = 1 ✓).
**Warning signs:** `docker stats` montre `embedder` mémoire qui double, p95 latence stable mais OOM kills.

### Pitfall 8: PWA TS types — non requis pour Phase 4
**What goes wrong:** Réflexe `make generate-types` après tout changement API.
**Why pas nécessaire ici:** Aucun changement de groupes de sérialisation, aucun DTO API Platform, aucun endpoint public PHP nouveau. Le `RemoveBackgroundStepParams` est utilisé en interne (validation) et exposé via le JSON `params` d'un `TransformationStep` existant.
**How to avoid:** Mentionner dans le plan que `make generate-types` est **non requis**, mais qu'un grep `api/docs.json` doit confirmer aucun delta.

## Runtime State Inventory

Phase 4 ne renomme rien. Section omise.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker BuildKit | Multi-stage build avec `--mount=type=cache` | À confirmer (>= 20.10 ; certainement OK : Docker récent dans docker-compose) | — | Sans BuildKit, le download HF se refait à chaque rebuild de l'app layer (lent mais fonctionnel). |
| Réseau HF Hub depuis le runner de build | Stage `model-downloader` | À confirmer (CI Vente-Unique) | — | Bake images locales et push registry depuis poste dev si CI réseau insuffisant. |
| `embedder` container RAM ≥ 3 GB | BiRefNet (490 MB FP16) + isnet (~170 MB) + CLIP (~700 MB) + headroom | À provisionner | — | Aucune (D-13 checklist item 3 le matérialise). |
| Datadog log ingest | D-12 logs JSON | Présumé OK (org utilise Datadog) | — | stdout reste lisible directement. |

**Missing dependencies with no fallback:** RAM 3 GB prod (à provisionner — pas de blocage code, mais blocage deploy gate).

**Note** : pas de dépendance OS supplémentaire dans le Dockerfile (`libgomp1` déjà installé pour torch CPU, suffit pour ORT CPU).

## Code Examples

### Multi-stage Dockerfile (skeleton recommandé)

```dockerfile
# syntax=docker/dockerfile:1.6
FROM python:3.11-slim AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends libgomp1 \
 && rm -rf /var/lib/apt/lists/*

# ---- Stage 1: model-downloader ---------------------------------------
FROM base AS model-downloader
WORKDIR /tmp
RUN pip install --no-cache-dir "huggingface_hub>=0.27,<1"

# BuildKit cache mount so repeated builds reuse the HF cache.
ENV HF_HOME=/root/.cache/huggingface
RUN --mount=type=cache,target=/root/.cache/huggingface \
    python -c "from huggingface_hub import snapshot_download; \
               snapshot_download('onnx-community/BiRefNet-ONNX', \
                                 local_dir='/models/birefnet', \
                                 allow_patterns=['onnx/model_fp16.onnx', 'config.json'])"

# isnet — direct GitHub release (no HF repo for this canonical asset)
RUN --mount=type=cache,target=/root/.cache/wget \
    apt-get update && apt-get install -y wget && \
    wget -q -O /models/isnet/isnet-general-use.onnx \
      https://github.com/danielgatis/rembg/releases/download/v0.0.0/isnet-general-use.onnx

# ---- Stage 2: final ---------------------------------------------------
FROM base
WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

ENV HF_HOME=/app/.cache/huggingface
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('clip-ViT-B-32')"

COPY requirements-dev.txt .
RUN pip install --no-cache-dir -r requirements-dev.txt

# Copy pre-downloaded models from stage 1
COPY --from=model-downloader /models /app/models

COPY app.py .
COPY core ./core
COPY routers ./routers
COPY tests ./tests
COPY pytest.ini .

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD python -c "import urllib.request, sys; sys.exit(0 if urllib.request.urlopen('http://localhost:8000/health').status == 200 else 1)"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
```

**Note** : `--start-period` bumpé de 15 s à 30 s pour laisser le temps au load des deux ORT sessions au startup.

### Structured JSON log helper

```python
# embedder/core/log_json.py
import json
import logging
import sys
import time

_log = logging.getLogger("embedder")

def log_event(event: str, **fields) -> None:
    payload = {"event": event, "ts": time.time(), **fields}
    # Datadog ingest naturally consumes JSON lines on stdout.
    sys.stdout.write(json.dumps(payload, default=str) + "\n")
    sys.stdout.flush()
```

### Test fixture commit-checked

```python
# embedder/tests/test_remove_background.py
def test_remove_background_birefnet_returns_png_rgba(client, product_2048_jpg):
    r = client.post("/img/remove-background",
                    files={"image": ("p.jpg", product_2048_jpg, "image/jpeg")},
                    data={"params": '{"model":"birefnet"}'})
    assert r.status_code == 200
    assert r.headers["content-type"] == "image/png"
    out = Image.open(io.BytesIO(r.content))
    assert out.mode == "RGBA"
    # at least 5% transparent pixels — sanity check that mask is non-trivial
    alpha = np.array(out.split()[3])
    assert (alpha < 16).mean() >= 0.05


def test_remove_background_rejects_oversized(client):
    huge = make_png(5000, 5000)
    r = client.post("/img/remove-background",
                    files={"image": ("x.png", huge, "image/png")},
                    data={"params": '{"model":"birefnet"}'})
    assert r.status_code == 413
```

**Test infrastructure CI note** : Charger les vrais modèles ONNX en CI = +1 GB de pull + ~30 s startup. Deux options pour le plan :
1. **Heavy tests gated** : marquer `@pytest.mark.integration_ml` ; ne tourner que sur `make test-ml` local ou en CI nightly.
2. **Light tests fallback** : monkey-patch `core.bgremove_models.run_birefnet` pour retourner un mask trivial (uniform 128) — vérifie le wiring routeur + multipart + header, pas la qualité du mask.

→ **Recommandation au planner** : adopter (2) pour `pytest` rapide (< 3 s), garder (1) pour smoke E2E pré-deploy (Webfacto checklist item 4).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `rembg` library full | `onnxruntime` direct + ONNX from HF | This phase | Évite +200 MB de deps Python, contrôle fin du lifecycle session. |
| PyTorch + transformers `AutoModelForImageSegmentation` | ORT direct | This phase | -800 MB image, latence CPU meilleure (op fusion ORT). |
| BriaAI RMBG-1.4/2.0 | BiRefNet | Pivot 2026-05-26 | Licence MIT (commercial OK) vs CC-BY-NC. |
| ORT 1.23+ external data | ORT 1.22.0 pinned | Bug #26261 (2026) | Pin temporaire jusqu'à fix upstream. |

**Deprecated/outdated:**
- `rembg.new_session("isnet-general-use")` — encore valide mais embarque dep `rembg` lourde. On lit juste le `.onnx` directement.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Post-processing isnet-general-use = `(out - min) / (max - min) * 255`, pas sigmoid | Pattern 2 | Mask noir/blanc trop dur ou inversé. **Mitigation** : lire `rembg/sessions/dis_general_use.py` au moment de l'implémentation et copier le post-proc exact. |
| A2 | BiRefNet ONNX `model_fp16.onnx` accepte des tenseurs FP16 en entrée | Pattern 2 | InvalidArgument à l'inférence. **Mitigation** : inspecter `sess.get_inputs()[0].type` au démarrage et caster en conséquence (`np.float16` si "tensor(float16)", sinon `np.float32`). |
| A3 | `--workers 1` actuel reste le default uvicorn — D-14 lock mono-process valide | Pattern 7 anti-patterns | Si on bascule à >1 workers, lock cassé. Documenter dans `CMD` explicitement. |
| A4 | RAM 3 GB suffisante pour CLIP + BiRefNet FP16 + isnet en mémoire résidente | Environment Availability | OOM en prod. **Mitigation** : D-13 checklist item 3 le valide en staging avant prod. |
| A5 | `docker compose exec embedder pytest` est la commande validée pour tests Python | Validation Architecture | Si le service `embedder` n'a pas pytest installé en runtime, fallback `pytest` local (requiert `pip install -r requirements-dev.txt` sur l'hôte). |
| A6 | Aucune régression sur `/embed` (CLIP) suite à enrichissement `/health` et à l'ajout d'imports module-level | Pattern 4 | Smoke `test_embed_endpoint_still_works` doit rester vert. |
| A7 | Le repo `onnx-community/BiRefNet-ONNX` correspond à la variante "general DIS5K" décrite par D-02 | Stack | Modèle générique sub-optimal pour produits spécifiques. **Mitigation** : valider qualitativement sur 3 assets réels en staging (D-13 item 4). |
| A8 | Pas de token HuggingFace requis pour télécharger `onnx-community/*` | Stack | Build CI échoue si repo devient privé. Tous les repos `onnx-community/*` sont publics au 2026-05-27. |

## Open Questions

1. **Précision FP16 vs FP32 sur produits dark / blanc cassé** : FP16 perd 0.5-1.5% mIoU vs FP32 sur DIS5K benchmarks. Acceptable pour habitat / catalogue mode ?
   - What we know: HF distribue les deux ; FP16 = 490 MB, FP32 = 973 MB.
   - What's unclear: Impact qualitatif sur la silhouette détourée.
   - Recommendation: démarrer FP16 ; mesurer en D-13 item 4 ; bascule FP32 = config flag, pas un refactor.

2. **isnet-general-use download via GitHub release** : URL stable ?
   - What we know: `github.com/danielgatis/rembg/releases/download/v0.0.0/isnet-general-use.onnx` est l'URL canonique de rembg.
   - What's unclear: Tag `v0.0.0` peut être retiré si rembg change sa stratégie de release.
   - Recommendation: alternative HF mirror `briaai/RMBG-2.0` rejetée (licence). Possibilité de mirror le `.onnx` dans un repo HF privé Vente-Unique côté Webfacto, à arbitrer.

3. **Inversion de `TransformationLookup::isAsyncStep` casse-t-elle des tests Phase 3 ?**
   - What we know: Plan 03-03 a sûrement un test qui asserte 404 sur transformations avec `REMOVE_BACKGROUND`.
   - What's unclear: combien de tests à mettre à jour ; risque de découvrir d'autres call-sites du gate.
   - Recommendation: `git grep -n "REMOVE_BACKGROUND\|isAsyncStep" api/` en début de plan, lister les tests à reverdir.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework Python | `pytest==9.0.3` + `httpx==0.28.1` + `pytest-asyncio==1.4.0` (déjà installé Phase 2) |
| Framework PHP | PHPUnit (config `phpunit.dist.xml`, testsuites `unit` + `integration`) |
| Config files | `embedder/pytest.ini` (asyncio_mode=auto), `api/phpunit.dist.xml` |
| Quick run Python | `docker compose exec embedder pytest tests/test_remove_background.py -x` |
| Quick run PHP | `docker compose exec api ./vendor/bin/phpunit --testsuite=unit --filter RemoveBackground` |
| Full suite Python | `docker compose exec embedder pytest` |
| Full suite PHP | `docker compose exec api ./vendor/bin/phpunit` (unit + integration) |
| Smoke E2E ops | `curl -F image=@product.jpg -F params='{"model":"birefnet"}' http://embedder:8000/img/remove-background -o out.png` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| IMGSVC-06 | POST /img/remove-background accepte multipart + params JSON ; retourne PNG | unit (Python) | `pytest tests/test_remove_background.py::test_remove_background_birefnet_returns_png_rgba -x` | ❌ Wave 0 |
| BGREMOVE-01 | BiRefNet default ; licence MIT (vérifier headers `X-Model-Used`) | unit (Python) | `pytest tests/test_remove_background.py::test_default_model_is_birefnet -x` | ❌ Wave 0 |
| BGREMOVE-02 | `model=isnet-general-use` accepté ; mauvais enum → 422 | unit (Python) | `pytest tests/test_remove_background.py::test_isnet_explicit_selection -x` + `test_unknown_model_returns_422` | ❌ Wave 0 |
| BGREMOVE-03 | Modèles pré-téléchargés (build) — vérifier `/app/models/birefnet/*` et `/app/models/isnet/*` présents | smoke (build) | `docker compose run --rm embedder ls /app/models/` | ❌ Wave 0 (manual check at build) |
| BGREMOVE-04 | Concurrent requests sérialisées par lock — `inflight` reflète occupation | unit (Python) | `pytest tests/test_remove_background.py::test_concurrent_requests_are_serialized -x` (utilise `asyncio.gather` + monkey-patched slow run) | ❌ Wave 0 |
| BGREMOVE-05 | < 3 s sur 2048² + fallback isnet sur timeout | manual + perf | `bin/bench_bgremove.sh` (script à créer, run 3 assets) ; **manual-only** pour la mesure p95 prod (D-13 item 4) | ❌ Wave 0 |
| BGREMOVE-06 | `RemoveBackgroundHandler` PHP appelle l'endpoint via MockHttpClient | unit (PHP) | `./vendor/bin/phpunit --filter RemoveBackgroundHandlerTest` | ❌ Wave 0 |
| (D-11) | `/health` rapporte birefnet.loaded + inflight + last_inference_ms | unit (Python) | `pytest tests/test_health.py::test_health_reports_birefnet -x` | ❌ Wave 0 (étendre existant) |
| (D-17) | 413 sur > 4K ; 422 sur params invalides ; 504 sur timeout sans fallback ; 415 sur SVG | unit (Python) | `pytest tests/test_remove_background.py -k "rejects or timeout" -x` | ❌ Wave 0 |
| (Lookup gate) | Transformation avec `remove_background` n'est plus 404 sur `/t/*` | integration (PHP) | `./vendor/bin/phpunit --testsuite=integration --filter PublicTransformationControllerTest` (étendre tests Phase 3) | ⚠️ existe Phase 3 — à mettre à jour |

### Sampling Rate

- **Per task commit:** Python = `pytest tests/test_remove_background.py -x` (~2 s monkey-patched) ; PHP = `phpunit --filter RemoveBackground` (~3 s).
- **Per wave merge:** Full unit + integration des deux côtés : `docker compose exec embedder pytest` + `docker compose exec api ./vendor/bin/phpunit`.
- **Phase gate (avant `/gsd-verify-work`):** Full suite verte + smoke E2E manuel avec **vrais** modèles ONNX (intégration heavy, gated par `@pytest.mark.integration_ml`).

### Wave 0 Gaps

- [ ] `embedder/tests/test_remove_background.py` — smoke + 413 + 422 + 504 + RGBA assertions
- [ ] `embedder/tests/conftest.py` — ajouter fixture `product_2048_jpg` + monkey-patch helper pour `run_birefnet`/`run_isnet`
- [ ] `embedder/tests/fixtures/product_2048.jpg` — image checked-in (~500 KB, libre de droits ou photo produit générique)
- [ ] `api/tests/Unit/Service/AssetTransformation/StepHandler/RemoveBackgroundHandlerTest.php` — pattern `HandlersHttpTest` étendu (MockHttpClient)
- [ ] `api/tests/Integration/Controller/PublicTransformationControllerTest.php` — **modifier** test existant qui asserte 404 sur `REMOVE_BACKGROUND`
- [ ] `api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php` — mettre à jour les asserts du gate
- [ ] (Optionnel mais recommandé) `embedder/bin/bench_bgremove.sh` — script bench p95 pour D-13 item 4

**Framework install : aucun.** pytest, phpunit, httpx, MockHttpClient déjà disponibles.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | non | Endpoint embedder = réseau interne `embedder:8000`, isolé par ScopingHttpClient (Phase 3) |
| V3 Session Management | non | Stateless |
| V4 Access Control | partial | Toujours via `/t/*` derrière `isPublic=true` (Phase 3 ROUTE-08) |
| V5 Input Validation | **yes** | Pydantic `Literal` enum + size cap 4K + mime check via `decode_image` ; PHP `RemoveBackgroundStepParams` `Assert\Choice` |
| V6 Cryptography | non | Aucun secret manipulé. Pas de token HF |
| V12 Files & Resources | yes | Multipart `UploadFile` ; size limit Pillow → 50 MPx (`decode_image`) ; pas de path traversal (lecture in-memory only) |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Pillow zip-bomb / decompression bomb | DoS | `decode_image` enforce `w*h > 50 MPx → 422` AVANT `.load()` (Phase 2 ✓) |
| ONNX file tampering | Tampering | Modèles bakés dans image immuable + digest registry (D-13 item 1) |
| Inference flooding | DoS | Cap timeout 5 s + asyncio.Lock sérialise → 1 inférence à la fois ; multi-inflight retournera 503 PHP côté orchestrateur si cap 8 s dépassé. **Rate-limit `/t/*` au CDN** (D-13 item 6). |
| SSRF via `model` param | SSRF | Enum strict Pydantic ; aucune URL manipulée |
| Resource exhaustion (RAM) | DoS | Long-edge 4K cap + downscale auto 2048 ; lock empêche les peaks |
| Log injection via fields | Logging tampering | `json.dumps(default=str)` echappe les valeurs ; `client_ip` à ne **pas** logger sans normalisation (X-Forwarded-For) |

## Sources

### Primary (HIGH confidence)
- [VERIFIED: huggingface.co/onnx-community/BiRefNet-ONNX] — `onnx/model.onnx` 973 MB FP32 + `onnx/model_fp16.onnx` 490 MB FP16, MIT, input name `input_image`, output `output_image`
- [VERIFIED: github.com/danielgatis/rembg] — `isnet-general-use.onnx` from `releases/download/v0.0.0/isnet-general-use.onnx`, source DIS project
- [VERIFIED: github.com/microsoft/onnxruntime — discussions/10107 + issue/114] — `Run()` est thread-safe sauf DirectML
- [VERIFIED: github.com/microsoft/onnxruntime/issues/26261] — bug 1.23+ external data, fix not yet shipped at search date
- [VERIFIED: onnxruntime.ai/docs/performance/tune-performance/threading.html] — `SessionOptions.inter_op_num_threads`
- [VERIFIED: huggingface.co/docs/huggingface_hub] — `snapshot_download(repo_id, local_dir, allow_patterns)`
- [VERIFIED: docs.docker.com/build/cache/optimize] — `--mount=type=cache` persistence across builds

### Secondary (MEDIUM confidence)
- [CITED: debuggercafe.com/introduction-to-birefnet] — BiRefNet input 1024×1024 + ImageNet normalization confirmé
- [CITED: huggingface.co/martintomov/comfy/.../isnet-general-use.onnx] — mirror communautaire, mêmes hashes que rembg release
- [CITED: fastapi.tiangolo.com/async/] — `run_in_executor` est l'idiome FastAPI pour CPU-bound work

### Tertiary (LOW confidence — flag for validation)
- isnet-general-use mean=0.5 std=1.0 — single source DeepWiki, à recroiser avec code rembg avant impl (A1)
- ORT 1.22.0 = dernière 1.22.x — bumper et re-tester à l'exécution si une 1.22.1 a été publiée

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — modèles, runtime et versions vérifiés sur dépôts officiels HF + ORT GitHub
- Architecture: HIGH — pattern routers/handler 100% conforme à Phase 2 + Phase 3 existantes
- Pitfalls: HIGH — issues GitHub citées (ORT 1.23) + idiomes FastAPI documentés ; A1 (isnet post-proc) reste à confirmer dans le code rembg
- Validation: MEDIUM — Wave 0 tests à créer (rien n'existe encore), mais infrastructure pytest/phpunit déjà en place

**Research date:** 2026-05-27
**Valid until:** 2026-06-27 (stable — ORT 1.22 pin, modèles HF stables ; revoir si bug #26261 fixé)
