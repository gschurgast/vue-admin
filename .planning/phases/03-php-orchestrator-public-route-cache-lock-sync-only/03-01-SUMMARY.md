---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
plan: 01
subsystem: api
tags: [validators, doctrine-listener, migration, ssrf-guard, route-gating]
dependency_graph:
  requires:
    - Phase 1 TransformationStep / AssetTransformation entities
    - Phase 1 StepType enum
    - Phase 1 TransformationHashListener
    - Phase 2 embedder DTO contracts (resize/crop/rotate/format_convert/add_background)
  provides:
    - StepParamsFactory::fromStep  (consumed by PipelineRunner, Plan 02)
    - Asset::isPublic()             (consumed by public route, Plan 02)
    - AssetTransformation::getWarnings() (consumed by editor PWA Phase 7 + runtime header Plan 02)
    - TransformationStepValidationListener (auto-active on every flush)
  affects:
    - api/src/Entity/AssetTransformation/AssetTransformation.php (warnings column)
    - api/src/Entity/Asset/Asset.php (isPublic column)
    - DB schema (asset_transformation.warnings, asset.is_public)
tech-stack:
  added: [Symfony Serializer denormalize strict-fields, Symfony Validator Callback]
  patterns: [readonly DTO with Assert, Doctrine LifecycleListener via AsDoctrineListener]
key-files:
  created:
    - api/src/Service/AssetTransformation/StepParams/ResizeStepParams.php
    - api/src/Service/AssetTransformation/StepParams/CropStepParams.php
    - api/src/Service/AssetTransformation/StepParams/RotateStepParams.php
    - api/src/Service/AssetTransformation/StepParams/FormatConvertStepParams.php
    - api/src/Service/AssetTransformation/StepParams/AddBackgroundStepParams.php
    - api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php
    - api/src/Service/AssetTransformation/StepParams/UnsupportedStepTypeException.php
    - api/src/EventListener/TransformationStepValidationListener.php
    - api/migrations/Version20260527000001.php
    - api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php
    - api/tests/Integration/AssetTransformation/WarningsDerivationTest.php
  modified:
    - api/src/Entity/Asset/Asset.php
    - api/src/Entity/AssetTransformation/AssetTransformation.php
    - api/src/EventListener/TransformationHashListener.php
    - api/tests/Integration/EventListener/TransformationHashListenerTest.php
decisions:
  - D-14/D-15 readonly DTOs + Factory implemented with strict-fields denormalize (SSRF-safe)
  - D-16 validation hook implemented as Doctrine prePersist/preUpdate listener
  - D-18 warnings persisted on AssetTransformation, recomputed by TransformationHashListener
  - ROUTE-08 prerequisite Asset.is_public matérialisée (default false)
metrics:
  duration: "~6 minutes"
  completed: "2026-05-27"
  tasks: 3
  files_created: 11
  files_modified: 4
  tests_added: 19
requirements: [HANDLERS-03, HANDLERS-05, ROUTE-08]
---

# Phase 3 Plan 01: Validators DTO + colonnes warnings & is_public Summary

DTO Validators readonly par step type avec hook Doctrine prePersist/preUpdate,
colonne `warnings` recalculée par le `TransformationHashListener` existant,
et colonne `Asset.is_public` (default false) — prérequis matériel de la
route publique `/t/*` du Plan 02.

## What Was Built

### 5 readonly StepParams DTOs

`api/src/Service/AssetTransformation/StepParams/` — un DTO par
`StepType` actuellement supporté (REMOVE_BACKGROUND reste Phase 4) :

| DTO | Contrat (Phase 2) | Particularités |
|-----|-------------------|----------------|
| `ResizeStepParams` | `{ width?, height?, mode, upscale? }` | Callback : au moins une dimension requise |
| `CropStepParams` | `{x,y,width,height}` OU `{aspectRatio, anchor?}` | Callback : formes mutuellement exclusives |
| `RotateStepParams` | `{ angle: -360..360, background? }` | Regex `#rrggbb` |
| `FormatConvertStepParams` | `{ format ∈ png/jpg/jpeg/webp/avif, quality? }` | Choice + Range 1..100 |
| `AddBackgroundStepParams` | `{type: 'color', color}` OU `{type: 'asset', assetId}` | Callback symétrique ; `ai_prompt` REJETÉ en Phase 3 |

### StepParamsFactory

Hydrate le DTO via le Symfony Serializer en mode `ALLOW_EXTRA_ATTRIBUTES=false`,
puis valide via `ValidatorInterface`. Une clé inconnue (ex: `url` sur
`add_background` → vecteur SSRF potentiel) lève une
`ExtraAttributesException`. Une violation Assert lève
`ValidationFailedException` (convertie en 422 par API Platform).

REMOVE_BACKGROUND → `UnsupportedStepTypeException` explicite (Phase 4).

### Doctrine listener de validation

