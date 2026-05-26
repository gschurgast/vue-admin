# Stack Research — Asset Transformations Milestone

**Domain:** Brownfield additions to a Symfony 7.3 / API Platform 4 / Vue 3 admin app to support an image transformation pipeline (resize, crop, rotate, format conversion, add/remove background) exposed via a public, S3-cached URL `/t/{code}/{id}.{ext}`.
**Researched:** 2026-05-26
**Confidence:** MEDIUM-HIGH (Imagine + Symfony Lock + Symfony Validator: HIGH from official docs and existing stack; rembg model trade-offs: MEDIUM, verified against rembg 2.0.75 docs but exact RSS in our exact container untested)

**Scope reminder:** Only the *new* dependencies for this milestone. The existing stack (PHP 8.4, Symfony 7.3, API Platform 4, Doctrine ORM, Pillow, sentence-transformers/CLIP, FastAPI, Messenger/Redis, Flysystem) is reused as-is.

---

## Recommended Stack — at a glance

| Concern | Recommendation | Version |
|---|---|---|
| PHP image manipulation | **`imagine/imagine`** with **Imagick** driver | `^1.5.2` (PHP) + Imagick `^3.7` ext + ImageMagick 7 |
| Background removal (Python) | **`rembg`** with **`isnet-general-use`** model, ONNX Runtime CPU | `rembg==2.0.75`, `onnxruntime==1.20.*` |
| HTTP cache headers / ETag | **Symfony `HttpFoundation` Response built-ins** (`setEtag`, `setPublic`, `setImmutable`, `isNotModified`) | bundled (Symfony 7.3) |
| Concurrency / anti-stampede | **Symfony Lock component** + Redis store | `symfony/lock` 8.0.* |
| JSON parameter validation per step | **Symfony Validator** with a per-step-type DTO + `#[Assert\*]`, dispatched by a `StepHandlerRegistry` | bundled |

No new framework, no new runtime. All five additions are stdlib/Symfony-native or already-vendored Python tooling. The only architectural addition is `rembg` + an extra `/rmbg` route on the existing `embedder` FastAPI service.

---

## Core Technologies (new)

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| `imagine/imagine` | `^1.5.2` | PHP image manipulation engine for resize/crop/rotate/format convert/add_background | Mature (74M installs), MIT, framework-agnostic, pluggable backend (GD/Imagick/Gmagick). Native fit with our pure-Symfony app and our "one handler per step type" architecture (each Imagine call is one method on the `ImageInterface`). Already the *Key Decision* in PROJECT.md. |
| ImageMagick 7 + `ext-imagick` | `imagick ^3.7` | C backend for Imagine | Required for AVIF/WebP/HEIC encode-decode at production quality, ICC color profile preservation, and PDF rasterization should we ever need it. GD cannot do AVIF reliably and rotates EXIF poorly. |
| `rembg` (Python) | `2.0.75` | Background removal in the `embedder` service via a new `POST /rmbg` endpoint | Most adopted OSS bg-removal lib (≈18k★), MIT, single-file Python API, ships 15+ ONNX models with one-line download. Coexists fine with `sentence-transformers`/`torch` because it uses ONNX Runtime (no torch fight). |
| `onnxruntime` (CPU) | `1.20.*` | Inference backend for rembg models | Pure-CPU wheel, no CUDA, ~50 MB. Threading via `OMP_NUM_THREADS` already aligned with our `libgomp1` install in the existing Dockerfile. |
| Symfony `Lock` component | 8.0.* (matches `symfony/*` pins) | Per-variant Redis lock to prevent thundering herd on first request to `/t/{code}/{id}.{ext}` | Already part of the Symfony ecosystem we pull in. Reuses the **existing Redis** (`predis/predis ^3.0`) — no new infrastructure. Battle-tested at scale (`framework.lock: redis://...`). |
| Symfony `Validator` | 8.0.* (already transitively pulled by framework-bundle / API Platform) | Per-step parameter validation (`resize.width > 0`, `add_background.color` is `#RRGGBB`, etc.) | Already in the project. Keeping validation in PHP attributes on small step-DTOs avoids a second source of truth (JSON Schema files) and surfaces errors through API Platform's standard 422 path. |

---

## Supporting Libraries (when actually needed)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `ext-imagick` (PHP) | `^3.7` | Native binding to ImageMagick 7 | Always — selected as Imagine's driver. Install via `pecl install imagick` in the FrankenPHP image, or use the `php-imagick` Debian package. |
| `ext-gd` (PHP) | bundled | Fallback driver | Only if Imagick is unavailable in some environment (CI without IM7). Imagine's `Imagine\Gd\Imagine` is a drop-in. |
| `symfony/cache` | already pulled | Optional negative-cache for "variant exists in S3" lookups | Phase ≥ 2 optimisation. Skip in MVP — the S3 HEAD is cheap. |
| `league/flysystem-aws-s3-v3` | already pulled | Read/write of `transformations/{code}-v{hash}/{shard}/{id}.{ext}` | Reused as-is. Use `writeStream()` to avoid loading the variant into RAM twice. |

