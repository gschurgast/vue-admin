# Architecture — Asset Transformations Integration

**Project:** Antigravity — v1.0 Asset Transformations milestone
**Researched:** 2026-05-26
**Confidence:** HIGH (decisions ground in existing files of this repo)

Scope: integrating a transformation pipeline (resize / crop / rotate / format / add_bg / remove_bg) on top of the existing Asset domain, exposed via a public cacheable URL `/t/{code}/{id}.{ext}`. The architecture below is opinionated and references existing code paths.

---

## 1. Storage Layout for Variants

### Recommended layout

```
{bucket}/
  originals/                         # (existing, unchanged) original assets
    0/42.jpg
    15/15234.mp4
  transformations/
    {transformationId}-v{hash8}/     # version segment = sha1(steps_json)[:8]
      0/42.webp
      0/42.jpg
      15/15234.avif
```

- `{transformationId}` keeps the prefix stable across version bumps (good for IAM/audit by transformation).
- `v{hash8}` makes the version **part of the prefix**, not the leaf — that way old versions can be purged with a single S3 prefix delete (`aws s3 rm --recursive transformations/12-v3a91c87f/`).
- `{shard}` = `floor(assetId / 1000)` — same convention as `AssetUploader` so the math is centralized in one helper.
- Final extension is dictated by the URL extension (`.webp`, `.jpg`, …), not by the source — one transformation can store **several variants per asset** under the same version prefix.

### Why not other layouts

| Alternative | Reject because |
|-------------|----------------|
| `transformations/{code}/...` | `code` is user-mutable; renaming would orphan everything. Use the immutable numeric id. |
| Flat `v{hash}/{assetId}.{ext}` with no transformationId | Hash collisions across transformations possible; harder to GC per-transformation. |
| Version as suffix `{id}.v3a91c87f.ext` | Can't bulk-delete a version with one prefix op; complicates listing for GC. |

### Interaction with S3 prefix scaling

S3 partitions on key prefix. With `transformations/{id}-v{hash}/{shard}/...` the `{shard}` segment provides natural randomness for request distribution at scale. No additional hash prefix needed until > ~3500 PUT/s sustained — well beyond the milestone's scope.

### Flysystem listing

Listings happen only in two places:

1. **GC command** (`bin/console app:asset-transformations:gc`) — `listContents('transformations/{id}-v{oldHash}/', deep: true)` returns the dead prefix, then `deleteDirectory()`.
2. **Variant existence check** — never via `listContents`, always via `fileExists($expectedKey)` on the deterministic path (cheap, single HEAD on S3).

### Orphan GC strategy

Orphans = variant prefixes whose `{transformationId}-v{hash}` is not the current version. Two triggers:

- **On version bump** (Doctrine listener — see §6) — dispatch `PurgeTransformationVersionMessage($transformationId, $oldHash)` to the `async` transport. Handler calls `$storage->deleteDirectory("transformations/{$id}-v{$oldHash}")`.
- **Nightly sweep** (Symfony Console command + cron / Messenger scheduler) — list `transformations/`, compare each `{id}-v{hash}` prefix against the current hash from DB; delete the orphans. Defensive net for races / partial deletes.

**New helper:** `App\Service\AssetTransformation\VariantStorageKey` (static `forVariant(int $transformationId, string $hash, int $assetId, string $ext): string`) — single source of truth, mirrors the implicit logic in `AssetUploader::computeS3Key()`.

---

## 2. Public Route Flow

### Controller location and shape

`api/src/Controller/AssetTransformationController.php` — plain Symfony `#[AsController]`, **not** an API Platform operation.

Rationale:
- API Platform resources live under `/api/*` and the JWT firewall (`security.yaml` line `pattern: ^/api`). The public route MUST be at the application root (`/t/{code}/{id}.{ext}`) to escape that pattern cleanly without weakening it.
- The response is a binary stream, not Hydra/JSON-LD — API Platform adds nothing here.

### Bypassing JWT cleanly

Add to `api/config/packages/security.yaml`:

