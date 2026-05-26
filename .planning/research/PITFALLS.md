# Pitfalls Research

**Domain:** Public image transformation pipeline added to existing Symfony/API Platform + Vue admin (Antigravity) — S3 cache, Imagine handlers, rembg via the existing CLIP `embedder` service
**Researched:** 2026-05-26
**Confidence:** HIGH on Imagine/rembg/S3 mechanics (verified against project CLAUDE.md, common knowledge of Imagick/Pillow/rembg); MEDIUM on exact phase boundaries (depends on final roadmap split)

Pitfalls below are scoped to **adding** transformations to the existing system. They assume the pre-existing constraints from `CLAUDE.md` (JWT-protected `/api/assets/{id}/content`, Flysystem dev FS vs prod S3, Messenger over Redis Streams, a single Python `embedder/` already running CLIP ViT-B/32 in FastAPI).

Phase labels reference the milestone phase that should own the mitigation. Final names may shift; the binding is by topic, not literal name:

- **P-Schema** — entities `Transformation`/`TransformationStep`, Doctrine, API Platform CRUD
- **P-Hash** — versioning hash & cache key derivation
- **P-Imagine** — Imagine pipeline & step handlers (resize/crop/rotate/format)
- **P-Embedder** — rembg integration into the existing `embedder/` service
- **P-Route** — public `/t/{code}/{id}.{ext}` controller, locking, headers
- **P-Worker** — async warmup & GC commands
- **P-UI** — drag-and-drop step editor + preview
- **P-Ops** — deployment, lifecycle, observability, rollout

---

## Critical Pitfalls

### Pitfall 1: Asset ID enumeration on the public route

**What goes wrong:**
`/t/thumb/1.jpg`, `/t/thumb/2.jpg`, … lets anyone walk the entire asset catalog because `Asset.id` is a sequential bigint. Private/unreleased product photography, NSFW-flagged assets, draft visuals all become scrapable.

**Why it happens:**
The original `/api/assets/{id}/content` is JWT-protected so IDs were never sensitive. The new route is intentionally public for CDN-friendliness, and developers reuse the same numeric id without changing the threat model.

**How to avoid:**
- Add an explicit `Asset.isPublic` boolean (default `false`), required true for `/t/*` to serve. Backfill existing rows to `false` and let users opt-in per asset (or via a flag/collection rule).
- Or: derive the public path component from a non-sequential token — e.g. `Asset.publicSlug` (ULID/short hash) populated lazily, route becomes `/t/{code}/{slug}.{ext}`. Keeps id numeric internally.
- Filter on `AssetFlag` (e.g. asset must carry `validated` flag, never `nsfw` or `internal`).
- 404 (not 403) on missing/unauthorized so probes cannot distinguish "exists but private" from "does not exist".

**Warning signs:**
Logs showing sequential id scans from a single IP, sudden bandwidth spike, presence of unflagged drafts in CDN logs.

**Phase to address:** **P-Route** (route guard) + **P-Schema** (column + flag semantics).

---

### Pitfall 2: Denial-of-wallet via random-extension cache miss

**What goes wrong:**
`/t/thumb/42.jpg`, `/t/thumb/42.JPG`, `/t/thumb/42.jpeg`, `/t/thumb/42.webp?x=1`, `/t/thumb/42.avif`… each forces a synchronous transformation + S3 write. An attacker pinning a random query string can force unbounded transformations and S3 PUTs, burning CPU on the API container and money on S3 + (if any) AVIF encoding (expensive).

**Why it happens:**
"Conversion forced by URL extension" is great UX but also great abuse surface. Query strings are forwarded by some CDN configs and silently bust the cache.

**How to avoid:**
- Whitelist extensions strictly (`['jpg','png','webp','avif']`), 404 on anything else (no `.jpeg`, no uppercase, lowercased server-side).
- **Strip query string before computing cache key** and instruct the CDN/CloudFront to ignore query strings on `/t/*`.
- Rate-limit `/t/*` per IP at the reverse-proxy/CDN level (e.g. 60 req/s/IP) with separate limits for cache-miss vs cache-hit (X-Cache header signaling).
- Add a per-transformation `maxRequestsPerMinute` knob enforced in the controller before the lock is acquired.
- Use a stable **canonical URL** redirect: any non-canonical input (uppercase ext, query string) → 301 to canonical form.

**Warning signs:**
Spike in S3 PUT/GET cost, Messenger queue idle but API CPU pinned, CDN cache hit ratio collapsing below 90%.

**Phase to address:** **P-Route** (validation + canonicalization), **P-Ops** (CDN config, rate limits, alerts on S3 cost).

---

### Pitfall 3: Path traversal via `{code}` or `{ext}` segments

