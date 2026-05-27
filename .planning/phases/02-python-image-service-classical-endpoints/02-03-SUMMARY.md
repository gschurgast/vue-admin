---
phase: 02-python-image-service-classical-endpoints
plan: 03
date: 2026-05-27
status: complete
requirements: [IMGSVC-04, IMGSVC-09]
---

# Plan 02-03 — format-convert — SUMMARY

## Endpoint livré

`POST /img/format-convert` — params `{format: png|jpg|jpeg|webp|avif, quality?: 1..100}`

## Logique mode-couleur

| Source mode | Cible | Action |
|-------------|-------|--------|
| RGBA / LA / PA | JPEG | `_flatten_on_white()` + header `X-Alpha-Flattened: true` |
| autre non-RGB/L | JPEG | `convert("RGB")` |
| non-RGB/RGBA | AVIF | `convert("RGBA" if A else "RGB")` |
| toutes | PNG/WebP | passthrough — alpha préservée |

## Décisions implem

- **Alpha-flatten obligatoire** sur JPEG : composite RGBA sur fond blanc `(255,255,255)` via `bg.paste(img, mask=img.split()[3])`. Header `X-Alpha-Flattened: true` signale au PHP (Phase 3) que la transparence a été perdue — utile pour warning UX.
- **`jpg` vs `jpeg`** : `Literal[...]` accepte les deux, normalisé à `image/jpeg` côté Content-Type.
- **AVIF** : codec déjà enregistré au module load (Plan 02-01 `import pillow_avif`). Try/except `KeyError` défensif → 422 si codec manquant.
- **quality hors range** : Pydantic `Field(ge=1, le=100)` rejette 0 et 101 → 422 automatique.

## Tests (12/12 ✓)

1. test_to_png — RGB JPEG → PNG decodable
2. test_to_jpeg_quality — quality 30 < quality 95 en taille
3. test_jpg_alias_normalised_to_image_jpeg — `jpg` → `image/jpeg`
4. test_to_webp — quality + format
5. test_to_avif — round-trip AVIF
6. test_rgba_to_jpeg_flattens_on_white — header + mode RGB
7. test_rgb_to_jpeg_no_flatten_header — pas de header parasite
8. test_rgba_to_webp_preserves_alpha
9. test_rgba_to_png_preserves_alpha
10. test_quality_out_of_range_422 — 0 et 101
11. test_unsupported_format_422 — bmp
12. test_format_convert_rejects_svg

## Webfacto reminder

`X-Alpha-Flattened: true` est le contrat header avec PHP Phase 3. Tout consumer doit le détecter pour signaler à l'utilisateur la perte de transparence (UX).
