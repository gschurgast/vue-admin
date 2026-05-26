# REQUIREMENTS.md

**Milestone:** v1.0 — Asset Transformations
**Created:** 2026-05-26

## Milestone v1.0 Requirements

### TRANSFORM — Définition des transformations

- [ ] **TRANSFORM-01** : Un administrateur peut créer une transformation avec un `code` unique (kebab-case, validé) et un libellé
- [ ] **TRANSFORM-02** : Un administrateur peut composer une transformation d'une liste ordonnée de steps typés (resize, crop, rotate, format_convert, add_background, remove_background)
- [ ] **TRANSFORM-03** : Un administrateur peut modifier les steps d'une transformation après création (paramètres, ordre, type) sans casser le cache existant
- [ ] **TRANSFORM-04** : Le système recalcule automatiquement un `versionHash` (sha1 canonical des steps) à chaque modification de step
- [ ] **TRANSFORM-05** : Un administrateur peut supprimer une transformation, ce qui purge tous ses variants S3 en arrière-plan
- [ ] **TRANSFORM-06** : Les codes réservés (`api`, `admin`, `t`, `_`, `assets`, mono-caractères) sont rejetés à la création

### ROUTE — Route publique et cache

- [ ] **ROUTE-01** : Un consommateur HTTP non authentifié peut requêter `GET /t/{code}/{id}.{ext}` et recevoir une image transformée
- [ ] **ROUTE-02** : Le format de sortie est déterminé exclusivement par l'extension d'URL (png, jpg, jpeg, webp, avif) ; toute autre extension retourne 404
- [ ] **ROUTE-03** : Le système sert depuis le cache S3 (préfixe `transformations/{transformationId}-v{hash}/{shard}/{assetId}.{ext}`) si la variante existe, sinon génère puis stocke
- [ ] **ROUTE-04** : La 1ère requête concurrente prend un Redis lock par variante ; les requêtes suivantes attendent (follower poll) puis lisent depuis S3
- [ ] **ROUTE-05** : Les variantes nécessitant `remove_background` sont générées en sync avec un cap dur de 8s ; au-delà → 503 + `Retry-After`
- [ ] **ROUTE-06** : Les réponses servies incluent `Cache-Control: public, max-age=31536000, immutable` + `ETag`
- [ ] **ROUTE-07** : Seuls les assets dont `isPublic=true` (ou flag équivalent) sont accessibles via la route publique ; sinon 404
- [ ] **ROUTE-08** : La route est désactivable via feature flag `transformations.public_route.enabled`
- [ ] **ROUTE-09** : Les en-têtes CORS sont configurés pour permettre `<img>` cross-origin sur `/t/*`

### HANDLERS — Pipeline Imagine (transformations classiques)