**What goes wrong:**
A naive router builds the cache key as `transformations/{code}-v{hash}/{shard}/{id}.{ext}`. If `code` or `ext` is taken verbatim from the URL, requests like `/t/..%2Fpublic/1.jpg` or extension `..%2F..%2Fetc%2Fpasswd` can poison S3 keys or, worse on the local dev Flysystem adapter, write outside `var/assets`.

**Why it happens:**
Symfony route requirements default to anything-but-slash, but `%2F` decoding plus string concatenation into a Flysystem path is the classic foot-gun.

**How to avoid:**
- Route requirements: `code` must match `[a-z0-9_-]{1,64}`, `ext` must be in the whitelist (regex `(jpg|png|webp|avif)`), `id` must be `\d+`.
- Resolve `Transformation` by `code` via repository — if no match, 404. Never inject the raw `code` from the URL into a storage path; use `transformation.id` (numeric) in the S3 key.
- Reject `%2F`, `..`, leading dots in the controller before any lookup.

**Warning signs:**
404 spikes with unusual URL patterns, Flysystem `UnableToWriteFile` exceptions referencing absolute paths, S3 keys with `../` segments visible in CloudWatch.

**Phase to address:** **P-Route**.

---

### Pitfall 4: SSRF in `add_background` step

**What goes wrong:**
If `add_background` accepts `{ "source": "url", "url": "https://..." }`, an attacker creates a transformation pointing at `http://169.254.169.254/latest/meta-data/iam/security-credentials/` (AWS IMDS) or `http://embedder:8000/internal` and exfiltrates secrets or pivots inside the cluster. Even server-side-only inputs are dangerous because admin UI can be tricked or compromised.

**Why it happens:**
"Background image from URL" feels like a normal feature; HTTP clients follow redirects to private IPs by default.

**How to avoid:**
- **Do not accept arbitrary URLs.** `add_background` source = an `Asset` id (FK), reusing the same auth model as everything else. The UI uploads the background first, then references it.
- If URL must be supported (later), use an allow-list of domains, resolve DNS server-side, reject any answer in RFC1918 / link-local / loopback / IMDS ranges, disable redirects, hard timeout.

**Warning signs:**
Outbound requests from the API container to unexpected hosts (egress logs), `add_background` steps with `url` field instead of asset reference.

**Phase to address:** **P-Schema** (constrain step JSON schema), **P-Imagine** (handler implementation).

---

### Pitfall 5: Cache stampede / thundering herd on first request

**What goes wrong:**
A new variant URL goes viral (newsletter, social share). Hundreds of concurrent requests all miss S3, all start computing the same variant, all write the same key. Memory blows up on the api container, S3 cost is multiplied, and racing writes may corrupt the last byte (especially via Flysystem streamed writes).

**Why it happens:**
"Lock acquired before computing" is easy to write wrong: TTL too short, lock per-request instead of per-variant key, no wait-and-poll for the followers.

**How to avoid:**
- Lock key = sha1(`{transformation.id}:{hash}:{asset.id}:{ext}`). Use Symfony `LockFactory` with the Redis store already present (Messenger uses Redis).
- Followers (`->acquire(false)` fails) → poll `head()` on Flysystem with exponential backoff up to ~2× expected compute time; once present, stream the result; else 503 with `Retry-After`.
- Holder TTL = generous (e.g. 60s for rembg, 10s for simple resize), but **explicitly extend** via `Lock::refresh()` during long ops. Don't pick one short global TTL.
- On crash, finally-release the lock; ensure the lock is auto-expiring so a dead worker doesn't wedge a variant forever.

**Warning signs:**
Bursts of identical PUTs to S3 within seconds, Messenger or PHP-FPM workers pinned during a traffic spike, intermittent truncated images.

**Phase to address:** **P-Route** (lock + follower poll), **P-Worker** (async warmup uses the same lock to prevent worker vs request races).

---

### Pitfall 6: S3 eventual consistency / Flysystem semantic drift

**What goes wrong:**
S3 has been strongly consistent since Dec 2020 for PUT/GET/DELETE on the same key, so this is **mostly** safe. But: (1) Flysystem caches metadata in some adapters, (2) `fileExists()` then `read()` is two calls — between them, a TTL/lifecycle deletion or GC command can remove the file → 500. (3) On the local dev FS adapter, `fileExists()` returns true before `fwrite` is fsync'd, leading to dev-only races.

**Why it happens:**
Devs trust the dev environment, then hit timing issues in prod where multiple worker replicas + concurrent GC run.

**How to avoid:**
- Single call pattern: try `readStream()` and catch `UnableToReadFile`; do not pre-check existence.
- For dev FS, write to `.tmp` sibling and `rename()` (atomic on POSIX) before responding; Flysystem `move()` works.
- GC commands skip files written less than `T` minutes ago (where `T` >> longest expected compute) to avoid racing in-flight writes.

