# Roadmap: Antigravity — v1.0 Asset Transformations

## Overview

Le milestone v1.0 livre un pipeline de transformations d'images nommées exposé via une route publique cacheable `/t/{code}/{id}.{ext}`. **Pivot architectural** : toute la manipulation d'image est désormais portée par le service Python `embedder` (un endpoint par step type) ; le PHP devient un orchestrateur thin (`StepHandlerInterface` = clients HTTP via `RetryableHttpClient`). La suppression d'arrière-plan utilise **BiRefNet (MIT)**. La roadmap progresse en 5 phases : fondations domaine (P1), endpoints Python classiques (P2), orchestrateur + route publique sync (P3), endpoint BiRefNet + remove_background (P4, **deploy gate dur**), éditeur PWA + ops (P5). La génération d'arrière-plan par IA (Stable Diffusion) est reportée hors v1.0 — cadrage Webfacto requis pour ressources prod (RAM, latence CPU 30-180s, transport Messenger dédié).

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Domain & Versioning Foundation** - Entités AssetTransformation/Step, hash canonical, helper de clé S3, listener de purge (completed 2026-05-26)
- [x] **Phase 2: Python Image Service (classical endpoints)** - Pillow/OpenCV : resize, crop, rotate, format_convert, add_background (color/asset) (completed 2026-05-27)
- [x] **Phase 3: PHP Orchestrator + Public Route + Cache + Lock (sync-only)** - StepHandlerInterface, PipelineRunner, route `/t/*`, cache S3, Redis lock, sync-first 8s sans AI (completed 2026-05-27)
- [x] **Phase 4: BiRefNet Endpoint + remove_background — DEPLOY GATE** - `/img/remove-background` (BiRefNet MIT) + step PHP remove_background sync (completed 2026-05-27)
- [ ] **Phase 5: Editor PWA, Warmup, GC, Observability** - Éditeur drag-and-drop + preview + commandes ops + métriques

## Phase Details

### Phase 1: Domain & Versioning Foundation
**Goal**: Établir le modèle de données des transformations (entités, steps, versioning sha1 canonical, helper de clé S3, listener de purge) sur lequel reposera tout le pipeline aval — sans aucune dépendance au service Python.
**Depends on**: Nothing (first phase)
**Requirements**: TRANSFORM-01, TRANSFORM-02, TRANSFORM-03, TRANSFORM-04, TRANSFORM-05, TRANSFORM-06
**Success Criteria** (what must be TRUE):
  1. Un administrateur peut créer une transformation via l'API Platform CRUD avec un `code` unique (kebab-case validé) et un libellé, et la voir listée
  2. Un administrateur peut composer/réordonner/modifier les steps d'une transformation après création sans erreur ; le `versionHash` (sha1 canonical) est recalculé automatiquement à chaque persistance
  3. Les codes réservés (`api`, `admin`, `t`, `_`, `assets`, mono-caractères) sont rejetés à la création avec une erreur explicite 422
  4. La suppression d'une transformation déclenche un job async de purge des variants S3 (le row part immédiatement, le storage est nettoyé en arrière-plan)
  5. Le hash canonical produit la même valeur pour deux ensembles de steps équivalents (clés triées, defaults droppés) — vérifié par golden-file tests
**Plans:** 5 plans
Plans:
- [ ] 01-01-PLAN.md — Entités AssetTransformation + TransformationStep + enum StepType + migration + API Platform CRUD
- [ ] 01-02-PLAN.md — Install PHPUnit (Wave 0) + TransformationHasher service (sha1 canonical) + golden-file tests
- [ ] 01-03-PLAN.md — Constraint AppAssert\TransformationCode (kebab-case + blocklist) + validator + 422 wiring
- [ ] 01-04-PLAN.md — TransformationHashListener (onFlush + postFlush) + PurgeTransformationVariantsMessage + Messenger transport transformations_backfill
- [ ] 01-05-PLAN.md — TransformationStorageKey helper + PWA config AssetTransformation.json + types TS regen

