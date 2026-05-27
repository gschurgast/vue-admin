# Phase 2: Python Image Service (classical endpoints) — Research

**Researched:** 2026-05-27
**Domain:** FastAPI image processing service — Pillow classical ops, AVIF support, EXIF handling, add_background multipart strategy, /health model status
**Confidence:** HIGH (all critical claims verified against the live embedder container or official sources)

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| IMGSVC-01 | POST /img/resize — width/height/mode fit\|cover\|contain, upscale flag | Pillow `ImageOps.fit`, `ImageOps.pad`, `thumbnail` verified in live container |
| IMGSVC-02 | POST /img/crop — absolute (x/y/w/h) ou aspect ratio + anchor | Pillow `Image.crop()` + manual aspect-ratio math verified |
| IMGSVC-03 | POST /img/rotate — angle, expand canvas, transparent bg pour RGBA | Pillow `Image.rotate(expand=True)` — RGBA background is transparent by default, verified |
| IMGSVC-04 | POST /img/format-convert — png/jpg/jpeg/webp/avif, quality param, alpha-flatten si JPEG | WebP natif Pillow 11.0 OK ; AVIF via `pillow-avif-plugin==1.5.5` (import side-effect) ; alpha-flatten pattern vérifié |
| IMGSVC-05 | POST /img/add-background — type:color (#RRGGBB) OU type:asset (assetId int) — SSRF-safe | PHP sérialise les bytes du bg asset en second champ multipart — Python ne fait jamais de requête réseau |
| IMGSVC-08 | EXIF auto-orient à chaque endpoint + rejet > 50 MPx avec 422 | `ImageOps.exif_transpose()` vérifié ; `Image.open()` lazy + `.size` avant `.load()` ; Pillow `MAX_IMAGE_PIXELS` = 89 MPx par défaut — surcharger à 50 MPx via exception explicite |
| IMGSVC-09 | Retourner l'image traitée en binaire (Content-Type approprié) + headers de timing | FastAPI `Response(content=bytes, media_type=..., headers={"X-Processing-Time": ...})` |
| IMGSVC-10 | GET /health — état clip/birefnet/stable_diffusion (`loaded\|lazy\|not_loaded\|failed`) | Extension de l'endpoint existant — schéma étendu pour P4/P5 |

</phase_requirements>

---

## Summary

Phase 2 étend le service `embedder/` FastAPI existant (`python:3.11-slim`, Pillow 11.0, FastAPI 0.115.6, Pydantic 2.13) avec cinq endpoints d'image classiques. Toute la manipulation reste dans Pillow — OpenCV n'est **pas nécessaire** en Phase 2 (les ops crop/resize/rotate sont natives Pillow). L'unique dépendance nouvelle est `pillow-avif-plugin==1.5.5` pour l'encodage AVIF : le plugin s'enregistre par import side-effect et fonctionne avec Pillow 11.0 (vérifié dans le container live). L'endpoint `/health` est refactorisé vers un schéma structuré par modèle (`clip`, `birefnet`, `stable_diffusion`) extensible en P4/P5.

La question la plus structurante de cette phase est la stratégie `add_background type:asset` : l'embedder n'a **aucun accès S3** (aucune variable d'environnement AWS dans le service, confirmé). La solution SSRF-safe et la plus simple est que le **PHP sérialise les bytes du fond en second champ multipart** (`background_image`), évitant toute dépendance S3 côté Python. Les SVG ne sont **pas supportés** par les endpoints d'image classiques (Pillow ne lit pas les SVG) — un asset SVG envoyé à n'importe quel endpoint de Phase 2 doit retourner 422.

**Recommandation principale :** Pillow pur pour tous les endpoints classiques, `pillow-avif-plugin` pour AVIF, multipart double-champ pour `add_background type:asset`. Aucun besoin d'OpenCV en Phase 2.

---

## Standard Stack

### Core (modifications Dockerfile/requirements.txt uniquement)

| Library | Version | Purpose | Justification |
|---------|---------|---------|---------------|
| `pillow` | `==11.0.0` (déjà installé) | Toutes les opérations classiques (resize, crop, rotate, format-convert, composite) | Déjà en place ; `ImageOps.exif_transpose`, `ImageOps.fit`, `ImageOps.pad`, `Image.rotate(expand)` couvrent tous les besoins [VERIFIED: live container] |
| `pillow-avif-plugin` | `==1.5.5` | Encoder AVIF (Pillow 11.0 n'a pas AVIF natif, AVIF natif arrive en Pillow 11.2+) | Import side-effect enregistre le codec ; fonctionne avec Pillow 11.0 + Python 3.11 [VERIFIED: live container] |
| `pydantic` | `2.13.4` (déjà installé) | Validation des paramètres JSON de chaque endpoint | Pydantic v2 `BaseModel.model_validate_json()` ; `Field(gt=0, le=8000)` etc. [VERIFIED: live container] |

### Supporting

| Library | Version | Purpose | Quand utiliser |
|---------|---------|---------|----------------|
| `pytest` | `==9.0.3` (à ajouter en dev-dep) | Test runner | Wave 0 — aucun test présent, à créer |
| `pytest-asyncio` | `==1.4.0` (à ajouter en dev-dep) | Tests async FastAPI | Requis pour `async def test_*` + `AsyncClient` |
| `httpx` | `==0.28.1` (à ajouter en dev-dep) | `AsyncClient` pour TestClient FastAPI | Requis par `fastapi.testclient.TestClient` [VERIFIED: container manque httpx] |

### Alternatives écartées

| Standard | Alternative | Pourquoi écarté |
|----------|-------------|-----------------|
| `pillow-avif-plugin` | `pillow-heif` | pillow-heif a supprimé le support AVIF (focalisé HEIC) pour laisser place au support natif Pillow 11.2+ [CITED: search results] |
| `pillow-avif-plugin` | Pillow 11.2+ AVIF natif | L'image actuelle est Pillow 11.0.0 — mettre à jour vers 11.2+ nécessite recompiler avec libavif (non disponible en pré-compilé sur Debian trixie sans apt install libavif16) ; upgrade optionnel en P3+ [VERIFIED: container test] |
| Pillow pur | OpenCV | OpenCV n'est pas nécessaire en Phase 2 (pas de perspective warp, pas de detection). Poids +200 MB image Docker pour aucun gain. [ASSUMED] |
| Double-champ multipart | Python lit S3 directement | L'embedder n'a aucun env var S3/AWS — ajout de credentials S3 augmenterait la surface d'attaque et le couplage [VERIFIED: docker-compose.yml + container env] |

### Installation

```dockerfile
# Dockerfile — ajouter après l'install pip existant
RUN pip install --no-cache-dir pillow-avif-plugin==1.5.5
```

```
# requirements.txt — ajouter
pillow-avif-plugin==1.5.5
```

```
# requirements-dev.txt (nouveau fichier) — pour les tests uniquement
pytest==9.0.3
pytest-asyncio==1.4.0
httpx==0.28.1
```

---

## Architecture Patterns

### Structure recommandée du service

```
embedder/
├── app.py                  # FastAPI app — ajouter les 5 routers + health refacto
├── routers/
│   ├── __init__.py
│   ├── img_resize.py
│   ├── img_crop.py
│   ├── img_rotate.py
│   ├── img_format_convert.py
│   └── img_add_background.py
├── core/
│   ├── __init__.py
│   ├── image_utils.py      # decode_image(), check_pixel_limit(), content_type_for_format()
│   └── models.py           # Pydantic request models pour chaque endpoint
├── requirements.txt
├── requirements-dev.txt
├── Dockerfile
└── tests/
    ├── conftest.py         # fixtures (client, sample images)
    ├── fixtures/
    │   ├── test_rgb.png          # 200x150 PNG RGB
    │   ├── test_rgba.png         # 200x200 RGBA avec transparence
    │   ├── test_exif_rot6.jpg    # JPEG avec Orientation=6 (iPhone portrait)
    │   └── test_huge_mock.py     # helper créant un "header" PNG 10000x5001
    ├── test_resize.py
    ├── test_crop.py
    ├── test_rotate.py
    ├── test_format_convert.py
    ├── test_add_background.py
    └── test_health.py
```

### Pattern 1 : Décodage sécurisé commun (wrapper de base)

Toutes les routes appellent ce helper en premier. Il rejette les images SVG et > 50 MPx **avant** de décoder les pixels.

```python
# Source: IMGSVC-08 requirement + Pillow docs [VERIFIED: live container]
import io
from PIL import Image, ImageOps, UnidentifiedImageError
from fastapi import HTTPException

MAX_PIXELS = 50_000_000  # 50 megapixels

def decode_image(raw: bytes) -> Image.Image:
    """
    Ouvre une image de façon paresseuse, vérifie le pixel count AVANT load(),
    applique exif_transpose, et retourne l'objet Image.
    Rejette SVG, images > 50 MPx, et binaires non-images.
    """
    try:
        img = Image.open(io.BytesIO(raw))
    except UnidentifiedImageError as exc:
        raise HTTPException(status_code=422, detail=f"Fichier image non reconnu : {exc}")

    # .size est disponible après open() (lazy) — pas de décodage pixels encore
    w, h = img.size
    if w * h > MAX_PIXELS:
        raise HTTPException(
            status_code=422,
            detail=f"Image trop grande ({w}x{h} = {w*h/1e6:.1f} MPx). Maximum : 50 MPx."
        )

    # exif_transpose() retourne une NOUVELLE image — toujours réassigner
    img = ImageOps.exif_transpose(img)
    img.load()  # déclenche le décodage complet maintenant que la taille est validée
    return img
```

### Pattern 2 : Réponse image avec header de timing

```python
# Source: FastAPI Response + IMGSVC-09 [VERIFIED: live container pattern]
import io
import time
from fastapi import Response

def image_response(img: Image.Image, fmt: str, quality: int | None = None) -> Response:
    t0 = time.perf_counter()
    buf = io.BytesIO()
    save_kwargs: dict = {}
    if quality is not None:
        save_kwargs["quality"] = quality
    img.save(buf, format=fmt.upper(), **save_kwargs)
    elapsed_ms = (time.perf_counter() - t0) * 1000
    return Response(
        content=buf.getvalue(),
        media_type=content_type_for_format(fmt),
        headers={"X-Processing-Time": f"{elapsed_ms:.1f}ms"},
    )

CONTENT_TYPES = {
    "png": "image/png",
    "jpeg": "image/jpeg",
    "jpg": "image/jpeg",
    "webp": "image/webp",
    "avif": "image/avif",
}

def content_type_for_format(fmt: str) -> str:
    return CONTENT_TYPES.get(fmt.lower(), "application/octet-stream")
```

### Pattern 3 : POST /img/resize

```python
# Source: Pillow ImageOps [VERIFIED: live container]
from PIL import Image, ImageOps
from pydantic import BaseModel, Field
from typing import Literal, Optional
from fastapi import File, UploadFile, Form

class ResizeParams(BaseModel):
    width: Optional[int] = Field(None, gt=0, le=8000)
    height: Optional[int] = Field(None, gt=0, le=8000)
    mode: Literal["fit", "cover", "contain"] = "fit"
    upscale: bool = False

@app.post("/img/resize")
async def resize(image: UploadFile = File(...), params: str = Form(...)):
    p = ResizeParams.model_validate_json(params)
    raw = await image.read()
    img = decode_image(raw)

    src_w, src_h = img.size
    target_w = p.width or src_w
    target_h = p.height or src_h

    # Pas d'upscale si non demandé
    if not p.upscale:
        target_w = min(target_w, src_w)
        target_h = min(target_h, src_h)

    if p.mode == "cover":
        # Rognage centré pour couvrir le target (ImageOps.fit)
        result = ImageOps.fit(img, (target_w, target_h), Image.Resampling.LANCZOS)
    elif p.mode == "contain":
        # Ajout de bandes noires pour contenir (ImageOps.pad)
        result = ImageOps.pad(img, (target_w, target_h), Image.Resampling.LANCZOS)
    else:  # fit — conserver le ratio, thumbnail
        img.thumbnail((target_w, target_h), Image.Resampling.LANCZOS)
        result = img

    fmt = img.format or "png"  # conserver le format source
    return image_response(result, fmt)
```

### Pattern 4 : POST /img/crop

Deux modes : coordonnées absolues OU rapport d'aspect + ancre.

```python
# Source: Pillow Image.crop() [VERIFIED: live container]
from pydantic import BaseModel, Field
from typing import Literal, Optional

class CropParamsAbsolute(BaseModel):
    x: int = Field(ge=0)
    y: int = Field(ge=0)
    width: int = Field(gt=0)
    height: int = Field(gt=0)

class CropParamsRatio(BaseModel):
    aspectRatio: float = Field(gt=0)  # e.g. 1.0 pour carré, 1.777 pour 16:9
    anchor: Literal["center", "top", "bottom", "left", "right"] = "center"

# ancrage : calcul de la boîte de crop
def crop_by_ratio(img: Image.Image, ratio: float, anchor: str) -> Image.Image:
    w, h = img.size
    if w / h > ratio:  # image trop large → rogner latéralement
        new_w = int(h * ratio)
        if anchor == "left":    left = 0
        elif anchor == "right": left = w - new_w
        else:                   left = (w - new_w) // 2
        return img.crop((left, 0, left + new_w, h))
    else:               # image trop haute → rogner verticalement
        new_h = int(w / ratio)
        if anchor == "top":    top = 0
        elif anchor == "bottom": top = h - new_h
        else:                  top = (h - new_h) // 2
        return img.crop((0, top, w, top + new_h))
```

### Pattern 5 : POST /img/rotate

```python
# Source: Pillow Image.rotate() [VERIFIED: live container]
class RotateParams(BaseModel):
    angle: float  # degrés, sens antihoraire
    background: str = "#000000"  # couleur de fond pour les zones découvertes
    expand: bool = True  # agrandir le canvas si True

@app.post("/img/rotate")
async def rotate(image: UploadFile = File(...), params: str = Form(...)):
    p = RotateParams.model_validate_json(params)
    raw = await image.read()
    img = decode_image(raw)

    # Pour RGBA, rotate() utilise la transparence (fillcolor=None par défaut)
    # Pour RGB, remplir avec la couleur de fond
    if img.mode == "RGBA":
        result = img.rotate(p.angle, expand=p.expand, resample=Image.Resampling.BICUBIC)
    else:
        # Parser la couleur hex
        bg = tuple(int(p.background.lstrip("#")[i:i+2], 16) for i in (0, 2, 4))
        result = img.rotate(p.angle, expand=p.expand, resample=Image.Resampling.BICUBIC,
                            fillcolor=bg)
    fmt = img.format or "png"
    return image_response(result, fmt)
```

### Pattern 6 : POST /img/format-convert — AVIF + alpha-flatten

```python
# Source: Pillow + pillow-avif-plugin [VERIFIED: live container test]
import pillow_avif  # DOIT être importé au niveau module pour enregistrer le codec AVIF

class FormatConvertParams(BaseModel):
    format: Literal["png", "jpg", "jpeg", "webp", "avif"]
    quality: Optional[int] = Field(None, ge=1, le=100)

@app.post("/img/format-convert")
async def format_convert(image: UploadFile = File(...), params: str = Form(...)):
    p = FormatConvertParams.model_validate_json(params)
    raw = await image.read()
    img = decode_image(raw)

    target_fmt = p.format.upper().replace("JPG", "JPEG")

    # Alpha-flatten sur JPEG : composite sur fond blanc
    if target_fmt == "JPEG" and img.mode in ("RGBA", "LA", "PA"):
        bg = Image.new("RGB", img.size, (255, 255, 255))
        if img.mode == "RGBA":
            bg.paste(img, mask=img.split()[3])  # canal alpha comme masque
        else:
            bg.paste(img.convert("RGBA"), mask=img.convert("RGBA").split()[3])
        img = bg
    elif target_fmt != "PNG":
        img = img.convert("RGB")

    return image_response(img, p.format, quality=p.quality)
```

### Pattern 7 : POST /img/add-background — type:color ET type:asset

La clé de cette phase : PHP envoie deux champs multipart.

```python
# Source: conception SSRF-safe (embedder sans creds S3) [VERIFIED: container env check]
from typing import Union, Optional
from pydantic import BaseModel

class BgColor(BaseModel):
    type: Literal["color"]
    color: str  # "#RRGGBB"

class BgAsset(BaseModel):
    type: Literal["asset"]
    # Pas d'URL — jamais. PHP résout les bytes et les envoie directement.
    # Le champ assetId n'est présent qu'à titre de traçabilité dans les logs.
    assetId: int

@app.post("/img/add-background")
async def add_background(
    image: UploadFile = File(...),
    params: str = Form(...),
    background_image: Optional[UploadFile] = File(None),  # présent si type=asset
):
    p_raw = json.loads(params)
    raw = await image.read()
    img = decode_image(raw)

    if p_raw["type"] == "color":
        color = tuple(int(p_raw["color"].lstrip("#")[i:i+2], 16) for i in (0, 2, 4))
        bg = Image.new("RGBA" if img.mode == "RGBA" else "RGB", img.size, color + (255,))
        if img.mode == "RGBA":
            result = bg
            result.paste(img, mask=img.split()[3])
        else:
            result = bg.convert("RGB")
            result.paste(img)
    elif p_raw["type"] == "asset":
        if background_image is None:
            raise HTTPException(status_code=422, detail="background_image requis pour type:asset")
        bg_raw = await background_image.read()
        bg_img = decode_image(bg_raw)
        # Redimensionner le fond aux dimensions de l'image source
        bg_img = bg_img.resize(img.size, Image.Resampling.LANCZOS).convert("RGBA")
        if img.mode == "RGBA":
            bg_img.paste(img, mask=img.split()[3])
        else:
            bg_img.paste(img)
        result = bg_img.convert("RGB")
    else:
        raise HTTPException(status_code=422, detail=f"type inconnu : {p_raw['type']}")

    fmt = img.format or "png"
    return image_response(result, fmt)
```

### Pattern 8 : GET /health — schéma étendu pour P4/P5

```python
# Source: extension de l'endpoint existant [VERIFIED: app.py existant]
# Le schéma doit être extensible sans breaking change pour P4 (birefnet) et P5 (SD)

from enum import Enum

class ModelStatus(str, Enum):
    loaded = "loaded"
    lazy = "lazy"        # initialisé lazily, pas encore chargé
    not_loaded = "not_loaded"  # modèle pas dans cette phase
    failed = "failed"

@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "models": {
            "clip": {
                "status": ModelStatus.loaded if _model is not None else ModelStatus.lazy,
                "name": MODEL_NAME,
                "dim": EMBEDDING_DIM,
            },
            "birefnet": {
                "status": ModelStatus.not_loaded,  # P4 le changera en lazy/loaded
            },
            "stable_diffusion": {
                "status": ModelStatus.not_loaded,  # P5 le changera
            },
        },
    }
```

### Anti-Patterns à éviter

- **Appeler `.load()` avant vérifier `.size`** : `Image.open()` est lazy. Checker `img.size` d'abord, puis `.load()` — le seul moyen de rejeter les images > 50 MPx sans OOM.
- **Ne pas réassigner après `exif_transpose()`** : la fonction retourne une nouvelle image ; `img = ImageOps.exif_transpose(img)` — oublier le `=` garde l'ancienne orientation. [VERIFIED: comportement vérifié live]
- **Importer `pillow_avif` dans un try/except** : l'import doit être au niveau module et garanti. S'il échoue (libavif absent), l'endpoint AVIF doit retourner 422 clair, pas un 500 silencieux.
- **Encoder en JPEG une image RGBA sans flatten** : Pillow lèverait une exception ou produirait une image corrompue selon les versions. Le flatten sur blanc est obligatoire.
- **Modifier `Image.MAX_IMAGE_PIXELS` globalement** : ne pas le désactiver (`= None`) — utiliser le check manuel `w * h > 50_000_000` qui donne un message d'erreur contrôlé.
- **`async def` avec Pillow sans thread pool** : Pillow libère le GIL sur les opérations CPU-bound, donc plusieurs resize concurrents sont OK sans lock. Cependant, les endpoints doivent être déclarés `async def` et le traitement Pillow peut rester synchrone dans ce contexte (FastAPI l'exécute dans un thread pool via `run_in_executor` automatiquement pour les def synchrones, mais les `async def` Pillow sont acceptables pour < 500ms). [CITED: FastAPI docs + search results 2025]

---

## Don't Hand-Roll

| Problème | Ne pas construire | Utiliser | Pourquoi |
|----------|-------------------|----------|---------|
| EXIF orientation | Lookup table manuelle Orientation → rotation | `ImageOps.exif_transpose(img)` | Gère les 8 valeurs EXIF, retire le tag, retourne un nouvel objet [VERIFIED] |
| Resize mode "contain" avec padding | Loop manuel + calcul | `ImageOps.pad(img, (w, h), color=...)` | Calcule le ratio, centre, padde [VERIFIED] |
| Resize mode "cover" avec crop centré | Crop + resize manuel | `ImageOps.fit(img, (w, h))` | Calcule le ratio optimal, crope centré [VERIFIED] |
| Alpha flatten JPEG | Conversion manuelle mode | `bg.paste(img, mask=img.split()[3])` + `save JPEG` | Pattern canonique Pillow [VERIFIED] |
| AVIF encode | Recompiler Pillow | `pillow-avif-plugin==1.5.5` | S'installe en 1 ligne pip, fonctionne par import side-effect [VERIFIED] |
| Validation params | Validation manuelle JSON | Pydantic v2 `BaseModel.model_validate_json()` | Types, ranges, enums — 422 automatique via FastAPI [VERIFIED] |

---

## Common Pitfalls

### Pitfall 1 : `exif_transpose` sur JPEG avec profil ICC
**Ce qui se passe :** `ImageOps.exif_transpose()` applique la rotation mais peut perdre le profil ICC.
**Pourquoi :** Pillow strip les métadonnées au `rotate()` selon les versions.
**Comment éviter :** Après `exif_transpose`, vérifier `img.info.get('icc_profile')` et le réinjecter si présent. Alternative simple : copier `img.info['icc_profile']` avant transposition et le passer à `.save()` via `icc_profile=`.
**Signaux d'alerte :** Couleurs délavées sur photos produit avec espace colorimétrique élargi (Adobe RGB).

### Pitfall 2 : `pillow-avif-plugin` non importé = pas de codec AVIF
**Ce qui se passe :** `Image.save(buf, "AVIF")` lève `KeyError: 'AVIF'` si le plugin n'est pas importé.
**Pourquoi :** Le plugin s'enregistre via un import side-effect — il doit être importé au niveau module dans `app.py` ou dans `routers/img_format_convert.py`.
**Comment éviter :** `import pillow_avif` au top du module qui gère format-convert. Ajouter un test de smoke qui encode une image AVIF au démarrage et logue le résultat.
**Signaux d'alerte :** 500 sur `/img/format-convert` avec `format=avif` uniquement.

### Pitfall 3 : SVG envoyé à un endpoint image
**Ce qui se passe :** `Image.open()` sur un SVG lève `UnidentifiedImageError` — Pillow ne lit pas les SVG.
**Pourquoi :** SVG est un format vectoriel XML, pas raster. Or l'enum `AssetType.IMAGE` dans le PHP autorise `image/svg+xml` [VERIFIED: AssetType.php].
**Comment éviter :** Détecter le MIME avant `Image.open()` : si `image/svg+xml`, retourner 422 avec le message "SVG non supporté par les endpoints de transformation raster".
**Signaux d'alerte :** Erreur 422 pour tous les assets SVG — comportement attendu et documenté.

### Pitfall 4 : `add_background type:asset` — PHP doit envoyer les bytes, pas l'ID
**Ce qui se passe :** Si PHP envoie uniquement `assetId`, Python doit aller chercher les bytes. L'embedder n'a pas de credentials S3 et n'a pas accès à Flysystem [VERIFIED: aucune env var S3 dans embedder].
**Pourquoi :** Architecture intentionnelle — l'embedder est un service de transformation sans état, pas un lecteur de stockage.
**Comment éviter :** PHP résout `Asset::computeS3Key(assetId, ext)`, lit les bytes via `FilesystemOperator::readStream($key)`, et les envoie en second champ multipart `background_image`. Le champ `assetId` dans le JSON params sert uniquement à la traçabilité dans les logs Python.
**Signaux d'alerte :** Tentation d'ajouter `S3_*` env vars à l'embedder — red flag de design.

### Pitfall 5 : `image/jpeg` vs `image/jpg` Content-Type
**Ce qui se passe :** Le PHP peut recevoir un Content-Type `image/jpg` (non standard) mais envoyer `image/jpeg` en retour. Les navigateurs acceptent les deux mais certains proxies rejettent `image/jpg`.
**Comment éviter :** La map `CONTENT_TYPES` doit normaliser `jpg` → `image/jpeg`. Toujours retourner `image/jpeg` jamais `image/jpg`.

### Pitfall 6 : `Image.open()` garde le fichier ouvert
**Ce qui se passe :** `Image.open(io.BytesIO(raw))` garde la référence au BytesIO. Si `.load()` n'est pas appelé et que le BytesIO est GC'd, les opérations ultérieures échouent.
**Comment éviter :** Appeler `.load()` explicitement dans `decode_image()` après la vérification de taille. C'est le pattern recommandé pour les images chargées depuis bytes.

---

## Code Examples

### Structure de réponse d'erreur (alignée PHP RetryableHttpClient)

```python
# Source: FastAPI standard [VERIFIED]
# PHP RetryableHttpClient retente sur 5xx, pas sur 4xx
# 422 Unprocessable Entity = erreur client (params invalides, image trop grande, SVG) — ne pas retenter
# 500 Internal Server Error = bug serveur — retenter (max 3x)
from fastapi.responses import JSONResponse

# Pattern standard FastAPI — déjà utilisé dans app.py existant
raise HTTPException(status_code=422, detail="Image trop grande : 55.0 MPx > 50 MPx")
# Retourne : {"detail": "Image trop grande : 55.0 MPx > 50 MPx"}
```

### Multipart request depuis PHP vers Python (contrat)

```php
// Source: pattern existant AssetEmbedder.php (VERIFIED)
// Pour add_background type:asset :
$response = $this->client->request('POST', "{$this->embedderUrl}/img/add-background", [
    'body' => [
        'image' => DataPart::fromPath($tempImagePath, 'image', $mimeType),
        'params' => json_encode(['type' => 'asset', 'assetId' => $bgAsset->getId()]),
        'background_image' => DataPart::fromPath($tempBgPath, 'background_image', $bgMimeType),
    ],
]);
```

### Test pattern minimal (Wave 0)

```python
# Source: FastAPI docs + httpx AsyncClient [ASSUMED - pattern standard]
import pytest
import httpx
from fastapi.testclient import TestClient
from app import app  # ou AsyncClient pour async

@pytest.fixture
def client():
    return TestClient(app)

@pytest.fixture
def rgb_image():
    """Petit PNG 200x150 RGB généré en mémoire."""
    from PIL import Image
    import io
    img = Image.new("RGB", (200, 150), color=(255, 0, 0))
    buf = io.BytesIO()
    img.save(buf, "PNG")
    buf.seek(0)
    return buf.getvalue()

def test_resize_fit(client, rgb_image):
    response = client.post(
        "/img/resize",
        data={"params": '{"width": 100, "height": 100, "mode": "fit"}'},
        files={"image": ("test.png", rgb_image, "image/png")},
    )
    assert response.status_code == 200
    assert response.headers["content-type"] == "image/png"
    from PIL import Image; import io
    result = Image.open(io.BytesIO(response.content))
    assert result.size[0] <= 100 and result.size[1] <= 100
```

---

## Questions techniques résolues

### Q1 : Pillow vs OpenCV pour Phase 2 ?
**Réponse : Pillow uniquement.** Resize/crop/rotate/format-convert/composite sont nativement dans Pillow. OpenCV n'apporte rien en Phase 2 et ajoute ~200 MB à l'image Docker. [ASSUMED pour le poids, VERIFIED pour les fonctionnalités Pillow]

### Q2 : AVIF — quelle lib ?
**Réponse : `pillow-avif-plugin==1.5.5`.** Fonctionne avec Pillow 11.0.0 (vérifié dans le container live). L'alternative "Pillow 11.2+ natif" nécessite `libavif16` système + upgrade Pillow, reporté à P3+. [VERIFIED]

### Q3 : EXIF auto-rotate — où l'appeler ?
**Réponse : Dans `decode_image()`, après `Image.open()` et avant `.load()`.** Le check de taille (`img.size`) est disponible après `open()` sans `.load()`. Ensuite `exif_transpose()`, ensuite `.load()`. [VERIFIED: Pillow lazy open]

### Q4 : MPx limit — comment implémenter sans OOM ?
**Réponse : `img.size` (tuple width, height) est disponible après `Image.open()` sans décoder les pixels.** Checker `w * h > 50_000_000`, lever HTTPException(422). NE PAS modifier `Image.MAX_IMAGE_PIXELS` globalement car cela affecte aussi l'endpoint `/embed` CLIP. [VERIFIED: live container test]

### Q5 : `add_background type:asset` — comment Python obtient les bytes du fond ?
**Réponse : PHP lit les bytes de l'asset fond via Flysystem et les envoie en second champ multipart `background_image`.** L'embedder n'a aucune variable d'environnement S3 [VERIFIED: docker-compose.yml + container env]. Ajouter des credentials S3 à l'embedder est un anti-pattern de sécurité.

### Q6 : Lock asyncio pour les opérations Pillow classiques ?
**Réponse : Aucun lock nécessaire.** Pillow libère le GIL sur les opérations CPU-bound. Plusieurs resize/format-convert peuvent s'exécuter en parallèle sans corruption. Les locks asyncio sont réservés aux modèles ML non thread-safe (BiRefNet en P4, SD en P5). [CITED: FastAPI docs 2025 + Pillow GIL behavior]

### Q7 : Format de sortie par défaut quand non spécifié ?
**Réponse : Conserver le format de l'image source.** `img.format` donne le format original après `Image.open()`. Si `None` (image créée programmatiquement), fallback sur PNG. Pour les endpoints resize/crop/rotate, le format source est préservé. Pour `format-convert`, le format cible est explicite.

### Q8 : SVG — supporté en Phase 2 ?
**Réponse : Non.** Pillow ne lit pas les SVG (`UnidentifiedImageError` confirmé live). Retourner 422 avec message explicite. L'encodage SVG→raster (via cairosvg) est hors scope Phase 2.

### Q9 : `async def` vs `def` pour les endpoints image ?
**Réponse : `async def` est correct.** Pour les opérations Pillow < ~500ms, le traitement synchrone dans un `async def` est acceptable (FastAPI l'exécute dans le thread pool). Pour des opérations potentiellement longues (AVIF encode sur grosse image), préférer `asyncio.to_thread()` ou un `def` standard (FastAPI utilise automatiquement un executor pour les `def` sync). En pratique, les endpoints Phase 2 sont suffisamment rapides pour `async def` avec Pillow synchrone. [CITED: FastAPI docs best practices 2025]

### Q10 : Breaking change sur `/health` ?
**Réponse : Changement de schéma mais pas breaking.** L'ancien schéma `{"status": "ok", "model": ..., "dim": ..., "loaded": bool}` devient `{"status": "ok", "models": {"clip": {...}, "birefnet": {...}, "stable_diffusion": {...}}}`. Aucun PHP ne consomme `/health` actuellement [VERIFIED: grep API src/]. Le Dockerfile HEALTHCHECK appelle `/health` pour asserter HTTP 200 uniquement — pas de parsing du body. Pas de breaking change pratique.

---

## Environment Availability

| Dependency | Required By | Available | Version | Notes |
|------------|------------|-----------|---------|-------|
| Python 3.11 | Tous les endpoints | ✓ | 3.11 (slim) | [VERIFIED: container] |
| Pillow | Toutes les ops image | ✓ | 11.0.0 | [VERIFIED: container] |
| FastAPI | Routing | ✓ | 0.115.6 | [VERIFIED: container] |
| Pydantic v2 | Validation params | ✓ | 2.13.4 | [VERIFIED: container] |
| pillow-avif-plugin | AVIF encode | ✗ dans image → à ajouter | 1.5.5 | `pip install pillow-avif-plugin==1.5.5` — fonctionne sans libavif système car le wheel embarque libavif [VERIFIED: test live] |
| WebP support | WEBP encode | ✓ | natif Pillow 11.0 | [VERIFIED: live container test] |
| SVG support | N/A | ✗ | — | Hors scope Phase 2 |
| pytest + httpx + pytest-asyncio | Tests | ✗ dans image → à ajouter | pytest 9.0.3, httpx 0.28.1, pytest-asyncio 1.4.0 | requirements-dev.txt [VERIFIED: container manque httpx] |

**Dépendances manquantes sans fallback :**
- `pillow-avif-plugin` : requis pour IMGSVC-04 (AVIF). Sans lui, l'endpoint format-convert retourne 422 sur `avif`.

**Dépendances manquantes avec solution :**
- `pytest`, `httpx`, `pytest-asyncio` : tests uniquement — ne bloquent pas le runtime.

---

## Validation Architecture

### Test Framework

| Propriété | Valeur |
|-----------|--------|
| Framework | pytest 9.0.3 + pytest-asyncio 1.4.0 + httpx 0.28.1 |
| Config file | `embedder/pytest.ini` (à créer — Wave 0) |
| Quick run command | `pytest embedder/tests/ -x -q` |
| Full suite command | `pytest embedder/tests/ -v --tb=short` |
| Dans Docker | `docker compose exec embedder pytest tests/ -x` (après install dev-deps) |

### Phase Requirements → Test Map

| REQ-ID | Behaviour | Test Type | Commande automatisée | Fichier existant ? |
|--------|-----------|-----------|----------------------|--------------------|
| IMGSVC-01 | resize fit préserve ratio | unit | `pytest tests/test_resize.py::test_fit_preserves_ratio -x` | ❌ Wave 0 |
| IMGSVC-01 | resize cover produit exactement target size | unit | `pytest tests/test_resize.py::test_cover_exact_size -x` | ❌ Wave 0 |
| IMGSVC-01 | resize contain avec padding | unit | `pytest tests/test_resize.py::test_contain_padding -x` | ❌ Wave 0 |
| IMGSVC-01 | resize sans upscale ne dépasse pas src | unit | `pytest tests/test_resize.py::test_no_upscale -x` | ❌ Wave 0 |
| IMGSVC-02 | crop absolu (x/y/w/h) | unit | `pytest tests/test_crop.py::test_absolute_crop -x` | ❌ Wave 0 |
| IMGSVC-02 | crop par ratio 1:1 centré | unit | `pytest tests/test_crop.py::test_ratio_center -x` | ❌ Wave 0 |
| IMGSVC-02 | crop par ratio 16:9 anchor top | unit | `pytest tests/test_crop.py::test_ratio_anchor_top -x` | ❌ Wave 0 |
| IMGSVC-03 | rotate 90° RGB garde dimensions transposées | unit | `pytest tests/test_rotate.py::test_rotate_90_rgb -x` | ❌ Wave 0 |
| IMGSVC-03 | rotate 45° RGBA → fond transparent | unit | `pytest tests/test_rotate.py::test_rotate_rgba_transparent_bg -x` | ❌ Wave 0 |
| IMGSVC-04 | format-convert → PNG | unit | `pytest tests/test_format_convert.py::test_to_png -x` | ❌ Wave 0 |
| IMGSVC-04 | format-convert → JPEG avec quality | unit | `pytest tests/test_format_convert.py::test_to_jpeg_quality -x` | ❌ Wave 0 |
| IMGSVC-04 | format-convert → WebP | unit | `pytest tests/test_format_convert.py::test_to_webp -x` | ❌ Wave 0 |
| IMGSVC-04 | format-convert → AVIF | unit | `pytest tests/test_format_convert.py::test_to_avif -x` | ❌ Wave 0 |
| IMGSVC-04 | format-convert RGBA→JPEG : alpha-flatten sur blanc | unit | `pytest tests/test_format_convert.py::test_rgba_to_jpeg_flatten -x` | ❌ Wave 0 |
| IMGSVC-05 | add_background color #FF0000 | unit | `pytest tests/test_add_background.py::test_color_red -x` | ❌ Wave 0 |
| IMGSVC-05 | add_background type:asset multipart 2 champs | unit | `pytest tests/test_add_background.py::test_asset_multipart -x` | ❌ Wave 0 |
| IMGSVC-08 | EXIF Orientation=6 redresse l'image | unit | `pytest tests/test_exif.py::test_orientation_6_corrected -x` | ❌ Wave 0 |
| IMGSVC-08 | Image > 50 MPx → 422 | unit | `pytest tests/test_security.py::test_reject_huge_image -x` | ❌ Wave 0 |
| IMGSVC-08 | SVG → 422 | unit | `pytest tests/test_security.py::test_reject_svg -x` | ❌ Wave 0 |
| IMGSVC-09 | Header X-Processing-Time présent | unit | `pytest tests/test_headers.py::test_processing_time_header -x` | ❌ Wave 0 |
| IMGSVC-10 | GET /health retourne clip + birefnet + stable_diffusion | unit | `pytest tests/test_health.py::test_health_schema -x` | ❌ Wave 0 |

### Sampling Rate
- **Par commit :** `pytest embedder/tests/ -x -q` (< 10s sur fixtures in-memory)
- **Par wave merge :** `pytest embedder/tests/ -v` (suite complète)
- **Phase gate :** Suite complète verte avant `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `embedder/pytest.ini` — `[pytest] asyncio_mode = auto`
- [ ] `embedder/requirements-dev.txt` — `pytest==9.0.3`, `pytest-asyncio==1.4.0`, `httpx==0.28.1`
- [ ] `embedder/tests/__init__.py`
- [ ] `embedder/tests/conftest.py` — fixtures `client`, `rgb_image`, `rgba_image`, `exif_rot6_jpeg`, `huge_image_bytes`
- [ ] `embedder/tests/fixtures/` — générés programmatiquement (pas de binaires commitées)
- [ ] Installation dev deps dans Dockerfile (multi-stage ou target dev) OU script `make test`

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V5 Input Validation | oui | Pydantic v2 `BaseModel` + validation `MAX_PIXELS` avant décodage |
| V5 — Path traversal | N/A | Python ne construit aucun chemin fichier (tout en mémoire) |
| V5 — SSRF | oui | `add_background type:asset` : jamais d'URL acceptée, jamais de fetch réseau — multipart bytes seulement [VERIFIED: conception] |
| V6 Cryptography | non | Pas de crypto dans ce service |
| V4 Access Control | partiel | Embedder uniquement accessible en interne (port non exposé publiquement) [VERIFIED: docker-compose.yml] |

### Threat Patterns

| Pattern | STRIDE | Mitigation |
|---------|--------|------------|
| Decompression bomb (PNG/GIF malformé) | DoS | Check `w * h > 50_000_000` AVANT `.load()` — 422 immédiat |
| SSRF via URL dans `add_background` | Elevation of Privilege | Type system strict : `type: color \| asset` — aucun champ URL dans le schéma |
| Path traversal | Tampering | N/A — aucun I/O fichier, tout en mémoire (BytesIO) |
| Format confusion (JPEG header, SVG body) | Spoofing | Pillow détecte le format par magic bytes, pas par Content-Type multipart |

---

## Assumptions Log

| # | Claim | Section | Risque si faux |
|---|-------|---------|----------------|
| A1 | OpenCV ajoute ~200 MB à l'image Docker | Standard Stack alternatives | Impact faible — OpenCV n'est de toute façon pas nécessaire en Phase 2 |
| A2 | Les ops Pillow classiques (resize/crop/rotate) sont bien < 500ms sur des images produit typiques | Common Pitfalls Q9 | Si faux (très grosses images, 8000px+) → wrapping dans `asyncio.to_thread()` recommandé |
| A3 | `async def` endpoints Pillow sans `to_thread` est acceptable pour les tailles produit normales | Architecture Patterns | Si le service monte à N requêtes concurrentes lourdes → profiler et wraper si p95 > 1s |

---

## Open Questions

1. **Format de sortie de `resize`/`crop`/`rotate` quand l'input est WebP**
   - Ce qu'on sait : `img.format` retourne `"WEBP"` pour un WebP ouvert avec Pillow
   - Ce qui est flou : la Phase 3 (PHP orchestrateur) veut-elle toujours forcer le format de sortie par l'extension d'URL, ou les endpoints intermédiaires doivent-ils préserver le format source ?
   - Recommandation : les endpoints Phase 2 préservent le format source ; la conversion de format est explicitement le rôle de `format-convert`. La Phase 3 ajoutera un step `format-convert` implicite si l'extension URL diffère.

2. **`add_background type:asset` — format du champ background**
   - Ce qu'on sait : PHP doit envoyer les bytes bruts en multipart
   - Ce qui est flou : le PHP connaît le `s3Key` de l'asset fond, mais doit connaître l'extension pour choisir le MIME type du champ
   - Recommandation : PHP lit l'`AssetFlag` → `getMimeType()` de l'entité `Asset` fond et l'utilise comme Content-Type du champ `background_image`. Alternative : Python détecte le format via Pillow magic bytes (indépendant du MIME déclaré).

---

## Sources

### Primary (HIGH confidence)
- `embedder/app.py`, `embedder/Dockerfile`, `embedder/requirements.txt` — service existant à étendre [VERIFIED: live files]
- Live embedder container `antigravity-embedder-1` — Pillow 11.0.0, Python 3.11, Debian trixie ; tests Pillow exécutés directement [VERIFIED: multiple docker exec tests]
- `docker-compose.yml` — aucun env var S3 dans le service embedder [VERIFIED]
- `api/src/Service/Asset/AssetUploader.php` + `Asset.php` — layout S3 key `{shard}/{id}.{ext}` [VERIFIED]
- `api/src/Service/AssetTransformation/TransformationStorageKey.php` — helper S3 variant déjà créé en Phase 1 [VERIFIED]
- `api/src/Enum/AssetType.php` — `image/svg+xml` autorisé → SVG peut arriver sur les endpoints [VERIFIED]

### Secondary (MEDIUM confidence)
- [pillow-avif-plugin PyPI](https://pypi.org/project/pillow-avif-plugin/) — version 1.5.5, Python 3.11 compatible [VERIFIED: install test en live + PyPI]
- [Pillow ImageOps docs](https://pillow.readthedocs.io/en/stable/reference/ImageOps.html) — `exif_transpose`, `fit`, `pad` [CITED]
- [FastAPI production practices 2025](https://orchestrator.dev/blog/2025-1-30-fastapi-production-patterns/) — async vs sync pour CPU-bound [CITED]

### Tertiary (LOW confidence)
- Poids Docker d'OpenCV (~200 MB) — estimation training knowledge, non mesurée dans ce contexte [ASSUMED: A1]

---

## Metadata

**Confidence breakdown :**
- Standard stack : HIGH — toutes les libs vérifiées dans le container live
- Architecture patterns : HIGH — basé sur le code existant et tests Pillow live
- Pitfalls : HIGH — vérifiés par tests directs dans le container
- AVIF strategy : HIGH — `pillow-avif-plugin` installé et testé dans le container live
- `add_background type:asset` : HIGH — absence de vars S3 dans embedder confirmée

**Research date :** 2026-05-27
**Valid until :** 2026-06-27 (dépendances stables, Pillow changelog à surveiller pour 11.2+ AVIF natif)