**Warning signs:**
Sporadic 404 on `/t/*` for variants that "should" exist; truncated images served exactly once then OK on retry.

**Phase to address:** **P-Route** (read pattern), **P-Worker** (GC age guard).

---

### Pitfall 7: Animated GIF/WebP losing animation, EXIF rotation, ICC profiles, transparency→black

**What goes wrong:**
Four classic image-conversion bugs that all hit at once when a generic pipeline is built:
1. Animated GIF or animated WebP source → only the first frame survives after Imagine `resize()` / `save()`.
2. iPhone JPEG with `Orientation=6` (90° CW) → resized image looks rotated because EXIF was stripped without applying it.
3. Color-managed JPEG (Adobe RGB or P3) → ICC profile dropped → washed-out or oversaturated on sRGB viewers.
4. PNG with alpha converted to JPEG without explicit background → **black** background (Imagick default), not white.

**Why it happens:**
Imagine's default `save()` does the minimum; it does not auto-rotate, does not preserve ICC, does not flatten alpha, does not preserve multi-frame.

**How to avoid:**
- **Auto-rotate first**: before any geometric op, read EXIF orientation and `->rotate()` accordingly, then strip the EXIF Orientation tag. Imagick has `autoOrient()` since 7.0.16 — guard with a version check.
- **ICC handling**: convert to sRGB explicitly (`Imagick::profileImage('icc', sRGB.icc)` then `Imagick::stripImage()`). Embed a small sRGB profile in the output for color-critical assets, strip for the rest (trade-off documented per transformation).
- **Alpha flatten on JPEG target**: in `format_convert` handler, if source has alpha and target is `jpg`, composite onto a configurable background color (`add_background` step is the supported way; default white if absent).
- **Animated formats**: detect `imagick->getNumberImages() > 1`. Either (a) preserve frames (`coalesceImages` → apply transforms in a loop → `deconstructImages` → `writeImages`), or (b) explicitly drop animation and document this in the transformation editor. Pick per-transformation via a `preserveAnimation` step flag.
- AVIF: confirm Imagick is built with `libheif`/`libaom` (`Imagick::queryFormats('AVIF')`) at boot; if not present, 503 on AVIF requests with a clear error, do not silently fall back.

**Warning signs:**
QA reports "image is sideways on mobile", "thumbnail is black", "logo lost transparency", "GIF doesn't move anymore", "colors look dull on iPad". AVIF requests returning JPEG content-type.

**Phase to address:** **P-Imagine**.

---

### Pitfall 8: Imagine memory exhaustion on huge source images

**What goes wrong:**
A 12000×8000 px DSLR JPEG (~25 MB on disk) decodes to ~290 MB raw in Imagick. Two concurrent requests OOM the FrankenPHP worker. With rembg in the loop, the Python side also balloons. PHP `memory_limit` errors are confusing because Imagick allocates outside PHP's heap.

**Why it happens:**
Source images come from external sources (DAM, suppliers) without a hard size cap.

**How to avoid:**
- Reject sources with `width*height > MAX_PIXELS` (e.g. 50 MP) at the controller, before opening with Imagine.
- Configure Imagick resource limits in php.ini / runtime:
  ```ini
  imagick.resource_limit_memory=512MB
  imagick.resource_limit_map=1GB
  imagick.resource_limit_disk=2GB
  ```
- Use Imagick's **read-time downscaling** for huge JPEGs: `setOption('jpeg:size', '2048x2048')` before `readImage()` decodes a smaller version directly. Saves an order of magnitude on resize-to-thumbnail flows.
- For ultra-large originals, dispatch the first computation to the **worker** (async, generous memory limit) and return 202 + `Retry-After` to the public route. Subsequent hits read from S3.
- Use `try/finally` with `$imagick->clear()` and `$imagick->destroy()` after each step — Imagick does not reliably free between PHP requests in long-running workers (FrankenPHP, Messenger consumer).

**Warning signs:**
SIGKILL/137 in worker logs, FrankenPHP worker restarts spiking, `cache:clear` showing odd OPcache state.

**Phase to address:** **P-Imagine** (limits + clear/destroy), **P-Ops** (php.ini, worker memory budget).

---

### Pitfall 9: rembg cold start + CLIP coexistence in `embedder/`

**What goes wrong:**
Adding `rembg`/`u2net` to the existing `embedder/` container causes:
1. **Model download at runtime**: first request hangs 30-90s while `u2net.onnx` downloads to `~/.u2net/`, the request times out, the controller marks it failed.
2. **Memory doubled**: CLIP ViT-B/32 (~350 MB) + u2net (~170 MB) + ONNX runtime arenas + Python interpreter overhead = ~1.5 GB per worker. With Uvicorn `--workers 4` that's 6 GB and OOM in containers sized for CLIP alone.
3. **Thread safety**: rembg's session object is not thread-safe; FastAPI default executor runs sync handlers on a thread pool — concurrent `/bg-remove` requests corrupt session state, returning garbled masks or segfaults.
4. **CPU latency**: CPU-only u2net on a 2048×2048 image is 5-30s. This is incompatible with sync HTTP serving from PHP.

