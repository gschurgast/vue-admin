# Phase 5: Editor PWA, Warmup, GC, Observability - Research

**Researched:** 2026-05-28
**Domain:** PWA (Vue 3 / Vuetify 4) + Symfony 8.0 (API Platform 4, Messenger Redis Streams, RateLimiter, Monolog) + Observabilité via Datadog Logs
**Confidence:** HIGH (stack vérifié dans le repo + CONTEXT.md verrouille les décisions clés)

## Summary

Phase 5 livre l'expérience admin (éditeur drag-and-drop + preview server-authoritative + composable `useTransformedUrl`), l'opérabilité (commandes `transformations:warm` + `transformations:gc`, 3 transports Messenger séparés avec failed queues distinctes) et l'observabilité (métriques en logs JSON Monolog ingérés par Datadog Logs). Aucun agent DogStatsD, pas de Prometheus endpoint, pas de scheduling auto.

Les décisions sont **largement verrouillées** par `05-CONTEXT.md` (D-01 → D-24). La recherche se concentre donc sur la **vérification des versions/patterns** et l'identification des points d'attention concrets dans le code existant.

**Recommandation principale :** Suivre 1:1 les patterns existants — `ChatRequest`/`ChatRequestProcessor` (DTO+Processor preview), `useAssetUrl` (blob URL ref-counted pour `usePreviewUrl`), `pwa/src/config/Asset.json` + `components/asset/` (composant custom de champ `steps`), `worker` Docker (cloner pour `worker-transformations` + `worker-transformations-backfill`). Ajouter `vuedraggable@4.1.0` (Vue 3 compat). Configurer `framework.rate_limiter` (jamais activé jusqu'ici). Splitter `failed` en 3 queues nommées par transport. Créer le channel Monolog `transformations_metrics` + service `TransformationMetrics` injecté aux points clés.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (verbatim from CONTEXT.md `<decisions>`)

**Éditeur PWA (EDITOR-01/02/03)**
- **D-01 :** Drag-and-drop via `vuedraggable@next` (wrapper officiel SortableJS pour Vue 3). API déclarative `<draggable v-model>`, gère touch events, ~10kB. Lib à installer (`npm install vuedraggable@next`).
- **D-02 :** Sous-formulaire par step type via composants Vue dédiés (un composant par `StepType` : `ResizeStepFields.vue`, `CropStepFields.vue`, `RotateStepFields.vue`, `FormatConvertStepFields.vue`, `AddBackgroundStepFields.vue`, `RemoveBackgroundStepFields.vue`). Réutilise les `pwa/src/components/fields/*` existants (NumberField, EnumField, RelationField pour `assetId`).
- **D-03 :** Composants éditeur dans `pwa/src/components/asset_transformation/edit/`. Le champ `steps` dans `AssetTransformation.json` pointe vers un composant custom `StepsField.vue` qui orchestre drag-and-drop + composants par type.

**Warning EDITOR-08**
- **D-04 :** Affichage non bloquant : banner orange en haut + chip "warning" sur les steps incriminés. Save autorisé (alpha-flatten garanti côté serveur Phase 3).
- **D-05 :** Source de vérité = `warnings` JSONB calculé par `TransformationHashListener` (Phase 1/3). PWA peut recalculer en miroir pour feedback temps réel.

**Preview (EDITOR-04/05)**
- **D-06 :** Trigger bouton manuel "Prévisualiser" (pas d'auto-debounce).
- **D-07 :** Picker explicite (modal search/paginate `/api/assets`) + mémorisation localStorage `preview_asset_{transformationId}`.
- **D-08 :** Payload `POST /api/asset_transformations/preview` = steps inline (DTO `PreviewRequest`) `{ assetId, ext, steps[] }`. Processor invoque `PipelineRunner` **bypass cache S3 et écriture S3**.
- **D-09 :** Réponse = stream binaire (`Content-Type: image/*`), `Cache-Control: no-store`, `X-Robots-Tag: noindex`. PWA consomme via blob URL ref-counted (`usePreviewUrl`).
- **D-10 :** Rate-limit Symfony RateLimiter (token bucket) **10 req/min par user JWT**. Retour 429 + `Retry-After`.
- **D-11 :** Sécurité : `is_granted('ROLE_USER')`. DTO validators existants (Phase 3) appliqués sur chaque step inline.

**Composable `useTransformedUrl` (EDITOR-07)**
- **D-12 :** Implémentation pure-string : `useTransformedUrl(code, assetId, ext) → string` retournant `/t/{code}/{assetId}.{ext}` (préfixe via env `VITE_PUBLIC_TRANSFORMATION_BASE`). Pas de fetch, pas de réactivité async.
- **D-13 :** Pas de gestion 202/503 client (cap 8s serveur garantit la réponse).

**Commandes ops (OPS-01/02/06)**
- **D-14 :** `transformations:warm {code} --asset-id=N` **requiert `--asset-id`** (pas de bulk v1.0). Dispatch `WarmupTransformationVariantMessage` sur transport `transformations`. Validation : code existe, asset existe et `isPublic=true`.
- **D-15 :** `transformations:gc --keep=N` = garder N derniers `versionHash` (N=1 par défaut). Scan S3 sous `transformations/{transformationId}-v{hash}/` pour énumérer les hashes existants, comparer à `versionHash` actif.
- **D-16 :** `gc --dry-run` : sortie liste complète + résumé par transformation (count variants + MB + total).
- **D-17 :** Aucun scheduling automatique en v1.0. Documentation dans `docs/transformations-ops.md`.

**Transports Messenger (OPS-03/04)**
- **D-18 :** 3 transports : `async` (CLIP, intouché), `transformations` (warmup live), `transformations_backfill` (purge/bulk, déjà câblé Phase 1).
- **D-19 :** Chaque transport a sa propre failed queue (`async_failed`, `transformations_failed`, `transformations_backfill_failed`). Inspection `messenger:failed:show --transport=X`.
- **D-20 :** Chaque transport son worker dédié. `docker-compose.yml` : ajouter services worker pour `transformations` et `transformations_backfill`.

**Observabilité (OPS-05)**
- **D-21 :** Backend = logs JSON structurés via Monolog (`JsonFormatter`, channel dédié `transformations_metrics`). Datadog Logs ingest et dérive les métriques. Pas de DogStatsD.
- **D-22 :** Service PHP `TransformationMetrics` (`src/Service/`) injecté dans `PublicTransformationController`, `PipelineRunner`, step handlers HTTP, `WarmupTransformationVariantHandler`.
- **D-23 :** Métriques embedder : PHP scrape `/health` périodiquement (commande `transformations:health-collect` cron-able OU listener post-call). Pas de secret DD dans container Python.
- **D-24 :** Format : un log Monolog par événement avec champs structurés `{metric, value, unit, transformation_id, step_type, transport, ...}`. Documentation des facets dans `docs/transformations-ops.md`.

### Claude's Discretion
- Schéma exact du DTO `PreviewRequest` (champs, validation) — suivre DTO Phase 3.
- Modal picker asset : extension `AssetGrid` en mode select ou modal custom.
- Granularité exacte des tags Monolog (nommage `metric` cohérent).
- Commande dédiée `transformations:health-collect` vs listener inline pour D-23.
- Tests : couverture (unit `TransformationMetrics`, integration preview+rate-limit, smoke E2E drag-and-drop).

### Deferred Ideas (OUT OF SCOPE)
- Mode bulk `transformations:warm` (sans `--asset-id`).
- Scheduling automatique (cron / Symfony Scheduler).
- UI admin de gestion des failed queues.
- Dashboards Datadog packagés/livrés par la phase.
- Auto-preview avec debounce.
- Add-background AI / Stable Diffusion.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| EDITOR-01 | Admin peut créer/modifier transformation via PWA (CRUD standard schema-driven) | Pattern `pwa/src/config/AssetTransformation.json` déjà câblé (Phase 1 plan 01-05). Enrichir le champ `steps` avec composant custom. |
| EDITOR-02 | Reorder steps en drag-and-drop | `vuedraggable@4.1.0` (npm verified, latest stable, Vue 3 compat). Wrapper de `Sortable.js@1.15.7`. |
| EDITOR-03 | Sous-formulaire dynamique adapté au `type` du step | Registry de composants par `StepType` (mapping enum → composant), réutilise `fields/*` existants. |
| EDITOR-04 | Preview via `POST /api/asset_transformations/preview` (JWT, no-store, rate-limité) | DTO+Processor pattern (`ChatRequest`/`ChatRequestProcessor` blueprint). `framework.rate_limiter` token_bucket (à activer — config absente). |
| EDITOR-05 | Preview server-authoritative, jamais sur cache S3 | `PipelineRunner` (Phase 3) — ajouter mode `bypass cache` (param `?dryRun` ou flag interne). Stream binaire en `Response` avec `Content-Type: image/*`. |
| EDITOR-07 | Composable `useTransformedUrl(code, assetId, ext)` retournant string | Pure helper TS, pas de fetch. Préfixe via `import.meta.env.VITE_PUBLIC_TRANSFORMATION_BASE`. |
| EDITOR-08 | Warning visible JPEG + remove_background sans add_background | Source : `transformation.warnings` JSONB (Phase 1/3). PWA lit après save + recalcule en miroir pendant l'édition. |
| OPS-01 | Commande `transformations:warm {code} [--asset-id=...]` | Symfony Console + MessageBus + nouveau `WarmupTransformationVariantMessage` + nouveau handler. `--asset-id` requis (v1.0). |
| OPS-02 | Commande `transformations:gc [--dry-run] [--keep=2]` | Symfony Console + Flysystem prefix listing (`transformations/{id}-v{hash}/`) + DELETE par hash non-actif. |
| OPS-03 | 3 transports Messenger (`async`, `transformations`, `transformations_backfill`) | `messenger.yaml` actuel a `async` + `transformations_backfill` + `sync` + `failed`. Manque `transformations`. |
| OPS-04 | Chaque transport a son worker et sa failed queue | Splitter le `failed: 'redis://...'` actuel en 3 transports failed séparés + cloner le service Docker `worker`. |
| OPS-05 | Métriques : cache hit/miss, render duration par endpoint Python, embedder timeout count, lock contention, `birefnet_inflight`, messages par transport | Service `TransformationMetrics` + channel Monolog dédié + scrape `/health` Phase 4 (déjà enrichi). |
| OPS-06 | Aucun backfill automatique au déploiement | Pas de hook deploy ; documentation `docs/transformations-ops.md` explicite (warmup = action ops manuelle). |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

> Note : CLAUDE.md mentionne « Symfony 7.3 / Vuetify 3 » mais le code installé est **Symfony 8.0 / Vuetify 4 / Vue Router 5 / Vite 8**. Les patterns conventionnels restent valides, mais les versions sont plus récentes. Aligner les implémentations sur le code réel, pas sur la doc.

| Directive | Impact Phase 5 |
|-----------|----------------|
| PHP entités : propriétés `private`, setters `static` | Aucune nouvelle entité dans Phase 5 (DTO uniquement). |
| ApiResource DTO PascalCase + `Request` suffix | `PreviewRequest` (à créer dans `api/src/ApiResource/`). Hidden via `#[MenuGroup('hidden')]` (POST only). |
| State Processor `Processor` suffix + `ProcessorInterface` | `PreviewRequestProcessor` dans `api/src/State/`. |
| Vue components `<script setup lang="ts">` + `defineProps<T>()` + `defineEmits<{}>()` | Tous les nouveaux composants Phase 5. |
| Composables prefix `use` | `useTransformedUrl`, `usePreviewUrl`. |
| Page actions : `PageActionBtn` + `PageActionsFooter` (pas de `<v-btn>` brut) | Bouton « Prévisualiser » + Save dans footer. |
| `make generate-types` après tout changement DTO | À lancer après `PreviewRequest`. |
| i18n 14 langues (`pwa/src/locales/`) | Toutes les chaînes UI Phase 5. |
| Boolean property naming `isXxx()` getter | N/A (pas de nouveau champ booléen entité). |
| Custom list `standalone` mode (`refresh()` via `defineExpose`) | Si modal asset-picker s'appuie sur `AssetGrid` standalone. |
| Voice/AI assistant : JWT auth via `/api/login`, roles `ROLE_USER`/`ROLE_ADMIN` | Preview = `ROLE_USER` (D-11). Commandes ops = CLI (pas de garde web). |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `vuedraggable` | **4.1.0** | Drag-and-drop reorder | Wrapper officiel Sortable.js pour Vue 3, API `<draggable v-model>`. [VERIFIED: npm view vuedraggable@next version → 4.1.0] |
| `sortablejs` | **1.15.7** | Sous-dépendance de vuedraggable | Tirée transitivement. [VERIFIED: npm view sortablejs version → 1.15.7] |
| `symfony/rate-limiter` | 8.0.* | Token bucket par user JWT | Composant Symfony natif, intégration `Limiter` factory. [CITED: symfony.com/doc/current/rate_limiter.html] |
| `symfony/messenger` | 8.0.* (déjà installé) | 3 transports Redis Streams + failed queues séparées | Déjà câblé Phase 1 (`async`, `transformations_backfill`, `sync`, `failed`). |
| `symfony/console` | 8.0.* (déjà installé) | Commandes `transformations:warm` + `:gc` | Pattern `CreateUserCommand.php` existant. |
| Monolog (via `symfony/monolog-bundle`) | déjà installé | Channel `transformations_metrics` + `JsonFormatter` | Pas d'agent à ajouter. [CITED: symfony.com/doc/current/logging/monolog_channels_handlers.html] |
| `league/flysystem` | déjà installé | List + delete sous préfixe S3 pour GC | `listContents($prefix, deep: true)`. [CITED: flysystem.thephpleague.com/docs] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `@vueuse/core` | ^14.0.0 (déjà installé) | `useLocalStorage` pour `preview_asset_{txId}` | Réactif et SSR-safe. |
| `axios` | ^1.13.2 (déjà installé) | Upload binaire blob + JWT pour preview | Réutilise `apiPlatform.client`. |
| Vuetify 4 | ^4.0.7 (déjà installé) | `<v-alert>` warning banner, `<v-chip color="warning">`, `<v-dialog>` picker | Composants natifs design system. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `vuedraggable@4` | Native HTML5 DnD API | DnD natif n'a pas le support touch mobile, pas de placeholder visuel, pas de scroll auto. vuedraggable +10kB justifié. |
| Logs JSON Monolog | DogStatsD UDP direct | Verrouillé D-21 : pas d'agent dans le container. Logs JSON ingérés par Datadog Logs avec measure facets. |
| `PrometheusBundle` exposing `/metrics` | — | Hors scope (verrouillé D-21). Datadog Logs = la source de métriques. |
| Symfony Scheduler pour `gc`/`warm` | — | Verrouillé D-17 : pas de scheduling auto. Webfacto décidera cron prod. |
| `vuedraggable-next` (package séparé v2 alpha) | vuedraggable@4 | Le package "next" historique a été remplacé par `vuedraggable@4.x` sous le même nom. **À installer : `npm install vuedraggable@4`** (pas `@next`). |

**Installation:**
```bash
docker compose exec pwa npm install vuedraggable@4
docker compose exec api composer require symfony/rate-limiter  # si absent du lock
```

**Version verification:**
```bash
npm view vuedraggable version           # → 4.1.0 (verified 2026-05-28)
npm view sortablejs version             # → 1.15.7 (transitive, verified)
```

**Important :** Le tag `@next` historiquement utilisé pour vuedraggable v4 alpha a été dépublié ; la cible canonique aujourd'hui est `vuedraggable@4` ou `@4.1.0`. Le CONTEXT.md D-01 mentionne `@next` mais l'install réel doit cibler `@4`. [VERIFIED: npm registry]

## Architecture Patterns

### Recommended Project Structure

```
api/src/
├── ApiResource/
│   └── PreviewRequest.php          # NEW — DTO preview (POST only, hidden)
├── State/
│   └── PreviewRequestProcessor.php # NEW — invoque PipelineRunner (bypass S3)
├── Command/
│   ├── TransformationsWarmCommand.php  # NEW — transformations:warm
│   ├── TransformationsGcCommand.php    # NEW — transformations:gc
│   └── (TransformationsHealthCollectCommand.php — discrétion, voir D-23)
├── Message/
│   └── WarmupTransformationVariantMessage.php  # NEW
├── MessageHandler/
│   └── WarmupTransformationVariantHandler.php  # NEW (instrumenté Metrics)
└── Service/
    └── TransformationMetrics.php   # NEW — façade Monolog channel transformations_metrics

api/config/packages/
├── messenger.yaml                  # MODIFY — ajout transport `transformations`, 3 failed queues
├── monolog.yaml                    # MODIFY — channel `transformations_metrics` + JsonFormatter
└── rate_limiter.yaml               # NEW — token_bucket preview_endpoint

docker-compose.yml                  # MODIFY — services worker-transformations, worker-transformations-backfill

pwa/src/
├── components/asset_transformation/edit/
│   ├── StepsField.vue              # NEW — orchestrateur drag-and-drop + dispatch composants
│   ├── steps/
│   │   ├── ResizeStepFields.vue
│   │   ├── CropStepFields.vue
│   │   ├── RotateStepFields.vue
│   │   ├── FormatConvertStepFields.vue
│   │   ├── AddBackgroundStepFields.vue
│   │   └── RemoveBackgroundStepFields.vue
│   ├── PreviewPanel.vue            # NEW — bouton + display blob
│   ├── AssetPickerDialog.vue       # NEW — modal pick asset
│   └── WarningBanner.vue           # NEW — JPEG+remove_background
├── composables/
│   ├── useTransformedUrl.ts        # NEW — pure string builder
│   ├── usePreviewUrl.ts            # NEW — copie/adapt useAssetUrl pour blob preview
│   └── (useTransformationWarnings.ts — discrétion : recalcul miroir EDITOR-08)
└── config/
    └── AssetTransformation.json    # MODIFY — `steps` → composant `StepsField`

docs/
└── transformations-ops.md          # NEW — ops doc (commandes, transports, failed, métriques)
```

### Pattern 1: DTO ApiResource + Processor pour endpoint custom POST

**What:** Endpoint custom non-CRUD (preview, chat, translate) → DTO PascalCase+`Request` + Processor.
**When to use:** Tout endpoint POST qui n'est pas un simple CRUD d'entité Doctrine.
**Example:**
```php
// Source: api/src/ApiResource/ChatRequest.php (pattern existant)
#[ApiResource(
    operations: [new Post(
        uriTemplate: '/asset_transformations/preview',
        processor: PreviewRequestProcessor::class,
        security: "is_granted('ROLE_USER')",
    )],
)]
#[MenuGroup('hidden')]
final class PreviewRequest
{
    #[Groups(['preview:write'])]
    public int $assetId;

    #[Groups(['preview:write'])]
    public string $ext;   // png|jpg|jpeg|webp|avif

    #[Groups(['preview:write'])]
    public array $steps;  // tableau d'objets step inline (type + params)
}
```

```php
// Source: api/src/State/ChatRequestProcessor.php (pattern existant)
final class PreviewRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private PipelineRunner $runner,
        private AssetRepository $assets,
        private LimiterFactory $previewLimiter,   // injected from rate_limiter.yaml
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        if (!$data instanceof PreviewRequest) { /* 400 */ }
        $limit = $this->previewLimiter->create($this->currentUserId())->consume(1);
        if (!$limit->isAccepted()) {
            return new Response('', 429, ['Retry-After' => (string) $limit->getRetryAfter()->getTimestamp()]);
        }
        $binary = $this->runner->runInline(/* steps */, /* asset */, $data->ext, bypassCache: true);
        return new Response($binary, 200, [
            'Content-Type' => "image/{$data->ext}",
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
```

### Pattern 2: Token bucket RateLimiter par user JWT

**What:** `framework.rate_limiter` + factory injectée + `consume()` retourne `Limit` avec `isAccepted()`.
**When:** Endpoint sensible (BiRefNet coûteux ~3s/inference, BiRefNet sérialise via `asyncio.Lock`).
**Example:**
```yaml
# Source: api/config/packages/rate_limiter.yaml (NEW — pas encore présent)
# CITED: https://symfony.com/doc/current/rate_limiter.html
framework:
    rate_limiter:
        preview_endpoint:
            policy: 'token_bucket'
            limit: 10
            rate: { interval: '1 minute', amount: 10 }
```

```php
// Auto-wiring : LimiterFactory $previewLimiter (param name → factory key)
$limit = $this->previewLimiter->create($userIdentifier)->consume(1);
```

### Pattern 3: Composable Vue blob URL ref-counted

**What:** Map module-level `cache: Map<key, blobUrl>` + `refCount` + `URL.revokeObjectURL` au unmount.
**When:** Toute consommation binaire authentifiée (assets, preview).
**Example (existing):** `pwa/src/composables/useAssetUrl.ts` (déjà lu, ~80 lignes, ref-counted + invalidate).

→ `usePreviewUrl.ts` clone `useAssetUrl` mais POST avec body JSON au lieu de GET, cache keyed par `hash(steps+assetId+ext)`.

### Pattern 4: vuedraggable@4 wrapper Sortable.js

**What:** `<draggable v-model="steps" item-key="id" handle=".drag-handle">` + `<template #item="{ element }">`.
**When:** Réordonner une liste réactive.
**Example:**
```vue
<!-- Source: vuedraggable@4 docs (CITED: github.com/SortableJS/vue.draggable.next) -->
<script setup lang="ts">
import draggable from 'vuedraggable'
const steps = ref<Step[]>([])
</script>

<template>
  <draggable v-model="steps" item-key="id" handle=".drag-handle" animation="200">
    <template #item="{ element, index }">
      <div class="step-row">
        <v-icon class="drag-handle">mdi-drag-vertical</v-icon>
        <component :is="stepComponentFor(element.type)" v-model="steps[index].params" />
      </div>
    </template>
  </draggable>
</template>
```

### Pattern 5: Messenger transport séparé + failed queue dédiée

**What:** Un transport DSN distinct par flux + `failure_transport` global remplacé par failure_transport per-transport (Symfony Messenger 5.2+).
**When:** Empêcher qu'un job lourd bloque un autre flux ; isolation des échecs.
**Example:**
```yaml
# Source: api/config/packages/messenger.yaml (MODIFY)
# CITED: https://symfony.com/doc/current/messenger.html#saving-and-handling-failed-messages
framework:
    messenger:
        # Per-transport failure transports (Symfony Messenger 5.2+ feature).
        failure_transport: failed          # default fallback
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                failure_transport: async_failed
                retry_strategy: { max_retries: 3, delay: 2000, multiplier: 2, max_delay: 60000 }
            transformations:
                dsn: 'redis://redis:6379/messages_transformations'
                options: { group: transformations }
                failure_transport: transformations_failed
                retry_strategy: { max_retries: 3, delay: 2000, multiplier: 2, max_delay: 30000 }
            transformations_backfill:
                dsn: 'redis://redis:6379/messages_transformations_backfill'
                options: { group: transformations_backfill }
                failure_transport: transformations_backfill_failed
                retry_strategy: { max_retries: 3, delay: 5000, multiplier: 2, max_delay: 120000 }
            async_failed: 'redis://redis:6379/messages_async_failed'
            transformations_failed: 'redis://redis:6379/messages_transformations_failed'
            transformations_backfill_failed: 'redis://redis:6379/messages_transformations_backfill_failed'
            failed: 'redis://redis:6379/messages_failed'   # legacy / fallback
            sync: 'sync://'
        routing:
            App\Message\ComputeEmbeddingMessage: async
            App\Message\WarmupTransformationVariantMessage: transformations
            App\Message\PurgeTransformationVariantsMessage: transformations_backfill
```

**Inspection :** `php bin/console messenger:failed:show --transport=transformations_failed`.
**Replay :** `php bin/console messenger:failed:retry --transport=transformations_failed`.
[VERIFIED: symfony/messenger 6.x+ supports `failure_transport` per-transport key]

### Pattern 6: Monolog channel dédié + JsonFormatter

```yaml
# Source: api/config/packages/monolog.yaml (MODIFY)
# CITED: https://symfony.com/doc/current/logging/monolog_channels_handlers.html
monolog:
    channels: ['transformations_metrics']
    handlers:
        transformations_metrics:
            type: stream
            path: 'php://stderr'                    # Docker stdout/stderr → Datadog Logs agent
            level: info
            channels: ['transformations_metrics']
            formatter: monolog.formatter.json
```

```php
// Source: src/Service/TransformationMetrics.php (NEW)
final class TransformationMetrics
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.transformations_metrics')]
        private LoggerInterface $logger,
    ) {}

    public function recordCacheHit(int $txId, string $hash): void
    {
        $this->logger->info('cache_event', [
            'metric' => 'transformations.cache.hit',
            'value' => 1,
            'unit' => 'count',
            'transformation_id' => $txId,
            'version_hash' => $hash,
        ]);
    }
    // recordCacheMiss, recordRenderDuration($stepType, $ms), recordLockContention,
    // recordEmbedderTimeout($stepType), recordMessageHandled($transport, $outcome)
}
```

[CITED: docs.datadoghq.com/logs/log_configuration/parsing/ — JSON structured logs auto-parsed, facets configurable plateforme côté]

### Anti-Patterns to Avoid

- **Modifier le transport `async` (CLIP)** — verrouillé D-18 « intouché ». Toute touch sur `async` casse les embeddings CLIP en flight.
- **Hand-roll un système de cache blob URL** — utiliser `useAssetUrl` comme base ; le ref-counted est tricky (race conditions sur unmount).
- **Mettre la preview-page dans le routeur PWA dynamique** — c'est un sous-formulaire de la page edit `AssetTransformation`, pas une page.
- **DELETE en batch dans `gc` sans `--dry-run` par défaut** — UX dangereuse. Sans dry-run, demander confirmation interactive `--force` si stdin TTY.
- **Lancer `transformations:warm` sur tous les assets** — verrouillé D-14 : `--asset-id` requis. Pas de mode bulk en v1.0.
- **Persister la preview en S3** — verrouillé D-09 : `no-store`. Le `PipelineRunner` doit avoir un mode `bypassCache=true` qui ne touche pas Flysystem en écriture.
- **Push DogStatsD UDP depuis le container Python embedder** — verrouillé D-23 : pas de secret DD côté embedder. Métriques scrapées via `/health` enrichi (Phase 4).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Drag-and-drop reorder | Native HTML5 DnD | `vuedraggable@4` | Touch mobile, placeholder, scroll auto, accessibilité. |
| Rate-limiting par user | Counter Redis manuel | `symfony/rate-limiter` token_bucket | TTL, distributed lock, sliding window géré. |
| JSON structured logs | `json_encode` + `error_log` | Monolog `JsonFormatter` + channel | Channel filtering, context propagation, handlers chain. |
| Blob URL lifecycle | `URL.createObjectURL` ad-hoc | `useAssetUrl` ref-counted pattern | Memory leaks (chaque blob URL = mémoire bloquée jusqu'à `revokeObjectURL`). |
| Picker asset modal | Composant from scratch | `AssetGrid` standalone (déjà existe) + `<v-dialog>` wrapper | Pagination + search déjà implémentés. |
| Listing S3 par préfixe pour GC | `aws-sdk` direct | `Flysystem::listContents($prefix, deep: true)` | Adapter-agnostic (local dev FS / S3 prod identique). |
| Symfony failed transport per-transport | Routing manuel par exception listener | `failure_transport:` clé per-transport (Messenger 5.2+) | Built-in. |

**Key insight :** Phase 5 = quasi 100 % d'assemblage de patterns existants ; quasiment rien à inventer. Le seul élément à construire from-scratch est `TransformationMetrics` (façade simple Monolog) et le `StepsField.vue` (orchestrateur + registry de composants par type).

## Runtime State Inventory

> Phase 5 ne renomme rien et ne migre pas de données. Cette section ne s'applique pas. (Pas de rename/refactor/migration → section omise.)

## Common Pitfalls

### Pitfall 1 : `vuedraggable@4` mismatch avec Vue 3.5 + Vite 8
**What goes wrong :** Le `vuedraggable@next` mentionné dans D-01 est un legacy tag. Installer naïvement `npm install vuedraggable@next` peut tomber sur une v2.x alpha incompatible.
**Why :** Le projet `vue.draggable.next` est devenu `vuedraggable@4` sur le registry npm sous le même package name.
**How to avoid :** `npm install vuedraggable@4` (cible explicite). Vérifier après install : `npm view vuedraggable@4.1.0 dependencies` → doit inclure `sortablejs ^1.x`.
**Warning signs :** Erreur de build « can't resolve `@vue/composition-api` » ou exception `provide is not a function` au runtime → mauvaise version installée.

### Pitfall 2 : Symfony Messenger — failed transport global vs per-transport
**What goes wrong :** Le `failure_transport: failed` global actuel envoie TOUS les messages échoués dans `messages_failed`, perdant la traçabilité par flux (CLIP vs warmup vs purge).
**Why :** Les développeurs oublient que `failure_transport:` peut se définir AU NIVEAU de chaque transport (override le global).
**How to avoid :** Ajouter `failure_transport: <name>_failed` à CHACUN des 3 transports (async, transformations, transformations_backfill). Garder le `failed:` global comme dead-letter de secours.
**Warning signs :** `messenger:failed:show --transport=transformations_failed` retourne vide alors qu'un warmup a échoué → la config per-transport est manquante, les jobs sont allés dans `failed`.

### Pitfall 3 : RateLimiter sans `LimiterFactory` autowired
**What goes wrong :** Injecter `RateLimiterFactory` au lieu de `LimiterFactory $previewLimiter` (param name binding).
**Why :** Symfony bind la factory par nom de param == nom du limiter dans `rate_limiter.yaml`.
**How to avoid :** Nommer le constructor param `$previewLimiter` (camelCase de `preview_endpoint`). Tester via `php bin/console debug:autowiring preview`.
**Warning signs :** `Cannot autowire service "App\State\PreviewRequestProcessor": argument $previewLimiter ... not found`.

### Pitfall 4 : Preview qui touche le cache S3
**What goes wrong :** Le `PipelineRunner` actuel (Phase 3) écrit systématiquement le résultat dans Flysystem sous `transformations/{txId}-v{hash}/...`. Sans option `bypassCache`, la preview pollue le cache (et utilise un hash potentiellement instable car les steps sont inline non-persistés).
**Why :** Le `PipelineRunner` est conçu pour la route publique, pas pour preview.
**How to avoid :** Ajouter au `PipelineRunner` un mode `bypassCache: bool` (ou méthode séparée `runEphemeral`) qui : (a) ne calcule pas de storageKey, (b) ne lit pas le cache, (c) ne stocke pas le résultat, (d) ne pose pas de lock Redis (preview = sérialisée côté rate-limiter).
**Warning signs :** Un fichier apparaît dans `var/assets/transformations/preview-XXX/` après un click preview en dev → bypass non appliqué.

### Pitfall 5 : `useTransformedUrl` qui devient async
**What goes wrong :** Tentation de faire `useTransformedUrl` async (fetch HEAD, gérer 202, debounce) — verrouillé contre par D-12/D-13.
**Why :** Toutes les transformations v1.0 sont sync (cap 8s). Aucun chemin AI 202.
**How to avoid :** Garder le composable PUREMENT synchrone : juste un builder de string. Pas de `ref`, pas de `watch`, pas de fetch. Si demain l'AI revient, un *autre* composable (`useAsyncTransformedUrl`) sera créé.
**Warning signs :** PR review ajoutant `import { ref } from 'vue'` dans `useTransformedUrl.ts` → red flag.

### Pitfall 6 : GC énumère uniquement le hash actif (perd les variants à supprimer)
**What goes wrong :** `gc` lit `transformation.versionHash` (= hash courant) et conclut « rien à supprimer ». Or les variants à supprimer sont précisément les hashes *non-courants*.
**Why :** Symfony ne tient pas d'historique des hashes — la source de vérité des hashes « ayant existé » est S3 lui-même.
**How to avoid :** Le GC énumère via `Flysystem::listContents("transformations/{txId}-v", deep: false)` pour découvrir tous les `*-v{hash}/` existants, puis compare au `versionHash` actif et garde les N derniers selon `mtime` (D-15).
**Warning signs :** `gc --dry-run` retourne « 0 hash to delete » alors que la transformation a été modifiée plusieurs fois → le listing S3 n'est pas exhaustif.

### Pitfall 7 : Workers Docker non scalés correctement
**What goes wrong :** Cloner le service `worker` avec `--transport=transformations` mais oublier d'isoler le binding mount ou le nom de container → conflit de fichier `var/cache/` partagé.
**Why :** Le service `worker` actuel mount `./api:/app` (partagé avec api). Multiplexer 3 workers sur le même mount n'est pas un souci en soi (read-only Symfony app), mais ils écrivent tous dans `var/log` et `var/cache`.
**How to avoid :** Chaque worker passe `APP_CACHE_DIR=/tmp/cache-<transport>` via env, ou utilise des noms de container distincts (`worker-async`, `worker-transformations`, `worker-transformations-backfill`). Lance `messenger:consume <transport> --time-limit=3600 --memory-limit=512M`.
**Warning signs :** Erreurs Symfony « cache pool already locked » dans les logs worker.

### Pitfall 8 : Logs JSON sortis vers fichier au lieu de stdout/stderr
**What goes wrong :** Default Monolog handler écrit dans `var/log/dev.log` → Datadog Logs agent ne récolte rien en prod containerisée.
**Why :** Docker logging driver capture stdout/stderr, pas les fichiers.
**How to avoid :** Le handler `transformations_metrics` doit avoir `path: 'php://stderr'` (pas `%kernel.logs_dir%/...`).
**Warning signs :** Pas d'events visibles dans Datadog Logs Explorer après déploiement staging.

## Code Examples

### Example 1 : `useTransformedUrl.ts` (pure string)
```typescript
// Source: docs Vue 3 import.meta.env (CITED: vitejs.dev/guide/env-and-mode)
const base = (import.meta.env.VITE_PUBLIC_TRANSFORMATION_BASE as string | undefined) ?? ''

export function useTransformedUrl(code: string, assetId: number, ext: string): string {
  return `${base}/t/${code}/${assetId}.${ext}`
}

// Usage:
//   <img :src="useTransformedUrl('product-thumb', product.heroAssetId, 'webp')" />
```

### Example 2 : `transformations:warm` command (squelette)
```php
// Source: pattern api/src/Command/CreateUserCommand.php (existing)
#[AsCommand(name: 'transformations:warm', description: 'Dispatch a warmup job for one transformation+asset pair.')]
final class TransformationsWarmCommand extends Command
{
    public function __construct(
        private MessageBusInterface $bus,
        private AssetTransformationRepository $transformations,
        private AssetRepository $assets,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Transformation code (kebab-case)')
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED, 'Required: target asset id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $input->getArgument('code');
        $assetId = $input->getOption('asset-id');
        if (!$assetId) { /* error: --asset-id required in v1.0 */ return Command::FAILURE; }

        $tx = $this->transformations->findOneBy(['code' => $code]) ?? throw /* not found */;
        $asset = $this->assets->find((int) $assetId) ?? throw /* not found */;
        if (!$asset->isPublic()) { /* error: asset must be public */ return Command::FAILURE; }

        $this->bus->dispatch(new WarmupTransformationVariantMessage($tx->getId(), $asset->getId()));
        $output->writeln("<info>Warmup dispatched: tx={$tx->getCode()} asset={$asset->getId()}</info>");
        return Command::SUCCESS;
    }
}
```

### Example 3 : `transformations:gc --dry-run` listing S3 par préfixe
```php
// Source: flysystem.thephpleague.com/docs/usage/filesystem-api/#listing-contents
$prefix = sprintf('transformations/%d-v', $tx->getId());
$activeHash = $tx->getVersionHash();
$hashes = [];   // hash => ['bytes' => int, 'count' => int, 'mtime' => int]

foreach ($this->fs->listContents($prefix, deep: true) as $item) {
    // path = transformations/12-v81d2af.../{shard}/{assetId}.{ext}
    if (!preg_match('#transformations/\d+-v([0-9a-f]+)/#', $item->path(), $m)) continue;
    $hash = $m[1];
    $hashes[$hash] ??= ['bytes' => 0, 'count' => 0, 'mtime' => 0];
    $hashes[$hash]['bytes'] += $item->fileSize();
    $hashes[$hash]['count']++;
    $hashes[$hash]['mtime'] = max($hashes[$hash]['mtime'], $item->lastModified());
}

// Garder N derniers (le actif + N-1 plus récents)
uasort($hashes, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$keep = array_slice([$activeHash => $hashes[$activeHash] ?? null] + $hashes, 0, $keepN, preserve_keys: true);
$toDelete = array_diff_key($hashes, $keep);
```

### Example 4 : `StepsField.vue` — orchestrateur drag-and-drop + dispatch
```vue
<script setup lang="ts">
import draggable from 'vuedraggable'
import ResizeStepFields from './steps/ResizeStepFields.vue'
import CropStepFields from './steps/CropStepFields.vue'
// ... etc.

const props = defineProps<{ modelValue: Step[] }>()
const emit = defineEmits<{ (e: 'update:modelValue', steps: Step[]): void }>()

const componentRegistry: Record<StepType, Component> = {
  resize: ResizeStepFields,
  crop: CropStepFields,
  rotate: RotateStepFields,
  format_convert: FormatConvertStepFields,
  add_background: AddBackgroundStepFields,
  remove_background: RemoveBackgroundStepFields,
}

const local = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})
</script>

<template>
  <draggable v-model="local" item-key="id" handle=".drag-handle" animation="200">
    <template #item="{ element, index }">
      <v-card class="mb-2 step-row">
        <v-card-title class="d-flex align-center">
          <v-icon class="drag-handle me-2">mdi-drag-vertical</v-icon>
          <span>{{ element.type }}</span>
        </v-card-title>
        <v-card-text>
          <component
            :is="componentRegistry[element.type]"
            v-model="local[index].params"
          />
        </v-card-text>
      </v-card>
    </template>
  </draggable>
</template>
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Symfony Messenger : 1 failed transport global | `failure_transport` par transport | Messenger 5.2 (2020) | Per-transport replay/inspection (D-19). |
| Vue 2 + Sortable wrapper | `vuedraggable@4` natif Vue 3 | 2022 | Pas de polyfill `@vue/composition-api`. |
| DogStatsD UDP push | Datadog Logs ingest + measure facets | Datadog Pipelines 2023+ | Pas d'agent dans container Python (D-21/D-23). |
| `framework.rate_limiter` + Redis manuel | `LimiterFactory` autowired par nom | Symfony 5.2 (2020) | Pas de Redis pool ad-hoc à câbler. |

**Deprecated/outdated :**
- `vuedraggable@2` (alpha "next" historique) → remplacé par `@4`.
- `dogstatsd-php` SDK → verrouillé contre (D-21).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Le `PipelineRunner` (Phase 3) accepte ou peut accepter un flag `bypassCache: bool` sans refactor lourd | Pitfall 4 + Example DTO | Si refactor profond requis, ajouter une tâche Wave 0 « introduire mode ephemeral dans PipelineRunner ». **À confirmer** en relisant `api/src/Service/PipelineRunner.php`. |
| A2 | `failure_transport:` per-transport est supporté en Symfony Messenger 8.0 (héritage 5.2+) | Pattern 5 | Symfony 8.0 hérite des features 5.x+ — très faible risque, mais vérifier la doc 8.0 explicitement. [ASSUMED] |
| A3 | `vuedraggable@4` est compatible Vuetify 4 + Vue 3.5 + Vite 8 | Pitfall 1 | Pas d'incompatibilité connue, mais le projet utilise des majeurs récents (Vuetify 4 GA récente). À smoke-tester en Wave 0. [ASSUMED] |
| A4 | Le `transformation.warnings` JSONB est exposé via API Platform dans la sérialisation actuelle | EDITOR-08 | Si non exposé, ajouter à `Groups(['transformation:read'])`. **À vérifier** en lisant l'entité `AssetTransformation`. |
| A5 | Datadog Logs (Webfacto config) accepte les logs JSON via stdout des containers Docker en prod | Pitfall 8 / D-21 | Si Webfacto n'a pas Datadog Logs activé sur ces containers, fallback : écrire dans `var/log/transformations-metrics.json` + tail forwarder. **À confirmer Webfacto** (Open Question Q1). |
| A6 | Le `WarmupTransformationVariantMessage` doit déclencher le `PipelineRunner` normal (avec cache write) — pas la version bypass | Pattern 1 | Si la spec voulait warmup = bypass, on serait inutile. Le warmup PRE-REMPLIT le cache → cache write OBLIGATOIRE. [VERIFIED par lecture CONTEXT D-14 + ROADMAP success criteria #4] |
| A7 | Les versions npm vérifiées (`vuedraggable 4.1.0`, `sortablejs 1.15.7`) sont stables à la date du plan | Standard Stack | Très faible risque de breaking entre planification et implémentation (semaines). [VERIFIED: npm registry 2026-05-28] |

## Open Questions

1. **(Q1) Datadog Logs pipeline configuré côté Webfacto pour ces containers ?**
   - What we know : D-21 verrouille « logs JSON + Datadog Logs ingest ».
   - What's unclear : Si la config Datadog Logs au niveau infra (DD_API_KEY, tags `service:antigravity-api`) est déjà active en prod/staging.
   - Recommandation : Demander à Webfacto avant la phase. Si non, ajouter une tâche « config Datadog Logs Source/Pipeline » dans le sub-plan ops.

2. **(Q2) Choix entre commande `transformations:health-collect` (D-23) vs listener inline**
   - What we know : D-23 laisse les deux options ouvertes (Claude's Discretion).
   - What's unclear : Quel rythme de scraping suffit pour le SLO ? (1/min via cron ? Tag à chaque appel handler ?)
   - Recommandation : Listener inline post-call dans chaque step handler HTTP — pas de cron, données plus fraîches, instrumentation co-localisée avec le code qui appelle l'embedder. Commande dédiée si on veut un endpoint santé synthétique.

3. **(Q3) `--keep` par défaut : 1 ou 2 ?**
   - What we know : ROADMAP dit `--keep=2`, CONTEXT D-15 dit « N=1 par défaut ». **Divergence.**
   - What's unclear : Veut-on conserver le hash actif uniquement (1) ou conserver aussi le précédent (2) pour rollback rapide ?
   - Recommandation : `--keep=2` par défaut (rollback friendly, aligné ROADMAP). Documenter dans `transformations-ops.md`.

4. **(Q4) Le picker d'asset : modal full-screen vs side-panel ?**
   - What we know : D-07 dit « picker explicite (modal search/paginate `/api/assets`) ».
   - What's unclear : UX exacte (modal centré Vuetify `<v-dialog>` ou drawer latéral).
   - Recommandation : `<v-dialog max-width="1200">` avec `<AssetGrid standalone @select="...">` à l'intérieur. Pattern existant (CLAUDE.md « Custom list components: standalone mode »).

5. **(Q5) Recalcul mirror EDITOR-08 côté PWA**
   - What we know : D-05 mentionne « PWA peut recalculer côté client pour feedback temps réel ».
   - What's unclear : Faut-il porter la logique exacte du `TransformationHashListener` PHP en TypeScript (duplication) ?
   - Recommandation : Composable `useTransformationWarnings(steps, outputExt)` qui implémente UNE seule règle simple (« derniers steps incluent `remove_background` ET `outputExt in [jpg,jpeg]` ET aucun `add_background` après le `remove_background` »). Pas de duplication exhaustive du hashing — juste la détection des warnings. Aligner les codes (`remove-background-requires-png`, `alpha-flatten-on-jpeg`) avec ceux du serveur (Phase 4 plan 04-05).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Node.js (in pwa container) | `vuedraggable` install | ✓ | `node:lts` (≥ 20) | — |
| Symfony 8.0 + Console | Commands `:warm`/`:gc` | ✓ | 8.0.* | — |
| Symfony RateLimiter | Preview endpoint | ✓ (bundled in symfony/framework-bundle 8.0) | 8.0.* | — |
| symfony/messenger + redis-messenger | 3 transports | ✓ | 8.0.* | — |
| Monolog + JsonFormatter | Structured logs | ✓ (déjà installé via symfony/monolog-bundle) | — | — |
| Flysystem listContents | GC S3 scan | ✓ (utilisé Phase 1-4) | — | — |
| Redis 7 | Transports + rate limiter storage | ✓ (docker service `redis`) | 7-alpine | — |
| Datadog Logs ingest config prod | OPS-05 livraison métriques | ✗ (à confirmer Webfacto) | — | Logs JSON dans `php://stderr` → captés par Docker logging driver ; Webfacto ajoute pipeline après |
| BiRefNet `/health` endpoint enrichi | D-23 scraping métriques embedder | ✓ (livré Phase 4 plan 04-03 : `birefnet_inflight`, `last_inference_ms`) | — | — |

**Missing dependencies with no fallback :** Aucune (Datadog Logs prod est non-bloquant, la phase peut livrer les logs JSON sans pipeline configuré).

**Missing dependencies with fallback :** Datadog Logs pipeline (Q1) — fallback : les logs JSON sortent sur stdout/stderr de toute façon, ils restent inspectables via `docker logs` ou agent host-level. La valeur OPS-05 est atteinte dès que les logs sortent au bon format.

## Validation Architecture

> `.planning/config.json` n'a pas été inspecté individuellement ; les phases antérieures (1-4) ont livré des plans avec Wave 0 PHPUnit + pytest, l'infrastructure est donc opérationnelle. Section incluse par défaut.

### Test Framework

| Property | Value |
|----------|-------|
| Framework (PHP) | PHPUnit (installé Phase 1 plan 01-02) + Symfony test pack |
| Framework (Python) | pytest + httpx (installé Phase 2 plan 02-01) — pas de delta Phase 5 |
| Framework (PWA) | Aucun runner installé (`package.json` n'a ni `vitest` ni `jest`) — voir Wave 0 Gaps |
| Config file | `api/phpunit.xml.dist` (existant) |
| Quick run command (API) | `docker compose exec api ./vendor/bin/phpunit --testsuite=unit` |
| Full suite command (API) | `docker compose exec api ./vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| EDITOR-04 | POST `/api/asset_transformations/preview` returns 200 + binary + no-store | integration | `phpunit tests/Integration/PreviewEndpointTest.php` | ❌ Wave 0 |
| EDITOR-04 | RateLimiter 429 after 10 req/min/user | integration | `phpunit tests/Integration/PreviewRateLimitTest.php` | ❌ Wave 0 |
| EDITOR-05 | Preview bypasses S3 (no write under `transformations/`) | integration | `phpunit tests/Integration/PreviewBypassCacheTest.php` | ❌ Wave 0 |
| EDITOR-07 | `useTransformedUrl` returns expected string | unit | manual / type-check via `vue-tsc` | manual-only (no PWA runner) |
| EDITOR-08 | `warnings` JSONB exposes `remove-background-requires-png` | integration | `phpunit tests/Integration/TransformationWarningsTest.php` (extend Phase 3 test) | partial (Phase 3 test exists) |
| EDITOR-02/03 | Drag-and-drop reorders steps in DOM | manual smoke E2E | manual checklist | manual-only |
| OPS-01 | `transformations:warm` dispatches message on `transformations` transport | unit (command) | `phpunit tests/Unit/Command/TransformationsWarmCommandTest.php` | ❌ Wave 0 |
| OPS-02 | `transformations:gc --dry-run` enumerates non-active hashes | unit (command) | `phpunit tests/Unit/Command/TransformationsGcCommandTest.php` | ❌ Wave 0 |
| OPS-03 | 3 transports registered + routing | smoke | `phpunit tests/Smoke/MessengerTransportsTest.php` | ❌ Wave 0 |
| OPS-04 | Per-transport failed queues | smoke | `phpunit tests/Smoke/MessengerFailedQueuesTest.php` (assert config) | ❌ Wave 0 |
| OPS-05 | `TransformationMetrics::recordX()` emits JSON log on dedicated channel | unit | `phpunit tests/Unit/Service/TransformationMetricsTest.php` | ❌ Wave 0 |
| OPS-06 | No deploy hook triggers backfill | doc check | grep `transformations:warm` in deploy scripts → empty | manual-only |

### Sampling Rate

- **Per task commit :** `./vendor/bin/phpunit --testsuite=unit --filter <touched class>`
- **Per wave merge :** `./vendor/bin/phpunit` (full suite api + python pytest + `vue-tsc --noEmit` pour PWA)
- **Phase gate :** Full suite green + smoke E2E manuel sur drag-and-drop + preview round-trip avant `/gsd-verify-work`.

### Wave 0 Gaps

- [ ] `tests/Integration/PreviewEndpointTest.php` — couvre EDITOR-04 (200 + no-store + binary)
- [ ] `tests/Integration/PreviewRateLimitTest.php` — couvre EDITOR-04 (429 + Retry-After)
- [ ] `tests/Integration/PreviewBypassCacheTest.php` — couvre EDITOR-05 (pas d'écriture S3)
- [ ] `tests/Unit/Command/TransformationsWarmCommandTest.php` — couvre OPS-01
- [ ] `tests/Unit/Command/TransformationsGcCommandTest.php` — couvre OPS-02 (avec FlysystemMock)
- [ ] `tests/Smoke/MessengerTransportsTest.php` — assert config `framework.messenger.transports.transformations` existe (OPS-03)
- [ ] `tests/Smoke/MessengerFailedQueuesTest.php` — assert chaque transport a un `failure_transport` distinct (OPS-04)
- [ ] `tests/Unit/Service/TransformationMetricsTest.php` — couvre OPS-05 (mock LoggerInterface, vérifie payload JSON)
- [ ] `tests/Integration/TransformationWarningsTest.php` — étendre test Phase 3 avec cas `remove_background + jpeg + no add_background` (EDITOR-08)
- [ ] PWA test runner : **pas dans le scope Wave 0 Phase 5** (manuel-only — décision implicite, aucun runner installé en Phase 1-4). Recommandation : noter en Open Question Q6 pour cadrage post-v1.0 (vitest + @vue/test-utils).

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | JWT via `lexik/jwt-authentication-bundle` (existant). Preview endpoint = `is_granted('ROLE_USER')` (D-11). |
| V3 Session Management | yes | JWT stateless ; rate limiter keyed par user identifier. |
| V4 Access Control | yes | `security:` sur `PreviewRequest` Post operation. Asset target preview = check `isPublic` OU appartenance user (à confirmer — D-11 mentionne JWT user, pas la propriété d'asset). |
| V5 Input Validation | yes | DTO validators existants Phase 3 (`ResizeStepParams`, etc.) appliqués via `StepParamsFactory` sur les steps inline. `ext` validé contre allowlist `[png, jpg, jpeg, webp, avif]`. |
| V6 Cryptography | no | Aucun nouveau besoin (JWT signature déjà gérée). |
| V11 Business Logic | yes | RateLimiter 10/min/user prévient DoS sur BiRefNet (~3s CPU/inférence). |
| V13 API & Web Service | yes | `Content-Type: image/*` strictement (pas de mismatch). `Cache-Control: no-store` + `X-Robots-Tag: noindex` (D-09). |
| V14 Configuration | yes | RateLimiter config dans `rate_limiter.yaml` versionné. Pas de secret en clair. |

### Known Threat Patterns for stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| DoS via preview spam (chaque preview = inférence BiRefNet potentielle ~3s CPU) | Denial of Service | RateLimiter token bucket 10/min/user (D-10). |
| SSRF via `add_background type:asset` preview avec assetId arbitraire | Tampering | Déjà mitigé Phase 2 plan 02-04 : assetId numérique uniquement, lookup S3 interne, jamais d'URL externe. |
| Preview retourne un asset privé non-autorisé | Information Disclosure | Vérifier `Asset::isPublic` OU appartenance avant render. **À spécifier dans le plan preview** (D-11 ne le précise pas). |
| Cache empoisonnement via preview | Tampering | D-08/D-09 : preview NE touche PAS le cache S3 (bypass). |
| Replay JWT volé sur preview | Spoofing | RateLimiter par user limite l'impact, JWT TTL standard (déjà géré). |
| `transformations:gc` supprime variants encore servis | Tampering (data loss) | `--dry-run` par défaut + `--keep=2` (rollback rapide) + log JSON de chaque DELETE (audit Datadog). |
| Failed queue saturation Redis | DoS | `max_retries: 3` par transport + alerting Datadog sur taille de stream `messages_*_failed`. |
| Logs JSON contenant données utilisateur PII | Information Disclosure | TransformationMetrics ne logge QUE des IDs numériques + step types + durées (pas de path asset, pas d'email). |

## Sources

### Primary (HIGH confidence)
- Code repo : `api/config/packages/messenger.yaml`, `api/composer.json`, `pwa/package.json`, `pwa/src/composables/useAssetUrl.ts`, `pwa/src/config/AssetTransformation.json`, `docker-compose.yml`, `api/src/ApiResource/ChatRequest.php`, `api/src/State/ChatRequestProcessor.php` — patterns existants vérifiés à la lecture.
- npm registry : `vuedraggable@4.1.0`, `sortablejs@1.15.7` (commande `npm view ... version`, 2026-05-28).
- `.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md` — décisions verrouillées D-01 à D-24.
- `.planning/REQUIREMENTS.md` — IDs EDITOR-* et OPS-* avec definitions of done.
- `.planning/ROADMAP.md` § Phase 5 — success criteria.

### Secondary (MEDIUM confidence)
- symfony.com/doc/current/rate_limiter.html — token_bucket policy, LimiterFactory injection. [CITED]
- symfony.com/doc/current/messenger.html#saving-and-handling-failed-messages — `failure_transport` per-transport. [CITED]
- symfony.com/doc/current/logging/monolog_channels_handlers.html — channel + handler scoping. [CITED]
- flysystem.thephpleague.com/docs/usage/filesystem-api/ — listContents deep+prefix. [CITED]
- vitejs.dev/guide/env-and-mode — `import.meta.env.VITE_*`. [CITED]
- github.com/SortableJS/vue.draggable.next — vuedraggable v4 API. [CITED]
- docs.datadoghq.com/logs/log_configuration/parsing/ — JSON logs facets. [CITED]

### Tertiary (LOW confidence)
- Versions exactes des features Symfony Messenger 8.0 héritées de 5.2+ — assumé compatible (A2).
- Compatibilité runtime Vuetify 4 + vuedraggable@4 — pas de smoke test fait, à valider Wave 0 (A3).

## Metadata

**Confidence breakdown:**
- Standard stack : **HIGH** — versions vérifiées (npm registry, composer.json read).
- Architecture : **HIGH** — patterns 1:1 avec code existant (ChatRequest, useAssetUrl, AssetTransformation.json).
- Pitfalls : **HIGH** — issus de la lecture du code (messenger.yaml a effectivement un seul `failed`, pas de `rate_limiter.yaml`, `worker` Docker mount partagé).
- Open questions : **MEDIUM** — Q1 (Datadog Webfacto) et Q3 (--keep default divergence) requièrent input externe.

**Research date :** 2026-05-28
**Valid until :** 2026-06-27 (30 jours — stack stable).
