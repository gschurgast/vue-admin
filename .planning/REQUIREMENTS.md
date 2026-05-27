# REQUIREMENTS.md

**Milestone:** v1.0 — Asset Transformations
**Created:** 2026-05-26
**Last revised:** 2026-05-26 — pivot architecture vers Python-first + BiRefNet + Stable Diffusion (add_background AI)

## Milestone v1.0 Requirements

### TRANSFORM — Définition des transformations

- [ ] **TRANSFORM-01** : Un administrateur peut créer une transformation avec un `code` unique (kebab-case, validé) et un libellé
- [ ] **TRANSFORM-02** : Un administrateur peut composer une transformation d'une liste ordonnée de steps typés (resize, crop, rotate, format_convert, add_background, remove_background)
- [ ] **TRANSFORM-03** : Un administrateur peut modifier les steps d'une transformation après création (paramètres, ordre, type) sans casser le cache existant
- [ ] **TRANSFORM-04** : Le système recalcule automatiquement un `versionHash` (sha1 canonical des steps) à chaque modification de step
- [ ] **TRANSFORM-05** : Un administrateur peut supprimer une transformation, ce qui purge tous ses variants S3 en arrière-plan
- [ ] **TRANSFORM-06** : Les codes réservés (`api`, `admin`, `t`, `_`, `assets`, mono-caractères) sont rejetés à la création
- [ ] **TRANSFORM-07** : Une transformation contenant au moins un step `add_background type:ai_prompt` est flaggée `requires_async` pour activer le chemin asynchrone à l'exécution

### IMGSVC — Endpoints Python d'image (un par step type)

