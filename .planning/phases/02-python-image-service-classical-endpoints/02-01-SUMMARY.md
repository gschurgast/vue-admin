---
phase: 02-python-image-service-classical-endpoints
plan: 01
date: 2026-05-27
status: complete
requirements: [IMGSVC-08, IMGSVC-09, IMGSVC-10]
---

# Plan 02-01 — Test harness + decode helper — SUMMARY

## Wave 0 — deps + tooling

- `embedder/requirements-dev.txt` : pytest 9.0.3 + pytest-asyncio 1.4.0 + httpx 0.28.1
- `embedder/requirements.txt` : ajout `pillow-avif-plugin==1.5.5`
- `embedder/pytest.ini` : `asyncio_mode = auto`, `testpaths = tests`
- `embedder/Dockerfile` : `COPY requirements-dev.txt` + `pip install -r requirements-dev.txt` ; `COPY core/` + `COPY tests/` + `COPY pytest.ini`
- Build OK (27s), container redéployé sans casse de `/embed`.

## Helper partagé `core/image_utils.py`

API publique :
- `decode_image(raw: bytes) -> Image.Image` : lazy open → check `w*h > 50 MPx` AVANT `.load()` → `exif_transpose` (réassigné) → `.load()`. Rejette SVG / empty / corrupt / oversized en 422.
- `content_type_for_format(fmt) -> str` : mapping `jpg/jpeg → image/jpeg`, png, webp, avif.
- `image_response(img, fmt, quality=None, extra_headers=None) -> Response` : sérialise + header `X-Processing-Time`.
- Constante `MAX_PIXELS = 50_000_000`.

## Refacto `/health`

Nouveau schéma :
```json
{
  "status": "ok",
  "models": {
    "clip": {"status": "loaded", "name": "clip-ViT-B-32", "dim": 512},
    "birefnet": {"status": "not_loaded"},
    "stable_diffusion": {"status": "not_loaded"}
  }
}
```

Extensible pour Phase 4 (`birefnet.status: "loaded"`) et Phase 5 (`stable_diffusion.status: "loaded"`).

## `pillow_avif` au module level

Import side-effect dans `app.py` ligne 22 — registre l'AVIF codec une fois pour tout le service. Vérifié par `test_avif_codec_registered`.

## Tests (11/11 ✓)

- 8 tests `test_decode_image.py` : RGB PNG, SVG reject, corrupt reject, empty reject, huge >50MPx reject, EXIF Orientation=6 → landscape, content-type mapping, image_response headers.
- 3 tests `test_health.py` : schéma multi-modèles, regression `/embed`, AVIF codec actif.

## Pitfalls adressés

- ✅ `ImageOps.exif_transpose()` retourne un NEW Image → réassignement explicite.
- ✅ `Image.open()` lazy → `.size` lu AVANT `.load()` pour rejection rapide.
- ✅ SVG détecté via `_looks_like_svg()` AVANT Pillow.
- ✅ `Image.MAX_IMAGE_PIXELS` NON modifié globalement (n'affecte pas `/embed`).
- ✅ AVIF plugin importé une seule fois, side-effect global.

## Handoff Plans 02-02..04

Tous les endpoints suivants consommeront `decode_image()` + `image_response()` — pas besoin de re-tester EXIF/MPx/SVG dans chaque endpoint. Les tests d'endpoint peuvent se concentrer sur la logique métier (resize math, crop bounds, format conversion).

## Webfacto reminder

Image embedder croît : CLIP (existant) + AVIF plugin (~1 MB). À cadrer dimensionnement final en fin de Phase 4 (avec BiRefNet) avant tout deploy partagé.
