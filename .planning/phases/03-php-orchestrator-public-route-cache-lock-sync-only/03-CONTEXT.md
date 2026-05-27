# Phase 3: PHP Orchestrator + Public Route + Cache + Lock (sync-only) — Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Câbler le PHP comme orchestrateur thin des endpoints Python (Phase 2) :
- `StepHandlerInterface` + `PipelineRunner` séquentiel pour les steps non-AI
- DTO Validators par step type (validation à la persistance)
- Route publique `GET /t/{code}/{id}.{ext}` derrière feature flag
- Cache S3 versionné (`transformations/{id}-v{hash8}/{shard}/{assetId}.{ext}`) déjà
  modélisé par `TransformationStorageKey` (Phase 1)
- Lock Redis anti-thundering-herd + cap dur 8s wall-clock
- Headers `Cache-Control: public, max-age=31536000, immutable` + `ETag` déterministe
- CORS wildcard GET pour consommation `<img>` cross-origin

**Hors scope Phase 3 :**
- Tout step AI (`remove_background` BiRefNet → Phase 4 ; `add_background type:ai_prompt` SD
  → Phase 5/6)
- Chemin async 202+Location (Phase 5)
- Preview JWT, éditeur PWA, warmup, GC, métriques (Phase 7)

</domain>

<decisions>
## Implementation Decisions

### Lock & Cap 8s

- **D-01 (Lock impl) :** `symfony/lock` avec `RedisStore` (réutilise le service Redis
  déjà câblé). Clé `lock:tx:{storageKey}`. `InMemoryStore` substitué en test
  (`when@test`).
- **D-02 (Lock TTL) :** TTL initial 10s avec **auto-extend par heartbeat** (Symfony
  Lock `refresh()` natif). Marge de 2s sur le cap pour absorber les latences réseau.
- **D-03 (Cap 8s) :** Wall-clock mesuré dans `PipelineRunner::run()`, démarré à
  l'acquisition du lock. À chaque step, calcul du `remainingMs` et passage en
  `timeout` HTTP au prochain handler. Si dépassement → abort + 503.
- **D-04 (Waiter behavior) :** Un waiter qui acquiert le lock après le release
  vérifie S3. Si variant trouvé → stream depuis S3. Sinon → 503 + `Retry-After: 2`.
  **Pas de re-génération concurrente** par un waiter.
- **D-05 (AI gating) :** Toute requête vers `/t/*` pour une transformation
  `requires_async` (step `ai_prompt`) retourne **404 silencieux** en Phase 3. Phase 5
  introduira 202+Location pour ce cas. La création de transformations
  `requires_async` reste autorisée à la persistance (TRANSFORM-07 Phase 6).

### HTTP Client embedder & Pipeline I/O

- **D-06 (HTTP client) :** Service nommé `embedder.client` —
  `ScopingHttpClient` vers `http://embedder:8000` décoré par `RetryableHttpClient`.
  Injecté dans chaque `StepHandler` via tag `app.step_handler`.
- **D-07 (Retry policy) :** 3 retries, backoff exponentiel **200/400/800 ms**, sur
  **5xx + timeout uniquement**. **Aucun retry sur 4xx** (paramètre invalide →
  remonte immédiatement comme erreur déterministe).
- **D-08 (Timeouts par step) :** Defaults sains, configurables via env :
  - `resize`/`crop`/`rotate` : `2000 ms`
  - `format_convert` : `3000 ms`
  - `add_background` (color/asset) : `4000 ms`
  - Override : `EMBEDDER_TIMEOUT_RESIZE_MS`, etc.
  - Le `PipelineRunner` plafonne aussi par `min(stepTimeout, remainingMs)`.
- **D-09 (Pipeline I/O contract) :** Le binaire transite en **bytes `string`** entre
  handlers (cap 50 MP déjà appliqué côté embedder). Pas de streams, pas de temp
  files. Mémoire bornée à ~10-40 MB par requête, OK pour FrankenPHP.