- [ ] **IMGSVC-01** : Le service `embedder` expose `POST /img/resize` (Pillow) — body multipart : image + JSON `{ width?, height?, mode: "fit|cover|contain", upscale?: bool }`
- [ ] **IMGSVC-02** : Le service expose `POST /img/crop` — JSON `{ x, y, width, height }` ou `{ aspectRatio, anchor }`
- [ ] **IMGSVC-03** : Le service expose `POST /img/rotate` — JSON `{ angle, background?: color }`
- [ ] **IMGSVC-04** : Le service expose `POST /img/format-convert` — JSON `{ format: "png|jpg|jpeg|webp|avif", quality?: 1-100 }`. AVIF via `pillow-avif-plugin`.
- [ ] **IMGSVC-05** : Le service expose `POST /img/add-background` — JSON `{ type: "color", color: "#RRGGBB" }` ou `{ type: "asset", assetId: int }` (jamais d'URL ; SSRF-safe)
- [ ] **IMGSVC-06** : Le service expose `POST /img/remove-background` — JSON `{ model?: "birefnet|isnet-general-use", fallbackOnTimeout?: bool }` (défaut `birefnet`)
- [ ] **IMGSVC-07** : Le service expose `POST /img/generate-background` — JSON `{ prompt: str, negativePrompt?: str, strength?: float, seed?: int }` ; utilise Stable Diffusion (inpainting) sur la zone détectée transparente
- [ ] **IMGSVC-08** : Chaque endpoint applique EXIF auto-orient en pré-traitement et rejette les images > 50 mégapixels
- [ ] **IMGSVC-09** : Les endpoints retournent l'image traitée en binaire (Content-Type approprié) + headers de timing
- [ ] **IMGSVC-10** : `/health` reporte l'état de chargement des modèles : CLIP, BiRefNet, Stable Diffusion (chargé / lazy / failed)

### HANDLERS — Orchestration PHP (clients HTTP vers Python)

- [x] **HANDLERS-01** : Le système applique séquentiellement les steps via un `PipelineRunner` qui orchestre des `StepHandlerInterface` taggés
- [x] **HANDLERS-02** : Chaque handler PHP appelle l'endpoint Python correspondant via `RetryableHttpClient` (3 retries, exponential backoff, timeout step-dépendant)
- [x] **HANDLERS-03** : Chaque type de step a un DTO Validator côté PHP (`ResizeStepParams`, `CropStepParams`, …) garantissant la validation à la persistance
- [ ] **HANDLERS-04** : Le `PipelineRunner` détecte au démarrage si la transformation contient un step `requires_async` et bascule sur le chemin asynchrone
- [x] **HANDLERS-05** : Le format de sortie cible JPEG sans `add_background` aval déclenche un alpha-flatten implicite sur fond blanc + warning visible côté éditeur
- [ ] **HANDLERS-06** : Le step `add_background` accepte `{ type: "color" }`, `{ type: "asset", assetId }`, ou `{ type: "ai_prompt", prompt, ... }` ; `ai_prompt` flagge la transformation `requires_async`

### ROUTE — Route publique et cache

- [ ] **ROUTE-01** : Un consommateur HTTP non authentifié peut requêter `GET /t/{code}/{id}.{ext}` et recevoir une image transformée
- [ ] **ROUTE-02** : Le format de sortie est déterminé exclusivement par l'extension d'URL (png, jpg, jpeg, webp, avif) ; toute autre extension retourne 404
- [ ] **ROUTE-03** : Le système sert depuis le cache S3 (préfixe `transformations/{transformationId}-v{hash}/{shard}/{assetId}.{ext}`) si la variante existe, sinon génère puis stocke
- [ ] **ROUTE-04** : Pour une transformation **sans step AI**, la 1ère requête prend un Redis lock par variante, génère en sync avec cap dur 8 s, puis stream depuis S3
- [ ] **ROUTE-05** : Pour une transformation **avec step AI** (`requires_async`), la 1ère requête dispatch un `GenerateAITransformationMessage` sur le transport `transformations_ai` et retourne **202 Accepted + Location + Retry-After**
- [ ] **ROUTE-06** : Les requêtes ultérieures sur un variant AI en cours retournent **503 + Retry-After** tant que non prête, puis **200 + image** dès que disponible
- [ ] **ROUTE-07** : Les réponses 200 incluent `Cache-Control: public, max-age=31536000, immutable` + `ETag`
- [x] **ROUTE-08** : Seuls les assets dont `isPublic=true` (ou flag équivalent) sont accessibles via la route publique ; sinon 404
- [ ] **ROUTE-09** : La route est désactivable via feature flag `transformations.public_route.enabled`
- [ ] **ROUTE-10** : Les en-têtes CORS sont configurés pour permettre `<img>` cross-origin sur `/t/*`

### BGREMOVE — Suppression d'arrière-plan (BiRefNet)

- [ ] **BGREMOVE-01** : Le service utilise **BiRefNet** (licence MIT) comme modèle par défaut de remove_background — usage commercial autorisé
- [ ] **BGREMOVE-02** : L'enum supporté est `birefnet` (défaut) et `isnet-general-use` (fallback léger MIT)
- [ ] **BGREMOVE-03** : Les modèles BiRefNet sont pré-téléchargés à la construction de l'image Docker `embedder` (~1 GB ; pas de download au runtime)
- [ ] **BGREMOVE-04** : Le service utilise un `asyncio.Lock` mono-process pour sérialiser les inférences (modèle non thread-safe)
- [ ] **BGREMOVE-05** : La latence cible est < 3 s sur photo produit 2048×2048 en CPU ; au-delà, fallback (si activé) sur `isnet-general-use` plus rapide
- [ ] **BGREMOVE-06** : `RemoveBackgroundHandler` côté PHP appelle `POST /img/remove-background` via `RetryableHttpClient`

### BGGEN — Génération d'arrière-plan par IA (Stable Diffusion)

- [ ] **BGGEN-01** : Le service utilise **Stable Diffusion** (modèle SD 1.5 ou SDXL selon dimensionnement Webfacto) pour le step `add_background type:ai_prompt`
- [ ] **BGGEN-02** : Le pipeline SD est **inpainting** : prend l'image (avec alpha typiquement issu d'un step `remove_background` amont) et remplit la zone transparente avec un fond généré
- [ ] **BGGEN-03** : Le modèle SD est pré-téléchargé au build Docker (~4-7 GB selon SD 1.5 vs SDXL) ; pas de download au runtime
- [ ] **BGGEN-04** : La génération est toujours **asynchrone** côté Symfony (jamais sync) — voir ROUTE-05/06
- [ ] **BGGEN-05** : Un `asyncio.Lock` mono-process sérialise les inférences SD (modèle lourd, mémoire critique)
- [ ] **BGGEN-06** : Le step `add_background type:ai_prompt` accepte `{ prompt: str, negativePrompt?: str, strength?: float (0.0-1.0), seed?: int, steps?: int }`
- [ ] **BGGEN-07** : Le SDK `diffusers` est utilisé (HuggingFace) ; tokens HF stockés en variable d'environnement (jamais commitées)
- [ ] **BGGEN-08** : Le timeout côté Messenger handler est de 5 minutes ; jobs au-delà passent en `failed` queue avec retry policy 3×