**Why it happens:**
The decision to mutualize CLIP + rembg in one container is correct cost-wise but the operational consequences are easy to overlook.

**How to avoid:**
- **Bake the model** into the Docker image at build time (same as CLIP), not at first request. `RUN python -c "from rembg import new_session; new_session('u2net')"` in the Dockerfile.
- **Process-level lock**, not thread: instantiate one `rembg` session per Uvicorn worker, protect with `asyncio.Lock`, OR use Uvicorn `--workers N` (process-based) with `--limit-concurrency 1` per worker for bg-remove route. Don't run rembg under FastAPI's threadpool.
- **Bump container memory** request/limit to ~2 GB; document it explicitly in the compose/k8s manifest. Add a startup probe that calls both `/embed` and `/bg-remove` to ensure both models are loaded before traffic is routed.
- **Always-async via Messenger** for bg-remove: the public `/t/*` route never blocks on rembg synchronously. First request returns 202 + `Retry-After: 10` and dispatches a `ComputeTransformationMessage` to a **dedicated `transformations` Messenger transport** (separate from the existing `async` queue used by embeddings) so a flood of bg-remove jobs doesn't starve CLIP embedding.
- Track `bgremover_inflight` gauge and `bgremover_duration_seconds` histogram per request; alert if p95 > 20s.

**Warning signs:**
Embedder container OOM-killed after deployment, p99 latency on `/api/assets/upload` (which depends on CLIP) regresses after rembg rollout, sporadic `RuntimeError: input tensor shape mismatch` from rembg, model download visible in container logs after restart.

**Phase to address:** **P-Embedder** (model baking, separate route, locking), **P-Worker** (dedicated transport, dispatch flow), **P-Ops** (container memory, probes, metrics).

---

### Pitfall 10: Orphan variants in S3 after step edit

**What goes wrong:**
User edits transformation `thumb` (changes resize 200→250). Hash changes from `v=abc` to `v=def`. All `thumb-vabc/*` files in S3 become orphans. Over a year of iteration, S3 contains dozens of dead versions per transformation × thousands of assets = hundreds of GB of pure cost. No automatic cleanup.

**Why it happens:**
The hash-versioned cache key is the right design for cache-busting; the missing piece is the lifecycle of old versions.

**How to avoid:**
- **`TransformationVersion` table**: every time a transformation's hash changes, insert a row `{ transformationId, hash, createdAt, retiredAt }`. Old versions stay queryable.
- **GC command** `app:transformations:gc` that, for each retired version older than `--keep-days=30`, lists S3 under `transformations/{transformationId}-v{hash}/` and deletes. Run nightly via cron / scheduled task.
- **S3 lifecycle policy** as a backstop: `transformations/` prefix → expire objects with `tagging` `version=retired` after 30 days. Set the tag when the version is retired.
- **Soft cap per transformation**: warn in UI when > N old versions exist, refuse to save if > M.

**Warning signs:**
S3 cost month-over-month growth uncorrelated with new assets, `aws s3 ls --summarize` on `transformations/` showing orders of magnitude more keys than `transformations × assets`.

**Phase to address:** **P-Worker** (GC command), **P-Schema** (`TransformationVersion`), **P-Ops** (cron + lifecycle policy).

---

### Pitfall 11: Hash drift / non-deterministic versioning

**What goes wrong:**
The cache-busting hash is computed differently between PHP and JS preview, or between two PHP runs:
- `json_encode` reorders keys differently than the editor's serialization → different hash → cache thrashes on every save even when nothing changed.
- Float `0.5` rendered as `"0.5"` in one place and `"0.50"` in another.
- Null vs missing key (`{"angle": null}` vs `{}`) → different hash.
- Step **reorder** doesn't change the hash because hashing was over an unordered set.
- Editor preview computes a different hash than the saved entity → preview shows a stale variant.

**Why it happens:**
"Just `sha1(json_encode($steps))`" looks fine until you discover that PHP and JS sort keys differently and floats serialize differently.

**How to avoid:**
- **Canonical JSON**: define one serialization (sorted keys, no whitespace, no trailing zeros on floats, explicit `null`s dropped, integers always as integers). Implement in PHP (`StepHasher` service) and mirror in TS (`hashSteps()` shared util via codegen). Cover with golden-file unit tests in **both** languages with identical fixtures.
- **Include step order** in the hash by hashing the **array** (ordered), not a set/dict.
- **Server is authoritative**: the editor computes a *preview* hash for UX, but the final hash returned by the API after save is the truth — the UI re-reads it and updates the preview URL. Never persist client-computed hashes.
- Include `transformation.code` and an explicit `algorithmVersion = 1` in the input so changing the hash algorithm in v2 cleanly busts everything.

