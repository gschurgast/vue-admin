# Embedder — Image microservice

FastAPI service exposing CLIP image embeddings + classical image transformation endpoints.

**Not exposed publicly** — only reachable as `embedder:8000` from inside the Docker network.

## Endpoints

### Health / introspection

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Returns `{status, models: {clip, birefnet, stable_diffusion}}` with per-model load status |

### Embedding (existing)

| Endpoint | Body | Returns |
|----------|------|---------|
| `POST /embed` | multipart `file` (image) | `{embedding: float[512], model, dim}` |

### Classical image transformations (Phase 2)

All endpoints below take multipart `image` (binary) + `params` (JSON string) and return the processed image bytes with the appropriate Content-Type. Errors are 422 with `{detail: ...}`. Every response carries `X-Processing-Time: Nms`.

**Shared input filter (`core/image_utils.decode_image`)** — applied to every uploaded image:
1. Reject empty / SVG / corrupt → 422
2. Reject > 50 megapixels → 422
3. Auto-apply EXIF orientation
4. Return a Pillow `Image` ready to process

| Endpoint | `params` schema | Notes |
|----------|----------------|-------|
| `POST /img/resize` | `{width?, height?, mode: fit\|cover\|contain, upscale?: bool}` | At least one dim required. Format preserved from source. |
| `POST /img/crop` | Absolute `{x, y, width, height}` OR Ratio `{aspectRatio, anchor: center\|top\|bottom\|left\|right}` | Bounds checked → 422 if out of source. |
| `POST /img/rotate` | `{angle, expand?: bool, background?: "#RRGGBB"}` | RGBA → transparent corners. RGB → fillcolor. |
| `POST /img/format-convert` | `{format: png\|jpg\|jpeg\|webp\|avif, quality?: 1..100}` | RGBA→JPEG: composite on white + header `X-Alpha-Flattened: true`. |
| `POST /img/add-background` | `{type: color, color: "#RRGGBB"}` OR `{type: asset, assetId: int}` | type=asset REQUIRES second multipart field `background_image` (bg bytes inline, no URL ever). |

### Contract: `add-background type=asset`

```
multipart/form-data
  image            (required) — source image (RGBA recommended)
  params           (required) — JSON {"type":"asset","assetId":<int>}
  background_image (required) — background bytes; PHP reads via Flysystem, sends inline
```

**Anti-SSRF by construction**: the schema accepts only `assetId: int` (log-only), no URLs anywhere. The container has no `boto3`, no `AWS_*`/`S3_*` env vars, no outbound HTTP fetch.

## Running tests

```bash
docker compose exec embedder pytest tests/ -v
```

55 tests covering the 5 endpoints + `decode_image` guards + `/health` schema.

## Adding a new endpoint

1. Create `embedder/routers/img_<name>.py` (router with one `POST /img/<name>`).
2. Import + register in `embedder/app.py`.
3. Reuse `decode_image` / `image_response` from `core/image_utils`.
4. Write tests in `embedder/tests/test_<name>.py` using the `client` fixture.
5. Rebuild: `docker compose build embedder && docker compose up -d embedder`.

## Phase 4/5 outlook

The `/health` schema reports `birefnet.status: not_loaded` and `stable_diffusion.status: not_loaded`. Phase 4 will flip BiRefNet to `loaded` (background removal). Phase 5 will flip Stable Diffusion (inpainting) — the endpoint dimensions imply ~2-3 GB RAM (BiRefNet) and 4-7 GB (SD), to be validated with Webfacto before prod deploy.