No new Composer "frontend" packages. No new PHP runtime extensions beyond `ext-imagick`.

---

## Python service deltas (`embedder/`)

The existing image is already `python:3.11-slim` + torch CPU + sentence-transformers. We add:

```diff
 fastapi==0.115.6
 uvicorn[standard]==0.32.1
 python-multipart==0.0.18
 pillow==11.0.0
 sentence-transformers==3.3.1
+rembg==2.0.75
+onnxruntime==1.20.1
 --extra-index-url https://download.pytorch.org/whl/cpu
 torch==2.12.0+cpu
```

Note: install `rembg` *without* the `[cpu]` extra. The `rembg[cpu]` extra pins `onnxruntime`, which we already pin explicitly — declaring both lets pip resolve cleanly. We deliberately skip `rembg[gpu]` (no CUDA in our base image).

Dockerfile addition (pre-pull the single model we use at build time, mirroring the CLIP pre-pull):

```dockerfile
ENV U2NET_HOME=/app/.cache/u2net
RUN python -c "from rembg import new_session; new_session('isnet-general-use')"
```

### Model choice rationale (rembg)

| Model | On-disk | Quality on product photos | RAM at inference (1024px input) | Recommendation |
|---|---|---|---|---|
| `u2netp` | ~5 MB | Mediocre edges, halos on hair/fur | ~400 MB | Skip — quality too low for catalog. |
| `u2net` | ~176 MB | Good general purpose, the 2020 baseline | ~700 MB | Acceptable fallback. |
| `silueta` | ~43 MB | Fast, decent for clean studio shots | ~450 MB | Good "fast lane" if we add a quality tier later. |
| **`isnet-general-use`** | ~176 MB | Best of the lightweight tier on e-commerce-style cutouts, sharper edges than u2net | ~700-900 MB | **Recommended** — best quality/footprint for product images. |
| `birefnet-general` | ~440 MB | State-of-the-art quality | ~2.5 GB | Skip in MVP — doubles the worker memory budget. Reconsider once we measure traffic. |
| `bria-rmbg` | ~176 MB | SOTA but **non-commercial license** | ~700 MB | Skip — license forbids Vente-Unique's use. |

Sizing implication: `isnet-general-use` keeps the embedder container under **~2 GB RSS** with CLIP + rembg loaded simultaneously (CLIP ~600 MB + isnet ~800 MB peak + Python overhead). This fits a single `t3.small`-equivalent worker. If we observe contention, scale horizontally — both endpoints are stateless.

### Compat note (torch vs onnxruntime in same venv)

`rembg` does not import torch; it loads `.onnx` weights via `onnxruntime`. CLIP under `sentence-transformers` keeps its torch path. The two coexist routinely (verified pattern in many community deployments). The only practical caveat is OpenMP: both runtimes link `libgomp1`, which is already installed in our Dockerfile. Set `OMP_NUM_THREADS=$(nproc)` once in the entrypoint to avoid double-spawning threads.

---

## Installation

```bash
# PHP side (api/)
docker compose exec api composer require imagine/imagine:^1.5
# Imagick extension — add to the FrankenPHP Dockerfile:
#   RUN install-php-extensions imagick

# Symfony Lock + Validator are already transitively present.
# Make sure to enable the lock recipe:
docker compose exec api composer require symfony/lock
# Then in config/packages/lock.yaml:
#   framework:
#     lock: '%env(REDIS_URL)%'
```

```bash
# Python side (embedder/) — append to requirements.txt:
#   rembg==2.0.75
#   onnxruntime==1.20.1
docker compose build embedder
```

No PWA dependency additions for this concern (the editor is built on existing Vuetify primitives — see ARCHITECTURE.md).

---

## Alternatives Considered