**Warning signs:**
S3 fills up immediately after every save with no real change; preview URLs in the editor 404 until full page reload; identical transformations on two environments have different hashes.

**Phase to address:** **P-Hash** (canonical hasher in PHP + TS), **P-UI** (re-read server hash).

---

### Pitfall 12: Doctrine cascade deletes wiping S3 objects

**What goes wrong:**
Deleting a `Transformation` cascades and deletes all `TransformationStep` rows but **leaves all variant files in S3**. Or, conversely, cascade is wired so aggressively that deleting an asset wipes variants from S3 in a synchronous loop — taking 30+ seconds for an asset used by 20 transformations, locking a DB transaction, hitting S3 rate limits.

**Why it happens:**
The existing `AssetDeleteProcessor` already cleans the storage object for the original — devs assume the same pattern works for "delete transformation". But a transformation deletion touches **N assets × M variants** worth of S3 keys.

**How to avoid:**
- Asset deletion: synchronously delete the original from S3 (existing behavior), **dispatch async** `CleanupAssetVariantsMessage` to remove `transformations/*/{shard}/{asset.id}.*` in the background.
- Transformation deletion: do **not** cascade-delete variant files inline. Mark the transformation `deletedAt`, dispatch `CleanupTransformationMessage`, hard-delete the row after GC completes (or keep soft-deleted, your call). Document in API Platform with a custom processor `TransformationDeleteProcessor`.
- Wrap S3 deletes in batches (`deleteObjects` up to 1000 keys) to respect S3 limits.
- Tests: delete asset → verify variants gone (eventually); delete transformation → verify no orphans.

**Warning signs:**
DELETE on transformation timing out, S3 storage growing after deletes (orphans surviving), Messenger queue swelling with cleanup jobs after a bulk operation.

**Phase to address:** **P-Schema** (custom processors), **P-Worker** (batch cleanup messages).

---

### Pitfall 13: API Platform serializer recursion & N+1 on steps

**What goes wrong:**
`Transformation.steps` (OneToMany to `TransformationStep`) and `TransformationStep.transformation` (ManyToOne back). Without `MaxDepth(1)`, the JSON-LD serializer loops, response payloads explode, or Symfony throws a circular reference exception. Separately, listing 50 transformations → 50 lazy-load queries for steps → N+1, list page takes 3s.

**Why it happens:**
The CLAUDE.md standard already specifies `MaxDepth(1)`, but for new entities devs sometimes forget. N+1 is invisible in dev with 5 fixtures.

**How to avoid:**
- `#[MaxDepth(1)]` on both sides of the relation.
- `#[Groups(['transformation:read'])]` strict on `transformation` field of step (only expose under nested groups), avoid exposing it at all in step-read collection.
- Add `EAGER` fetch via QueryBuilder extension or `#[ApiFilter]` + custom DataProvider that joins `steps` on the collection endpoint: `->leftJoin('t.steps', 's')->addSelect('s')`.
- Add a profiler check / acceptance test asserting `<= 3 queries` to list 50 transformations.

**Warning signs:**
`/api/transformations` slow (>500ms) with small dataset, response size disproportionate to row count, Symfony profiler showing 50+ queries on a list view.

**Phase to address:** **P-Schema**.

---

### Pitfall 14: Code uniqueness race + URL squatting

**What goes wrong:**
Two admins create transformation `thumb` simultaneously — without a DB unique constraint, both succeed. The `/t/thumb/*` route now matches whichever the repo returns first, possibly the wrong one. Worse, a code like `assets` or `api` is allowed and conflicts with existing routes.

**Why it happens:**
`#[AppAssert\Code]` validates format but not uniqueness; uniqueness checked only at the validator level is racy.

**How to avoid:**
- `#[ORM\Column(length: 50, unique: true)]` on `Transformation.code` (matches the existing `Code` convention from `CLAUDE.md`).
- Validator chain: `AppAssert\Code` + `UniqueEntity` + **reserved-word blocklist** (`assets`, `api`, `admin`, `t`, `_`, single char) enforced both as a `Choice`-style negative and at route level (route requirement excludes reserved).
- Catch `UniqueConstraintViolationException` in the processor → return 409 Conflict with a clear message.

**Warning signs:**
500s on transformation create under load, two transformations with same code visible in DB (if you also forgot the unique index), `/t/api/...` returning a transformed asset instead of the API.

**Phase to address:** **P-Schema** + **P-Route** (route requirement excludes reserved).

---

### Pitfall 15: Browser cache + immutable headers + stale variant