```yaml
access_control:
    - { path: ^/t/, roles: PUBLIC_ACCESS }   # public transformation route
    - { path: ^/api/docs, roles: PUBLIC_ACCESS }
    - { path: ^/api/login, roles: PUBLIC_ACCESS }
    - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }
```

The `^/api` firewall pattern already does **not** match `/t/`, so no new firewall block is needed; `access_control` just makes the public exposure explicit.

### Flow

```
GET /t/{code}/{id}.{ext}
  │
  ▼
AssetTransformationController::serve($code, $id, $ext)
  1. Resolve transformation by code (404 if missing/disabled)
  2. Validate ext ∈ transformation.allowedExtensions (404 otherwise — no surprise formats)
  3. Compute variantKey = VariantStorageKey::forVariant(t.id, t.versionHash, id, ext)
  4. if $storage->fileExists(variantKey)
       → StreamedResponse(readStream(variantKey)) with long Cache-Control + ETag
  5. else
       → acquire Redis lock "tlock:{t.id}:{hash}:{assetId}:{ext}" (LockFactory + RedisStore, TTL 30s)
       → if lock acquired:
            $pipelineRunner->execute(asset, transformation, ext, variantKey)
            release lock
            stream the result
       → if lock NOT acquired (someone else is generating):
            short poll loop (sleep 200ms × max 30) checking fileExists
            then stream — or 503 if still missing after timeout
```

### Why synchronous-first (not 202 + redirect)

The existing system already runs everything async via Messenger for *embedding* (a slow ML call). Transformations are different:
- Imagine resize/crop/rotate: typically < 200ms — sync is fine.
- remove_bg via embedder: 1-3s — still acceptable in sync with a Redis lock and reasonable HTTP timeout (e.g. 10s).
- 202 + redirect breaks `<img src>` semantics. Browsers don't retry a 202 on an image tag — the user sees a broken image.

For pre-warming popular variants, a **separate async path** exists: `WarmupTransformationVariantMessage($assetId, $transformationId, $ext)` dispatched by:
- product editor on save (best-effort UX),
- an admin "warm all" button per transformation.

The handler reuses the same `PipelineRunner` service — same code path, just invoked from a Messenger handler instead of the controller.

### Cache headers

```
Cache-Control: public, max-age=31536000, immutable
ETag: "{transformationId}-{versionHash}-{assetId}-{ext}"
```

The version is baked into the URL via the `transformationId-v{hash}` prefix — but the URL itself is `/t/{code}/{id}.{ext}` (no hash). To get cache-busting on version change, the controller MUST send `Cache-Control: public, max-age=60, must-revalidate` instead, OR the frontend must append `?v={hash}` to URLs. **Decision: append `?v={hash}` to URLs from the PWA** — keeps the route clean and lets CDNs treat each version as a different object. The controller ignores the query string for routing but echoes it into the ETag.

---

## 3. Pipeline Execution Pattern

| Mode | Pros | Cons | Verdict |
|------|------|------|---------|
| **Sync on first request + Redis lock** | Simple, no broken-image UX, reuses existing Symfony Lock + Redis | Cold first hit is slow; ties up FrankenPHP worker | ✅ **Chosen for cold path** |
| 202 + redirect + async | Frees PHP worker quickly | Breaks `<img src>`; complex client retry | ❌ |
| Always-async warmup, 404 until ready | Predictable load | Requires pre-listing every (asset × transformation × ext) combo; broken UX on miss | ❌ |
| Hybrid: sync cold path + async warmup | Best of both | More code, but cleanly separable | ✅ **For known-hot paths** |

### Concrete locking

`Symfony\Component\Lock\LockFactory` is already wireable (Symfony 7.3 standard). Configure a Redis store:

```yaml
# api/config/packages/lock.yaml  (NEW file)
framework:
    lock:
        transformation: '%env(REDIS_URL)%'
```

Then in the controller / runner:

```php
$lock = $this->lockFactory->createLock("tvariant:$tId:$hash:$assetId:$ext", ttl: 30.0);
if (!$lock->acquire(blocking: false)) {
    // someone else is generating — poll fileExists, or 503
}
```

### Messenger integration for warmup

New message + handler:

```
api/src/Message/WarmupTransformationVariantMessage.php
api/src/MessageHandler/WarmupTransformationVariantHandler.php
```

Routed to the existing `async` transport (no new transport needed — see `config/packages/messenger.yaml`). The existing `worker` container already consumes `async`, so **no docker-compose change** is required for this part.

---

## 4. Step Handler Registry

### Contract

```php
// api/src/Service/AssetTransformation/Step/StepHandlerInterface.php
namespace App\Service\AssetTransformation\Step;

use Imagine\Image\ImageInterface;

interface StepHandlerInterface
{
    /** Backed-enum value matching AssetTransformationStep.type (e.g. 'resize', 'remove_bg'). */
    public static function supportedType(): string;

    /**
     * Apply this step in-place and return the (possibly new) image instance.
     * For steps that do not operate on raster data (e.g. format_convert is
     * handled at the end), return $image unchanged.
     *
     * @param array<string,mixed> $params   AssetTransformationStep.params
     */
    public function apply(ImageInterface $image, array $params): ImageInterface;
}
```

### Tagged services + registry

```yaml
# api/config/services.yaml — add at the bottom
services:
    _instanceof:
        App\Service\AssetTransformation\Step\StepHandlerInterface:
            tags: ['app.transformation.step_handler']

    App\Service\AssetTransformation\StepHandlerRegistry:
        arguments:
            $handlers: !tagged_iterator { tag: 'app.transformation.step_handler', index_by: 'type' }
```

Each handler declares `#[AutoconfigureTag('app.transformation.step_handler', ['type' => 'resize'])]` or, simpler, the registry resolves by calling `::supportedType()` once at construction.

### Handler classes (new)

```
api/src/Service/AssetTransformation/Step/ResizeHandler.php
api/src/Service/AssetTransformation/Step/CropHandler.php
api/src/Service/AssetTransformation/Step/RotateHandler.php
api/src/Service/AssetTransformation/Step/FormatConvertHandler.php   # may be a no-op step; conversion driven by URL ext
api/src/Service/AssetTransformation/Step/AddBackgroundHandler.php
api/src/Service/AssetTransformation/Step/RemoveBackgroundHandler.php
```

### PipelineRunner

```
api/src/Service/AssetTransformation/PipelineRunner.php
```

Responsibilities:
1. Load source bytes via Flysystem (`assets.storage`).
2. Open with Imagine (`new \Imagine\Gd\Imagine()` or Imagick — Imagick recommended for AVIF / better SVG handling; Imagine wraps both).
3. Iterate `transformation.steps` (already ordered by `position`), calling `$registry->get($step->type)->apply($image, $step->params)`.
4. Encode to the requested extension (final `$image->save()` / `->get($format)`).
5. Stream-write to Flysystem under the variant key.

### remove_bg handler — calling the embedder

`RemoveBackgroundHandler` doesn't speak Imagine to remove the background — it ships the bytes out to the embedder service, which has the ML model. Pattern mirrors `App\Service\Asset\AssetEmbedder` (existing).

```php
// api/src/Service/AssetTransformation/Step/RemoveBackgroundHandler.php
public function __construct(
    private readonly HttpClientInterface $client,
    #[Autowire('%env(EMBEDDER_URL)%')] private readonly string $embedderUrl,
) {}

public function apply(ImageInterface $image, array $params): ImageInterface
{
    $png = $image->get('png');                      // rembg expects RGBA-friendly input
    $response = $this->client->request('POST', "{$this->embedderUrl}/remove-background", [
        'headers' => ['Content-Type' => 'application/octet-stream'],
        'body'    => $png,
        'timeout' => 30,                            // u2net cold start can be ~5s
        'max_duration' => 60,
    ]);
    if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException('Background removal failed: HTTP '.$response->getStatusCode());
    }
    return $this->imagine->load($response->getContent());   // PNG with alpha
}
```

Retries: rely on Symfony HttpClient's `RetryableHttpClient` decorator wired in `services.yaml` (3 retries, exponential backoff, only on 5xx + connection errors). Do NOT retry inside the controller — let it fail fast; the warmup Messenger path retries via Messenger's own retry strategy (`config/packages/messenger.yaml`).

