---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
plan: 02
subsystem: api
tags: [http-client, retry, orchestrator, pipeline, wall-clock-cap, embedder]
dependency_graph:
  requires:
    - Plan 03-01 StepParams DTOs + StepParamsFactory (validated params at persistence)
    - Phase 2 embedder endpoints `/img/{resize,crop,rotate,format-convert,add-background}`
    - StepType enum (Phase 1)
    - AssetTransformation + TransformationStep entities (Phase 1)
  provides:
    - PipelineRunner::run(AssetTransformation, bytes, outputExt): PipelineResult — consumed by Plan 03-03 controller and Phase 7 warmup
    - StepHandlerInterface (app.step_handler tag) — extension surface for future step types (BiRefNet Phase 4, SD Phase 5/6)
    - embedder.client (RetryableHttpClient) — reusable scoped HTTP client to the Python service
    - TransformationPipelineException with CODE_* — semantic error mapping for the public route
  affects:
    - api/config/services.yaml (new parameters + embedder.client/scoping/retry_strategy services)
tech-stack:
  added:
    - Symfony HttpClient ScopingHttpClient (SSRF guard)
    - Symfony HttpClient RetryableHttpClient + GenericRetryStrategy
    - Symfony Mime FormDataPart for multipart embedder requests
  patterns:
    - app.step_handler service tag + AutowireIterator collection
    - AbstractEmbedderStepHandler base class factorising multipart POST + status check
    - Virtual (non-persisted) format_convert step appended at runtime
key-files:
  created:
    - api/src/Service/AssetTransformation/StepHandler/StepHandlerInterface.php
    - api/src/Service/AssetTransformation/StepHandler/HandlerResult.php
    - api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php
    - api/src/Service/AssetTransformation/StepHandler/ResizeStepHandler.php
    - api/src/Service/AssetTransformation/StepHandler/CropStepHandler.php
    - api/src/Service/AssetTransformation/StepHandler/RotateStepHandler.php
    - api/src/Service/AssetTransformation/StepHandler/FormatConvertStepHandler.php
    - api/src/Service/AssetTransformation/StepHandler/AddBackgroundStepHandler.php
    - api/src/Service/AssetTransformation/PipelineRunner.php
    - api/src/Service/AssetTransformation/PipelineResult.php
    - api/src/Service/AssetTransformation/TransformationPipelineException.php
    - api/tests/Unit/Service/AssetTransformation/StepHandler/HandlersHttpTest.php
    - api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php
  modified:
    - api/config/services.yaml
