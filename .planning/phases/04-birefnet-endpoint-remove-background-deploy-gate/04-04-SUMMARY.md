---
phase: 04
plan: 04
subsystem: php-remove-background-sync-handler
tags: [phase-04, wave-2, api, php, symfony, handler, dto, transformation-lookup, sync-gate-inversion]
requires:
  - Phase 04 Plan 01 (DTO + handler stubs marked Plan-04-04-skipped)
  - Phase 04 Plan 02 (Python POST /img/remove-background endpoint live with X-Render-Duration-Ms + X-Model-Used headers)
  - Phase 03 Plan 02 (AbstractEmbedderStepHandler base + embedder.client RetryableHttpClient)
  - Phase 03 Plan 03 (TransformationLookup::isAsyncStep gate)
provides:
  - "App\\Service\\AssetTransformation\\StepParams\\RemoveBackgroundStepParams (readonly DTO, Assert\\Choice on model)"
  - "App\\Service\\AssetTransformation\\StepHandler\\RemoveBackgroundHandler (extends AbstractEmbedderStepHandler, /img/remove-background)"
  - "transformations.embedder_timeout_remove_background_ms parameter (default 6000ms, env override EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS)"
  - "Sync serving of /t/{code}/{id}.{ext} for transformations containing remove_background"
affects:
  - api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php (REMOVE_BACKGROUND → RemoveBackgroundStepParams)
  - api/src/Service/AssetTransformation/TransformationLookup.php (REMOVE_BACKGROUND retiré du branchement isAsyncStep)
  - api/config/services.yaml (param embedder_default_rmbg_ms)
  - api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php (4 nouveaux tests, dont 3 routing)
  - api/tests/Unit/Service/AssetTransformation/StepParams/RemoveBackgroundStepParamsTest.php (3 stubs → 4 real tests)
  - api/tests/Unit/Service/AssetTransformation/StepHandler/RemoveBackgroundHandlerTest.php (3 stubs → 4 real tests)
  - api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php (testRemoveBackgroundStepRaisesNotFound → 2 tests inversés)
  - api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php (commentaire mis à jour pour testUnsupportedStepTypeRaises)
tech-stack:
  added: []
  patterns:
    - "Handler concret minimal: 2 méthodes (supportedType + endpointPath), tout le HTTP dance hérité de AbstractEmbedderStepHandler"
    - "Strict-fields denormalisation (ALLOW_EXTRA_ATTRIBUTES=false) refuse les clés inconnues — SSRF guard pour T-04-04"
    - "Auto-discovery handler via #[AutoconfigureTag('app.step_handler')] sur StepHandlerInterface — pas de DI explicite"
key-files:
  created:
    - api/src/Service/AssetTransformation/StepParams/RemoveBackgroundStepParams.php
    - api/src/Service/AssetTransformation/StepHandler/RemoveBackgroundHandler.php
  modified:
    - api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php
    - api/src/Service/AssetTransformation/TransformationLookup.php
    - api/config/services.yaml
    - api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php
    - api/tests/Unit/Service/AssetTransformation/StepParams/RemoveBackgroundStepParamsTest.php
    - api/tests/Unit/Service/AssetTransformation/StepHandler/RemoveBackgroundHandlerTest.php
    - api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php
    - api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php
decisions:
  - "Handler timeout = 6000ms (D-18): 5s Python hard cap (D-05) + 1s network margin. Param exposé en services.yaml + env override."
  - "PHPUnit testUnsupportedStepTypeRaises conservé (Option A) avec commentaire renversé — synthétique mais utile pour couvrir le `default => throw` du PipelineRunner sans dépendre de l'absence d'un StepType."
  - "Aucun warning custom ajouté côté TransformationHashListener pour `remove_background + format != png` — punt à Plan 04-05 ou Phase 7 (warnings UX)."
metrics:
  duration: ~12 min
  completed_date: 2026-05-27
  tasks_completed: 3
  files_created: 2
  files_modified: 8
---

# Phase 04 Plan 04: PHP RemoveBackground Handler + Sync Gate Inversion Summary