| Recommended | Alternative | When the Alternative is Better | Why we picked the recommended |
|-------------|-------------|------------|-------------------------------|
| `imagine/imagine` | `intervention/image ^4.1.2` | Greener API surface, libvips driver available, more active development in 2026. Pick it for a *greenfield* project that wants fluent chains. | PROJECT.md already commits to Imagine ("Imagine pour transformations classiques"). Both call ImageMagick under the hood — quality identical. Switching would burn the existing key decision without gain. |
| `imagine/imagine` | `spatie/image ^3.x` | Friendlier API, ships GD + Imagick auto-detection, nice for one-off scripts | Spatie/image is a thin wrapper over GD/Imagick with fewer per-step primitives (no fine-grained `Point`/`Box` API). Worse fit for our "step handler with discrete params" model. |
| `imagine/imagine` (Imagick driver) | Raw `ext-imagick` / `ext-gd` calls | Maximum control, zero abstraction overhead | We'd reinvent Imagine's primitives. Not worth it for the few % perf. |
| `rembg` + `isnet-general-use` | `BackgroundMattingV2`, `MODNet`, `BiRefNet` (raw torch) | If quality on hair/fur becomes blocking | Each requires hand-rolled inference code, torch dependency, and a model loader. `rembg` is a one-import wrapper around the same families. |
| `rembg` | Remove.bg / Cloudinary / Bria.ai SaaS API | Zero infra, instant SOTA quality | Recurring cost per image, data egress, vendor lock-in, GDPR review needed. Out-of-scope per "Webfacto cadrage". |
| Symfony Validator on per-step DTOs | **JSON Schema (`opis/json-schema`)** validating raw `parameters` JSON | If steps were user-defined / plugin-loaded at runtime with no PHP code | Adds a 2nd validation language, error messages less idiomatic, no IDE autocomplete. Our steps are a *closed enum* shipped with the app — PHP classes are the natural source of truth. |
| Symfony Validator | Custom validators inline in handlers | Trivial 1-field steps | Diverges from API Platform's standard 422 contract, harder to test. |
| Symfony Lock + Redis | Database advisory locks (`pg_advisory_lock`) | If we ever wanted Postgres as the single coordination point | Redis is already on the lock path (Messenger). Postgres locks are more expensive and tie cache regen to DB pool availability. |
| Symfony Lock | `redis-semaphore` style raw `SET NX PX` in PHP | If we wanted to skip the abstraction | Symfony Lock already does this *plus* auto-expire, fencing tokens, blocking/non-blocking acquire, and multi-store quorum. |
| Symfony HTTP cache primitives | `FOSHttpCacheBundle` | If we needed tag-based purge across a CDN | Overkill — our cache is *content-addressed* (hash of steps in URL), so we never need to purge: a step change generates a new URL. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `gd` driver for Imagine in prod | No AVIF encode, weak WebP quality, broken ICC profile handling, EXIF orientation must be done by hand. | Imagine + Imagick driver with IM 7. |
| `rembg[gpu]` extra | Pulls `onnxruntime-gpu` and CUDA libs (~3 GB), unused without a GPU node. | `rembg` + `onnxruntime` (CPU) explicit pin. |
| `bria-rmbg` model | Non-commercial license; incompatible with Vente-Unique's commercial use. | `isnet-general-use`. |
| `birefnet-*` models in MVP | 2-3× memory footprint of isnet for marginal quality on studio product shots. | Add as an optional "quality" tier later if customer feedback demands it. |
| Storing the raw `parameters` JSON without schema | Silent corruption when a step type evolves; pain in migrations. | One PHP DTO per step type, validated through `Symfony\Validator`, persisted as JSON column. |
| Recomputing ETag on each request from S3 body | Extra S3 GET, defeats the cache. | Use the **steps-hash + asset id + variant ext** as the ETag — already content-addressed. |
| `must-revalidate` on `/t/*` responses | Forces clients/CDNs to re-validate every time. | `Cache-Control: public, max-age=31536000, immutable` — URLs are versioned by hash, never mutate. |
| Synchronous bg removal in the HTTP request hot path beyond a sane budget | First request can stall for 1-3 s on isnet | Hold the **Symfony Lock**, compute synchronously, but cap at a hard timeout; on timeout return `503` with `Retry-After` and dispatch a Messenger job to warm up. |

---

## Stack Patterns by Variant

**If the catalog grows past ~5 M distinct variants/year:**
- Promote variant warmup to **always-async** (Messenger) and serve a "low-quality placeholder" on first hit.
- Add a Cloudfront/Fastly CDN in front of `/t/*` — the `immutable` headers we already emit make this drop-in.

**If hair/fur cutout quality becomes blocking:**
- Add a second rembg session with `birefnet-general` as a *quality tier*, selected by a step parameter `{"step": "remove_background", "params": {"quality": "high"}}`.
- Budget +1.5 GB RAM on the embedder container.

**If we ever need GPU for bg removal at scale:**
- Split the `embedder` service into `embedder-cpu` (CLIP, batch) and `embedder-gpu` (rembg, single-image latency). `rembg[gpu]` becomes the right extra.
- Out of scope for v1.0.

