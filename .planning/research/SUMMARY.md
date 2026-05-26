# Project Research Summary

**Project:** Antigravity — milestone v1.0 Asset Transformations
**Domain:** Brownfield image transformation pipeline (named presets, public CDN-friendly route, S3-cached variants, Imagine handlers, rembg-in-embedder) on top of an existing Symfony 7.3 / API Platform 4 / Vue 3 admin app.
**Researched:** 2026-05-26
**Confidence:** MEDIUM-HIGH

## Executive Summary

Le milestone ajoute un pipeline d'images de type "preset nommé Cloudinary" au domaine Asset déjà livré. Les quatre décisions clés de PROJECT.md (route publique `/t/{code}/{id}.{ext}`, format forcé par extension, cache versionné par hash, rembg intégré au container `embedder`, Imagine pour les opérations classiques) sont confirmées par les quatre axes de recherche — elles correspondent à des patterns industriels bien compris (imgproxy, Cloudinary, Sharp) et à des idiomes déjà présents dans le codebase (Flysystem, Messenger sur Redis Streams, FastAPI/CLIP, firewall JWT sur `^/api`).

Le build recommandé est essentiellement un slot-in brownfield : **pas de nouveau framework, pas de nouveau runtime, pas de nouveau container**. Cinq ajouts seulement — `imagine/imagine` + ext-imagick côté PHP, `rembg` + `onnxruntime` côté Python, Symfony Lock avec le Redis existant, Symfony Validator sur DTOs par step, et un contrôleur Symfony public qui contourne le firewall `^/api` via `access_control`. Les variantes vivent sous `transformations/{transformationId}-v{hash}/{shard}/{id}.{ext}`, générées paresseusement au premier hit, derrière un Redis lock par variante.

Les risques dominants sont **opérationnels, pas techniques** : (1) la route publique hérite de `Asset.id` séquentiel et fuit donc le catalogue sauf si gatée par un flag `isPublic`/`validated`, (2) la RAM du container embedder double quand rembg partage avec CLIP, (3) le hash drift entre PHP et JS thrashera S3 à chaque save d'éditeur si le contrat JSON canonical n'est pas appliqué des deux côtés, (4) le bg-removal coûte 1-3 s en CPU et affamera le pool worker s'il est largué dans le transport `async` Messenger existant.

## Key Findings

### Recommended Stack
- `imagine/imagine ^1.5.2` + `ext-imagick ^3.7` (ImageMagick 7) — déjà acté dans PROJECT.md.
- `rembg 2.0.75` + `onnxruntime 1.20.x` (CPU) dans l'embedder existant ; modèle par défaut **`isnet-general-use`** (meilleur ratio qualité/empreinte pour photos produit ; `bria-rmbg` rejeté pour licence, `birefnet-*` reporté pour mémoire).
- Symfony **Lock** + store Redis — anti-stampede par variante.
- Symfony **Validator** sur DTOs par step (enum fermée, pas de JSON Schema runtime).
- Symfony **HttpFoundation** ETag + `setImmutable` — pas de FOSHttpCacheBundle.

### Expected Features
**Must have (P1):** entités + CRUD ; route publique `/t/*` avec whitelist stricte ; Redis lock avec follower poll ; handlers Imagine (resize/crop/rotate/format_convert/add_background-color) ; `remove_background` via embedder ; cache S3 avec préfixe versionné ; éditeur PWA drag-and-drop avec **preview server-authoritative** (endpoint JWT séparé) ; commande `transformations:gc` ; EXIF auto-orient + alpha-flatten + cap 50 MP source ; métriques basiques.
**Should have (P2):** toggle warm-on-upload + commande bulk warm ; modèles rembg supplémentaires ; `add_background type: asset` ; dashboard par preset.
**Defer (v2+):** smart crop, formats animés, LUT, watermark, URLs ad-hoc signées, rastérisation PDF.

### Architecture Approach
Contrôleur `/t/*` hors firewall `^/api` via `access_control`. `StepHandlerInterface` tagué + `PipelineRunner`. `VersionHasher` invoqué dans `onFlush` Doctrine (JSON canonical, clés triées, 8-12 hex de sha1). Cleanup via jobs Messenger dispatched depuis `postFlush` + commande nocturne. Trois transports Messenger : `async` (CLIP existant), `transformations` (warmup live), `transformations_backfill` (bulk).

