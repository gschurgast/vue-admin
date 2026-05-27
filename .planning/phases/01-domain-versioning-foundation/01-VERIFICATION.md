---
phase: 01-domain-versioning-foundation
verified: 2026-05-27T09:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
---

# Phase 1 : Domain & Versioning Foundation — Rapport de vérification

**Goal de phase :** Établir le modèle de données des transformations (entités, steps, versioning sha1 canonical, helper de clé S3, listener de purge) sans dépendance Python.
**Vérifié :** 2026-05-27
**Status :** PASS
**Re-vérification :** Non — vérification initiale

---

## Critères de succès

### SC-1 : CRUD AssetTransformation via API Platform (TRANSFORM-01)

**PASS**

- `AssetTransformation.php` : `#[ApiResource(operations: [GetCollection, Get, Post, Patch, Delete])]` avec sécurité `is_granted('ROLE_ADMIN')` sur les opérations d'écriture (lignes 28-37).
- `#[UniqueEntity(fields: ['code'])]` garantit l'unicité (ligne 25).
- Types TypeScript régénérés : `/api/asset_transformations` (GET/POST/PATCH/DELETE) présents dans `pwa/src/types/api.d.ts` (lignes 147-196).
- `pwa/src/config/AssetTransformation.json` expose `code`, `label`, `versionHash`, `steps` dans les vues list/edit/show.

### SC-2 : Steps composables + versionHash recalculé automatiquement (TRANSFORM-02, TRANSFORM-03, TRANSFORM-04)

**PASS**

- `TransformationStep` est géré exclusivement via la relation parente (cascade persist/remove, orphanRemoval, `operations: []`).
- `TransformationHashListener.onFlush` couvre : insertions de steps (ligne 37-48), modifications directes de la transformation (lignes 51-58), mutations de collection via `getScheduledCollectionUpdates/Deletions` (lignes 62-74).
- `recomputeSingleEntityChangeSet` est appelé pour forcer le flush de `versionHash` modifié (ligne 84).
- Test d'intégration `TransformationHashListenerTest` : 5 cas couvrant création, ajout/suppression de step, suppression de transformation, mise à jour no-op.

### SC-3 : Codes réservés rejetés avec 422 (TRANSFORM-06)

**PASS**

