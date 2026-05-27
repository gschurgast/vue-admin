# REQUIREMENTS.md

**Milestone:** v1.0 — Asset Transformations
**Created:** 2026-05-26
**Last revised:** 2026-05-27 — drop AI (Stable Diffusion / add_background ai_prompt) hors v1.0 ; renumérotation Phase 7 → Phase 5

## Milestone v1.0 Requirements

### TRANSFORM — Définition des transformations

- [x] **TRANSFORM-01** : Un administrateur peut créer une transformation avec un `code` unique (kebab-case, validé) et un libellé
- [x] **TRANSFORM-02** : Un administrateur peut composer une transformation d'une liste ordonnée de steps typés (resize, crop, rotate, format_convert, add_background, remove_background)
- [x] **TRANSFORM-03** : Un administrateur peut modifier les steps d'une transformation après création (paramètres, ordre, type) sans casser le cache existant
- [x] **TRANSFORM-04** : Le système recalcule automatiquement un `versionHash` (sha1 canonical des steps) à chaque modification de step
- [x] **TRANSFORM-05** : Un administrateur peut supprimer une transformation, ce qui purge tous ses variants S3 en arrière-plan
- [x] **TRANSFORM-06** : Les codes réservés (`api`, `admin`, `t`, `_`, `assets`, mono-caractères) sont rejetés à la création

### IMGSVC — Endpoints Python d'image (un par step type)