### Phase 2: Python Image Service (classical endpoints)
**Goal**: Étendre le service `embedder` avec les endpoints d'image classiques (Pillow/OpenCV) — un endpoint par step type non-AI — testables en isolation via curl/httpie, sans dépendance Symfony.
**Depends on**: Phase 1
**Requirements**: IMGSVC-01, IMGSVC-02, IMGSVC-03, IMGSVC-04, IMGSVC-05, IMGSVC-08, IMGSVC-09, IMGSVC-10
**Success Criteria** (what must be TRUE):
  1. Un développeur peut appeler `POST /img/resize`, `/img/crop`, `/img/rotate`, `/img/format-convert`, `/img/add-background` (type color et type asset) en multipart et recevoir l'image traitée en binaire avec le bon Content-Type
  2. Une image avec EXIF Orientation `6` (rotation 90° CW) ressort orientée correctement de tous les endpoints sans rotate explicite ; une image > 50 mégapixels est rejetée avec 422 (pas d'OOM)
  3. `format_convert` produit du PNG, JPEG, WebP et AVIF (via `pillow-avif-plugin`) avec un paramètre `quality` honoré
  4. `add_background type:asset` accepte uniquement un `assetId` numérique (jamais d'URL) ; le service récupère l'asset par son chemin S3 interne — SSRF-safe par construction
  5. `GET /health` reporte l'état de chargement des modèles (`clip`, `birefnet` — `loaded|lazy|failed`) et le service tourne dans son container Docker sans changement de signature de l'endpoint CLIP `/embed` existant
**Plans:** 5 plans
Plans:
- [ ] 02-01-PLAN.md — Wave 0: dev deps (pytest+httpx) + pillow-avif-plugin + decode_image() helper + /health refacto
- [ ] 02-02-PLAN.md — TDD endpoints /img/resize + /img/crop + /img/rotate (Pillow primitives)
- [ ] 02-03-PLAN.md — TDD endpoint /img/format-convert (PNG/JPEG/WebP/AVIF + alpha-flatten)
- [ ] 02-04-PLAN.md — TDD endpoint /img/add-background (type:color + type:asset multipart double-champ)
- [ ] 02-05-PLAN.md — Rebuild + smoke E2E curl + embedder/README.md + checkpoint humain

### Phase 3: PHP Orchestrator + Public Route + Cache + Lock (sync-only)
**Goal**: Câbler le PHP comme orchestrateur thin des endpoints Python (handlers + DTOs validators), exposer la route publique `/t/{code}/{id}.{ext}` derrière feature flag, avec cache S3 versionné, lock anti-thundering-herd et headers immutables — uniquement pour des transformations **sans step AI**.
**Depends on**: Phase 2
**Requirements**: HANDLERS-01, HANDLERS-02, HANDLERS-03, HANDLERS-05, HANDLERS-06, ROUTE-01, ROUTE-02, ROUTE-03, ROUTE-04, ROUTE-07, ROUTE-08, ROUTE-09, ROUTE-10
**Success Criteria** (what must be TRUE):
  1. Un consommateur HTTP non authentifié peut requêter `GET /t/{code}/{id}.{ext}` sur un asset `isPublic=true` avec une transformation non-AI (resize + format_convert par ex.) et recevoir une image transformée en sync avec `Cache-Control: public, max-age=31536000, immutable` + `ETag`
  2. La 2ème requête sur la même URL est servie depuis le cache S3 (préfixe `transformations/{transformationId}-v{hash}/{shard}/{assetId}.{ext}`) sans réexécuter le pipeline
  3. Sous charge concurrente (N requêtes simultanées sur une variante froide), une seule génération s'exécute (Redis lock par variante), avec un cap dur à 8s ; les autres attendent puis lisent depuis S3
  4. Chaque type de step a son DTO Validator dédié côté PHP qui rejette à la persistance les paramètres invalides ; un format JPEG sans `add_background` aval déclenche un alpha-flatten implicite sur blanc + warning visible
  5. Désactiver le feature flag `transformations.public_route.enabled` fait passer la route à 404 immédiatement ; les en-têtes CORS autorisent `<img>` cross-origin ; un asset non public ou un code inconnu retourne 404 (jamais 403)
**Plans:** 3 plans
Plans:
- [x] 03-01-PLAN.md — DTO StepParams + factory + Doctrine validation listener + migration warnings JSONB + alpha-flatten-on-jpeg derivation
- [x] 03-02-PLAN.md — StepHandlerInterface + 5 handlers HTTP (embedder.client RetryableHttpClient) + PipelineRunner cap 8s + format_convert implicite
- [x] 03-03-PLAN.md — PublicTransformationController + route /t/* + lock Redis + cache S3 + feature flag + CORS + tests concurrence

### Phase 4: BiRefNet Endpoint + remove_background — DEPLOY GATE
**Goal**: Ajouter au service `embedder` l'endpoint `POST /img/remove-background` (modèle BiRefNet MIT pré-téléchargé au build, fallback `isnet-general-use`), câbler côté PHP le step `remove_background` sync (cap 8s), et provisionner les ressources prod. **Hard gate : aucun déploiement prod avant que cette phase soit live et stable en staging+prod (RAM, latence, /health vérifiés).**
**Depends on**: Phase 3
**Requirements**: IMGSVC-06, BGREMOVE-01, BGREMOVE-02, BGREMOVE-03, BGREMOVE-04, BGREMOVE-05, BGREMOVE-06
**Success Criteria** (what must be TRUE):
  1. `POST /img/remove-background` accepte un binaire multipart, retourne un PNG RGBA avec alpha généré par BiRefNet (défaut) ; le paramètre `model` accepte `birefnet` et `isnet-general-use` (fallback) ; aucun téléchargement de modèle au runtime (modèles intégrés à l'image Docker, ~1 GB)
  2. Des inférences concurrentes sur `/img/remove-background` sont sérialisées par un `asyncio.Lock` mono-process — pas de segfault, pas de masques corrompus — et la métrique `birefnet_inflight` reflète l'occupation réelle
  3. La latence cible < 3s sur photo produit 2048×2048 (CPU) est mesurée en prod ; au-delà, le paramètre `fallbackOnTimeout` bascule sur `isnet-general-use` plus rapide
  4. Une transformation contenant `remove_background` + `format_convert png` est requêtable via `/t/...` et retourne un PNG transparent en sync < 8s, via `RetryableHttpClient` (3 retries, backoff exponentiel)
  5. **Deploy gate** : l'image embedder est live en prod, `/health` confirme `birefnet.loaded=true`, le quota RAM est validé (~CLIP + BiRefNet ≈ 2-3 GB), et le step PHP `remove_background` est validé sur 3+ assets réels — checklist signée en console ops
**Plans:** 5 plans
Plans:
- [x] 04-01-PLAN.md — Wave 0 test infrastructure (pin ORT 1.22.0, integration_ml marker, fixtures, 12 Python stubs xfail + 6 PHP stubs)
- [x] 04-02-PLAN.md — Python endpoint POST /img/remove-background (BiRefNet + isnet, asyncio.Lock, downscale, timeout 5s + fallback opt-in)
- [x] 04-03-PLAN.md — Dockerfile multi-stage avec model-downloader + /health enrichi (birefnet/isnet/inflight/last_inference_ms) + --workers 1
- [x] 04-04-PLAN.md — PHP RemoveBackgroundHandler + DTO + StepParamsFactory routing + TransformationLookup inversion (sync-gate)
- [x] 04-05-PLAN.md — 04-DEPLOY-CHECKLIST.md (Webfacto signoff D-13) + bench_bgremove.sh + warning remove-background-requires-png

### Phase 5: Editor PWA, Warmup, GC, Observability
**Goal**: Livrer l'expérience admin complète (éditeur drag-and-drop + preview server-authoritative), l'opérabilité (commandes warmup/GC, transports Messenger séparés) et l'observabilité (métriques cache, lock, embedder, transports). Couvre les 5 step types non-AI livrés (resize, crop, rotate, format_convert, add_background color/asset, remove_background).
**Depends on**: Phase 4
**Requirements**: EDITOR-01, EDITOR-02, EDITOR-03, EDITOR-04, EDITOR-05, EDITOR-07, EDITOR-08, OPS-01, OPS-02, OPS-03, OPS-04, OPS-05, OPS-06
**Success Criteria** (what must be TRUE):
  1. Un administrateur peut créer/modifier une transformation depuis la PWA, réordonner les steps en drag-and-drop, éditer les paramètres dans un sous-formulaire dynamique par type, et prévisualiser le résultat sur un asset choisi via `POST /api/asset_transformations/preview` (JWT, `no-store`, jamais sur S3, rate-limité)
  2. Un warning visible apparaît si `remove_background` cible une extension JPEG sans `add_background` aval (issu du listener déjà câblé en Phase 4)
  3. Un composable `useTransformedUrl(code, assetId, ext)` retourne une string `/t/...` directement consommable par `<img :src>`, sans logique d'attente côté client (les transformations sont toutes sync ; le cap 8s côté serveur garantit la réponse)
  4. Un ops peut lancer `php bin/console transformations:warm {code} [--asset-id=...]` qui dispatch des `WarmupTransformationVariantMessage` sur le transport `transformations` et `php bin/console transformations:gc [--dry-run] [--keep=2]` qui supprime les variants S3 dont le `versionHash` n'est plus actif ; aucun backfill automatique au déploiement
  5. Les métriques exposées et consommables (Datadog ou équivalent) incluent : cache hit/miss, render duration par endpoint Python, embedder timeout count, lock contention, `birefnet_inflight`, messages handled par transport ; chaque transport (`async`, `transformations`, `transformations_backfill`) dispose de sa propre `failed` queue inspectable
**Plans**: 5 plans
Plans:
- [ ] 01-preview-api-base — Preview endpoint + rate_limiter + PipelineRunner bypassCache
- [ ] 02-messenger-transports-warmup — 3 transports + 3 failed queues + WarmupTransformationVariantMessage/Handler + workers Docker
- [ ] 03-ops-commands-docs — `transformations:warm` + `transformations:gc --keep=2 [--dry-run]` + docs ops
- [ ] 04-pwa-editor-steps — StepsField (vuedraggable@4) + 6 StepFields + WarningBanner + useTransformedUrl
- [ ] 05-pwa-preview-metrics — PreviewPanel + AssetPickerDialog + usePreviewUrl + i18n 14 locales + TransformationMetrics
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

**Hard gate:** Phase 4 deploy gate signé Webfacto avant toute exposition prod (BiRefNet live + stable RAM/latence/`/health`).

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Domain & Versioning Foundation | 5/5 | Complete | 2026-05-26 |
| 2. Python Image Service (classical endpoints) | 5/5 | Complete | 2026-05-27 |
| 3. PHP Orchestrator + Public Route + Cache + Lock | 3/3 | Complete | 2026-05-27 |
| 4. BiRefNet Endpoint + remove_background — DEPLOY GATE | 5/5 | Complete | 2026-05-27 |
| 5. Editor PWA, Warmup, GC, Observability | 0/5 | Not started | - |