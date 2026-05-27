---
phase: 02-python-image-service-classical-endpoints
verified: 2026-05-27T00:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
---

# Phase 2 — Python Image Service (Classical Endpoints) — Rapport de vérification

**Objectif de la phase :** Étendre le service `embedder` avec les endpoints d'image classiques (Pillow/OpenCV) — un endpoint par step type non-AI — testables en isolation via curl/httpie, sans dépendance Symfony.
**Vérifié :** 2026-05-27
**Statut :** PASS
**Re-vérification :** Non — vérification initiale

---

## SC1 — 5 endpoints POST multipart → binaire + bon Content-Type

**Statut : PASS**

Tous les 5 routers sont présents et inclus dans `app.py` :
- `embedder/routers/img_resize.py` → `POST /img/resize`
- `embedder/routers/img_crop.py` → `POST /img/crop`
- `embedder/routers/img_rotate.py` → `POST /img/rotate`
- `embedder/routers/img_format_convert.py` → `POST /img/format-convert`
- `embedder/routers/img_add_background.py` → `POST /img/add-background`

Chaque router appelle `image_response()` qui positionne `media_type=content_type_for_format(fmt)` et retourne un `Response` binaire.
Tests couvrant ce critère : `test_resize.py`, `test_crop.py`, `test_rotate.py`, `test_format_convert.py`, `test_add_background.py`.

---

## SC2 — EXIF Orientation=6 corrigé ; > 50 MPx → 422

**Statut : PASS**

Dans `core/image_utils.py` `decode_image()` :
1. Guard 50 MPx : `if w * h > MAX_PIXELS` → HTTP 422 (`MAX_PIXELS = 50_000_000`)
2. EXIF transpose : `img = ImageOps.exif_transpose(img)` (réassignation explicite)
3. Matérialisation pixels : `img.load()`

Tests : `test_decode_rejects_huge` (image 8000×7000 = 56 MPx) et `test_decode_applies_exif_orientation_6` (JPEG 100×200 Orientation=6 → 200×100 après transposition).

**Résultat pytest :** 55 passed, 0 failed.

---

## SC3 — format_convert : PNG/JPEG/WebP/AVIF avec quality

**Statut : PASS**

- `pillow_avif` importé en tête de `app.py` (`import pillow_avif  # noqa: F401`) — enregistrement du codec AVIF au démarrage du module.
- `FormatConvertParams` : `Literal["png", "jpg", "jpeg", "webp", "avif"]` + `quality: Optional[int] = Field(None, ge=1, le=100)`
- Alpha-flatten sur JPEG : si `img.mode in ("RGBA", "LA", "PA")` → `_flatten_on_white()` + header `X-Alpha-Flattened: true`
- Tests verts : `test_to_png`, `test_to_jpeg_quality`, `test_to_webp`, `test_to_avif`, `test_rgba_to_jpeg_flattens_on_white`, `test_avif_codec_registered`

---

## SC4 — add_background type:asset — assetId int uniquement, SSRF-safe

**Statut : PASS**

Analyse du schéma `BgAsset` :
```python
class BgAsset(BaseModel):
    type: Literal["asset"]
    assetId: int = Field(gt=0)
```
Aucun champ URL. Defense-in-depth supplémentaire : rejet explicite des champs `url`, `src`, `href` à l'entrée JSON (`lines 76-82 img_add_background.py`).

Le service reçoit les bytes du background image comme second champ multipart `background_image` — le PHP orchestrateur (Phase 3) est responsable de lire depuis Flysystem. Le service Python n'effectue aucune requête réseau sortante.

Aucune dépendance boto/S3 dans le container : `docker compose exec embedder env | grep -iE "s3|aws|boto"` → vide ; `pip list | grep -iE "boto|s3"` → vide.

Tests : `test_url_field_rejected_ssrf`, `test_asset_missing_background_image_422`, `test_asset_invalid_assetid_422`, `test_asset_svg_background_rejected`, `test_asset_huge_background_rejected`.

---

## SC5 — GET /health : modèles clip/birefnet/stable_diffusion ; /embed CLIP intact

**Statut : PASS**

`/health` retourne :
```json
{
  "status": "ok",
  "models": {
    "clip": {"status": "loaded|lazy", "name": "...", "dim": 512},
    "birefnet": {"status": "not_loaded"},
    "stable_diffusion": {"status": "not_loaded"}
  }
}
```

Endpoint `/embed` inchangé (signature `POST /embed`, `file: UploadFile`, réponse `{embedding, model, dim}`).

Tests : `test_health_schema` (vérifie les 3 clés de modèles + dim=512), `test_embed_endpoint_still_works` (régression CLIP 512-d).

---

## Qualité — Gates additionnels

| Gate | Résultat |
|------|----------|
| `pytest tests/ -q` dans container | **55 passed, 0 failed, 2 warnings** |
| SSRF : pas de boto/S3 dans container | **Confirmé (env + pip vides)** |
| `decode_image` guard 50 MPx | Implémenté + testé |
| `decode_image` EXIF correction | Implémenté + testé |
| `decode_image` rejet SVG | Implémenté + testé |
| `pillow_avif` import module-level | Ligne 23 `app.py` |
| `X-Alpha-Flattened` header RGBA→JPEG | Ligne 51 `img_format_convert.py` + test |
| BgAsset sans champ URL | Schéma Pydantic + guard explicite |

**Note :** `@app.on_event("startup")` génère un `DeprecationWarning` FastAPI (devrait migrer vers `lifespan`). Ceci n'est pas bloquant pour la Phase 2 ; à corriger en Phase 3 ou 4.

---

## VERDICT: PASS

Les 5 critères de succès sont vérifiés dans le code et confirmés par la suite de tests (55/55 verts en container live). La phase atteint son objectif.

---

_Vérifié le 2026-05-27_
_Vérificateur : Claude (gsd-verifier)_