Wave 2 wires the Symfony side of the BiRefNet sync path: a readonly DTO + factory routing for `remove_background`, a HTTP handler that POSTs to `/img/remove-background` with 6 s timeout, and the **inversion** of `TransformationLookup::isAsyncStep` so `/t/{code}/{id}.{ext}` now serves remove-background variants sync while `add_background type=ai_prompt` keeps its 404 until Phase 5.

## What Was Built

**`api/src/Service/AssetTransformation/StepParams/RemoveBackgroundStepParams.php`** — final readonly DTO with two params: `model` (default `birefnet`, validated via `Assert\Choice(['birefnet','isnet-general-use'])`) and `fallbackOnTimeout` (default `false`). Mirrors the Pydantic `RemoveBgParams` on the Python side (Plan 04-02) so the JSON body the handler emits is wire-identical to what the embedder expects. No `url` field accepted — any extra key is rejected at the `ALLOW_EXTRA_ATTRIBUTES=false` denormalisation stage with `ExtraAttributesException` → 422 (T-04-04 SSRF guard).

**`api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php`** — `match` extended with `StepType::REMOVE_BACKGROUND => RemoveBackgroundStepParams::class`. The Phase 3 `default => throw new UnsupportedStepTypeException($type)` branch remains for any future StepType added to the enum without a wiring counterpart, but in Phase 4 every case is reached.