- [ ] **HANDLERS-01** : Le système applique séquentiellement les steps via un `PipelineRunner` qui orchestre des `StepHandlerInterface` taggés
- [ ] **HANDLERS-02** : Le step `resize` accepte `{ width?, height?, mode: "fit|cover|contain", upscale?: bool }`
- [ ] **HANDLERS-03** : Le step `crop` accepte `{ x, y, width, height }` ou `{ aspectRatio, anchor }`
- [ ] **HANDLERS-04** : Le step `rotate` accepte `{ angle, background?: color }`
- [ ] **HANDLERS-05** : Le step `format_convert` accepte `{ format: "png|jpg|jpeg|webp|avif", quality?: 1-100 }`
- [ ] **HANDLERS-06** : Le step `add_background` accepte `{ type: "color", color: "#RRGGBB" }` ou `{ type: "asset", assetId: int }` (jamais d'URL pour éviter SSRF)
- [ ] **HANDLERS-07** : Le runner applique automatiquement EXIF auto-orient en pré-traitement
- [ ] **HANDLERS-08** : Les images source > 50 mégapixels sont rejetées avec une erreur explicite
- [ ] **HANDLERS-09** : Le format de sortie cible JPEG sans `add_background` aplatit l'alpha sur fond blanc par défaut avec un warning visible côté éditeur
- [ ] **HANDLERS-10** : Chaque type de step a un DTO Validator dédié (ResizeStepParams, CropStepParams, …) garantissant la validation à la persistance

### BGREMOVE — Suppression d'arrière-plan

- [ ] **BGREMOVE-01** : Le service `embedder` expose `POST /remove-background` (multipart binaire) qui retourne un PNG RGBA
- [ ] **BGREMOVE-02** : Le modèle par défaut est `isnet-general-use` ; l'enum inclut `u2net`, `isnet-general-use`, `silueta`
- [ ] **BGREMOVE-03** : Les modèles sont pré-téléchargés à la construction de l'image Docker `embedder` (pas de download au runtime)
- [ ] **BGREMOVE-04** : Le service utilise un `asyncio.Lock` mono-process pour sérialiser les inférences (rembg n'est pas thread-safe)
- [ ] **BGREMOVE-05** : L'endpoint `/health` reporte l'état de chargement des modèles rembg en plus de CLIP
- [ ] **BGREMOVE-06** : Le step `remove_background` accepte `{ model?: "isnet-general-use|u2net|silueta", fallbackOnTimeout?: bool }`
- [ ] **BGREMOVE-07** : `RemoveBackgroundHandler` côté PHP appelle l'embedder via `RetryableHttpClient` (3 retries, exponential backoff)
- [ ] **BGREMOVE-08** : La mémoire du container `embedder` est dimensionnée pour héberger CLIP + isnet-general-use simultanément (~2 GB)

### EDITOR — Éditeur PWA des transformations

- [ ] **EDITOR-01** : Un administrateur peut créer/modifier une transformation depuis la PWA via les écrans CRUD standards (générés par schema)
- [ ] **EDITOR-02** : La liste des steps d'une transformation est éditable en drag-and-drop pour réordonner
- [ ] **EDITOR-03** : Chaque step affiche un sous-formulaire dynamique adapté à son `type`
- [ ] **EDITOR-04** : Un administrateur peut prévisualiser le résultat des steps sur un asset choisi avant de sauvegarder, via `POST /api/asset_transformations/preview` (JWT, `no-store`, rate-limité)
- [ ] **EDITOR-05** : La preview est server-authoritative (jamais calculée client-side) et ne touche pas le cache S3
- [ ] **EDITOR-06** : Un composable `useTransformedUrl(code, assetId, ext)` renvoie une string `/t/...` consommable directement par `<img :src>`
- [ ] **EDITOR-07** : L'éditeur affiche un warning visible si l'enchaînement `remove_background` + extension JPEG est utilisé sans `add_background` aval

### OPS — Warmup, GC et observabilité

- [ ] **OPS-01** : Le système expose une commande `transformations:warm {code} [--asset-id=...]` qui dispatch des `WarmupTransformationVariantMessage` sur le transport `transformations_backfill`
- [ ] **OPS-02** : Le système expose une commande `transformations:gc [--dry-run] [--keep=2]` qui supprime les variants S3 dont le `versionHash` n'est plus actif
- [ ] **OPS-03** : Les jobs sont distribués sur 3 transports Messenger : `async` (CLIP existant, intouché), `transformations` (warmup live), `transformations_backfill` (bulk)
- [ ] **OPS-04** : Chaque transport dispose de son propre worker dédié et de sa propre `failed` queue
- [ ] **OPS-05** : Les métriques exposées incluent : cache hit/miss, render duration, embedder timeout count, lock contention, `bgremover_inflight`, messages handled par transport
- [ ] **OPS-06** : Aucun backfill automatique au déploiement ; génération lazy par défaut

## Future Requirements

<!-- Reportés au-delà de v1.0 -->

- Smart crop (saliency-based)
- Formats animés (GIF/WebP animé)
- LUT (color grading)
- Watermark
- URLs ad-hoc signées (sans preset)
- Rastérisation PDF
- Dashboard par preset (usage par transformation)
- Modèles rembg supplémentaires (birefnet, bria-rmbg si licence évolue)
- Warm-on-upload toggle (déclenchement automatique à l'upload d'un asset)

## Out of Scope

- **Exposition publique des assets originaux** — la route `/t/*` ne sert que des variantes transformées ; l'original reste derrière JWT
- **Listing public des transformations ou assets** — pas de `GET /t/` ni de discovery API publique
- **CDN front (CloudFront/Bunny) configuré côté app** — choix d'infra délégué à la Webfacto au moment du cadrage prod
- **Animation préservée** — GIF/WebP animé → première frame seulement en v1
- **Resize sans aspect ratio préservé** — toujours mode `fit/cover/contain` ; pas de `force` v1

## Traceability

<!-- Rempli par le roadmapper -->

| REQ-ID | Phase |
|--------|-------|
| (à mapper) | — |