- [x] **IMGSVC-01** : Le service `embedder` expose `POST /img/resize` (Pillow) — body multipart : image + JSON `{ width?, height?, mode: "fit|cover|contain", upscale?: bool }`
- [x] **IMGSVC-02** : Le service expose `POST /img/crop` — JSON `{ x, y, width, height }` ou `{ aspectRatio, anchor }`
- [x] **IMGSVC-03** : Le service expose `POST /img/rotate` — JSON `{ angle, background?: color }`
- [x] **IMGSVC-04** : Le service expose `POST /img/format-convert` — JSON `{ format: "png|jpg|jpeg|webp|avif", quality?: 1-100 }`. AVIF via `pillow-avif-plugin`.
- [x] **IMGSVC-05** : Le service expose `POST /img/add-background` — JSON `{ type: "color", color: "#RRGGBB" }` ou `{ type: "asset", assetId: int }` (jamais d'URL ; SSRF-safe)
- [x] **IMGSVC-06** : Le service expose `POST /img/remove-background` — JSON `{ model?: "birefnet|isnet-general-use", fallbackOnTimeout?: bool }` (défaut `birefnet`)
- [x] **IMGSVC-08** : Chaque endpoint applique EXIF auto-orient en pré-traitement et rejette les images > 50 mégapixels
- [x] **IMGSVC-09** : Les endpoints retournent l'image traitée en binaire (Content-Type approprié) + headers de timing
- [x] **IMGSVC-10** : `/health` reporte l'état de chargement des modèles : CLIP, BiRefNet (chargé / lazy / failed)

### HANDLERS — Orchestration PHP (clients HTTP vers Python)

- [x] **HANDLERS-01** : Le système applique séquentiellement les steps via un `PipelineRunner` qui orchestre des `StepHandlerInterface` taggés
- [x] **HANDLERS-02** : Chaque handler PHP appelle l'endpoint Python correspondant via `RetryableHttpClient` (3 retries, exponential backoff, timeout step-dépendant)
- [x] **HANDLERS-03** : Chaque type de step a un DTO Validator côté PHP (`ResizeStepParams`, `CropStepParams`, …) garantissant la validation à la persistance
- [x] **HANDLERS-05** : Le format de sortie cible JPEG sans `add_background` aval déclenche un alpha-flatten implicite sur fond blanc + warning visible côté éditeur
- [x] **HANDLERS-06** : Le step `add_background` accepte `{ type: "color" }` et `{ type: "asset", assetId }` (variante `ai_prompt` reportée hors v1.0)

### ROUTE — Route publique et cache

- [x] **ROUTE-01** : Un consommateur HTTP non authentifié peut requêter `GET /t/{code}/{id}.{ext}` et recevoir une image transformée
- [x] **ROUTE-02** : Le format de sortie est déterminé exclusivement par l'extension d'URL (png, jpg, jpeg, webp, avif) ; toute autre extension retourne 404
- [x] **ROUTE-03** : Le système sert depuis le cache S3 (préfixe `transformations/{transformationId}-v{hash}/{shard}/{assetId}.{ext}`) si la variante existe, sinon génère puis stocke
- [x] **ROUTE-04** : La 1ère requête prend un Redis lock par variante, génère en sync avec cap dur 8 s, puis stream depuis S3
- [x] **ROUTE-07** : Les réponses 200 incluent `Cache-Control: public, max-age=31536000, immutable` + `ETag`
- [x] **ROUTE-08** : Seuls les assets dont `isPublic=true` (ou flag équivalent) sont accessibles via la route publique ; sinon 404
- [x] **ROUTE-09** : La route est désactivable via feature flag `transformations.public_route.enabled`
- [x] **ROUTE-10** : Les en-têtes CORS sont configurés pour permettre `<img>` cross-origin sur `/t/*`

### BGREMOVE — Suppression d'arrière-plan (BiRefNet)

- [x] **BGREMOVE-01** : Le service utilise **BiRefNet** (licence MIT) comme modèle par défaut de remove_background — usage commercial autorisé
- [x] **BGREMOVE-02** : L'enum supporté est `birefnet` (défaut) et `isnet-general-use` (fallback léger MIT)
- [x] **BGREMOVE-03** : Les modèles BiRefNet sont pré-téléchargés à la construction de l'image Docker `embedder` (~1 GB ; pas de download au runtime)
- [x] **BGREMOVE-04** : Le service utilise un `asyncio.Lock` mono-process pour sérialiser les inférences (modèle non thread-safe)
- [x] **BGREMOVE-05** : La latence cible est < 3 s sur photo produit 2048×2048 en CPU ; au-delà, fallback (si activé) sur `isnet-general-use` plus rapide
- [x] **BGREMOVE-06** : `RemoveBackgroundHandler` côté PHP appelle `POST /img/remove-background` via `RetryableHttpClient`

### EDITOR — Éditeur PWA des transformations

- [ ] **EDITOR-01** : Un administrateur peut créer/modifier une transformation depuis la PWA via les écrans CRUD standards (générés par schema)
- [ ] **EDITOR-02** : La liste des steps d'une transformation est éditable en drag-and-drop pour réordonner
- [ ] **EDITOR-03** : Chaque step affiche un sous-formulaire dynamique adapté à son `type`
- [ ] **EDITOR-04** : Un administrateur peut prévisualiser le résultat des steps sur un asset choisi avant de sauvegarder, via `POST /api/asset_transformations/preview` (JWT, `no-store`, rate-limité)
- [ ] **EDITOR-05** : La preview est server-authoritative (jamais calculée client-side) et ne touche pas le cache S3
- [ ] **EDITOR-07** : Un composable `useTransformedUrl(code, assetId, ext)` renvoie une string `/t/...` consommable directement par `<img :src>` (toutes les transformations v1.0 sont sync — pas de gestion 202/503 client)
- [ ] **EDITOR-08** : L'éditeur affiche un warning visible si l'enchaînement `remove_background` + extension JPEG est utilisé sans `add_background` aval

### OPS — Warmup, GC et observabilité

- [ ] **OPS-01** : Le système expose une commande `transformations:warm {code} [--asset-id=...]` qui dispatch des `WarmupTransformationVariantMessage` sur le transport `transformations`
- [ ] **OPS-02** : Le système expose une commande `transformations:gc [--dry-run] [--keep=2]` qui supprime les variants S3 dont le `versionHash` n'est plus actif
- [ ] **OPS-03** : Les jobs sont distribués sur **3 transports Messenger** : `async` (CLIP existant, intouché), `transformations` (warmup live), `transformations_backfill` (bulk)
- [ ] **OPS-04** : Chaque transport dispose de son propre worker dédié et de sa propre `failed` queue
- [ ] **OPS-05** : Les métriques exposées incluent : cache hit/miss, render duration par endpoint Python, embedder timeout count, lock contention, `birefnet_inflight`, messages handled par transport
- [ ] **OPS-06** : Aucun backfill automatique au déploiement ; génération lazy par défaut

## Future Requirements

<!-- Reportés au-delà de v1.0 -->

- **Stable Diffusion (add_background type:ai_prompt)** — endpoint Python `/img/generate-background` + step PHP + chemin async `/t/*` 202/Location/503 + transport Messenger `transformations_ai` + métrique `sd_inflight`. Reporté hors v1.0 ; cadrage Webfacto requis (RAM 4-7 GB, latence CPU 30-180s, possible GPU).
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
- **Stable Diffusion / add_background ai_prompt** — reporté hors v1.0 (cadrage Webfacto requis pour RAM + latence + transport AI dédié)
- **GPU prod** — pas en v1.0 ; à reconsidérer si SD est intégré post-v1.0

## Traceability

Coverage: **48/48 REQ-IDs** mapped to exactly one phase. No orphans.

| REQ-ID | Phase | Status |
|--------|-------|--------|
| TRANSFORM-01 | Phase 1 | Complete |
| TRANSFORM-02 | Phase 1 | Complete |
| TRANSFORM-03 | Phase 1 | Complete |
| TRANSFORM-04 | Phase 1 | Complete |
| TRANSFORM-05 | Phase 1 | Complete |
| TRANSFORM-06 | Phase 1 | Complete |
| IMGSVC-01 | Phase 2 | Complete |
| IMGSVC-02 | Phase 2 | Complete |
| IMGSVC-03 | Phase 2 | Complete |
| IMGSVC-04 | Phase 2 | Complete |
| IMGSVC-05 | Phase 2 | Complete |
| IMGSVC-06 | Phase 4 | Complete |
| IMGSVC-08 | Phase 2 | Complete |
| IMGSVC-09 | Phase 2 | Complete |
| IMGSVC-10 | Phase 2 | Complete |
| HANDLERS-01 | Phase 3 | Complete |
| HANDLERS-02 | Phase 3 | Complete |
| HANDLERS-03 | Phase 3 | Complete |
| HANDLERS-05 | Phase 3 | Complete |
| HANDLERS-06 | Phase 3 | Complete |
| ROUTE-01 | Phase 3 | Complete |
| ROUTE-02 | Phase 3 | Complete |
| ROUTE-03 | Phase 3 | Complete |
| ROUTE-04 | Phase 3 | Complete |
| ROUTE-07 | Phase 3 | Complete |
| ROUTE-08 | Phase 3 | Complete |
| ROUTE-09 | Phase 3 | Complete |
| ROUTE-10 | Phase 3 | Complete |
| BGREMOVE-01 | Phase 4 | Complete |
| BGREMOVE-02 | Phase 4 | Complete |
| BGREMOVE-03 | Phase 4 | Complete |
| BGREMOVE-04 | Phase 4 | Complete |
| BGREMOVE-05 | Phase 4 | Complete |
| BGREMOVE-06 | Phase 4 | Complete |
| EDITOR-01 | Phase 5 | Pending |
| EDITOR-02 | Phase 5 | Pending |
| EDITOR-03 | Phase 5 | Pending |
| EDITOR-04 | Phase 5 | Pending |
| EDITOR-05 | Phase 5 | Pending |
| EDITOR-07 | Phase 5 | Pending |
| EDITOR-08 | Phase 5 | Pending |
| OPS-01 | Phase 5 | Pending |
| OPS-02 | Phase 5 | Pending |
| OPS-03 | Phase 5 | Pending |
| OPS-04 | Phase 5 | Pending |
| OPS-05 | Phase 5 | Pending |
| OPS-06 | Phase 5 | Pending |