**`api/src/Service/AssetTransformation/StepHandler/RemoveBackgroundHandler.php`** — `final` class extending `AbstractEmbedderStepHandler`. The constructor pulls `embedder.client` (the scoped + retryable Phase 3 client) and the new `transformations.embedder_timeout_remove_background_ms` parameter via `#[Autowire]` attributes. `supportedType()` returns `StepType::REMOVE_BACKGROUND` and `endpointPath()` returns `/img/remove-background`. All HTTP dance (multipart `image` + `params` JSON, Accept image/*, 4xx → TransformationPipelineException, transport-error wrap) is inherited.

**`api/config/services.yaml`** — added `embedder_default_rmbg_ms: '6000'` + `transformations.embedder_timeout_remove_background_ms: '%env(int:default:embedder_default_rmbg_ms:EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS)%'` following the same env-overridable parameter pattern as the other 5 step timeouts. 6 s = 5 s Python hard cap (D-05) + 1 s network margin (D-18).

**`api/src/Service/AssetTransformation/TransformationLookup.php`** — `isAsyncStep()` no longer branches on `REMOVE_BACKGROUND`; only `ADD_BACKGROUND` with `params.type === 'ai_prompt'` triggers the 404. The class-level PHPDoc + the D-05 inline comment now mention Phase 4 D-16 explicitly. The ROUTE-08 isPublic invariant (D-10/T-03-11) is untouched — private assets still 404 regardless of step types.

**Tests** — 11 new green tests, 3 stubs flipped from `markTestSkipped` to real assertions:

- `RemoveBackgroundStepParamsTest` (4 tests, no skip): defaults, invalid model rejected, fallback flag, isnet-general-use accepted.
- `StepParamsFactoryTest` (Phase 3 test `testRemoveBackgroundThrowsUnsupportedInPhase3` replaced by **4 new tests**): routing to `RemoveBackgroundStepParams`, invalid model → ValidationFailedException, unknown key → ExtraAttributesException, empty params → defaults.
- `RemoveBackgroundHandlerTest` (4 tests, no skip): MockHttpClient round-trip on `POST /img/remove-background`, supportedType, defaultTimeoutMs reflects DI, 504 wrap as TransformationPipelineException with exactly 1 call (no unit-level retry).
- `TransformationLookupTest` (the Phase 3 `testRemoveBackgroundStepRaisesNotFound` was inverted into **2 tests**): `testRemoveBackgroundIsServedSyncAfterPhase4` (sync OK) + `testRemoveBackgroundStill404IfAssetIsPrivate` (ROUTE-08 invariant). The pre-existing `testAddBackgroundAiPromptRaisesNotFound` + `testAddBackgroundColorIsAllowed` continue to pass — Phase 5 path preserved.
- `PipelineRunnerTest::testUnsupportedStepTypeRaises` (comment rewritten): the synthetic semantics are made explicit (handler map mis-wired, not a "Phase 3 intentional exclusion").

## Verification

```
$ docker compose exec -T api vendor/bin/phpunit --filter "RemoveBackgroundStepParamsTest|StepParamsFactoryTest"
OK (21 tests, 34 assertions)

$ docker compose exec -T api vendor/bin/phpunit --filter "RemoveBackgroundHandlerTest"
OK (4 tests, 8 assertions)

$ docker compose exec -T api vendor/bin/phpunit --filter "TransformationLookupTest|PipelineRunnerTest"
OK (14 tests, 41 assertions)

$ docker compose exec -T api vendor/bin/phpunit --testsuite=unit
OK (96 tests, 205 assertions)

$ docker compose exec -T api vendor/bin/phpunit --testsuite=integration
OK, but there were issues!   # pre-existing Doctrine uniqueConstraints deprecation in CollectionTranslation — out of scope
Tests: 15, Assertions: 38, Deprecations: 1

$ docker compose exec -T api php bin/console debug:container --tag=app.step_handler
6 handlers listed: AddBackground, Crop, FormatConvert, RemoveBackground, Resize, Rotate

$ docker compose exec -T api php bin/console debug:container --parameter=transformations.embedder_timeout_remove_background_ms
%env(int:default:embedder_default_rmbg_ms:EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS)%
```

All acceptance grep criteria verified passing: `final readonly class RemoveBackgroundStepParams`, `Assert\Choice(choices: ['birefnet', 'isnet-general-use'])`, `REMOVE_BACKGROUND => RemoveBackgroundStepParams::class`, `final class RemoveBackgroundHandler extends AbstractEmbedderStepHandler`, `return StepType::REMOVE_BACKGROUND`, `return '/img/remove-background'`, `embedder_default_rmbg_ms: '6000'`, `EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS`. `isAsyncStep` no longer references `REMOVE_BACKGROUND`; `ai_prompt` branch preserved.

## Deviations from Plan

None functional. Two minor adjustments documented in `decisions`:

1. `PipelineRunnerTest::testUnsupportedStepTypeRaises` was **kept** (Option A from plan, not Option B). The test still exercises the `default => throw` branch of `PipelineRunner` even though all StepTypes now have real handlers in DI — the map is intentionally mis-wired in the test to simulate a DI misconfiguration. Comment rewritten accordingly.
2. No `remove-background-requires-png` warning added to `TransformationHashListener::computeWarnings` (mentioned as "discussion ouverte" in 04-CONTEXT). Out of scope for this plan; can be added in Plan 04-05 deploy-gate prep or Phase 7 (warnings UX).

## Authentication Gates

None.

## Known Stubs

None. All three sets of stubs from Plan 04-01 (`RemoveBackgroundStepParamsTest`, `RemoveBackgroundHandlerTest`, `StepParamsFactoryTest::testRemoveBackgroundThrowsUnsupportedInPhase3`) are now real green tests or have been removed in favour of the real coverage path.

## Commits

| Hash    | Type | Message                                                                |
|---------|------|------------------------------------------------------------------------|
| 2718421 | feat | wire RemoveBackgroundStepParams DTO + StepParamsFactory routing        |
| 856c71a | feat | wire RemoveBackgroundHandler against embedder POST /img/remove-background |
| 0dd4c3d | feat | invert isAsyncStep gate so REMOVE_BACKGROUND is served sync             |

## Self-Check: PASSED

- FOUND: api/src/Service/AssetTransformation/StepParams/RemoveBackgroundStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepHandler/RemoveBackgroundHandler.php
- FOUND: api/config/services.yaml (embedder_default_rmbg_ms present)
- FOUND: api/src/Service/AssetTransformation/TransformationLookup.php (REMOVE_BACKGROUND no longer in isAsyncStep)
- FOUND: commit 2718421
- FOUND: commit 856c71a
- FOUND: commit 0dd4c3d
- VERIFIED: 96 unit tests pass + 15 integration tests pass + 6 step handlers in DI tag
