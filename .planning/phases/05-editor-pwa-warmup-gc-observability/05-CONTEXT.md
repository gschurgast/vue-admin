# Phase 5: Editor PWA, Warmup, GC, Observability - Context

**Gathered:** 2026-05-28
**Status:** Ready for planning
**Revised:** 2026-05-28 — D-15 défaut révisé à `--keep=2` (alignement ROADMAP)

<domain>
## Phase Boundary

Livraison de :
- Éditeur PWA pour `AssetTransformation` (création/édition, drag-and-drop des steps, sous-formulaires dynamiques par `StepType`, warning EDITOR-08).
- Endpoint et UX de **preview server-authoritative** (`POST /api/asset_transformations/preview`).
- Composable PWA `useTransformedUrl(code, assetId, ext)` (URL builder sync).
- Commandes ops `transformations:warm` et `transformations:gc`.
- Câblage **3 transports Messenger** distincts (`async` / `transformations` / `transformations_backfill`) avec `failed` queues séparées.
- Observabilité (métriques cache, render, lock, embedder, transports).

**Hors scope** (renvoyé en deferred ou hors v1.0) :
- Mode bulk de `transformations:warm` (warmup sans `--asset-id`) — explicitement retiré.
- Scheduling automatique (cron) des commandes ops.
- UI admin de gestion des failed queues.
- Add-background AI / Stable Diffusion (déjà reporté hors v1.0).

</domain>

<decisions>
## Implementation Decisions

### Éditeur PWA (EDITOR-01/02/03)

- **D-01 :** Drag-and-drop via **`vuedraggable@next`** (wrapper officiel SortableJS pour Vue 3). API déclarative `<draggable v-model>`, gère touch events, ~10kB. Lib à installer (`npm install vuedraggable@next`). **Note implémentation (research) :** cible canonique sur npm = `vuedraggable@4` (le tag `@next` historique a été dépublié). Installer `vuedraggable@4`.
- **D-02 :** Sous-formulaire par step type via **composants Vue dédiés** (un composant par `StepType` : `ResizeStepFields.vue`, `CropStepFields.vue`, `RotateStepFields.vue`, `FormatConvertStepFields.vue`, `AddBackgroundStepFields.vue`, `RemoveBackgroundStepFields.vue`). Réutilise les `pwa/src/components/fields/*` existants (NumberField, EnumField, RelationField pour `assetId`).
- **D-03 :** Composants éditeur dans `pwa/src/components/asset_transformation/edit/` (suit le pattern existant `components/asset/`, `components/taxonomy/`). Le champ `steps` dans `AssetTransformation.json` pointe vers un composant custom `StepsField.vue` qui orchestre drag-and-drop + composants par type.

### Warning EDITOR-08 (JPEG + remove_background sans add_background)