**What goes wrong:**
The route sets `Cache-Control: public, max-age=31536000, immutable` (correct for hash-versioned URLs). But during the editor's live preview, the URL stays `/t/thumb/42.png?preview=1` while the user tweaks parameters — the browser keeps serving the first response, the preview doesn't update.

Conversely, if the URL is hash-versioned but the editor accidentally caches the previous hash in a ref, the `<img :src>` never changes → preview frozen.

Third variant: the public `<img>` on a product page sends the JWT cookie or `Referer` containing internal admin paths, leaking through to the public asset URL.

**Why it happens:**
"Immutable" assumes the URL changes on every content change. Previews violate that assumption. Referrer policy is a separate concern devs forget.

**How to avoid:**
- Preview endpoint **separate** from public route: `/api/transformations/{id}/preview` (JWT-protected, `Cache-Control: no-store`, returns the variant computed from in-memory un-persisted steps). Don't use `/t/*` for the editor.
- On save, the editor receives the new hash from the API and updates `<img :src>` reactively. The `:key` on `<img>` includes the hash to force re-mount.
- Set `Referrer-Policy: strict-origin-when-cross-origin` on the admin pages (or `same-origin` for tighter control).
- For public consumers: document that they must use the hash-versioned URL; never serve a non-versioned alias.

**Warning signs:**
Editor preview not updating without hard refresh, support reports "I changed the transformation but the website still shows the old version" (the consumer cached a versioned URL that should be unchanged — actually a bug in their integration, but document).

**Phase to address:** **P-UI** (preview endpoint, reactive key), **P-Route** (headers).

---

### Pitfall 16: Deployment ordering between embedder and route

**What goes wrong:**
Route is enabled in prod before the new `embedder/` image (with rembg) is deployed → `/t/{code}/{id}.png` with a `remove_background` step → 502 from embedder → user-facing error on a public URL → bad press, indexed by CDN as a failed response.

Or the inverse: embedder is upgraded with a bigger memory footprint **before** the worker container memory limits are raised → embedder OOM-killed on first rembg request, also taking down CLIP and breaking asset uploads.

**Why it happens:**
Multi-container rollouts have no atomicity. Feature flags are easy to forget on backend-only changes.

**How to avoid:**
- **Feature flag** `transformations.public_route.enabled` (env var, default `false`). Even if the route is in code, controller short-circuits to 404 until flipped.
- Deployment order documented and enforced via CI: (1) bump container memory, (2) deploy embedder with rembg, (3) verify `/bg-remove` health, (4) deploy api with the new code (flag off), (5) flip flag.
- Health checks: api has a `/health/transformations` (admin-only) that calls embedder `/bg-remove` with a tiny fixture image; flag-flip script blocks until this returns 200.
- Rollback plan: flipping the flag back to `false` returns the route to 404 immediately, without redeploy.

**Warning signs:**
CDN error rate spike right after deploy, embedder restarting in a crashloop, api logs full of `Connection refused` to embedder.

**Phase to address:** **P-Ops**.

---

### Pitfall 17: Backfilling existing assets

**What goes wrong:**
Stakeholders expect "all 100k existing product photos to have a thumb variant on day one". The team runs a one-shot script that dispatches 100k `ComputeTransformationMessage` jobs into the existing `async` queue. CLIP embedding for new uploads is now blocked behind 100k rembg-heavy jobs. The frontend shows 502s for hours.

**Why it happens:**
Single shared queue, no priority, no rate limit.

**How to avoid:**
- **Dedicated Messenger transport** `transformations_backfill` with its own consumer (run on demand, not always-on), separate from the `async` (embeddings) and `transformations` (live warmup) transports.
- Backfill command `app:transformations:backfill --transformation=thumb --batch=1000 --rate=10/s` that paces dispatch and respects S3 PUT rate limits.
- Run during off-peak; observable via metrics (`backfill_progress_ratio`).
- Prefer **lazy generation**: serve the variant on first public request, no backfill at all unless a perf requirement demands it.

**Warning signs:**
Embedding lag on new uploads after a backfill kickoff, Redis Streams `XLEN` ballooning on `messages`, S3 PUT throttling errors (503 SlowDown).

**Phase to address:** **P-Worker** (transports + backfill command), **P-Ops** (runbook).

---

### Pitfall 18: Missing CORS for direct browser consumption

**What goes wrong:**
Public route works fine in `<img>` (no CORS needed) but breaks for `fetch()` from `habitat.fr` or canvas operations (e.g. WebGL texture upload) because the response has no `Access-Control-Allow-Origin`. Same for fonts referenced from variant SVGs.

**Why it happens:**
The existing API uses `nelmio/cors-bundle` for `/api/*` only; `/t/*` is a different route group.