`TransformationStepValidationListener` — `#[AsDoctrineListener]` sur
`prePersist` + `preUpdate`. Couvre **toutes** les écritures (API Platform,
fixtures, console, scripts) sans dépendre du validator HTTP.

### Migration `Version20260527000001`

Atomique pour le déploiement Plan 03 :

```sql
ALTER TABLE asset_transformation ADD COLUMN warnings JSONB NOT NULL DEFAULT '[]';
ALTER TABLE asset ADD COLUMN is_public BOOLEAN NOT NULL DEFAULT false;
```

Appliquée sur le schéma dev. Vérifiée via `information_schema`.

### TransformationHashListener étendu

`computeWarnings()` ajouté et appelé dans la même boucle `onFlush` que
le recalcul de `versionHash`. Heuristique HANDLERS-05 :
`alpha-flatten-on-jpeg` est dérivé quand la chaîne se termine en
JPEG sans `add_background` (le `/img/format-convert` Python flatten sur
blanc dans ce cas, perdant la transparence).

Sortie : `[{"code": "alpha-flatten-on-jpeg", "stepIndex": null}, …]`.

### Asset.isPublic

Propriété `bool $isPublic = false` + getter `isPublic()` + setter
`setIsPublic(bool)` exposés via `asset:read` et `asset:write`. Prérequis
**dur** du Plan 02 : la route publique `/t/*` checkera ce flag et 404
sur `false`, comportement le plus sûr par défaut.

## Tasks Completed

| Task | Description | Commit |
|------|-------------|--------|
| 1 | Asset.isPublic + 5 DTOs + Factory + 14 tests | `2c01ae0` |
| 2 | Migration + entité warnings + listeners | `f920f4d` |
| 3 | Apply migration + 5 integration tests | `0029b9b` |

## Verification

```
docker compose exec api ./vendor/bin/phpunit tests/
→ Tests: 63, Assertions: 94 (OK)

docker compose exec api php bin/console doctrine:migrations:migrate -n
→ Successfully migrated to Version20260527000001

psql information_schema:
  asset.is_public           BOOLEAN NOT NULL DEFAULT false
  asset_transformation.warnings JSONB NOT NULL DEFAULT '[]'::jsonb
```

19 nouveaux tests verts :
- 14 unit (`StepParamsFactoryTest`)
- 5 integration (`WarningsDerivationTest`)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Test phase-1 incompatible avec nouveau listener**
- **Found during:** Task 2 (`docker compose exec api ./vendor/bin/phpunit tests/Integration/EventListener/`)
- **Issue:** `testDeletingTransformationDispatchesPurge` créait un step
  `RESIZE` avec `params: []` — désormais rejeté par le nouveau listener de
  validation (comportement attendu par le Plan 03 lui-même).
- **Fix:** Mis à jour le test pour utiliser `['width' => 800]` (params valides).
- **Files modified:** `api/tests/Integration/EventListener/TransformationHashListenerTest.php`
- **Commit:** `f920f4d`

**2. [Rule 1 — Bug] Service factory inliné en test container**
- **Found during:** Task 1 (premier run phpunit)
- **Issue:** `self::getContainer()->get(StepParamsFactory::class)` lève
  "service inlined" car la factory n'est référencée nulle part ailleurs
  encore (Plan 02 la consommera). Le test container Symfony expose les
  services privés mais ne ressuscite pas les services inlinés.
- **Fix:** Instanciation directe dans `setUp()` à partir des deux
  dépendances publiques `SerializerInterface` + `ValidatorInterface`.
- **Files modified:** `api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php`
- **Commit:** `2c01ae0`

## Known Stubs

Aucun.

## Out-of-scope Items (deferred-items)

- Le diff `doctrine:schema:update --dump-sql` montre quelques drift
  d'index pgvector pré-existants (asset_embedding_hnsw, etc.) et une
  divergence JSON vs JSONB sur `warnings` (Doctrine ORM ne distingue
  pas natively). Ces points sont **pré-existants** ou inhérents à la
  carto Doctrine → JSONB Postgres ; hors scope Plan 01.

## Self-Check: PASSED

Files verified:
- FOUND: api/src/Service/AssetTransformation/StepParams/ResizeStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepParams/CropStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepParams/RotateStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepParams/FormatConvertStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepParams/AddBackgroundStepParams.php
- FOUND: api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php
- FOUND: api/src/Service/AssetTransformation/StepParams/UnsupportedStepTypeException.php
- FOUND: api/src/EventListener/TransformationStepValidationListener.php
- FOUND: api/migrations/Version20260527000001.php
- FOUND: api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php
- FOUND: api/tests/Integration/AssetTransformation/WarningsDerivationTest.php

Commits verified:
- FOUND: 2c01ae0 (Task 1)
- FOUND: f920f4d (Task 2)
- FOUND: 0029b9b (Task 3)