- **D-04 :** Affichage **non bloquant** : banner orange en haut du formulaire + chip "warning" sur les steps incriminés. Save reste autorisé (l'alpha-flatten implicite est garanti côté serveur depuis Phase 3).
- **D-05 :** Source de vérité : **réutiliser les `warnings` (JSONB)** déjà calculés par le `TransformationHashListener` (Phase 1/3). La PWA lit `transformation.warnings[]` après save et peut recalculer côté client pour feedback temps réel pendant l'édition (logique miroir, mais la source canonique reste le serveur).

### Preview (EDITOR-04/05)

- **D-06 :** Trigger **bouton manuel** "Prévisualiser" (pas d'auto-debounce). Économise les inférences BiRefNet (~3s/photo) et le rate-limit.
- **D-07 :** Sélection de l'asset de test via **picker explicite** (modal qui search/paginate `/api/assets`) + **mémorisation localStorage** clé `preview_asset_{transformationId}`. Restauré à la réouverture de la page.
- **D-08 :** Payload `POST /api/asset_transformations/preview` = **steps inline** (DTO `PreviewRequest` ApiResource) :
  ```json
  { "assetId": 42, "ext": "png", "steps": [ {...}, {...} ] }
  ```
  Permet de prévisualiser AVANT save (steps non encore persistés). Processor invoque `PipelineRunner` mais **bypass** le cache S3 et l'écriture S3.
- **D-09 :** Réponse = **stream binaire** (Content-Type: `image/*`), `Cache-Control: no-store`, `X-Robots-Tag: noindex`. Côté PWA : consommé via `useAssetUrl`-like (blob URL ref-counted) — créer un `usePreviewUrl` ou étendre `useAssetUrl`.
- **D-10 :** Rate-limit Symfony **RateLimiter (token bucket) 10 req/min par user JWT**. Retour 429 avec `Retry-After`.
- **D-11 :** Sécurité : DTO + processor sous `is_granted('ROLE_USER')` (admin/user authentifié JWT). Validation : tous les step types V1 acceptés + DTO validators existants (Phase 3) appliqués sur chaque step inline.

### Composable `useTransformedUrl` (EDITOR-07)

- **D-12 :** Implémentation pure-string : `useTransformedUrl(code, assetId, ext) → string` retournant `/t/{code}/{assetId}.{ext}` (préfixe configurable via env `VITE_PUBLIC_TRANSFORMATION_BASE`). Pas de fetch, pas de réactivité async — toutes les transfos v1.0 sont sync. Compose-friendly : `:src="useTransformedUrl(...)"`.
- **D-13 :** Pas de gestion 202/503 côté client (rappel : cap 8s côté serveur garantit la réponse).

### Commandes ops (OPS-01/02/06)

- **D-14 :** `transformations:warm {code} --asset-id=N` **requiert `--asset-id`** (pas de mode bulk en v1.0). Dispatch d'un `WarmupTransformationVariantMessage` sur le transport `transformations`. Validation : `code` existe, asset existe et `isPublic=true`.
- **D-15 :** `transformations:gc` : sémantique **`--keep=N` = garder les N derniers `versionHash`** d'une transformation. **Défaut révisé 2026-05-28 : `--keep=2`** (alignement ROADMAP Phase 5 success criteria #4 ; supersedes la rédaction initiale qui disait N=1). Rationale : conserver le hash actif + le précédent pour permettre un rollback rapide. Source de vérité : l'historique des hashes (table `asset_transformation` actuelle ne garde QUE le hash courant → besoin de scanner S3 sous `transformations/{transformationId}-v{hash}/...` pour énumérer les hashes existants, comparer à `versionHash` actif).
- **D-16 :** `gc --dry-run` : sortie **liste complète + résumé par transformation** :
  ```
  Transformation: product-thumb (id=12, active=a3f7...)
    To delete: 2 hash(es)
      - 81d2... (1240 variants, 184 MB)
      - 0b9e... (820 variants, 121 MB)
    Total to free: 305 MB
  ---
  Grand total: 8 transformations, 4.2 GB to free
  ```
- **D-17 :** **Aucun scheduling automatique** en v1.0 (cron, Symfony Scheduler). Documentation des commandes dans `docs/transformations-ops.md` (à créer). Webfacto déciderera du scheduling prod. Aligné avec OPS-06 (pas de backfill auto au déploiement).

### Transports Messenger (OPS-03/04)

- **D-18 :** **3 transports distincts** configurés dans `config/packages/messenger.yaml` (Redis Streams) :
  - `async` — CLIP existant (`ComputeEmbeddingMessage`), **intouché**.
  - `transformations` — warmup live (`WarmupTransformationVariantMessage`).
  - `transformations_backfill` — purge / bulk (`PurgeTransformationVariantsMessage` déjà câblé en Phase 1).
- **D-19 :** Chaque transport a sa **propre `failed` queue** (`async_failed`, `transformations_failed`, `transformations_backfill_failed`). Inspection via `bin/console messenger:failed:show --transport=X`. Pas d'UI admin (hors scope).
- **D-20 :** Chaque transport a son worker dédié. Mise à jour `docker-compose.yml` : ajouter (ou multiplexer) des services worker pour `transformations` et `transformations_backfill` aux côtés du `worker` actuel (`async`).

### Observabilité (OPS-05)

- **D-21 :** Backend = **logs JSON structurés via Monolog** (formatter `JsonFormatter`, channel dédié `transformations_metrics`). Datadog Logs ingest et dérive les métriques côté plateforme (les facets / measure facets sont configurables sans redéploiement applicatif). Pas d'agent DogStatsD à ajouter. **Note ops :** le shipper Webfacto branchera le pipeline Datadog plus tard ; pas de `dd-trace-php` dans cette phase.
- **D-22 :** Service PHP **`TransformationMetrics`** (à créer dans `src/Service/`) injecté dans les points clés :
  - `PublicTransformationController` → `recordCacheHit($transformationId, $hash)` / `recordCacheMiss(...)`.
  - `PipelineRunner` → `recordRenderDuration($transformationId, $stepType, $durationMs)` / `recordLockContention(...)`.
  - `StepHandler`s HTTP → `recordEmbedderTimeout($stepType)`.
  - `WarmupTransformationVariantHandler` → `recordMessageHandled('transformations', $outcome)`.
- **D-23 :** Métriques **embedder** : pas de push DogStatsD direct. Le PHP **scrape `/health`** périodiquement (commande `transformations:health-collect` cron-able OU listener sur chaque step handler post-call) et émet des logs avec `birefnet_inflight`, `last_inference_ms`. Garde le container Python sans secret DD.
- **D-24 :** Format des logs : un message Monolog par événement avec champs structurés `{metric, value, unit, transformation_id, step_type, transport, ...}`. Documentation des facets attendus dans `docs/transformations-ops.md`.

### Claude's Discretion

- Schéma exact du DTO `PreviewRequest` (champs, validation) — Claude planifie en suivant les DTO Phase 3.
- Modal de picker d'asset : Claude choisit la lib/pattern (probablement extension du `AssetGrid` en mode select).
- Granularité exacte des tags Monolog (nommage `metric` cohérent).
- Choix entre commande dédiée `transformations:health-collect` vs listener inline pour les métriques embedder (D-23).
- Tests : couverture (unit pour `TransformationMetrics`, integration pour preview endpoint + rate-limit, smoke E2E pour drag-and-drop).

### Folded Todos

Aucun todo folded — pas de match remonté par `gsd-tools todo match-phase`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project & Milestone
- `.planning/PROJECT.md` — Vision, milestone v1.0, contraintes (sync-only 8s, 3 transports, OPS-06 no auto backfill).
- `.planning/REQUIREMENTS.md` — REQ EDITOR-01/02/03/04/05/07/08 et OPS-01/02/03/04/05/06.
- `.planning/ROADMAP.md` § Phase 5 — Goal + Success Criteria.

### Phase artifacts upstream (à lire pour comprendre ce qui existe déjà)
- `.planning/phases/01-domain-versioning-foundation/01-CONTEXT.md` + `01-0X-PLAN.md` — Entités `AssetTransformation`, `TransformationStep`, `versionHash`, `TransformationHashListener`, `PurgeTransformationVariantsMessage`, `TransformationStorageKey`.
- `.planning/phases/03-php-orchestrator-public-route-cache-lock-sync-only/03-CONTEXT.md` + plans — `PipelineRunner` (cap 8s), `StepHandlerInterface`, DTO validators par step type, warnings JSONB, route publique `/t/*`, lock Redis, cache S3, CORS, feature flag.
- `.planning/phases/04-birefnet-endpoint-remove-background-deploy-gate/04-CONTEXT.md` + plans — `RemoveBackgroundHandler`, `/health` enrichi (`birefnet_inflight`, `last_inference_ms`), warning JPEG+remove_background.

### Code de référence (PWA — patterns à suivre)
- `pwa/src/config/AssetTransformation.json` — Config schema-driven à étendre (custom component pour `steps`).
- `pwa/src/config/Asset.json` + `pwa/src/components/asset/` — Modèle de référence pour custom components imbriqués.
- `pwa/src/components/fields/` — Field components réutilisables (NumberField, EnumField, RelationField, CodeField, BooleanField).
- `pwa/src/composables/useAssetUrl.ts` — Modèle pour `usePreviewUrl` (blob URL ref-counted, JWT fetch).
- `pwa/src/components/common/PageActionBtn.vue` + `PageActionsFooter.vue` — Boutons et footer requis (CLAUDE.md « Page actions »).

### Code de référence (API — patterns à suivre)
- `api/config/packages/messenger.yaml` — Pour ajouter les transports `transformations` et `transformations_backfill` (déjà partiellement câblés Phase 1).
- `api/config/packages/rate_limiter.yaml` (à créer si absent) — Symfony `framework.rate_limiter` pour le bucket preview.
- Pattern DTO + Processor : `api/src/ApiResource/ChatRequest.php` + `api/src/State/ChatRequestProcessor.php` (modèle pour `PreviewRequest` + `PreviewRequestProcessor`).
- `api/src/EventListener/TransformationHashListener.php` (Phase 1) — Source des warnings JSONB.

### CLAUDE.md (lignes directrices)
- `CLAUDE.md` § « Page actions » — Tous les CTA via `PageActionBtn` + `PageActionsFooter`.
- `CLAUDE.md` § « Generated TypeScript types » — `make generate-types` après tout changement DTO.
- `CLAUDE.md` § « Custom list components: standalone mode » — Pour le picker d'asset si standalone.

### Docs à créer pendant Phase 5
- `docs/transformations-ops.md` — Documentation des commandes `transformations:warm` / `transformations:gc`, des transports Messenger, des failed queues, des métriques (facets Datadog attendus), du scheduling recommandé (à valider Webfacto).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **PWA**
  - `pwa/src/components/fields/*` — NumberField, EnumField, RelationField (assetId picker), CodeField, BooleanField, RichTextField.
  - `pwa/src/composables/useAssetUrl.ts` — Modèle pour blob URL authentifié ref-counted (à dupliquer/étendre pour preview).
  - `pwa/src/components/common/PageActionBtn.vue` / `PageActionsFooter.vue` — Boutons et footer normalisés.
  - `pwa/src/components/asset/list/AssetGrid.vue` — Réutilisable en mode picker pour le sélecteur d'asset de preview.
  - `pwa/src/services/apiPlatform.ts` — Client API avec introspection schema.
  - Pattern `pwa/src/config/{Resource}.json` + composants custom dans `components/{resource}/` (asset, taxonomy).

- **API**
  - `App\EventListener\TransformationHashListener` (Phase 1) — Calcule `versionHash` + `warnings` JSONB → source de vérité pour EDITOR-08.
  - `App\Service\PipelineRunner` (Phase 3) — À invoquer dans le `PreviewRequestProcessor` avec cache/S3 bypass.
  - `App\Handler\*StepHandler` (Phase 3+4) — Tous les step handlers à instrumenter dans le service `TransformationMetrics`.
  - Pattern Symfony `framework.rate_limiter` (à activer) — Token bucket pour preview.
  - Transports Messenger Redis Streams déjà câblés (`async`, `transformations`, `transformations_backfill` ébauché Phase 1 pour la purge).
  - Worker container existant — modèle pour ajouter `worker-transformations` / `worker-transformations-backfill`.

### Established Patterns

- **DTO ApiResource + Processor** (`ChatRequest` / `ChatRequestProcessor`, `TranslatePavRequest` / `TranslatePavProcessor`) → modèle direct pour `PreviewRequest`.
- **Custom edit component** (`AssetShow.vue`, `AssetGrid.vue`) → modèle pour `StepsField.vue` (drag-and-drop) et les `{Type}StepFields.vue`.
- **Composable use*** (`useAssetUrl`, `useFormLocale`) → modèle pour `useTransformedUrl` et `usePreviewUrl`.
- **i18n 14 langues** (`pwa/src/locales/`) → toutes les chaînes UI doivent passer par i18n keys.
- **Boolean property naming** (`isXxx()` getter, exposé `isXxx`) → si nouveaux champs ajoutés aux entités.
- **Generated TS types** (`pwa/src/types/api.d.ts` via `make generate-types`) → à régénérer après `PreviewRequest`.

### Integration Points

- **Route éditeur** : auto-générée par le routeur PWA dynamique sur `AssetTransformation` (déjà accessible — il s'agit d'enrichir la config edit).
- **Endpoint preview** : nouveau `POST /api/asset_transformations/preview` exposé par ApiResource DTO.
- **Commandes ops** : nouvelles dans `api/src/Command/` (Symfony Console).
- **Worker containers** : nouveaux services dans `docker-compose.yml` (ou multiplexage avec args différents).
- **Monolog channel** : nouveau channel `transformations_metrics` dans `config/packages/monolog.yaml`.

### Creative Options Enabled

- Le pattern field components permet de plug très rapidement le sous-formulaire par step type sans réinventer la validation.
- `useAssetUrl` ref-counted simplifie la gestion mémoire des previews (blob URL revoke automatique).
- Le `versionHash` côté serveur garantit que le `gc` reste déterministe sans table d'historique additionnelle (scan S3 suffit).

### Existing Code Constraints

- Pas de lib drag-and-drop installée → ajout `vuedraggable@next` requis (impact bundle ~10kB).
- `RateLimiter` Symfony nécessite configuration `framework.rate_limiter` (probablement à activer pour la première fois).
- `dogstatsd-php` / agent DD non installés → confirmé OK puisque décision = logs JSON.

</code_context>

<specifics>
## Specific Ideas

- Le picker d'asset preview se mémorise par transformation (clé localStorage `preview_asset_{transformationId}`) pour ne pas reposer la question à chaque retour sur la page d'édition.
- La preview est un bouton explicite — pas de surprise pour l'utilisateur, pas de spam BiRefNet.
- Les warnings JSONB calculés par le listener Phase 1 sont la **source unique de vérité** ; la PWA peut recalculer en miroir pour feedback temps réel mais doit aligner sa logique sur le serveur.
- Métriques en logs JSON Monolog plutôt qu'agent DogStatsD : décision pragmatique pour livrer la phase sans ajouter d'infra ; Datadog Logs sait dériver les facets/measures côté plateforme.
- Embedder reste sans secret Datadog → métriques scrape via `/health` enrichi (déjà livré Phase 4).
- `transformations:warm` n'a **pas** de mode bulk en v1.0 : `--asset-id` est requis. Le bulk reviendra plus tard (deferred).

</specifics>

<deferred>
## Deferred Ideas

- **Mode bulk `transformations:warm`** (sans `--asset-id`) — explicitement écarté pour v1.0 ; reviendra dans un milestone ultérieur si la demande prod émerge (probablement combiné à un scheduling cron).
- **Scheduling automatique** (Symfony Scheduler / cron) des commandes `gc` et `warm` — laissé à la décision Webfacto post-livraison.
- **UI admin de gestion des failed queues** (list/replay des messages échoués via PWA) — out of scope v1.0 ; `messenger:failed:show` CLI suffit.
- **Dashboards Datadog** packagés/livrés par la phase — exclus du scope code ; à construire par ops avec la doc des facets fournie dans `docs/transformations-ops.md`.
- **Auto-preview avec debounce** (refresh sur chaque modif de step) — écarté pour ne pas saturer BiRefNet ; à reconsidérer si retours utilisateurs.
- **Add-background AI / Stable Diffusion** (`ai_prompt`) — déjà reporté hors v1.0 (PROJECT.md), reste deferred.

### Reviewed Todos (not folded)

Aucun todo remonté par le matching automatique pour Phase 5.

</deferred>

---

*Phase: 05-editor-pwa-warmup-gc-observability*
*Context gathered: 2026-05-28*
*Revised: 2026-05-28 — D-15 default `--keep=2` (was N=1) for ROADMAP alignment*