### Route Semantics (404 / Feature flag / CORS)

- **D-10 (Tout en 404) :** Unifié — code inconnu, asset inexistant,
  `Asset.isPublic = false`, transformation `requires_async`, extension hors
  `{png,jpg,jpeg,webp,avif}`, feature flag off → tous **404** sans message
  discriminant. Aucune fuite d'information. **Jamais 403.**
- **D-11 (Erreurs infra distinctes) :** 503 si lock-timeout/abort, 502 si embedder
  KO après retries. Le 8s cap → 503 + `Retry-After`.
- **D-12 (Feature flag) :** Paramètre Symfony bindé sur env var :
  ```yaml
  parameters:
      transformations.public_route.enabled: '%env(bool:TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED)%'
  ```
  Injecté en `bool` dans le controller. Désactivé → route 404 immédiat (pas de
  matching de route bypass DI). Redéploiement requis pour toggle ; suffisant en v1.
- **D-13 (CORS) :** `/t/*` sert un CORS dédié :
  - `Access-Control-Allow-Origin: *`
  - `Access-Control-Allow-Methods: GET, HEAD, OPTIONS`
  - `Cross-Origin-Resource-Policy: cross-origin`
  - **Pas** de credentials, pas de headers exotiques.
  - Configuration via `nelmio_cors` paths additionnel (laisser `/api/*` intact).

### DTO Validators & Alpha-Flatten Warning

- **D-14 (DTO struct) :** Une classe `readonly` par step type dans
  `Service/AssetTransformation/StepParams/` :
  `ResizeStepParams`, `CropStepParams`, `RotateStepParams`, `FormatConvertStepParams`,
  `AddBackgroundStepParams`. Hydratation via Serializer (`denormalize` depuis
  `TransformationStep::$params`). Assertions `#[Assert\*]` sur les propriétés
  publiques readonly.
- **D-15 (Factory) :** `StepParamsFactory::fromStep(TransformationStep): object`
  résout la classe cible via `StepType`. Lève sur type inconnu.
- **D-16 (Hook validation) :** **Doctrine LifecycleListener** `prePersist` + `preUpdate`
  sur `TransformationStep` : hydrate via factory, valide via `ValidatorInterface`,
  lève `ValidationFailedException` → 422 surfacé par API Platform. Couvre fixtures,
  console et toute écriture hors API.
