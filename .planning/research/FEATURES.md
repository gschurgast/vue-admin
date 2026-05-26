# Feature Research

**Domain:** Image transformation pipeline for an e-commerce/PIM asset library (Antigravity, milestone v1.0 Asset Transformations)
**Researched:** 2026-05-26
**Confidence:** MEDIUM-HIGH (industry conventions from imgproxy / Cloudinary / Thumbor / Sharp / Sirv / Bunny Optimizer are well-known and stable; WebSearch was unavailable during this run so claims rest on training data + the project's own CLAUDE.md / PROJECT.md. Verify URL-format edge cases and rembg model names against current upstream docs before locking).

---

## Scope reminder

Milestone scope (from `.planning/PROJECT.md`):

- Named transformations (`code`) = ordered list of steps
- Public route `/t/{code}/{id}.{ext}` — extension forces output format
- S3-cached variants keyed by `transformations/{transformationId}-v{hash}/{shard}/{id}.{ext}`
- Imagine-based steps for resize / crop / rotate / format_convert / add_background
- Background removal via the existing `embedder` container (extended with rembg + u2net)
- PWA drag-and-drop step editor with live preview
- Async warmup, GC command, metrics

The "validated" feature set (upload, dedup, embedding, similarity, CRUD) is already shipped and **out of scope** for this research — we only describe the **transformation pipeline**.

---

## 1. URL conventions — tradeoffs

Three families exist in the wild:

| Convention | Example | Pros | Cons |
|------------|---------|------|------|
| **Named preset + id (chosen)** | `/t/product-card/42.webp` | Short, CDN-friendly, immutable per `(code,id,ext)`; auth-less; non-leaking (params are server-side); easy to invalidate by `code` | Adding a new preset requires a write to the DB; no ad-hoc transforms |
| **Path-encoded params (imgproxy / Thumbor)** | `/rs:fit:300:300/g:ce/q:80/plain/s3://b/42.jpg` | Stateless server, infinite combinations, signature-protected | Long URLs, requires HMAC signing to prevent DOS, harder to reason about cache hit rates |
| **Query-param (Sirv, Bunny, ImageKit)** | `/42.jpg?w=300&h=300&fit=cover&q=80&fm=webp` | Easy to read/compose; works with off-the-shelf CDN rules | Some CDNs strip query strings from cache key by default; param ordering matters for cache; same DOS-by-combinatorial-explosion risk |

**Recommendation (confirms PROJECT.md):** stick with `/t/{code}/{id}.{ext}`.

Tradeoffs the user should know:

1. **You lose ad-hoc resizing.** Devs cannot ask for "this image at 312×217 just for this email". Either accept that (curated catalog) or add an admin-only signed-URL escape hatch later (anti-feature for v1).
2. **DPR / responsive** must be handled via separate presets (`product-card@1x`, `product-card@2x`) or via a single preset + `srcset` with multiple `code`s. Cloudinary-style `w_auto,dpr_auto` is **not** available with a path-only scheme.
3. **Extension drives format.** `.webp` ≠ `.jpg` ≠ `.avif` — same `code`, different cache keys, different S3 objects. Document clearly that `?format=` query is ignored.
4. **No signing needed** because the param surface is finite (number of presets × number of asset ids). DOS surface stays bounded.
5. **Cache key is `(code, version_hash, id, ext)`** — see §3.

Reference: imgproxy uses signed path-params; Cloudinary uses path-params with named transformations via `t_{name}`; Bunny Optimizer uses query params. The "named preset only" mode the project picked is closest to Cloudinary's `t_{name}` shortcuts and to Shopify's `_640x.jpg` suffix convention.

---

## 2. Step types and parameter shapes (concrete JSON schemas)

Each step is `{ "op": "<name>", "params": { … } }`. The transformation entity stores an ordered JSON array.

### 2.1 `resize`

Modes (industry standard across Sharp / Imagine / Cloudinary / imgproxy):

| Mode | Behavior | Equivalent |
|------|----------|------------|
| `fit` | Resize to fit **inside** box, preserve aspect, no crop, may letterbox if `background` set | Sharp `inside`, Cloudinary `c_fit`, imgproxy `rs:fit` |
| `cover` | Fill the box, preserve aspect, **crop** overflow | Sharp `cover`, Cloudinary `c_fill`, imgproxy `rs:fill` |
| `contain` | Same as `fit` but pads to exact dims with `background` (no transparency leak) | Sharp `contain`, Cloudinary `c_pad` |
| `scale` / `exact` | Stretch to exact dims, **break aspect** | Cloudinary `c_scale`. Useful for banner / texture |
| `inside` | Resize only if larger than box (never upscale) | Sharp `inside` w/ `withoutEnlargement` |
| `outside` | Resize so the smaller side matches; image may exceed box | Sharp `outside` |

JSON:

```json
{
  "op": "resize",
  "params": {
    "width": 800,
    "height": 600,
    "mode": "cover",
    "anchor": "center",
    "background": "#ffffff",
    "withoutEnlargement": true
  }
}
```

- `width` / `height`: integer px, 1 ≤ x ≤ 8192. At least one required.
- `mode`: enum, default `fit`.
- `anchor` (for `cover` crop overflow): `center | top | bottom | left | right | top-left | top-right | bottom-left | bottom-right | smart` (smart = entropy/face-aware; v1 = center, smart is a differentiator).
- `background`: CSS color (`#rrggbb`, `#rrggbbaa`, `rgba(...)`, named) — used in `contain` and `scale`-with-padding. Default `transparent` for formats supporting alpha, `#ffffff` otherwise.
- `withoutEnlargement`: prevent upscaling small originals.

### 2.2 `crop`

Two paradigms; **support both** as sub-modes:

```json
{
  "op": "crop",
  "params": {
    "mode": "absolute",
    "x": 100, "y": 50, "width": 400, "height": 300
  }
}
```

```json
{
  "op": "crop",
  "params": {
    "mode": "aspect",
    "ratio": "1:1",
    "anchor": "center"
  }
}
```

- `mode`: `absolute` (pixel coords) | `aspect` (preserves area, crops to ratio).
- `ratio`: `"W:H"` string (e.g. `"1:1"`, `"4:5"`, `"16:9"`) — validated.
- `anchor`: same enum as resize.
- Absolute mode validates coords against the source image dims (reject early to avoid post-decode failures).

### 2.3 `rotate`

```json
{
  "op": "rotate",
  "params": {
    "angle": 90,
    "background": "#ffffff",
    "expand": true
  }
}
```

- `angle`: float degrees, `-360 ≤ angle ≤ 360`. Common presets: 90, 180, 270.
- `background`: fill for the corners exposed by non-orthogonal rotations (ignored when `angle % 90 == 0`).
- `expand`: enlarge canvas to contain the rotated image (default `true`); if `false`, the original canvas is kept and corners are clipped.

EXIF-orientation auto-rotate should happen **before any step runs** (always-on, not a configurable step).

### 2.4 `add_background`

```json
{
  "op": "add_background",
  "params": {
    "type": "color",
    "color": "#f5f5f5"
  }
}
```

```json
{
  "op": "add_background",
  "params": {
    "type": "asset",
    "assetId": 1234,
    "fit": "cover",
    "opacity": 1.0
  }
}
```

- `type`: `color` | `asset`.
- For `asset`: the referenced asset is loaded via Flysystem and composited **under** the current image (which must have alpha). `fit`: `cover | contain | tile | center`.
- `opacity`: 0..1, default 1.

This step is typically used **after** `remove_background` to put a solid/branded backdrop behind a cutout. The editor should hint this dependency.

### 2.5 `remove_background`

```json
{
  "op": "remove_background",
  "params": {
    "model": "u2net",
    "alphaMatting": false,
    "postProcess": true,
    "fallbackOnTimeout": "passthrough"
  }
}
```

- `model`: `u2net` (general), `u2netp` (lighter/faster), `isnet-general-use` (newer, sharper edges), `silueta`. v1 = `u2net` only; expose enum so we can add models later without schema change.
- `alphaMatting`: improves edge quality on hair/fur — slower (~2-3×). Default false.
- `postProcess`: morphological cleanup of mask. Default true.
- `fallbackOnTimeout`: `passthrough` (return original, log) | `error` (502). Important because rembg shares the embedder GPU/CPU with CLIP — see §4.

Output: PNG with alpha. Any downstream `format_convert` to JPEG **must** be preceded by `add_background` or a flatten step, otherwise the alpha channel is silently flattened to black.

### 2.6 `format_convert`

Note: in the chosen URL scheme, format is **forced by the file extension**, so an explicit `format_convert` step is mostly redundant **for the final output**. Keep it anyway because:

- You may want quality/encoder controls in the pipeline definition.
- An intermediate convert may be useful (e.g. flatten PNG→JPEG before watermark).

```json
{
  "op": "format_convert",
  "params": {
    "format": "auto",
    "quality": 82,
    "progressive": true,
    "lossless": false,
    "stripMetadata": true,
    "chromaSubsampling": "4:2:0"
  }
}
```

- `format`: `auto` (= match URL extension) | `jpeg | png | webp | avif`. Use `auto` 95% of the time.
- `quality`: 1..100. Presets: `web` (80), `high` (90), `print` (95). Different defaults per format (AVIF ~50 ≈ WebP ~80 ≈ JPEG ~85).
- `progressive`: JPEG/PNG only.
- `lossless`: WebP/AVIF only.
- `stripMetadata`: remove EXIF/IPTC/XMP for size + privacy. Default true.
- `chromaSubsampling`: `4:4:4 | 4:2:2 | 4:2:0` (JPEG only). Default `4:2:0`.

### 2.7 Recommended additional steps for v1.x (not v1)

- `watermark` (text/image overlay with position + opacity)
- `flatten` (drop alpha onto color — useful before lossy formats)
- `auto_orient` (already implicit, but expose if needed)
- `trim` (auto-crop borders of given color)
- `blur` / `sharpen` (gaussian sigma + unsharp mask amount)

---

## 3. Cache invalidation strategy

Three options:

| Strategy | How | Pros | Cons |
|----------|-----|------|------|
| **Hash-of-params (chosen)** | `version = sha1(json_encode(steps, JSON_SORTED))`. S3 key includes `-v{hash}`. | Self-invalidating: editing a step produces new key, old key kept for cache GC. Zero-downtime preset edits. No CDN purge needed. | Storage cost = N versions until GC. Old hash URLs stay valid until GC runs. |
| **Version counter** | `transformation.version++` on each edit. Key uses `v123`. | Human-readable. | Forces editor to bump on every save; no content-based dedup if two edits cancel out. |
| **Manual purge** | Admin clicks "invalidate all variants of preset X". | Most control. | Forgetful = stale images in prod. Race conditions during purge. |

**Recommendation:** hash-of-params + **periodic GC command** that lists S3 prefixes per transformation and deletes objects whose version hash is no longer the current `transformation.versionHash`. GC must:

1. Be idempotent (`bin/console transformations:gc --dry-run`).
2. Keep N previous versions (default `--keep=2`) to avoid hard CDN/browser cache cliffs.
3. Respect a grace period (`--min-age=24h`) so a JIT request that's still uploading isn't deleted mid-stream.
4. Be safe to run in parallel via a Redis lock per `transformationId`.

Cache-Control headers on the `/t/...` response should be `public, max-age=31536000, immutable` because the URL is content-addressed (version hash) — never a 200 with a different body for the same URL.

---

## 4. Concurrency model

### Lazy vs warm-on-create

| Mode | Trigger | When to use |
|------|---------|-------------|
| **Lazy (JIT)** | First HTTP request renders + caches | Default. Bounded cost: only "real" demand pays compute. Cold-start latency on first hit (acceptable behind a CDN). |
| **Warm-on-create** | When `Transformation` is created/edited OR a new `Asset` is uploaded | Use for the "hero" / "thumbnail" preset that you know is needed for **every** asset. Avoid for niche presets (waste). |
| **Warm-on-demand** | Admin clicks "warm this preset for all assets" | Best of both: keep lazy default, allow batch warm-up. |

**Recommended hybrid:**

1. Lazy by default.
2. A boolean `Transformation.warmOnUpload` triggers `WarmTransformationMessage($transformationId, $assetId)` when a new asset is uploaded.
3. A console command `transformations:warm {code} [--missing-only]` enqueues warmup for the existing catalog.

### Anti-thundering-herd

Without a lock, 100 concurrent requests for an uncached variant each trigger a render. Use a **Redis lock** keyed on `(transformationId, versionHash, assetId, ext)`:

- First request acquires the lock and renders.
- Concurrent requests **wait** up to `N seconds` then either (a) re-check S3 / serve, or (b) return 503 with `Retry-After`.
- TTL on the lock = max render time × 2.

### Queue priority

Symfony Messenger supports multiple transports. Recommend:

- `embedding` transport (existing) — low priority, slow OK
- `transform_warm` transport — low priority, large batch
- **No transport for sync rendering** — JIT renders happen in the HTTP request itself (with the Redis lock). Pushing it to a queue would force the client to poll, which the chosen URL scheme can't express cleanly.

### Background remover bottleneck

`rembg + u2net` on CPU = ~1-3s per image; on GPU = ~150ms. The embedder container today runs CLIP for the dedup feature — sharing it means:

- A spike of uploads (dedup) competes with bg-removal requests (transformation). Either:
  1. Two FastAPI endpoints, **one worker pool**, accept FIFO scheduling — simple, possible head-of-line blocking.
  2. Separate process pools per endpoint inside the same container — better isolation.
  3. Separate replicas for `embedder-clip` vs `embedder-rembg` — easiest to scale independently, but defeats the "one container" goal.
- Add **timeout + fallback**. If rembg exceeds e.g. 10s, return the original image and log a warning. `params.fallbackOnTimeout` decides the UX.
- Mind memory: u2net ~ 170 MB resident; isnet-general-use ~ 180 MB. Pre-load at boot (already the pattern used for CLIP).

---

## 5. Output format negotiation: URL extension vs Accept header

| Approach | Pros | Cons |
|----------|------|------|
| **URL extension (chosen)** | Deterministic; one URL = one byte stream; trivially cacheable on any CDN; works in `<img src>` with `<picture>` for fallback; debuggable | Client must know which formats it supports — but `<picture><source type="image/avif">…<img src=".jpg">` solves it |
| **Accept header (Cloudinary `f_auto`, imgproxy auto)** | One URL, browser sees the best format | CDN cache key must include `Accept` (Vary: Accept) — many CDNs do this poorly; harder to debug; same URL returns different bytes |

The PROJECT decision (extension-forced) is the right call for a brownfield CDN-friendly setup. Document the `<picture>` pattern in the editor's "how to use this preset" panel:

```html
<picture>
  <source type="image/avif" srcset="/t/product-card/42.avif">
  <source type="image/webp" srcset="/t/product-card/42.webp">
  <img src="/t/product-card/42.jpg" alt="...">
</picture>
```

Allowed extensions enum: `jpg | jpeg | png | webp | avif`. Reject everything else with 404 (not 400 — avoids leaking info about non-existent presets).

---

## 6. Feature Landscape

### Table Stakes (Users Expect These)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Named transformations CRUD (`code`, `label`, ordered `steps`) | "Preset" is the mental model from Cloudinary/Bunny/Shopify | MEDIUM | New `Transformation` entity, JSON column `steps`, `versionHash` (sha1 of normalized steps), unique `code` + AppAssert\Code |
| Public route `/t/{code}/{id}.{ext}` | Stable, cacheable URL is the whole point | MEDIUM | Symfony controller, **no firewall**, content-type set from extension, Cache-Control immutable |
| Resize step (modes: fit/cover/contain) | Universal, every other product has it | LOW | Imagine `Imagine\Image\ImageInterface::thumbnail` + manual modes |
| Crop step (absolute + aspect) | Required for product hero shots, square thumbnails | LOW | Imagine `crop()` for absolute, ratio→box computation for aspect |
| Rotate step (orthogonal + arbitrary) | Catalogs always have a few sideways images | LOW | Imagine `rotate()`; force EXIF auto-orient before pipeline |
| Format conversion driven by URL extension | Modern delivery: AVIF/WebP for size, JPEG/PNG for compatibility | MEDIUM | Encoder options per format; quality presets; metadata stripping |
| S3 variant caching with version hash | Cost control + low p99 latency | MEDIUM | Key = `transformations/{tId}-v{hash}/{shard}/{id}.{ext}`; check-then-render-then-PUT |
| Redis anti-thundering-herd lock | Without it, the first deploy of a hot preset DDOSes the worker | MEDIUM | Lock TTL ≥ rendering deadline; tested with concurrent curl |
| Drag-and-drop step editor with live preview | Marketing/PIM users won't write JSON | HIGH | Vue 3 + vue-draggable-plus; preview via the `/t/...` route on a fixed sample asset |
| GC console command | Storage WILL bloat — must ship from day 1 | MEDIUM | `transformations:gc --keep=N --min-age=24h --dry-run` |
| 404 for missing asset / unknown code | Predictable behaviour for CDNs | LOW | Don't render anything; 404 must be cacheable for a short TTL (1-5 min) to avoid retry storms |

### Differentiators (Competitive Advantage)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| `remove_background` integrated in the same UI (rembg + u2net) | Saves a manual step in Photoshop/Canva for every product | HIGH | Extend `embedder/` with `/remove-background` endpoint; queue limit; model selector enum |
| `add_background` with **asset** type (composite branded backdrop under a cutout) | Lifestyle/scenic shots without a photographer | MEDIUM | Reuses existing assets — already in S3 — composited via Imagine |
| Warm-on-upload toggle per transformation | "Hero" thumbnails ready before the user finishes saving the product | MEDIUM | Messenger message dispatched from `AssetUploader` after embedding |
| Bulk warm command per preset | One-shot fill the cache after creating a new preset | LOW | `transformations:warm {code} [--missing-only]` |
| Versioned previews in editor (before / after each step) | UX leap vs Cloudinary's text-only param list | HIGH | Render N intermediate variants on demand (debounced) |
| Per-asset "available transformations" panel on Show page | Lets users discover what URLs exist without leaving the asset | LOW | Read all `Transformation` rows, render the `/t/...` URLs as copy-buttons |
| Smart-anchor crop (entropy / face detection) | One preset covers portrait + landscape catalogs | HIGH | Out of v1; could lean on a future face-detection model in the embedder. Plan an enum slot now (`anchor: smart`) so we don't break later. |
| Per-transformation Datadog/metrics (hit rate, render time, cache miss) | Ops visibility; cost attribution per preset | MEDIUM | Symfony Messenger middleware + simple counter via existing observability stack |

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Ad-hoc params via query string (`?w=300`) | Devs love the flexibility | DOS surface, cache explosion, undermines the "named preset" governance model. Defeats the chosen URL scheme. | Add a new preset when needed; admin-only signed escape hatch if absolutely required |
| Auto-format negotiation via Accept header | "One URL, best format" | Forces `Vary: Accept` on every CDN; many CDNs handle it badly; debuggability nightmare | `<picture>` with explicit `.avif`/`.webp`/`.jpg` srcs (one per preset extension) |
| Public listing of all transformations | "Discoverability" | Lets attackers enumerate codes and compute combinatorial S3 cost | List only inside the authenticated admin; presets are conceptually internal |
| User-defined upscaling > 2× | Big-image previews | Wastes storage, blurry output; trains users to ignore "do not upscale" guardrails | Cap upscale at 1.0 (no enlargement) by default; allow override per preset, never per URL |
| Animated GIF/APNG pipeline | "Our packshots have a tiny animation" | Imagine support is poor; AVIF/WebP animation in Sharp/libvips is fragile; per-frame compute is N× cost | Out of scope. If needed, video transform pipeline (separate feature) |
| PDF transformations | "We upload PDF assets too" | A different toolchain (Ghostscript / pdftoppm); merges into a feature about PDF page-rendering which has its own UX | Out of scope; deliver original PDF via existing `/api/assets/{id}/content` |
| Watermark in v1 | Brand protection | Triples the editor UX (position picker, font picker for text watermark, opacity, blend mode) without core demand for the e-commerce catalog | Defer to v1.x; the catalog isn't public-facing originals |
| User-uploaded LUT / custom shader steps | "Apply our brand color grading" | Massive surface area, slow to validate, niche | One-off via offline Photoshop batch; revisit only if multiple teams ask |
| Storing transformation outputs back as `Asset` rows | "We want to reuse the cropped version everywhere" | Pollutes the asset library, breaks dedup invariants, doubles storage accounting | Variants stay under `transformations/` prefix in the same bucket, not in the `asset` table |

---

## 7. Feature Dependencies

```
[Transformation entity (code, steps JSON, versionHash)]
        │
        ├──required-by──> [Public route /t/{code}/{id}.{ext}]
        │                        │
        │                        ├──required-by──> [Imagine handlers: resize / crop / rotate / format_convert / add_background]
        │                        │                        │
        │                        │                        └──required-by──> [PWA step editor]
        │                        │
        │                        └──required-by──> [Redis anti-thundering-herd lock]
        │
        ├──required-by──> [GC command]
        │
        └──required-by──> [Warm-on-upload + bulk warm command]
                                 │
                                 └──requires──> [Messenger transform_warm transport]

[remove_background step]
        ├──requires──> [embedder container extended with rembg + u2net]
        └──pairs-with──> [add_background step (color or asset)]   # alpha must be flattened before lossy formats

[format_convert step]
        └──interacts-with──> [URL extension]    # extension wins; step quality/encoder opts still apply

[Live preview in editor]
        └──requires──> [Public route + at least one sample asset bound to the transformation]
```

### Dependency notes

- **`remove_background` → `add_background` or `format_convert(jpeg)` flatten:** without one of these, exporting a cutout to JPEG silently fills alpha with black. The editor must warn when a JPEG-extension preset contains `remove_background` without a downstream `add_background` step.
- **`format_convert` is largely overridden by URL extension** — keep it as the place to declare `quality`, `chromaSubsampling`, `stripMetadata`, and as the safety net for intermediate format flips.
- **Warmup features depend on the public route being functional and idempotent** — warmup just pings the route on the worker side. Don't reimplement rendering in the warmer; reuse the controller logic via a `TransformationRenderer` service so JIT and warm share code.
- **GC depends on the version hash convention** — without content-addressed keys, GC can't tell "old" from "current".

---

## 8. MVP Definition

### Launch With (v1) — milestone-aligned

- [ ] `Transformation` entity + CRUD via API Platform (code, label, steps JSON, versionHash, enabled, warmOnUpload)
- [ ] `TransformationStep` validators per `op` (resize / crop / rotate / format_convert / add_background)
- [ ] Public `GET /t/{code}/{id}.{ext}` controller (no firewall on this exact route)
- [ ] `TransformationRenderer` service (Imagine-based) + per-op handlers
- [ ] S3 cache: check → render → put → stream; key includes versionHash
- [ ] Redis lock keyed on `(transformationId, versionHash, assetId, ext)`
- [ ] `remove_background` step via extended `embedder` (u2net only)
- [ ] PWA drag-and-drop step editor with debounced live preview
- [ ] `transformations:gc` console command with `--keep`, `--min-age`, `--dry-run`
- [ ] `Cache-Control: public, max-age=31536000, immutable` on success; `Cache-Control: public, max-age=60` on 404
- [ ] Basic metrics (cache hit/miss counter, render time histogram) wired into existing observability stack

### Add After Validation (v1.x)

- [ ] Warm-on-upload toggle + `transformations:warm {code}` command (defer until we see how the lazy-only cache behaves)
- [ ] Additional rembg models (`u2netp`, `isnet-general-use`) behind the existing enum
- [ ] `watermark` step (image + text)
- [ ] `flatten` / `trim` / `auto_orient` explicit steps
- [ ] Multi-preview in editor (before/after each step, not just final)
- [ ] Per-preset metrics dashboard

### Future Consideration (v2+)

- [ ] Smart-anchor crop (face / entropy detection) — depends on a new model on the embedder
- [ ] `blur` / `sharpen` / `tint` / color-curve steps
- [ ] Admin-only signed ad-hoc params endpoint (escape hatch for one-off marketing needs)
- [ ] Animated formats (WebP/AVIF animation)
- [ ] PDF-page rendering as a sibling pipeline
- [ ] LUT / 3D color grading

---

## 9. Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Transformation entity + CRUD | HIGH | LOW | P1 |
| Public `/t/...` route + S3 cache | HIGH | MEDIUM | P1 |
| Resize / crop / rotate steps | HIGH | LOW | P1 |
| format_convert + extension-driven output | HIGH | MEDIUM | P1 |
| add_background (color) | HIGH | LOW | P1 |
| remove_background (u2net) | HIGH | HIGH | P1 |
| Drag-and-drop editor + live preview | HIGH | HIGH | P1 |
| Redis anti-thundering-herd lock | HIGH (hidden) | LOW | P1 |
| GC command | HIGH (cost) | LOW | P1 |
| Metrics (basic) | MEDIUM | LOW | P1 |
| add_background (asset) | MEDIUM | MEDIUM | P2 |
| Warm-on-upload | MEDIUM | MEDIUM | P2 |
| Bulk warm command | MEDIUM | LOW | P2 |
| Extra rembg models | LOW | LOW | P2 |
| Watermark | MEDIUM | HIGH | P2 |
| Smart-anchor crop | MEDIUM | HIGH | P3 |
| Animated formats | LOW | HIGH | P3 |
| Ad-hoc signed URLs | LOW | MEDIUM | P3 |

---

## 10. Competitor Feature Analysis

| Feature | imgproxy | Cloudinary | Thumbor | Sharp/libvips (Sirv, Bunny) | Our Approach |
|---------|----------|------------|---------|-----------------------------|--------------|
| URL convention | Signed path-params | Path-params + named `t_{name}` | Path-params + filters | Query-params | Named preset only — `/t/{code}/{id}.{ext}` |
| Format negotiation | URL ext OR Accept (`@best`) | `f_auto` Accept-based | `format=` filter or Accept | Query or Accept | **URL extension only** (deterministic) |
| Cache invalidation | Stateless (params in URL) | Version on asset (`v123456`) | Stateless | Stateless or query version | **Content hash on transformation** (sha1 of normalized steps) |
| Background removal | No | Yes (`e_background_removal`, paid add-on) | No (community plugins) | No (Bunny offers via add-on) | **Yes, native** via extended embedder |
| Smart crop | `g:sm` (libvips smartcrop) | `g_auto`, `g_face` | `smart` filter | Some | Out of v1, enum slot reserved |
| Watermark | Yes (`wm:`) | Yes (`l_`) | Yes (`watermark()`) | Yes | Defer to v1.x |
| Animated images | Yes | Yes | Limited | Limited | Out of scope |
| Auth on origin | Optional signature | Account-bound + signed URL | Signature | Account-bound | Origin (`/api/assets/{id}/content`) **JWT-protected**; only `/t/...` is public; original URL not exposed via the public route |
| Editor UI | None (devs only) | Full editor in dashboard | None | Per-product dashboards | Vuetify drag-and-drop with live preview |

---

## 11. JSON Schema reference (paste-ready for Doctrine column validation)

Full `Transformation.steps` shape:

```json
[
  { "op": "rotate",           "params": { "angle": 90 } },
  { "op": "remove_background","params": { "model": "u2net", "alphaMatting": false, "fallbackOnTimeout": "passthrough" } },
  { "op": "add_background",   "params": { "type": "color", "color": "#ffffff" } },
  { "op": "resize",           "params": { "width": 800, "height": 800, "mode": "cover", "anchor": "center", "withoutEnlargement": true } },
  { "op": "crop",             "params": { "mode": "aspect", "ratio": "1:1", "anchor": "center" } },
  { "op": "format_convert",   "params": { "format": "auto", "quality": 82, "progressive": true, "stripMetadata": true } }
]
```

Normalization rule for the version hash:

1. JSON-encode with **sorted object keys** and no whitespace.
2. Drop default-valued params (so adding a defaulted field later doesn't bust caches retroactively).
3. `versionHash = substr(sha1($normalized), 0, 12)` — 12 hex chars (48 bits) is plenty for collision-free preset versions and keeps URLs/S3 keys short.

---

## Sources

- imgproxy documentation (URL structure, processing options, performance guide) — training data; verify at https://docs.imgproxy.net/
- Cloudinary URL transformation reference + named transformations (`t_{name}`) — training data; verify at https://cloudinary.com/documentation/image_transformations
- Thumbor filters and smart cropping — training data; verify at https://thumbor.readthedocs.io/
- Sharp / libvips resize modes (`cover | contain | fill | inside | outside`) — training data; verify at https://sharp.pixelplumbing.com/api-resize
- Symfony Imagine bundle — used by the project's stack
- rembg / u2net / isnet-general-use — https://github.com/danielgatis/rembg (model list, perf characteristics)
- Project's own `.planning/PROJECT.md` and `CLAUDE.md` (URL scheme, S3 layout, embedder reuse)
- Bunny Optimizer / Sirv / ImageKit query-param conventions — training data

**Verification status:** WebSearch was unavailable during this research run (permission denied). All upstream-product claims rest on training data (cutoff Jan 2026) for very stable APIs (imgproxy / Cloudinary / Thumbor / Sharp / rembg have not changed their URL/parameter conventions meaningfully in years), but the planner should **spot-check the rembg model list and the AVIF default quality numbers against current upstream docs before locking the validator constants**.

---
*Feature research for: image transformation pipeline (Antigravity v1.0 milestone)*
*Researched: 2026-05-26*
