---
phase: 01-domain-versioning-foundation
plan: 05
date: 2026-05-27
status: complete
requirements: [TRANSFORM-01, TRANSFORM-05]
---

# Plan 01-05 — StorageKey + PWA config — SUMMARY

## API : `TransformationStorageKey`

Signature :
```php
TransformationStorageKey::forVariant(
    transformationId: int,
    versionHash: string,
    assetId: int,
    ext: string,
): string
```

Exemple :
```
forVariant(42, 'abc12345...', 1234, 'webp') → 'transformations/42-vabc12345/1/1234.webp'
forVariant(1,  'hash...',     500,  '.jpg') → 'transformations/1-vhash.../0/500.jpg'   (leading dot stripped)
forVariant(7,  'fff...',      15234,'png')  → 'transformations/7-vfff.../15/15234.png' (shard=15)
```

Bonus : `prefixForVariants(transformationId, versionHash)` → `transformations/{id}-v{hash8}` (utile pour purge Phase 3+).

**Cohérence hash8** : 8 premiers chars du versionHash 40-chars — vérifié dans `testHash8IsAlwaysFirst8Chars`. Le `PurgeTransformationVariantsHandler` (Plan 04) loggera ce même préfixe.

## Tests (12/12 ✓)

- `testStandardVariantKey`
- `testExtensionWithLeadingDotIsStripped`
- 8 cas de shard (0, 1, 999, 1000, 1234, 1999, 15234, 1_000_000) via DataProvider
- `testHash8IsAlwaysFirst8Chars`
- `testPrefixForVariants`

## PWA : `pwa/src/config/AssetTransformation.json`

- `filters` : code, label
- `list` : code, label, versionHash (lecture seule en colonne)
- `edit` : code, label, steps (versionHash **absent** — cohérent avec `:write` group qui ne le contient pas — Plan 01)
- `show` : tout

Pas de composant custom — le schema-driven CRUD par défaut suffit en Phase 1. L'éditeur drag-and-drop optimisé est planifié en Phase 7 (EDITOR-02/03).

Types TS régénérés via `make generate-types` (29 occurrences `AssetTransformation` dans `pwa/src/types/api.d.ts`).

## État final Phase 1

| REQ-ID | Status | Plans |
|--------|--------|-------|
| TRANSFORM-01 (entity AssetTransformation + code unique kebab) | ✓ | 01-01, 01-03, 01-05 |
| TRANSFORM-02 (entity Step ordonné typé) | ✓ | 01-01 |
| TRANSFORM-03 (modification post-création) | ✓ | 01-01 (cascade), 01-02 (hash), 01-04 (recompute) |
| TRANSFORM-04 (versionHash auto sha1 canonical) | ✓ | 01-02, 01-04 |
| TRANSFORM-05 (helper S3 key + purge async) | ✓ | 01-04, 01-05 |
| TRANSFORM-06 (codes réservés 422) | ✓ | 01-03 |

## Handoff Phase 2 (Python endpoints)

Aucune dépendance directe — Phase 2 peut démarrer en parallèle après merge Phase 1.

## Handoff Phase 3 (route publique + cache)

- Utilisera `TransformationStorageKey::forVariant()` pour la clé S3.
- Complétera `PurgeTransformationVariantsHandler` (handler no-op livré en Plan 04) avec la vraie suppression Flysystem du préfixe `prefixForVariants()`.

## Webfacto reminder

Convention de clé S3 `transformations/{id}-v{hash8}/{shard}/{assetId}.{ext}` figée. À cadrer avant ouverture publique (Phase 3) : rétention, lifecycle rules S3, CDN.