- **D-17 (Alpha-flatten side) :** L'alpha-flatten sur fond blanc est déjà effectué
  par `/img/format-convert` (Phase 2 SC #3). **Pas de step ajouté côté PHP.** Le
  `PipelineRunner` confirme juste que la chaîne se termine par `format_convert`
  quand l'extension demande JPEG ; sinon il append un `format_convert` implicite
  basé sur l'extension d'URL.
- **D-18 (Warning surface) :**
  - **Persistance :** colonne `warnings JSONB` sur `AssetTransformation`,
    recalculée par le `TransformationHashListener` (déjà en place Phase 1) à
    chaque flush, en même temps que `versionHash`. Schéma : `[{"code": "alpha-flatten-on-jpeg", "stepIndex": null}]`.
  - **PWA :** exposée via le read group existant pour l'éditeur Phase 7.
  - **Runtime `/t/*` :** header debug `X-Transformation-Warnings: alpha-flatten-on-jpeg`
    sur les réponses 200 affectées (utile ops, pas un canal d'info sensible).

### Cross-cutting

- **D-19 (ETag) :** Déterministe `"{transformationId}-v{hash8}-{assetId}"` (sans
  recalcul du binaire). `If-None-Match` supporté → 304 instantané.
- **D-20 (Streaming) :** `StreamedResponse` + `Flysystem readStream`. Fonctionne
  identiquement local FS (dev) et S3 (prod). Pas de redirect presigné, pas de
  `BinaryFileResponse`.
- **D-21 (Headers cache) :** `Cache-Control: public, max-age=31536000, immutable` +
  `ETag` + `Content-Type` exact + `Content-Length` quand connu.
- **D-22 (Route definition) :** Controller `PublicTransformationController` dédié,
  hors `/api`. Routing dans `config/routes/transformations.yaml`. Pas de firewall
  JWT (security: `public` dans `security.yaml`).

### Claude's Discretion

- Découpage exact en classes du `PipelineRunner` (single class vs PipelineBuilder)
- Nommage exact des `StepHandler` (suffix `Handler` confirmé)
- Stratégie de logging structuré au sein de chaque handler (decision : suivre les
  patterns existants `ChatService`/`AssetUploader`)
- Choix `nelmio_cors` config vs middleware custom pour le path `/t/*`
- Format exact du `Content-Type` (image/jpeg vs image/jpg etc.) — règle l'extension
- Tests E2E : utiliser ou non Docker compose dans la CI (`docker compose exec api …`)

### Folded Todos

_(Aucun todo en backlog correspondant au scope Phase 3.)_

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-level
- `CLAUDE.md` — Coding standards (PHP entités, ApiResources, State Processors, Vue, sécurité)
- `.planning/PROJECT.md` — Vision milestone v1.0, contraintes, Key Decisions
- `.planning/REQUIREMENTS.md` — REQ-IDs HANDLERS-* / ROUTE-* assignés à Phase 3
- `.planning/ROADMAP.md` — Phase 3 SC (5 critères) et phases adjacentes 2/4/5

### Phase 1 (acquis — verrouillé)
- `.planning/phases/01-domain-versioning-foundation/01-RESEARCH.md` — patterns
  `TransformationStorageKey`, `TransformationHashListener`, `StepType` enum
- `api/src/Service/AssetTransformation/TransformationStorageKey.php` — helper de
  clé S3 `forVariant(transformationId, hash, assetId, ext)` (point de vérité unique)
- `api/src/Entity/AssetTransformation/AssetTransformation.php` + `TransformationStep.php`
  + enum `StepType.php`
- `api/src/Message/PurgeTransformationVariantsMessage.php` — pattern Messenger
- `api/config/packages/messenger.yaml` — transport `transformations_backfill` existant

### Phase 2 (acquis — verrouillé)
- `.planning/phases/02-python-image-service-classical-endpoints/02-RESEARCH.md`
- Endpoints embedder : `POST /img/resize`, `/img/crop`, `/img/rotate`,
  `/img/format-convert`, `/img/add-background` (multipart in / binary out + timing
  headers). Alpha-flatten sur blanc fait par `/img/format-convert` côté JPEG.
- `embedder/README.md` — contrat HTTP (cap 50 MP, EXIF auto-orient, /health)

### Patterns existants à imiter
- `api/src/Service/Asset/AssetUploader.php` — pattern erreur/rollback + Flysystem
- `api/src/Controller/AssetController.php` — pattern streaming Flysystem
- `api/config/packages/nelmio_cors.yaml` — config CORS à étendre
- `api/config/packages/flysystem.yaml` — switch dev FS / prod S3

### Symfony stack utilisé
- `symfony/lock` (Redis store) — https://symfony.com/doc/current/lock.html
- `RetryableHttpClient` — https://symfony.com/doc/current/http_client.html#retry-failed-requests
- `ScopingHttpClient` — https://symfony.com/doc/current/http_client.html#scoping-client

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`TransformationStorageKey::forVariant()`** — déjà la clé S3 canonique, à
  utiliser tel quel par le controller et le PipelineRunner pour la lecture/écriture
  cache
- **`AssetController::content()`** — patron exact pour `StreamedResponse +
  Flysystem readStream` ; à dupliquer/factoriser pour `/t/*`
- **`AssetUploader::doUpload()`** — pattern rollback + exception sémantique
  (`AssetUploadException`) à imiter pour `TransformationPipelineException`
- **Redis service** — déjà câblé pour Messenger + ConversationService, dispo pour
  `symfony/lock` RedisStore via DI
- **`nelmio_cors`** — config existante à étendre (path `/t/*`)
- **`TransformationHashListener`** — déjà en place pour recalcul `versionHash` au
  flush ; ajouter la dérivation `warnings` au même endroit (1 listener, 2 colonnes
  recalculées atomically)

### Established Patterns
- **Service tags + interface** (`StepHandlerInterface`) avec autoconfigure tag
  `app.step_handler` puis injection d'un iterable trié dans `PipelineRunner`
- **DTO readonly avec Assert** — déjà utilisé par `ChatRequest`/`TranslatePavRequest`
  côté ApiResource
- **Lifecycle listeners Doctrine** — déjà utilisé (`TransformationHashListener`,
  `AssetDeleteProcessor` côté API Platform)
- **Env vars typed** (`%env(bool:...)%`) — pattern Symfony standard, déjà utilisé
  pour Flysystem S3 toggle

### Integration Points
- `config/routes/` — nouveau fichier `transformations.yaml` (hors `/api`)
- `config/packages/messenger.yaml` — pas modifié en Phase 3 (transport
  `transformations` introduit en Phase 7 pour le warmup)
- `config/packages/nelmio_cors.yaml` — ajouter path `^/t/`
- `config/services.yaml` — déclarations `embedder.client`, `lock.transformations`,
  service tags pour `StepHandlerInterface`
- Migration Doctrine : ajout colonne `warnings JSONB DEFAULT '[]'` sur
  `asset_transformation`

</code_context>

<specifics>
## Specific Ideas

- Le rejet **404 (jamais 403)** est un invariant produit explicite, à tester en
  unitaire pour les 6 cas de la D-10.
- Le cap **8s** est mesuré en wall-clock, pas en CPU. Vérifier qu'aucun
  `set_time_limit` ne raccourcisse abusivement la fenêtre.
- L'ETag déterministe doit être identique entre une 200 servie depuis cache et
  une 200 servie après génération sync — sinon le revalidate échoue. Tester les
  deux paths.
- Le header `X-Transformation-Warnings` est multi-valeur potentielle ; séparer
  par `, ` ou liste en JSON ? **Décidé : liste séparée par `, `** (cohérent avec
  `Cache-Control`).
- Le `PipelineRunner` doit appender un `format_convert` implicite quand l'extension
  d'URL diffère du format produit par la dernière step (cas typique : pipeline
  resize + crop, extension `.webp` → append `format_convert webp`). Cette
  ré-injection ne change PAS le `versionHash` car le hash est sur les steps
  persistés ; **le cache key dépend uniquement du hash + de l'extension** — donc
  une même transformation servie en `.webp` et `.jpg` génère deux variantes
  distinctes côté S3, ce qui est l'invariant voulu.

</specifics>

<deferred>
## Deferred Ideas

- **Transport Messenger `transformations`** (warmup non-AI) → Phase 7 (OPS-03)
- **Métriques cache hit/miss, render duration, lock contention** → Phase 7 (OPS-05)
- **Commande `transformations:warm`** → Phase 7 (OPS-01)
- **GC variantes orphelines** → Phase 7 (OPS-02)
- **Preview JWT `POST /api/asset_transformations/preview`** → Phase 7 (EDITOR-04)
- **Composable `useTransformedUrl`** → Phase 7 (EDITOR-07)
- **Hot-toggle du feature flag sans redéploiement** → backlog post-v1
- **Path GPU pour BiRefNet** → backlog post-v1
- **CDN front (CloudFront/Bunny)** → cadrage Webfacto en prod

</deferred>

---

*Phase: 03-php-orchestrator-public-route-cache-lock-sync-only*
*Context gathered: 2026-05-27*