---

## 5. Embedder Service Extension

### Add to `embedder/app.py`

```python
# new import
from rembg import remove, new_session
_rembg_session = None

def get_rembg_session():
    global _rembg_session
    if _rembg_session is None:
        log.info("Loading rembg u2net session ...")
        _rembg_session = new_session("u2net")     # ~170 MB
        log.info("rembg session loaded.")
    return _rembg_session

@app.post("/remove-background")
async def remove_background(request: Request) -> Response:
    raw = await request.body()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty body.")
    session = get_rembg_session()
    out = remove(raw, session=session)            # returns PNG bytes with alpha
    return Response(content=out, media_type="image/png")
```

### `requirements.txt` additions

```
rembg==2.0.59          # pure-python wrapper, ONNX runtime under the hood
onnxruntime==1.20.1    # CPU-only
```

(No `torch` change — rembg uses ONNX, independent of the torch already installed for CLIP.)

### Dockerfile additions

```dockerfile
# Pre-download u2net weights at build time (same pattern as CLIP)
ENV U2NET_HOME=/app/.cache/u2net
RUN python -c "from rembg import new_session; new_session('u2net')"
```

### Memory & process model

- CLIP ViT-B/32 (sentence-transformers, fp32 CPU): ~600 MB RSS once loaded.
- rembg u2net (ONNX CPU): ~170 MB RSS.
- Combined: ~770 MB plus working memory per request (image tensors). One uvicorn worker is fine; **do not** scale uvicorn workers in-process (`--workers N`) because each forks the model in memory. Scale by adding replicas of the `embedder` container in `docker-compose.yml` instead.

**Decision: single FastAPI process, both models in memory.** Justifications:
1. Both endpoints are CPU-bound and serialised by the GIL anyway — separating into two processes wouldn't increase throughput on a single container.
2. Avoids a second container and a second image build (~600 MB Hugging Face weights + ONNX runtime).
3. Matches `PROJECT.md` key decision: "Éviter un 3e container ML, mutualiser ressources."

If RAM becomes a constraint on the host: split into `embedder-clip` and `embedder-bgremove` services and use a single env var (`BGREMOVE_URL`) in the API.

### Health check

Extend `/health`:

```python
@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "models": {
            "clip": {"name": MODEL_NAME, "loaded": _model is not None},
            "rembg": {"name": "u2net", "loaded": _rembg_session is not None},
        },
        "dim": EMBEDDING_DIM,
    }
```

The Dockerfile `HEALTHCHECK` only asserts HTTP 200 on `/health`, so no change needed there. But **important**: the `@app.on_event("startup")` hook currently warms only CLIP. Add `get_rembg_session()` to startup if cold-start latency on `/remove-background` is unacceptable; otherwise leave lazy to keep boot fast.

---

## 6. Cache Invalidation Hooks

### Where to listen

Use a **Doctrine event subscriber**, not an API Platform state processor.

Rationale:
- API Platform processors only fire on operations declared on the resource; admin CLI fixtures or manual `EntityManager::flush()` would bypass them, leaving stale hashes.
- Doctrine `onFlush` fires for every persistence path — safe net.

### New listener

```
api/src/EventListener/AssetTransformationVersioningListener.php
```

```php
#[AsDoctrineListener(event: Events::onFlush)]
class AssetTransformationVersioningListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $touched = [];   // transformationId => true

        foreach ($uow->getScheduledEntityUpdates() + $uow->getScheduledEntityInsertions() + $uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AssetTransformationStep) {
                $touched[$entity->getTransformation()->getId()] = true;
            } elseif ($entity instanceof AssetTransformation && $uow->isScheduledForUpdate($entity)) {
                $changes = $uow->getEntityChangeSet($entity);
                // Only recompute if something that affects the pipeline changed
                if (isset($changes['allowedExtensions']) /* or other relevant cols */) {
                    $touched[$entity->getId()] = true;
                }
            }
        }

        foreach (array_keys($touched) as $tId) {
            $t = $em->find(AssetTransformation::class, $tId);
            $oldHash = $t->getVersionHash();
            $newHash = $this->hasher->compute($t);    // sha1(json_encode(steps params, sorted))
            if ($newHash !== $oldHash) {
                $t->setVersionHash($newHash);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(AssetTransformation::class), $t);
                // Dispatch GC AFTER the flush completes — postFlush would re-enter UoW.
                $this->pendingPurges[] = new PurgeTransformationVersionMessage($tId, $oldHash);
            }
        }
    }

    #[AsDoctrineListener(event: Events::postFlush)]
    public function postFlush(): void
    {
        foreach ($this->pendingPurges as $msg) { $this->bus->dispatch($msg); }
        $this->pendingPurges = [];
    }
}
```