**How to avoid:**
- Add `/t/*` to the nelmio_cors `paths` config: `allow_origin: ['^https?://([a-z0-9-]+\.)?(vente-unique|habitat)\.(com|fr)$']`, `allow_methods: ['GET','HEAD']`, `max_age: 86400`.
- Set `Timing-Allow-Origin: *` for performance metrics in consumers.
- Document the allowlist in `PROJECT.md`.

**Warning signs:**
Frontend devs reporting "image loads but `getImageData()` throws SecurityError", CORS errors in CDN-served pages from outside the allowlist.

**Phase to address:** **P-Route** + **P-Ops**.

---

### Pitfall 19: Worker memory budget shared with CLIP

**What goes wrong:**
The existing `worker` container runs `messenger:consume async` with `--memory-limit=512M` (per CLAUDE.md). Adding rembg-driven transformation jobs in the same consumer means a single bg-remove job allocates 600+ MB and the consumer is SIGTERM'd mid-job, which then retries 3× and lands in `messages_failed`. Worse, the worker also handles CLIP embedding for uploads — so a transformation flood breaks uploads.

**Why it happens:**
"Reuse the existing worker, it's cheap" — but the workloads have different memory profiles.

**How to avoid:**
- Separate **Symfony Messenger transports**:
  - `async` (existing) → embeddings, low memory
  - `transformations` → bg-remove + heavy variants, high memory
- Separate **worker container** (or replica set) for `transformations` with `--memory-limit=1500M --time-limit=1800` and 2× CPU. Same image, different command.
- Document in compose / k8s and add per-transport metrics (`messenger_messages_handled_total{transport=...}`).
- Configure `failure_transport: failed_transformations` so failed-jobs from each pipeline can be inspected independently.

**Warning signs:**
Worker restart loop after a traffic spike on `/t/*`, `messages_failed` filling with `ComputeEmbeddingMessage` (collateral damage), upload latency p95 spiking.

**Phase to address:** **P-Worker** + **P-Ops**.

---

### Pitfall 20: Float precision & locale in step parameters

**What goes wrong:**
Step `rotate { angle: 0.5 }` round-trips through PHP (`(float)`) → JSON → JS (`Number`) → JSON → DB. With French locale on some env (`LC_NUMERIC=fr_FR`), `sprintf('%f', 0.5)` becomes `"0,5"` → JSON parse fails → hash mismatch. Or `0.1 + 0.2 !== 0.3` causes hash drift between PHP (`0.30000000000000004`) and JS.

**Why it happens:**
Locale and float printing are usually invisible until a French-locale dev container surfaces it.

**How to avoid:**
- Force locale `C` for JSON encoding paths: use `json_encode` with `JSON_PRESERVE_ZERO_FRACTION` and avoid `sprintf`/`number_format` in serialization.
- Canonical hasher (Pitfall 11) converts floats to strings with `serialize_precision = -1` and fixed format `"%.6f"` rstrip-zero, applied identically in PHP and TS.
- Schema-validate steps with a JSON Schema that constrains numeric ranges and types (angle: number, multipleOf: 0.5, range -360..360).

**Warning signs:**
Different hashes for "same" transformation across environments, JSON decode errors on production only.