### EDITOR — Éditeur PWA des transformations

- [ ] **EDITOR-01** : Un administrateur peut créer/modifier une transformation depuis la PWA via les écrans CRUD standards (générés par schema)
- [ ] **EDITOR-02** : La liste des steps d'une transformation est éditable en drag-and-drop pour réordonner
- [ ] **EDITOR-03** : Chaque step affiche un sous-formulaire dynamique adapté à son `type`
- [ ] **EDITOR-04** : Un administrateur peut prévisualiser le résultat des steps sur un asset choisi avant de sauvegarder, via `POST /api/asset_transformations/preview` (JWT, `no-store`, rate-limité)
- [ ] **EDITOR-05** : La preview est server-authoritative (jamais calculée client-side) et ne touche pas le cache S3
- [ ] **EDITOR-06** : Pour une preview contenant un step AI, l'éditeur affiche un état "génération en cours…" avec polling et progression visuelle
- [ ] **EDITOR-07** : Un composable `useTransformedUrl(code, assetId, ext)` renvoie une string `/t/...` consommable directement par `<img :src>` (avec gestion 202/503 en arrière-plan pour les transformations AI)
- [ ] **EDITOR-08** : L'éditeur affiche un warning visible si l'enchaînement `remove_background` + extension JPEG est utilisé sans `add_background` aval
- [ ] **EDITOR-09** : L'éditeur affiche un badge "IA — async" sur toute transformation contenant un step `ai_prompt`

### OPS — Warmup, GC et observabilité

- [ ] **OPS-01** : Le système expose une commande `transformations:warm {code} [--asset-id=...]` qui dispatch des `WarmupTransformationVariantMessage` sur le transport approprié (sync ou AI)
- [ ] **OPS-02** : Le système expose une commande `transformations:gc [--dry-run] [--keep=2]` qui supprime les variants S3 dont le `versionHash` n'est plus actif
- [ ] **OPS-03** : Les jobs sont distribués sur **4 transports Messenger** : `async` (CLIP existant, intouché), `transformations` (warmup live non-AI), `transformations_ai` (génération SD, queue lente dédiée), `transformations_backfill` (bulk)
- [ ] **OPS-04** : Chaque transport dispose de son propre worker dédié et de sa propre `failed` queue
- [ ] **OPS-05** : Les métriques exposées incluent : cache hit/miss, render duration par endpoint Python, embedder timeout count, lock contention, `birefnet_inflight`, `sd_inflight`, messages handled par transport
- [ ] **OPS-06** : Aucun backfill automatique au déploiement ; génération lazy par défaut

## Future Requirements

<!-- Reportés au-delà de v1.0 -->

- Smart crop (saliency-based, via modèle de saillance Python)
- Formats animés (GIF/WebP animé)
- LUT (color grading)
- Watermark
- URLs ad-hoc signées (sans preset)
- Rastérisation PDF
- Dashboard par preset (usage par transformation)
- Modèles SD additionnels (SDXL Turbo, ControlNet) si infra GPU disponible
- Warm-on-upload toggle (déclenchement automatique à l'upload d'un asset)
- Path GPU pour BiRefNet (latence < 500ms)

## Out of Scope