### Critical Pitfalls (top 5)
1. **Énumération Asset-ID sur `/t/*`** — gater par `Asset.isPublic` ou flag `validated`, 404 (pas 403) sur miss.
2. **Drift hash PHP↔JS** — spec JSON canonical unique mirrorée dans les deux langages avec tests golden-file ; hash serveur autoritaire, hash editor purement indicatif.
3. **Cold-start + mémoire + threading rembg** — modèle baké au build, `asyncio.Lock` single-process, RAM container portée à ~2 GB, probe startup.
4. **Famine transport Messenger unique** — splitter en trois transports avec workers dédiés et queues failed par transport.
5. **Variantes orphelines S3** — tracker les hashes retirés, GC nocturne par prefix delete, S3 lifecycle policy en backstop.

### Tensions surfaced between researchers (à résoudre avant phase planning)
- **T1 — Sync vs async bg-remove sur la route publique.** STACK/ARCHITECTURE/FEATURES disent sync avec lock ; PITFALLS dit toujours async + 202. **Résolution :** sync-first avec cap dur 8 s (202 casse l'UX `<img src>`) ; chemin async réservé au warmup/backfill.
- **T2 — Query `?v={hash}` sur les URLs `/t/*`.** ARCHITECTURE suggère de l'ajouter ; STACK + FEATURES + PITFALLS argumentent contre (strip query, ignore CDN). **Résolution :** pas de query `?v=`. Le hash vit uniquement dans le préfixe S3 ; si un consommateur doit voir une nouvelle version, shipper un nouveau `code`.
- **T3 — Blocklist de mots réservés sur `code`.** Seul PITFALLS le soulève. **Résolution :** adopter la blocklist + route requirement excluant `api|admin|t|_|assets|<single-char>`.
- **Modèle rembg par défaut :** mismatch dans les exemples (`u2net` dans FEATURES/ARCHITECTURE vs `isnet-general-use` dans STACK/PITFALLS). **Résolution :** livrer l'enum dès le départ, défaut `isnet-general-use`.
- **`add_background` depuis URL :** PITFALLS interdit la surface SSRF ; FEATURES l'exclut déjà. Verrouiller le schéma à `type: color | asset` uniquement.

## Implications for Roadmap

Split en **6 phases** suggéré. Gate de déploiement dur entre Phase 4 et Phase 5.

### Phase 1 — Domain & versioning foundation
**Rationale:** Tout l'aval a besoin des entités, du hash canonical et du helper de clé de stockage. Pas de surface user-facing ; peut atterrir derrière un feature flag.
**Delivers:** `AssetTransformation` + `AssetTransformationStep` + CRUD API Platform, `VersionHasher` (PHP + jumeau TS avec golden tests), `VariantStorageKey`, `AssetTransformationVersioningListener`, blocklist mots réservés, `MaxDepth(1)` + steps-join sur list, contrainte unique-code avec mapping 409.

### Phase 2 — Imagine pipeline (no bg-removal)
**Rationale:** PHP pur, pas de couplage Python deploy. Livre 5 step types sur 6.
**Delivers:** `StepHandlerInterface` + registry, handlers `resize | crop | rotate | format_convert | add_background-color`, `PipelineRunner`, EXIF auto-orient pre-step, alpha-flatten sur cible JPEG, cap source 50 MP + Imagick resource limits, check présence codec AVIF.

### Phase 3 — Public route + cache + lock
**Rationale:** Livre le contrat de cache complet contre le pipeline (presque) terminé. Pas de bg-removal dans l'enum encore.
**Delivers:** `AssetTransformationController` sur `/t/*`, `access_control` pour `^/t/`, regex route + whitelist extension, gate `Asset.isPublic`, 404-not-403, Symfony Lock + `lock.yaml`, follower poll loop + 503 + `Retry-After`, `readStream` unique avec catch `UnableToReadFile`, headers ETag + `immutable`, **pas de query `?v=`**, CORS pour `/t/*`, feature flag `transformations.public_route.enabled`.

### Phase 4 — Embedder upgrade (rembg) — DEPLOY GATE
**Rationale:** Prérequis dur pour Phase 5. Cycle de deploy Python indépendant.
**Delivers:** `requirements.txt` + ajouts Dockerfile, modèle pré-pull au build (`isnet-general-use` défaut + autres lazy), `POST /remove-background`, process unique + `asyncio.Lock`, `/health` étendu, probe startup, `OMP_NUM_THREADS` pinné, mémoire container portée à ~2 GB, transport Messenger `transformations` dédié + worker.
**Deploy gating:** Phase 5 ne peut shipper avant (a) image embedder live, (b) `/health/transformations` retourne 200, (c) mémoire bumped, (d) nouveau worker tournant.

### Phase 5 — Background-removal step + `add_background type: asset`
**Rationale:** Câbler le différenciateur maintenant que l'infra est live.
**Delivers:** `RemoveBackgroundHandler` (via `RetryableHttpClient`, 3 retries), step-DTO + Validator (enum `model`, `fallbackOnTimeout`), `add_background type: asset` (FK only, jamais URL), warning editor quand preset extension JPEG contient `remove_background` sans `add_background` aval, **sync-first avec cap dur 8 s** (résout T1).

### Phase 6 — Editor, warmup, GC, observability
**Rationale:** Polish UX + opérabilité ; items indépendants entre eux.
**Delivers:** éditeur drag-and-drop de steps avec preview server-authoritative sur `POST /api/asset_transformations/preview` (JWT, `no-store`, rate-limité), composable `useTransformedUrl` (`<img src>` direct), `WarmupTransformationVariantMessage` + dispatch sur `warmOnUpload`, `PurgeTransformationVersionMessage` + commande nocturne `transformations:gc` + S3 lifecycle backstop, `transformations:warm {code}` sur transport `transformations_backfill`, métriques (hit/miss, render time, embedder timeout, lock contention, `bgremover_inflight`, message-handled par transport), custom delete processors dispatchant `Cleanup*Message` (jamais inline).

### Phase Ordering Rationale
- Dependency graph depuis FEATURES §7 + ARCHITECTURE §Build Order : entités → hash → storage → handlers → runner → route → embedder → bg-removal step → editor/warmup/GC.
- Seul gate dur Phase 4 → Phase 5 (embedder doit être stable + mémoire raised avant que bg-removal touche prod).
- Phases 1-3 atterrissent derrière feature flag sans dépendance embedder — pas de lock amont.
- Points de cadrage Webfacto naturels : fin Phase 3 (design exposition publique prêt) et début Phase 5 (surface coût embedder réelle).

### Research Flags
- **Phase 2:** confirmer AVIF default-quality + stratégie formats animés contre docs Imagick courantes.
- **Phase 3:** choix CDN (CloudFront / Bunny / aucun) et localisation rate-limit — PROJECT.md silencieux ; affecte cache-key, CORS, TTL 404, edge cache vs origin lock.
- **Phase 4:** mesurer RSS réel CLIP + rembg-`isnet-general-use` sur container équivalent prod ; ~2 GB est une estimation.

Phases avec patterns standards (skip research-phase) : **Phase 1** (Doctrine + API Platform idiomatique), **Phase 6 éditeur** (drag-and-drop Vuetify bien établi ; seule pièce nouvelle — preview server-authoritative — déjà designée).

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | MEDIUM-HIGH | Imagine + Lock + Validator HIGH ; mémoire rembg MEDIUM jusqu'à benchmark. |
| Features | MEDIUM-HIGH | Conventions industrielles stables ; défauts AVIF + noms modèles rembg méritent spot-check upstream. |
| Architecture | HIGH | Ancré dans les fichiers du repo. |
| Pitfalls | HIGH | Mécaniques bien documentées ; phase mapping MEDIUM. |

**Overall confidence:** MEDIUM-HIGH.

### Gaps to Address
- Plafond RAM exact de l'embedder avec les deux modèles chargés — mesurer en Phase 4.
- Tuning qualité AVIF Imagick — passe de tuning en Phase 2.
- Choix CDN (hors scope PROJECT.md mais conditionne le design Phase 3).
- Modèle de visibilité asset (`Asset.isPublic` explicite vs flag-based) — pick durant Phase 1.
- Périmètre backfill — défaut lazy-only ; ship commande en Phase 6 uniquement.

## Sources

### Primary (HIGH confidence)
- `.planning/PROJECT.md`, `CLAUDE.md`.
- Existing code paths (`AssetController`, `ComputeEmbeddingHandler`, `AssetEmbedder`, `embedder/app.py`, `docker-compose.yml`, `security.yaml`, `useAssetUrl.ts`).
- Packagist `imagine/imagine` 1.5.2 ; docs Symfony 7.3 Lock + HTTP-cache.

### Secondary (MEDIUM confidence)
- PyPI `rembg==2.0.75` + GitHub `danielgatis/rembg` model list / licence.
- Conventions URL imgproxy / Cloudinary / Thumbor / Sharp.
- AWS S3 strong-consistency reference.

### Tertiary (LOW confidence)
- RSS exact CLIP + rembg sur le container prod — estimations uniquement ; benchmark en Phase 4.
- Recommandations qualité-défaut AVIF — spot-check upstream nécessaire.

> Rappel organisationnel : avant tout démarrage en intégration au SI ou exposition publique en production (route `/t/*`, coût S3, ressources `embedder`, transport Messenger dédié), ce cas d'usage doit être validé par la Webfacto (cadrage besoin, faisabilité, sécurité, priorisation).

---
*Research completed: 2026-05-26*
*Ready for roadmap: yes*