**Phase to address:** **P-Hash** + **P-Schema**.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Reuse `async` Messenger transport for transformations | No infra changes | Embedding/upload starvation under transformation load | Never in prod; OK for first local POC only |
| Hash steps with `sha1(json_encode($steps))` and ship it | Done in 5 lines | Hash drift PHP↔JS, reorder bug, locale bug → cache thrash | Never; canonical hasher is small and mandatory |
| `aws s3 sync` based GC instead of DB-tracked versions | No new entity | Cannot distinguish "in-use" from "orphan", risks deleting active variants | Never |
| Synchronous bg-remove in the public route | Simpler controller | DoS on first miss for popular asset, 30s timeouts | Never; always async via Messenger with 202 + Retry-After |
| Store ICC profile decisions per-step (per format) | Flexibility | Combinatorial config surface, hard to test | Only if a real business need surfaces — start with global "strip+sRGB" |
| Allow any string as `code` | Less validation code | Route collisions, traversal risk, ugly URLs | Never; `[a-z0-9_-]{1,64}` + blocklist |
| Cache on local disk before pushing to S3 | Faster repeat hits on same container | Variants only available on that node; consistency hell on multi-replica | Only if you genuinely add a CDN in front and treat local cache as ephemeral |
| Skip feature flag for the public route | One fewer config | Cannot dark-launch, cannot rollback without redeploy | Never for a public surface |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Embedder (existing CLIP) | Add rembg to same FastAPI workers without separating route concurrency | Process-per-worker, model-baked-at-build, separate route with explicit `asyncio.Lock`, bump container memory |
| Flysystem (S3 in prod, local FS in dev) | `fileExists()` then `readStream()` | Single `readStream()` call with `UnableToReadFile` catch; never branch on `fileExists` for hot reads |
| Symfony Messenger (Redis Streams) | Single transport for all async work | Three transports: `async` (existing, embeddings), `transformations` (live warmup), `transformations_backfill` (bulk), each with own worker pod |
| API Platform (resources) | Forget `MaxDepth(1)` on Transformation↔Step | Both sides annotated, list endpoint joins steps via QueryBuilder extension |
| JWT auth bundle | Reuse JWT firewall on `/t/*` to "just in case" check tokens | `/t/*` is in a **separate firewall** with `anonymous: true` and explicit `access_control`; do not depend on or read JWT in this route |
| Imagick + AVIF | Trust that AVIF "just works" | `Imagick::queryFormats('AVIF')` at boot; fail closed on missing codec; document required libheif/libaom in Dockerfile |
| Imagine library | Use `$imagine->open()` + `->save()` without cleanup | `try/finally` with `clear()`+`destroy()`; configure `imagick.resource_limit_*` in php.ini; reject pixel counts > MAX_PIXELS |
| Vue editor preview | Use `/t/*` URL with `?preview=ts` | Dedicated `/api/transformations/{id}/preview` (JWT, no-store) so public CDN is never polluted |
| rembg model | Lazy-load on first request | `RUN python -c "from rembg import new_session; new_session('u2net')"` in Dockerfile + startup probe |
| Doctrine cascade | `cascade={"remove"}` on Transformation→Step→S3 cleanup | DB cascade only between rows; S3 cleanup goes through `CleanupTransformationMessage` dispatched in custom processor |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| No pixel-count cap on source | OOM under load with DSLR uploads | Reject `width*height > 50MP` at controller; Imagick `jpeg:size` hint | First time a user uploads a 24MP source and shares the variant URL |
| Synchronous bg-remove | p95 latency 20s on first miss; cascading timeouts | Always async, 202 + Retry-After, dedicated transport | Day one for any transformation containing `remove_background` |
| N+1 on transformation listing | `/api/transformations` list slow, profiler shows 50+ queries | QueryBuilder extension joining `steps` + `addSelect` | At 50+ transformations or 10+ steps each |
| Single Messenger transport | Upload latency regresses after transformation rollout | Three transports with dedicated workers | First production traffic spike |
| Lock TTL fixed too short | Variants randomly truncated under load | `Lock::refresh()` mid-compute; TTL > 2× worst observed compute | Under concurrent identical-URL traffic (viral share) |
| No CDN ignore-query-string rule | Cache hit ratio crashes when bots add `?utm_*` | Strip query before cache key + CDN config to drop QS on `/t/*` | First marketing campaign that adds tracking params to image URLs |
| No GC for retired versions | S3 cost grows linearly with edits × assets | `TransformationVersion` table + nightly GC + S3 lifecycle backstop | 3-6 months after launch, invisible until the bill arrives |
| ICC stripped without sRGB conversion | "Colors look dull on iPad / wrong on Mac" tickets | Convert to sRGB before strip; embed sRGB profile on color-critical transformations | First color-critical use case (furniture, fashion) |
| EXIF orientation ignored | "Image is sideways on mobile uploads" | Auto-orient as first step of every pipeline | First iPhone-portrait upload through the pipeline |
| Hash includes timestamp by accident | 100% cache miss on every save | Golden-file unit tests asserting hash stability across runs and languages | Immediately, but masked by low traffic in dev |

---

## Sources & Confidence Notes

- Stack mechanics (Flysystem, Messenger, API Platform, JWT firewall structure, `MaxDepth`) — HIGH, verified against `CLAUDE.md` and existing project patterns (asset upload pipeline, embedder integration).
- Imagick/Imagine behaviors (animation, EXIF, ICC, alpha-on-JPEG, AVIF detection) — HIGH, well-documented Imagick library behavior consistent across recent releases.
- S3 consistency model — HIGH, AWS strong read-after-write since Dec 2020 for new objects on the same key.
- rembg / u2net memory footprint and CPU latency — MEDIUM, based on widely-reported community measurements; exact numbers will vary by container and image size. Validate with a benchmark before sizing prod containers.
- Phase assignments — MEDIUM, depend on final roadmap split. Re-map by topic if phase names change.

## Gaps To Surface To Phase Planners

- Final list of allowed extensions (AVIF in v1? deferred?) — affects Pitfall 7 (codec presence check) and Pitfall 2 (whitelist).
- CDN choice (CloudFront? Bunny? none?) — affects Pitfall 2 (rate limit location), Pitfall 18 (CORS strategy), Pitfall 15 (cache headers tuning).
- Multi-replica deployment topology for `embedder/` — affects Pitfall 9 (locking strategy: per-process vs distributed).
- Tolerance for "lazy first hit" vs "always pre-warmed" — affects Pitfall 17 (backfill scope) and the SLA stated on `/t/*`.