- **Exposition publique des assets originaux** — la route `/t/*` ne sert que des variantes transformées ; l'original reste derrière JWT
- **Listing public des transformations ou assets** — pas de `GET /t/` ni de discovery API publique
- **CDN front (CloudFront/Bunny) configuré côté app** — choix d'infra délégué à la Webfacto au moment du cadrage prod
- **Animation préservée** — GIF/WebP animé → première frame seulement en v1
- **Resize sans aspect ratio préservé** — toujours mode `fit/cover/contain` ; pas de `force` v1
- **Imagine PHP** — toute manipulation d'image passe par Python (perf + accès aux modèles ML)
- **RMBG-1.4/2.0 (BriaAI)** — licence non-commerciale incompatible avec usage commercial Vente-Unique
- **GPU prod en v1.0** — Stable Diffusion sur CPU accepté (chemin async). GPU = optimisation v1.1 après cadrage Webfacto

## Traceability

Coverage: **62/62 REQ-IDs** mapped to exactly one phase. No orphans.

| REQ-ID | Phase | Status |
|--------|-------|--------|
| TRANSFORM-01 | Phase 1 | Pending |
| TRANSFORM-02 | Phase 1 | Pending |
| TRANSFORM-03 | Phase 1 | Pending |
| TRANSFORM-04 | Phase 1 | Pending |
| TRANSFORM-05 | Phase 1 | Pending |
| TRANSFORM-06 | Phase 1 | Pending |
| TRANSFORM-07 | Phase 6 | Pending |
| IMGSVC-01 | Phase 2 | Pending |
| IMGSVC-02 | Phase 2 | Pending |
| IMGSVC-03 | Phase 2 | Pending |
| IMGSVC-04 | Phase 2 | Pending |
| IMGSVC-05 | Phase 2 | Pending |
| IMGSVC-06 | Phase 4 | Pending |
| IMGSVC-07 | Phase 5 | Pending |
| IMGSVC-08 | Phase 2 | Pending |
| IMGSVC-09 | Phase 2 | Pending |
| IMGSVC-10 | Phase 2 | Pending |
| HANDLERS-01 | Phase 3 | Complete |
| HANDLERS-02 | Phase 3 | Complete |
| HANDLERS-03 | Phase 3 | Complete |
| HANDLERS-04 | Phase 5 | Pending |
| HANDLERS-05 | Phase 3 | Complete |
| HANDLERS-06 | Phase 6 | Pending |
| ROUTE-01 | Phase 3 | Pending |
| ROUTE-02 | Phase 3 | Pending |
| ROUTE-03 | Phase 3 | Pending |
| ROUTE-04 | Phase 3 | Pending |
| ROUTE-05 | Phase 5 | Pending |
| ROUTE-06 | Phase 5 | Pending |
| ROUTE-07 | Phase 3 | Pending |
| ROUTE-08 | Phase 3 | Complete |
| ROUTE-09 | Phase 3 | Pending |
| ROUTE-10 | Phase 3 | Pending |
| BGREMOVE-01 | Phase 4 | Pending |
| BGREMOVE-02 | Phase 4 | Pending |
| BGREMOVE-03 | Phase 4 | Pending |
| BGREMOVE-04 | Phase 4 | Pending |
| BGREMOVE-05 | Phase 4 | Pending |
| BGREMOVE-06 | Phase 4 | Pending |
| BGGEN-01 | Phase 5 | Pending |
| BGGEN-02 | Phase 5 | Pending |
| BGGEN-03 | Phase 5 | Pending |
| BGGEN-04 | Phase 5 | Pending |
| BGGEN-05 | Phase 5 | Pending |
| BGGEN-06 | Phase 5 | Pending |
| BGGEN-07 | Phase 5 | Pending |
| BGGEN-08 | Phase 5 | Pending |
| EDITOR-01 | Phase 7 | Pending |
| EDITOR-02 | Phase 7 | Pending |
| EDITOR-03 | Phase 7 | Pending |
| EDITOR-04 | Phase 7 | Pending |
| EDITOR-05 | Phase 7 | Pending |
| EDITOR-06 | Phase 7 | Pending |
| EDITOR-07 | Phase 7 | Pending |
| EDITOR-08 | Phase 7 | Pending |
| EDITOR-09 | Phase 7 | Pending |
| OPS-01 | Phase 7 | Pending |
| OPS-02 | Phase 7 | Pending |
| OPS-03 | Phase 5 | Pending |
| OPS-04 | Phase 7 | Pending |
| OPS-05 | Phase 7 | Pending |
| OPS-06 | Phase 7 | Pending |
