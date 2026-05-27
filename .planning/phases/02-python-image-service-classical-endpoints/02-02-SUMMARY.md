---
phase: 02-python-image-service-classical-endpoints
plan: 02
date: 2026-05-27
status: complete
requirements: [IMGSVC-01, IMGSVC-02, IMGSVC-03, IMGSVC-08, IMGSVC-09]
---

# Plan 02-02 — resize/crop/rotate — SUMMARY

## Endpoints livrés

| Endpoint | Modes/params | Status code |
|----------|--------------|-------------|
| `POST /img/resize` | `width?`, `height?`, `mode: fit\|cover\|contain`, `upscale: bool` | 200 / 422 |
| `POST /img/crop` | Absolu `{x,y,width,height}` OU `{aspectRatio, anchor: center\|top\|bottom\|left\|right}` | 200 / 422 |
| `POST /img/rotate` | `angle`, `expand: bool`, `background: hex` | 200 / 422 |

## Décisions implem

- **Format de sortie** : préservé depuis la source (`img.format or PNG`). La conversion explicite est le rôle de `/img/format-convert` (Plan 02-03).
- **Resize fit** : `Image.thumbnail` (in-place, conserve ratio).
- **Resize cover** : `ImageOps.fit` (crop centré).
- **Resize contain** : `ImageOps.pad` (avec padding).
- **Resize upscale=false** : `min(target, src)` borne les dimensions.
- **Crop discriminator** : présence de la clé `aspectRatio` → ratio mode, sinon absolu.
- **Crop bounds** : `x+w > src_w` ou `y+h > src_h` → 422 explicite (sinon Pillow crop silencieux retourne image vide).
- **Rotate RGBA** : fond transparent natif (pas de fillcolor).
- **Rotate RGB** : fillcolor parsé depuis hex `#RRGGBB` (422 si invalide).

## Tests (19/19 ✓)

- 7 tests `test_resize.py` : fit (ratio), cover (exact), contain (padded), no-upscale, header X-Processing-Time, missing dims 422, SVG reject.
- 6 tests `test_crop.py` : absolute, out-of-bounds 422, ratio center, anchor top, anchor left, SVG reject.
- 6 tests `test_rotate.py` : 90° swap dims, 180° expand=false keeps, RGB fillcolor, RGBA transparent, invalid hex 422, SVG reject.

## Pattern réutilisable

Tous les routers suivent :
1. Parse params (Pydantic `model_validate_json` → 422 si invalide).
2. `raw = await image.read()` → `img = decode_image(raw)` (hérité 02-01, applique 50 MPx + EXIF + SVG reject).
3. Pillow op.
4. `image_response(result, fmt)` (header `X-Processing-Time` inclus).

Les Plans 02-03 (format-convert) et 02-04 (add-background) copient ce pattern.

## Handoff Plans 02-03, 02-04

- Le `decode_image` filter est central : aucun endpoint Phase 2 ne doit le bypasser.
- Pattern `app.py` : `from routers import img_resize, img_crop, img_rotate` + `app.include_router()`. Plans 02-03 et 02-04 ajouteront leurs propres routers à cette liste.

## Webfacto reminder

3 endpoints sont maintenant disponibles via `embedder:8000` (jamais exposé publiquement). Phase 3 (orchestrateur PHP) consommera via `RetryableHttpClient` — à cadrer le dimensionnement timeout (8s sync cap) avec la Webfacto.