decisions:
  - D-06 embedder.client = ScopingHttpClient(http://embedder:8000) decorated by RetryableHttpClient — SSRF guard + retries
  - D-07 Retry policy = 3 retries, 200/400/800 ms backoff, 5xx + transport exceptions only (4xx never retried)
  - D-08 Per-step default timeouts (2/2/2/3/4 s) bound from Symfony parameters with env override
  - D-09 Pipeline I/O = bytes string between handlers, no streaming, no temp files
  - D-03 Wall-clock cap enforced via min(defaultTimeoutMs, remainingMs) on every step + post-check
  - Virtual implicit format_convert NOT persisted → versionHash invariant preserved
  - AbstractEmbedderStepHandler base class chosen over copy-paste (5× reduction of boilerplate)
metrics:
  duration: "~5 minutes"
  completed: "2026-05-27"
  tasks: 2
  files_created: 13
  files_modified: 1
  tests_added: 12
requirements: [HANDLERS-01, HANDLERS-02]
---

# Phase 3 Plan 02: PHP Orchestrator (StepHandlers + PipelineRunner) Summary

Couche d'orchestration thin entre la route publique (Plan 03-03) et les
endpoints Python du service `embedder` (Phase 2) : `StepHandlerInterface`
+ 5 handlers HTTP tagués `app.step_handler` + `PipelineRunner` séquentiel
avec cap dur 8 s wall-clock et `format_convert` implicite quand l'extension
d'URL diffère du dernier format persisté (D-09).

## What Was Built

### StepHandlerInterface + HandlerResult

`api/src/Service/AssetTransformation/StepHandler/StepHandlerInterface.php`
expose 3 méthodes : `supportedType()` statique, `defaultTimeoutMs()`,
`run(bytes, params, timeoutMs): HandlerResult`. L'attribut
`#[AutoconfigureTag('app.step_handler')]` tag automatiquement chaque
implémentation pour la collecte par `PipelineRunner`.

`HandlerResult` est un `final readonly class` à 3 propriétés publiques :
`bytes`, `contentType`, `renderMs`.

### AbstractEmbedderStepHandler

Classe de base factorisant le POST multipart + check de statut + wrapping
des `TransportExceptionInterface` en `TransformationPipelineException`.
Chaque handler concret ne déclare plus que :
- son `StepType`
- son endpoint embedder (ex. `/img/resize`)
- son paramètre de timeout par DI (`#[Autowire(param: 'transformations.embedder_timeout_resize_ms')]`)

Décision pragmatique : 5 classes ~25 lignes au lieu de 5 classes ~70 lignes
identiques.

### 5 handlers concrets

| Classe | StepType | Endpoint | Default timeout |
|--------|----------|----------|-----------------|
| `ResizeStepHandler` | `RESIZE` | `/img/resize` | 2000 ms |
| `CropStepHandler` | `CROP` | `/img/crop` | 2000 ms |
| `RotateStepHandler` | `ROTATE` | `/img/rotate` | 2000 ms |
| `FormatConvertStepHandler` | `FORMAT_CONVERT` | `/img/format-convert` | 3000 ms |
| `AddBackgroundStepHandler` | `ADD_BACKGROUND` | `/img/add-background` | 4000 ms |

Tous les timeouts sont surchargés via env (`EMBEDDER_TIMEOUT_*_MS`).

### `embedder.client` (services.yaml)

Chaîne de décoration :
```
http_client
  → embedder.scoping (ScopingHttpClient::forBaseUri('http://embedder:8000'))
  → embedder.client (RetryableHttpClient, 3 retries)
      avec embedder.retry_strategy (GenericRetryStrategy, 200/400/800ms)
```

`statusCodes` configurés explicitement :
- `0` (= TransportException / timeout) : retry sur POST
- `423, 425, 429, 500, 502, 503, 504, 507, 510` : retry sur POST
- **Aucun statut 4xx déterministe (400/401/404/422)** : pas de retry

### PipelineRunner + PipelineResult

`PipelineRunner::run(AssetTransformation $tx, string $bytes, string $outputExt): PipelineResult`

Boucle clé (simplifiée) :

```php
foreach ($effectiveSteps as $step) {
    $remaining = $this->hardCapMs - (now - $start);
    if ($remaining <= 0) throw CODE_CAP_EXCEEDED;
    $timeoutMs = min($handler->defaultTimeoutMs(), $remaining);
    $res = $handler->run($bytes, $step->getParams(), $timeoutMs);
    $bytes = $res->bytes;
}
```

`format_convert` implicite : si l'extension d'URL diffère du dernier
`format` persisté (normalisation `jpg ↔ jpeg`), un step virtuel
`FORMAT_CONVERT` est appendé en mémoire. **Il n'est PAS persisté** →
`versionHash` reste invariant ; la divergence cache est assurée par la
clé S3 qui contient `{ext}` (clé construite par
`TransformationStorageKey::forVariant`).

`TransformationPipelineException` avec 4 codes sémantiques
(`CAP_EXCEEDED`, `EMBEDDER_ERROR`, `UNSUPPORTED_STEP`, `VALIDATION`) que
le controller Plan 03-03 mappera sur 503/502/404/422.

## Tasks Completed

| Task | Description | Commits |
|------|-------------|---------|
| 1 | Interface + 5 handlers + embedder.client + retry strategy + 6 tests | RED `f24b90a` / GREEN `a276084` |
| 2 | PipelineRunner + PipelineResult + Exception + 6 tests | RED `6af94de` / GREEN `124c2ba` |

## Verification

```
docker compose exec api ./vendor/bin/phpunit --testsuite=unit
→ Tests: 65, Assertions: 132 (OK)

docker compose exec api ./vendor/bin/phpunit --testsuite=integration
→ Tests: 10, Assertions: 20 (OK, 1 pre-existing deprecation Doctrine Table.uniqueConstraints)

docker compose exec api php bin/console debug:container --tag=app.step_handler
→ 5 handlers listed (Resize / Crop / Rotate / FormatConvert / AddBackground)

docker compose exec api php bin/console debug:container "App\Service\AssetTransformation\PipelineRunner"
→ Tagged Iterator app.step_handler resolved to 5 services
→ hardCapMs bound to %transformations.hard_cap_ms% (8000 default)
```

### Tests added (12 total)

**HandlersHttpTest (6)** — MockHttpClient direct (pas de Kernel) :
- `testResizeHandlerReturnsHandlerResultWithBytesAndContentType` — multipart correct + parse `X-Render-Duration-Ms`
- `testResizeHandlerRetriesOn503ThenSucceeds` — 4 appels au total (3 retries + succès)
- `testResizeHandlerDoesNotRetryOn422AndPreservesErrorBody` — 1 seul appel, body préservé
- `testSupportedTypeReturnsCorrectEnumForEachHandler` — 5 enums + 5 defaultTimeoutMs
- `testDefaultTimeoutMsReflectsConstructorInjection`
- `testRetryOnTransportTimeoutThenSucceeds` — **GATING D-07** : 3 appels (2 TransportException puis succès)

**PipelineRunnerTest (6)** — stubs anonymes via `PipelineRunner::fromMap()` :
- `testRunsStepsInOrderAndReturnsFinalBytes`
- `testAppendsImplicitFormatConvertWhenOutputExtDiffers` — vérifie aussi que la collection persistée n'est PAS mutée
- `testAppendsImplicitFormatConvertEvenAfterExplicitFormatConvertWithDifferentExt` (3 appels)
- `testHardCapExceededRaisesCapException` — cap 50ms, deux sleeps de 60ms → CODE_CAP_EXCEEDED
- `testRemainingMsDecreasesAcrossSteps` — cap clamp explicite step-par-step
- `testUnsupportedStepTypeRaises` — `REMOVE_BACKGROUND` → CODE_UNSUPPORTED_STEP

## Deviations from Plan

### Auto-fixed Issues / Adjustments

**1. [Rule 2 — Critical] AbstractEmbedderStepHandler ajouté**
- **Found during:** Task 1 — Le plan décrit 5 handlers indépendants avec ~70 lignes identiques chacun
- **Issue:** 5 copies du même `request()`/header check/error wrap → dette de maintenance immédiate, divergence facile
- **Fix:** Classe abstraite `AbstractEmbedderStepHandler` qui factorise multipart + status + render-ms, chaque handler concret tombe à ~25 lignes (StepType + endpoint + DI param)
- **Files:** `api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php` (créé)
- **Commit:** `a276084`

**2. [Rule 3 — Blocking] Tests placés sous `tests/Unit/` au lieu de `tests/Service/`**
- **Found during:** Task 1 — phpunit.dist.xml ne définit que deux testsuites (`tests/Unit` + `tests/Integration`) ; `tests/Service/...` du plan n'aurait pas été collecté
- **Fix:** Chemins finaux :
  - `tests/Unit/Service/AssetTransformation/StepHandler/HandlersHttpTest.php`
  - `tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php`
- **Commit:** `f24b90a` / `6af94de`

**3. [Rule 1 — Bug] Entity field `position`, plan référençait `sortOrder`**
- **Found during:** Task 2 — `TransformationStep` expose `getPosition()` / `setPosition()`, pas `getSortOrder()` / `setSortOrder()` comme cité dans le pseudo-code du plan
- **Fix:** Toutes les références adaptées à l'API réelle de l'entité
- **Files:** `api/src/Service/AssetTransformation/PipelineRunner.php`, `api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php`
- **Commit:** `124c2ba`

**4. [Rule 1 — Bug] `getSteps()` retourne une `Collection`, pas un array**
- **Found during:** Task 2 — pseudo-code du plan faisait `usort($tx->getSteps(), …)` qui ne marche pas sur un `ArrayCollection`
- **Fix:** `$tx->getSteps()->toArray()` avant le `usort`
- **Commit:** `124c2ba`

**5. [Rule 2 — Critical] `PipelineRunner::fromMap()` helper pour les tests**
- **Found during:** Task 2 — Les stubs anonymes héritant de `StepHandlerInterface` ne peuvent pas redéfinir la méthode `static supportedType()` par instance (LSP) → 6 instances retourneraient toutes `RESIZE`
- **Fix:** Un constructeur secondaire `fromMap(array $byType, …)` injecte directement la map type→handler. Le constructeur principal (DI prod) utilise toujours `static::supportedType()` via l'iterator taggé.
- **Note:** Le code de prod reste inchangé ; `fromMap()` est annoté `Test-only convenience` dans le PHPDoc.
- **Commit:** `124c2ba`

## Known Stubs

Aucun. Tous les flux sont câblés en bout-en-bout (handlers ↔ embedder.client ↔
PipelineRunner). Aucun mock résiduel dans le code de prod.

## Out-of-scope Items (deferred-items)

- Pre-existing Doctrine deprecation `Table.uniqueConstraints` sur
  `CollectionTranslation` — pré-existant, pas lié à Plan 03-02.
- Worker container `antigravity-worker-1` en état `Restarting` — pré-existant,
  pas lié à ce plan (pas d'écriture Messenger en Phase 3-02).

## Threat Flags

Aucun. Toutes les surfaces réseau sont conformes au threat model du plan :
- `embedder.scoping` enforce `http://embedder:8000` → T-03-08 (SSRF) mitigé
- Retry strategy exclut 4xx → T-03-07 (DoS amplification) mitigé
- Cap dur `microtime(true)` + `remainingMs` step-par-step → T-03-06 (DoS) mitigé

## Self-Check: PASSED

Files verified:
- FOUND: api/src/Service/AssetTransformation/StepHandler/StepHandlerInterface.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/HandlerResult.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/ResizeStepHandler.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/CropStepHandler.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/RotateStepHandler.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/FormatConvertStepHandler.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/AddBackgroundStepHandler.php
- FOUND: api/src/Service/AssetTransformation/PipelineRunner.php
- FOUND: api/src/Service/AssetTransformation/PipelineResult.php
- FOUND: api/src/Service/AssetTransformation/TransformationPipelineException.php
- FOUND: api/tests/Unit/Service/AssetTransformation/StepHandler/HandlersHttpTest.php
- FOUND: api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php

Commits verified:
- FOUND: f24b90a (Task 1 RED)
- FOUND: a276084 (Task 1 GREEN)
- FOUND: 6af94de (Task 2 RED)
- FOUND: 124c2ba (Task 2 GREEN)