### New service

```
api/src/Service/AssetTransformation/VersionHasher.php
```

Pure function over `AssetTransformation` → `string` (8 hex chars of `sha1(canonical_json(steps))`). Canonicalisation: sort steps by `position`, then sort each step's `params` keys recursively.

### GC handler

```
api/src/Message/PurgeTransformationVersionMessage.php
api/src/MessageHandler/PurgeTransformationVersionHandler.php
```

Calls `$storage->deleteDirectory("transformations/{$id}-v{$oldHash}")`. Idempotent (deleteDirectory on missing prefix is a no-op).

---

## 7. Frontend Integration

### Direct `<img src>` — recommended

Public URL → no auth → use the URL directly:

```vue
<img :src="`/t/${transformationCode}/${asset.id}.webp?v=${transformationVersionHash}`" />
```

- Browser handles caching, ETag revalidation, lazy loading (`loading="lazy"`).
- No JS blob plumbing, no memory churn — big win for grid views (`AssetGrid.vue`).
- Frontend gets `transformationVersionHash` from the `AssetTransformation` API resource (expose `versionHash` in the `asset_transformation:read` serialization group).

### New composable — thin wrapper

```
pwa/src/composables/useTransformedUrl.ts
```

```ts
import { computed, type Ref } from 'vue'

export function useTransformedUrl(
  asset: Ref<{ id: number } | null>,
  transformation: Ref<{ code: string; versionHash: string } | null>,
  ext: Ref<string> | string = 'webp',
) {
  return computed(() => {
    if (!asset.value || !transformation.value) return null
    const e = typeof ext === 'string' ? ext : ext.value
    return `/t/${transformation.value.code}/${asset.value.id}.${e}?v=${transformation.value.versionHash}`
  })
}
```

### Keep `useAssetUrl` for the original

`pwa/src/composables/useAssetUrl.ts` stays untouched — used by `AssetShow.vue` and the admin previews of the original (still JWT-protected). The two composables coexist with clear roles:

| Composable | Endpoint | Auth | Use case |
|------------|----------|------|----------|
| `useAssetUrl` | `/api/assets/{id}/content` | JWT (blob fetch) | Admin show, admin grids, original |
| `useTransformedUrl` | `/t/{code}/{id}.{ext}` | Public | Anywhere a transformation is referenced (product cards, public site, exports) |

### Editor preview

The drag-and-drop step editor needs a live preview that updates as the user edits, *before* persisting (so no version hash yet). Two options:

1. **Dry-run endpoint**: `POST /api/asset_transformations/preview` accepting `{ assetId, steps[] }`, executing the pipeline ad-hoc, returning the bytes inline (JWT-protected, never cached on S3). Recommended — same `PipelineRunner` service, just bypasses storage.
2. Save → reload — too slow for UX.

Implementation: new DTO `ApiResource\AssetTransformationPreviewRequest` + processor `State\AssetTransformationPreviewProcessor` → calls `PipelineRunner::executeInMemory(asset, stepsArray, ext): string` (binary) → returns `Response` with the image. Use a short Redis-based rate limit per user (5 req/s) to avoid abuse.

---

## Build Order (for the roadmapper)

Strict dependencies; later items depend on earlier ones:

1. **Entities** — `AssetTransformation`, `AssetTransformationStep` + migrations (FK, ordering, unique `code`, `versionHash` column, `allowedExtensions` json). API Platform CRUD auto-exposed.
2. **VersionHasher + versioning listener** — must exist before any variant is stored, otherwise hashes diverge.
3. **VariantStorageKey helper** + Flysystem read/write smoke test (existing `assets.storage`).
4. **StepHandlerInterface + registry + Imagine-based handlers** (resize, crop, rotate, format_convert, add_background). These are pure PHP, testable in isolation.
5. **PipelineRunner** orchestrating handlers end-to-end.
6. **Embedder `/remove-background` endpoint** + rembg dep + Dockerfile model pre-pull. Independent track, can run in parallel with steps 4–5.
7. **RemoveBackgroundHandler** — depends on (6) being deployed.
8. **Public controller `/t/{code}/{id}.{ext}`** + security.yaml access_control + Redis lock + `lock.yaml` config.
9. **Warmup async path** — `WarmupTransformationVariantMessage` + handler routed to `async` transport (no docker-compose change; existing `worker` consumes it).
10. **Orphan GC** — `PurgeTransformationVersionMessage` + handler + nightly Console command.
11. **Frontend**: `useTransformedUrl` composable + step editor + preview DTO/processor.
12. **Observability**: structured logs on cold-path generation latency, lock contention, embedder timeouts; optional Prometheus counter.

---

## Files: new vs touched

### New (PHP)

```
api/src/Entity/AssetTransformation/AssetTransformation.php
api/src/Entity/AssetTransformation/AssetTransformationStep.php
api/src/Controller/AssetTransformationController.php
api/src/EventListener/AssetTransformationVersioningListener.php
api/src/Service/AssetTransformation/PipelineRunner.php
api/src/Service/AssetTransformation/VariantStorageKey.php
api/src/Service/AssetTransformation/VersionHasher.php
api/src/Service/AssetTransformation/StepHandlerRegistry.php
api/src/Service/AssetTransformation/Step/StepHandlerInterface.php
api/src/Service/AssetTransformation/Step/ResizeHandler.php
api/src/Service/AssetTransformation/Step/CropHandler.php
api/src/Service/AssetTransformation/Step/RotateHandler.php
api/src/Service/AssetTransformation/Step/FormatConvertHandler.php
api/src/Service/AssetTransformation/Step/AddBackgroundHandler.php
api/src/Service/AssetTransformation/Step/RemoveBackgroundHandler.php
api/src/Message/WarmupTransformationVariantMessage.php
api/src/Message/PurgeTransformationVersionMessage.php
api/src/MessageHandler/WarmupTransformationVariantHandler.php
api/src/MessageHandler/PurgeTransformationVersionHandler.php
api/src/ApiResource/AssetTransformationPreviewRequest.php
api/src/State/AssetTransformationPreviewProcessor.php
api/src/Command/PurgeOrphanTransformationVariantsCommand.php
api/config/packages/lock.yaml
api/migrations/Version{ts}_AssetTransformations.php
```

### Touched (PHP)

```
api/config/packages/security.yaml         # add ^/t/ PUBLIC_ACCESS
api/config/services.yaml                  # tagged_iterator for step handlers + RetryableHttpClient
api/composer.json                         # add imagine/imagine (or use Imagick directly)
```

### New (Python embedder)

```
embedder/app.py        # add /remove-background endpoint, extend /health
embedder/requirements.txt   # add rembg, onnxruntime
embedder/Dockerfile    # pre-pull u2net weights
```

### New (PWA)

```
pwa/src/composables/useTransformedUrl.ts
pwa/src/config/AssetTransformation.json
pwa/src/components/assetTransformation/edit/StepEditor.vue
pwa/src/components/assetTransformation/edit/StepEditor*.vue (per type)
pwa/src/components/assetTransformation/show/AssetTransformationShow.vue
```

### Touched (PWA)

```
pwa/src/types/api.d.ts        # regenerated via `make generate-types` after API changes
```

`useAssetUrl.ts` is **not** modified.

### Docker

No `docker-compose.yml` change for the milestone — the existing `worker` consumes the `async` transport and will pick up the two new messages automatically; the existing `embedder` container just gains a new endpoint. Memory ceiling on `embedder` may need to be raised in prod orchestration if it was previously sized at 700 MB.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Hash the URL, not the version
**What goes wrong:** putting the hash in the URL (`/t/{code}-{hash}/{id}.{ext}`).
**Why bad:** every version bump invalidates every link in product DBs / emails / CDNs / marketplaces.
**Instead:** stable URL `/t/{code}/{id}.{ext}` + `?v={hash}` query param. The code is the contract; the hash is a cache-buster.