- `TransformationCode.php` : `$reservedCodes = ['api', 'admin', 't', '_', 'assets']` (ligne 14).
- Contrainte regex `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` rejette les mono-caractères (`strlen === 1`, ligne 37-39).
- `TransformationCodeValidator` : la blocklist prend priorité sur la validation regex (blocklist vérifiée en premier, ligne 25-29).
- 5 reserved codes + 8 invalid patterns + 5 valid patterns couverts dans `TransformationCodeValidatorTest`.
- `#[AppAssert\TransformationCode]` appliqué sur `AssetTransformation.code` (ligne 49 de l'entité) — contrainte dédiée, sans contaminer `AppAssert\Code` existant (Pitfall E respecté).

### SC-4 : Suppression d'une transformation déclenche un job async de purge (TRANSFORM-05)

**PASS**

- `TransformationHashListener.onFlush` : boucle sur `getScheduledEntityDeletions()`, capture `id` + `versionHash` **avant** flush (lignes 95-105 — Pitfall C respecté).
- `postFlush` dispatche les `PurgeTransformationVariantsMessage` accumulés après le flush (lignes 108-113 — Pitfall B respecté).
- `PurgeTransformationVariantsMessage` est routé sur le transport `transformations_backfill` (Redis Streams) dans `messenger.yaml` (ligne 38).
- Le handler est un no-op en Phase 1 avec log explicite — intentionnel, purge Flysystem réelle en Phase 3+.
- Test `testDeletingTransformationDispatchesPurge` vérifie qu'un message est bien dispatché avec le bon `transformationId` et `versionHash`.

**Note :** Le handler Phase 1 est volontairement un no-op (`PurgeTransformationVariantsHandler.php` ligne 16). Le SC-4 est satisfait car le **dispatch async** est en place ; la purge S3 effective est en Phase 3 (déférée).

### SC-5 : Hash canonical déterministe — golden-file tests (TRANSFORM-04)

**PASS**

- `TransformationHasher.canonicalizeParams` : drop des nulls + `ksort` récursif (lignes 50-58).
- `usort` par `position ASC` avant calcul (lignes 24-28).
- `json_encode` avec `JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR` (ligne 38-41).
- Test golden-file `testGoldenHashFixture` : hash `0b341db4763bc1a68b6c6cfce6cce866594de409` figé au 2026-05-27 (ligne 91 de `TransformationHasherTest`).
- Tests complémentaires : `testParamKeyOrderIndependent`, `testNullParamsAreDropped`, `testStepPositionOrderMatters`, `testEmptyStepsListIsStable`.

---

## Quality gates

### Tests

- **22 méthodes de test** réparties sur 4 fichiers :
  - `TransformationHasherTest` : 7 cas (hash 40-char, déterminisme, ordre clés, nulls droppés, ordre positions, empty, golden)
  - `TransformationStorageKeyTest` : 5 cas (clé standard, extension avec point, sharding, hash8, prefix)
  - `TransformationCodeValidatorTest` : 5 + 2 cas (valid/invalid/reserved + null/empty)
  - `TransformationHashListenerTest` : 5 cas d'intégration Doctrine (create, add step, remove step, delete, no-op)

### Pitfalls du RESEARCH

| Pitfall | Mitigation | Localisation |
|---------|-----------|--------------|
| A — Hash non-recalculé sur step insert/remove | `getScheduledEntityInsertions/Deletions` + `getScheduledCollectionUpdates/Deletions` | `TransformationHashListener.php` lignes 37-74 |
| B — Dispatch Messenger dans onFlush (flush récursive) | Accumulation dans `$pendingPurges`, dispatch dans `postFlush` | lignes 19, 108-113 |
| C — Hash capturé après flush sur suppression | Capture id+hash dans `onFlush` avant que la row parte | lignes 95-105 |
| G — Double déclenchement listener | Map `$dirty[$key]` déduplique par `id` ou `spl_object_id` | lignes 33-73 |

### Guardrails de sécurité

| Contrôle | Résultat |
|----------|---------|
| `versionHash` absent du groupe `:write` | PASS — `#[Groups(['asset_transformation:read'])]` uniquement (ligne 59 entité) |
| `versionHash` absent du write schema TS | PASS — `AssetTransformation-asset_transformation.write` : `code`, `label`, `steps` seulement (TS types ligne 1237-1241) |
| `TransformationStep` sans endpoints directs | PASS — `#[ApiResource(operations: [])]` (ligne 16 de `TransformationStep.php`) |
| Steps accessibles uniquement via parent | PASS — cascade persist/remove + orphanRemoval sur la relation |

### Migration

- `Version20260527_AssetTransformation.php` :
  - Crée `asset_transformation` avec `UNIQUE INDEX` sur `code`
  - Crée `transformation_step` avec `FK ... ON DELETE CASCADE` vers `asset_transformation`
  - Rollback `down()` présent et cohérent

### Artefacts PWA

- `pwa/src/config/AssetTransformation.json` : vues list/edit/show/filters configurées
- `pwa/src/types/api.d.ts` : types TS régénérés, endpoints `/api/asset_transformations` présents

---

## Vérification humaine requise

Aucune. Tous les critères sont vérifiables programmatiquement pour cette phase.

---

## Verdict

## VERDICT : PASS

Les 5 critères de succès sont atteints. Le modèle de données est en place, le hash canonical est déterministe avec golden-file test, les codes réservés sont rejetés, la purge async est dispatchée à la suppression. 22 tests couvrent les comportements critiques. Les 4 pitfalls du RESEARCH sont correctement adressés dans le code.

---

_Vérifié : 2026-05-27_
_Verifier : Claude (gsd-verifier)_
