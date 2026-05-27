---
phase: 02-python-image-service-classical-endpoints
plan: 05
date: 2026-05-27
status: complete
requirements: [IMGSVC-01, IMGSVC-02, IMGSVC-03, IMGSVC-04, IMGSVC-05, IMGSVC-08, IMGSVC-09, IMGSVC-10]
---

# Plan 02-05 — Rebuild + smoke E2E — SUMMARY

## Build final

```
$ docker compose build embedder
→ Image built (cached layers used)
$ docker compose up -d embedder
→ Container running, healthy
```

## Suite complète

```
$ docker compose exec embedder pytest tests/ -v
======================== 55 passed, 2 warnings in 1.21s ========================
```

Détails :
- 8 tests `test_decode_image.py`
- 3 tests `test_health.py`
- 7 tests `test_resize.py`
- 6 tests `test_crop.py`
- 6 tests `test_rotate.py`
- 12 tests `test_format_convert.py`
- 13 tests `test_add_background.py`

## Smoke E2E (via httpx in container — embedder pas exposé publiquement)

| Endpoint | Statut | Vérification |
|----------|--------|--------------|
| `GET /health` | 200 | `clip.status=loaded`, birefnet+SD `not_loaded` |
| `POST /img/resize` | 200 | 300x200 → 100x67 (ratio préservé), header `X-Processing-Time` |
| `POST /img/crop` | 200 | aspectRatio=1.0 anchor=center → carré centré |
| `POST /img/rotate` | 200 | angle=45 + `#FF0000` → corner pixel `(255,0,0)` |
| `POST /img/format-convert` (AVIF) | 200 | `image/avif`, 344 bytes, lisible Pillow |
| `POST /img/format-convert` (RGBA→JPEG) | 200 | format JPEG, mode RGB, header `X-Alpha-Flattened: true` |
| `POST /img/add-background` (color) | 200 | RGBA vert + `#FF0000` → center pixel `(55,200,0)` blend |
| `POST /img/add-background` (asset multipart) | 200 | RGBA vert + bg bleu → center pixel `(0,200,55)` composite |

## SSRF — vérification finale

```
$ docker compose exec embedder env | grep -iE "s3|aws|boto"
→ (rien)
$ docker compose exec embedder pip list | grep -iE "boto|s3"
→ (rien)
```

✅ Aucun credential ni lib outbound.

## README

`embedder/README.md` créé — documente endpoints, contrat multipart, anti-SSRF, et workflow add-endpoint pour Phases futures.

## État final Phase 2

| REQ-ID | Plans | Statut |
|--------|-------|--------|
| IMGSVC-01 (resize) | 02-02 | ✓ |
| IMGSVC-02 (crop) | 02-02 | ✓ |
| IMGSVC-03 (rotate) | 02-02 | ✓ |
| IMGSVC-04 (format-convert + quality + AVIF) | 02-03 | ✓ |
| IMGSVC-05 (add-background color + asset SSRF-safe) | 02-04 | ✓ |
| IMGSVC-08 (/health multi-modèles) | 02-01 | ✓ |
| IMGSVC-09 (EXIF auto-orient) | 02-01 (decode_image) | ✓ |
| IMGSVC-10 (> 50 MPx → 422) | 02-01 (decode_image) | ✓ |

## Handoff Phase 3 (PHP orchestrateur)

- 5 endpoints disponibles sur `http://embedder:8000/img/*`.
- Contrat multipart documenté dans `embedder/README.md`.
- Header `X-Alpha-Flattened: true` à surveiller côté PHP (UX warning).
- Pas de auth requise (réseau interne).
- Phase 3 implémentera le `StepHandlerInterface` qui appellera chaque endpoint via `RetryableHttpClient` Symfony.

## Webfacto reminder

Image embedder en production : pour l'instant ~600 MB (CLIP + Pillow + AVIF plugin). Phase 4 ajoutera ~1 GB (BiRefNet). Phase 5 ajoutera 4-7 GB (Stable Diffusion). À cadrer dimensionnement RAM/CPU prod avant tout déploiement partagé.