### Anti-Pattern 2: Listing S3 to check existence
**What goes wrong:** calling `listContents` to check if a variant exists.
**Why bad:** O(n) on prefix size, expensive on S3, dog-slow with many variants.
**Instead:** deterministic key + `fileExists()` (single HEAD request).

### Anti-Pattern 3: Running the pipeline inside the Doctrine listener
**What goes wrong:** computing variants in `onFlush`.
**Why bad:** blocks the request; if the transformation fails, the save fails.
**Instead:** listener only updates `versionHash` + dispatches `PurgeTransformationVersionMessage` for the old hash. Variants regenerate lazily on first hit (or via explicit warmup).

### Anti-Pattern 4: Two FastAPI workers in one container with `--workers 2`
**What goes wrong:** uvicorn forks → CLIP + u2net loaded twice → OOM.
**Instead:** single worker; scale horizontally via docker-compose replicas if needed.

### Anti-Pattern 5: Reading the asset bytes into memory in the controller for streaming
**What goes wrong:** `$contents = $storage->read($key); return new Response($contents);` — loads a full file (could be 20 MB) per request.
**Instead:** `StreamedResponse(fn () => fpassthru($storage->readStream($key)))` — same pattern as the existing `AssetController::content()`.

---

## Scalability Notes

| Concern | At 100 assets × 5 transformations | At 100k × 20 | At 1M × 20 |
|---------|-----------------------------------|--------------|------------|
| Variant storage | 500 objects, trivial | 2M objects in S3 — fine, but prefix layout matters | 20M objects — keep prefix `{shard}` randomness; consider S3 Inventory + Athena for GC reporting |
| Cold pipeline latency | One-off, ignorable | Same; lock contention only on viral assets | Add CDN in front of `/t/` (CloudFront with origin = ALB → api container) — origin shield collapses cold misses |
| Embedder throughput | 1 replica | 2–3 replicas + queue | Dedicated GPU node OR split CLIP/rembg containers |
| GC | Manual / nightly cron | Nightly Messenger job | S3 lifecycle rule on `transformations/{id}-v*/` older than 30 days + DB-driven explicit purge for active versions |

---

## Sources

- Existing code (HIGH confidence):
  - `api/src/Controller/AssetController.php` — current routing & streaming pattern
  - `api/src/MessageHandler/ComputeEmbeddingHandler.php` — current Messenger + Flysystem pattern
  - `api/src/Service/Asset/AssetEmbedder.php` — HTTP-to-embedder client to mirror
  - `embedder/app.py`, `embedder/Dockerfile`, `embedder/requirements.txt` — service to extend
  - `docker-compose.yml` — current service topology (no change needed)
  - `api/config/packages/security.yaml` — firewall pattern `^/api`, public route fits at `^/t/`
  - `pwa/src/composables/useAssetUrl.ts` — composable to coexist with, not modify
  - `.planning/PROJECT.md` — milestone goals & constraints
- Symfony 7.3 standard practice (HIGH confidence from training, broadly verifiable):
  - `Symfony\Component\Lock\LockFactory` + Redis store (`framework.lock` config)
  - `RetryableHttpClient` decorator
  - Doctrine `#[AsDoctrineListener]` + `onFlush` semantics
  - Messenger tagged transport routing
- rembg (MEDIUM confidence — verify versions at implementation time):
  - `rembg` Python package wraps u2net via ONNX; `new_session('u2net')` is the documented entry point.
  - Implementation phase should `pip show rembg` to confirm the current major and check the model download path.

**Gaps to verify at implementation time:**
- Exact Imagine vs Imagick API for AVIF encoding (Imagick ≥ 7.0.10-58 required).
- u2net model size and cold-load time on the prod embedder host (estimates only).
- Whether CloudFront / front CDN is in scope for this milestone (PROJECT.md does not say).
