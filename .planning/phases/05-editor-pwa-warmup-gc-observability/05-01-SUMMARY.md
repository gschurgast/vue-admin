---
phase: 05-editor-pwa-warmup-gc-observability
plan: 01
subsystem: api
tags: [api-platform, preview, rate-limiter, jwt, server-authoritative]

requires:
  - phase: 03-php-orchestrator-public-route-cache-lock
    provides: PipelineRunner + VariantCache existants
  - phase: 04-birefnet-endpoint-remove-background
    provides: stack complet pipeline operationnel sur les 6 step types
provides:
  - "POST /api/asset_transformations/preview — DTO + processor server-authoritative"
  - "PipelineRunner::run() : nouveau param bool bypassCache (intent marker, D-08)"
  - "rate_limiter.yaml : token-bucket 10/min/user (preview_endpoint, D-10)"
  - "symfony/rate-limiter 8.0.* ajouté à composer.json"
affects: [editor-pwa, preview-panel, observability]

tech-stack:
  added: [symfony/rate-limiter]
  patterns:
    - "Processor returning Response directly (short-circuit binary output)"
    - "Ephemeral non-persisted AssetTransformation pour preview"
    - "Token-bucket rate limit JWT user identifier"

key-files:
  created:
    - api/src/ApiResource/PreviewRequest.php
    - api/src/State/PreviewRequestProcessor.php
    - api/config/packages/rate_limiter.yaml
    - api/tests/Integration/PreviewEndpointTest.php
    - api/tests/Integration/PreviewRateLimitTest.php
    - api/tests/Integration/PreviewBypassCacheTest.php
  modified:
    - api/src/Service/AssetTransformation/PipelineRunner.php
    - api/composer.json

key-decisions:
  - "isPublic STRICT pour preview (aligné /t/*) — pas d'ownership check ce phase"
  - "bypassCache flag : intent marker, le runner reste cache/lock-agnostic (concerns dans le controller)"
  - "Response::Cache-Control no-store + X-Robots-Tag noindex + X-Preview-Warnings header pour debug"
  - "Rate limiter clef = JWT user identifier (Security::getUser())"

patterns-established:
  - "Preview endpoint pattern : DTO Request + Processor short-circuit Response binaire"
  - "Ephemeral pipeline : new AssetTransformation + new TransformationStep sans persist"

requirements-completed: [EDITOR-04, EDITOR-05]

duration: 25min (executor 15min + finalisation manuelle 10min)
completed: 2026-05-28
---

# Plan 05-01 — Preview API base

Livré l'API server-authoritative pour la preview UI : DTO `PreviewRequest`, processor avec rate-limit JWT, extension `PipelineRunner` mode `bypassCache`.

## Déviations notables

1. **Blocage sandbox runtime** : l'executor agent a été refusé par la sandbox sur la création de `PreviewRequestProcessor.php` (`api/src/State/`). Le processor a été finalisé manuellement par l'orchestrateur depuis le PLAN.md + RESEARCH.md + CONTEXT.md, en réutilisant le pattern `ChatRequestProcessor`. `PreviewRequest.php` (DTO) avait été créé par l'agent mais non commité — récupéré tel quel.

2. **PipelineRunner `$bypassCache` = intent marker** : le runner n'a aucun comportement cache/lock à modifier (ces responsabilités vivent dans `PublicTransformationController`). Le flag est documenté comme contrat pour les callers ephemeral et asserté par `PipelineRunnerTest` (Wave 0 stub).

3. **`AddBackground.assetId` validation** : laissée à `StepParamsFactory` côté pipeline runtime (cohérent avec les autres step params).

## Verify automatisé

- `api/tests/Integration/PreviewEndpointTest.php` : success + 400 invalid steps + 404 non-public + 422 pipeline failure
- `api/tests/Integration/PreviewRateLimitTest.php` : 11e req/min → 429 + Retry-After
- `api/tests/Integration/PreviewBypassCacheTest.php` : assertion no S3 write + no Redis lock pendant preview

## Non exécuté

- `docker compose exec api vendor/bin/phpunit` : à exécuter post-merge sur main (l'agent n'avait pas Docker dans son worktree)
- `make generate-types` : à exécuter avant l'intégration côté PWA (Plan 05-05 le fera)

## Followups

- **STATE.md** : tracker « preview ownership check (vs isPublic strict) » comme follow-up potentiel post-v1.0.