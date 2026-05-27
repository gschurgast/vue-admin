---
phase: 01-domain-versioning-foundation
plan: 02
date: 2026-05-27
status: complete
requirements: [TRANSFORM-03, TRANSFORM-04]
---

# Plan 01-02 — TransformationHasher — SUMMARY

## Wave 0 — install

- `symfony/test-pack` ajouté en `require-dev`.
- **PHPUnit 13.1.12** installé (Symfony recipe a généré `api/phpunit.dist.xml` au lieu du legacy `phpunit.xml.dist`).
- `phpunit.dist.xml` édité : remplacement du testsuite unique "Project Test Suite" par les deux suites `unit` (tests/Unit) et `integration` (tests/Integration).
- `tests/bootstrap.php` généré par la recipe (laissé tel quel).

## Golden hash figé

```
0b341db4763bc1a68b6c6cfce6cce866594de409
```

Payload de référence :
```
[
  {type: resize,         params: {height: 600, mode: fit, width: 800}, position: 0},
  {type: format_convert, params: {format: webp, quality: 85},          position: 1},
]
```

Documenté dans `TransformationHasherTest::testGoldenHashFixture` + commentaire "NEVER change without coordinated cache invalidation".

## Tests (7/7 ✓)

| # | Test | Assertion |
|---|------|-----------|
| 1 | testReturns40CharSha1 | 40 chars hex |
| 2 | testDeterministicAcrossRuns | hash stable sur 2 appels |
| 3 | testParamKeyOrderIndependent | clés params réordonnées → même hash |
| 4 | testNullParamsAreDropped | `null` → droppé (équiv. absent) |
| 5 | testStepPositionOrderMatters | swap position 0↔1 → hashes différents |
| 6 | testEmptyStepsListIsStable | `sha1('[]')` |
| 7 | testGoldenHashFixture | golden figé |

## Canonicalisation v1

- `usort` sur position ASC (re-tri défensif).
- Pour chaque step : `{ type: enum.value, params: canonicalize(params) }`.
- `canonicalizeParams` : récursif, drop des `null`, `ksort` par clé.
- `json_encode` avec `JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR`.
- `sha1` → 40 chars hex.

## Notes pour Plan 04

- `TransformationHasher` est pur (constructor vide). Le listener `TransformationHashListener` l'injectera via constructor injection standard Symfony.
- Pas d'autowiring spécial nécessaire — Symfony le détecte automatiquement via `services.yaml` (default autowire + autoconfigure).

## Webfacto reminder

Tout changement de canonicalisation (v2:) invalide les caches S3 existants. À cadrer avant tout deploy prod modifiant le hasher.
