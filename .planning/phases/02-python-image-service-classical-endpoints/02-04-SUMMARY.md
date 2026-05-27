---
phase: 02-python-image-service-classical-endpoints
plan: 04
date: 2026-05-27
status: complete
requirements: [IMGSVC-05, IMGSVC-09]
---

# Plan 02-04 — add-background — SUMMARY

## Endpoint livré

`POST /img/add-background` — contrat multipart double-champ.

```
multipart/form-data
  image            (binaire, requis) — image à composer (RGBA recommandé)
  params           (texte, requis)   — JSON { type, ... }
  background_image (binaire)         — REQUIS si params.type == "asset"
```

## Schémas Pydantic

```python
class BgColor(BaseModel):
    type: Literal["color"]
    color: str = Field(pattern=r"^#[0-9A-Fa-f]{6}$")

class BgAsset(BaseModel):
    type: Literal["asset"]
    assetId: int = Field(gt=0)   # log-only, jamais utilisé pour fetch
```

**Aucune URL n'est acceptée**, jamais — anti-SSRF par construction.

## Logique composite

| Mode | Source mode | Action |
|------|-------------|--------|
| color | RGBA | composite sur fond `#RRGGBB`, sortie RGB |
| color | RGB / autre | passthrough (pas de transparence à combler) |
| asset | RGBA | `bg_img.resize(img.size)` puis `bg.paste(img, mask=alpha)` |
| asset | RGB | `bg.paste(img)` (sans alpha) |

## Anti-SSRF — defense in depth

1. **Schema** : `BgAsset` ne contient que `type` + `assetId: int`. Aucun champ `url`/`src`/`href` accepté.
2. **Top-level reject** : avant toute validation, le code rejette `params` contenant `url`/`src`/`href` au top level.
3. **No fetch capability** : container vérifié — pas de `boto3`, pas d'env vars `S3_*`/`AWS_*`.
4. **Inline only** : le fond arrive en multipart depuis PHP (qui contrôle Flysystem en Phase 3).

```
$ docker compose exec embedder env | grep -iE "s3|aws|boto"
→ (rien)
$ docker compose exec embedder pip list | grep -iE "boto|s3"
→ (rien)
```

## Tests (13/13 ✓)

**type=color (8)** :
- color_red_under_rgba (blend visible)
- color_white_under_fully_transparent
- color_on_rgb_unchanged
- invalid_hex_color_422
- unknown_type_422
- missing_type_422
- url_field_rejected_ssrf
- addbg_rejects_svg (image principale)

**type=asset (5)** :
- multipart_blue_bg_under_green (composite OK, dimensions OK)
- missing_background_image_422 (message clair)
- invalid_assetid_422 (assetId=0)
- svg_background_rejected
- huge_background_rejected (> 50 MPx)

## Contrat pour Phase 3 (PHP orchestrateur)

```php
// Le handler PHP pour step type=ai_prompt avec type=asset :
$bgAsset = $assetRepo->find($assetId);
$bgBytes = $flysystem->readStream($bgAsset->getS3Key());
$mime = $bgAsset->getMimeType();

$response = $client->request('POST', 'http://embedder:8000/img/add-background', [
    'multipart' => [
        ['name' => 'image', 'contents' => $imageBytes, 'filename' => 'src.png'],
        ['name' => 'params', 'contents' => '{"type":"asset","assetId":'.$assetId.'}'],
        ['name' => 'background_image', 'contents' => $bgBytes, 'filename' => 'bg', 'headers' => ['Content-Type' => $mime]],
    ],
]);
```

## Webfacto reminder

L'architecture SSRF-safe par construction (zéro fetch sortant côté embedder) est un choix structurant qui simplifie la posture sécu prod : pas de runtime credentials à manager côté image embedder, pas de risque d'exfiltration via crafted URLs.