---

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| `imagine/imagine ^1.5.2` | PHP ^8.1 (works on 8.4) + `ext-imagick ^3.7` | Imagick 3.7+ adds AVIF support tied to IM 7.0.25+. |
| `ext-imagick ^3.7` | ImageMagick 7.1+ | Don't pair with IM 6 — AVIF/HEIC will silently degrade. |
| `rembg 2.0.75` | Python `>=3.11,<3.14`, `onnxruntime >=1.18,<1.21` | Our base is `python:3.11-slim` — green. |
| `rembg 2.0.75` + `sentence-transformers 3.3.1` | Same venv — yes | No shared deep-learning runtime (ONNX vs torch). Verified compat by community usage; CI a smoke test is mandatory in Phase 1. |
| `onnxruntime 1.20.1` + `torch 2.12.0+cpu` | Same venv — yes | Both link `libgomp1` (already installed). Pin `OMP_NUM_THREADS` to avoid oversubscription. |
| `symfony/lock 8.0.*` + `predis/predis ^3.0` | yes | Lock supports both `predis` and `phpredis`. We're on predis. |

---

## Concrete integration notes (for the roadmapper)

1. **HTTP cache wiring** — no package needed. In the `/t/{code}/{id}.{ext}` controller:
   ```php
   $etag = '"' . substr($stepsHash, 0, 16) . '-' . $assetId . '-' . $ext . '"';
   $response->setEtag($etag, true);            // weak ETag
   $response->setPublic();
   $response->setMaxAge(31_536_000);
   $response->setImmutable();
   if ($response->isNotModified($request)) {
       return $response;                        // 304, no body
   }
   ```

2. **Lock key shape** — `transform:{code}:{stepsHash}:{assetId}:{ext}`. Use **non-blocking** `acquire(false)`; on miss, double-check S3 then 503 with `Retry-After: 1` if still computing.

3. **Validator wiring** — model each step as a DTO:
   ```php
   #[StepType('resize')]
   final class ResizeStepParams {
       #[Assert\Positive] #[Assert\LessThanOrEqual(8000)] public int $width;
       #[Assert\Positive] #[Assert\LessThanOrEqual(8000)] public int $height;
       #[Assert\Choice(['fit','fill','crop'])] public string $mode = 'fit';
   }
   ```
   A `StepHandlerRegistry` resolves the DTO class from the `step` discriminator, deserializes the JSON via `symfony/object-mapper` (already in composer.json — nice), and runs the validator.

4. **Imagine handlers** — one class per step type implementing `StepHandlerInterface { apply(ImageInterface, mixed $params): ImageInterface }`. Pipeline composition is just a `foreach` + `reduce`.

5. **Background removal** — the PHP handler sends the intermediate buffer to `POST embedder:8000/rmbg` (multipart), receives a PNG with alpha, hands it back to Imagine as the next step's input.

---

## Sources

- **PROJECT.md / CLAUDE.md** (in repo) — already commits to Imagine, bgremover-in-embedder, hash-versioned cache. HIGH.
- **Packagist `imagine/imagine`** — version 1.5.2, 74M installs, MIT. HIGH.
- **Packagist `intervention/image`** — version 4.1.2 (2026-05-23), considered as alternative. HIGH.
- **PyPI `rembg` 2.0.75** (2026-04-08) — Python 3.11–3.13, ONNX Runtime backend, model catalog. HIGH.
- **rembg GitHub (`danielgatis/rembg`)** — model list, license per model, install extras (`[cpu]`/`[gpu]`/`[rocm]`). HIGH.
- **symfony.com/doc/current/lock.html** — Redis store config, non-blocking acquire pattern for cache-stampede prevention. HIGH.
- **symfony.com/doc/current/http_cache/validation.html** — `setEtag` / `isNotModified` / `setImmutable` flow. HIGH (from prior knowledge of Symfony HttpFoundation, consistent with all 7.x docs).
- **rembg + sentence-transformers coexistence** — no single authoritative doc; conclusion drawn from the fact that rembg depends on `onnxruntime` (verified) and not torch. MEDIUM — recommend a smoke test in Phase 1.
- **Model RAM figures for ONNX bg-removal** — order-of-magnitude estimates from public benchmarks; exact RSS in our exact container is **untested**. MEDIUM — must be re-measured during the embedder build.

---

## Gaps / Flag for Phase planning

- **Exact RAM ceiling of the embedder container with CLIP + isnet loaded simultaneously** — must be measured in Phase "embedder upgrade", not assumed. If > 1.5 GB RSS sustained, split into two FastAPI workers behind one Uvicorn or two services.
- **Imagick AVIF encode quality vs file size** — IM 7's AVIF defaults are conservative; expect a `-quality 60` tuning pass.
- **CDN choice in front of `/t/*`** — out of scope here but the immutable-hash URL scheme is CDN-agnostic.

> Reminder per organisation: avant tout démarrage en intégration au SI ou exposition publique en production (route `/t/*`, coût S3 des variantes, ressources du service `embedder`), ce cas d'usage doit être validé par la Webfacto (cadrage besoin, faisabilité, sécurité, priorisation).

---
*Stack research for: Asset Transformations milestone (v1.0)*
*Researched: 2026-05-26*
