---
phase: 01-domain-versioning-foundation
plan: 01
date: 2026-05-27
status: complete
requirements: [TRANSFORM-01, TRANSFORM-02, TRANSFORM-03]
---

# Plan 01-01 — Entities + ApiResource — SUMMARY

## Files created

- `api/src/Enum/StepType.php` — backed enum string (6 cases: resize, crop, rotate, format_convert, add_background, remove_background) + `label()` / `allCodes()` (static) / `toArray()`.
- `api/src/Entity/AssetTransformation/AssetTransformation.php` — entité principale, ApiResource CRUD (ROLE_ADMIN sur Post/Patch/Delete), UniqueEntity(code), SearchFilter(code,label), MenuGroup('Settings'), OneToMany→TransformationStep avec cascade persist/remove + orphanRemoval + OrderBy(position).
- `api/src/Entity/AssetTransformation/TransformationStep.php` — entité step, `ApiResource(operations: [])` (Pitfall F), MenuGroup('hidden'), ManyToOne→AssetTransformation onDelete CASCADE, params JSON.
- `api/migrations/Version20260527_AssetTransformation.php` — migration renommée pour traçabilité ; **nettoyée** des modifs parasites (drift schéma vs DB existante détecté sur `asset`, `attribute_definition`, `attribute_option` — ignoré, hors scope Phase 1).

## Decisions taken

- `versionHash` exposé en `read` uniquement (jamais writable — Pitfall D RESEARCH). Setter annoté `@internal Set automatically by TransformationHashListener`.
- `MaxDepth(1)` posé sur la collection `steps` côté parent ET sur la ManyToOne côté enfant (sans groups `:read` sur l'enfant→parent pour éviter la récursion JSON-LD).
- `params: array = []` avec `Assert\Type('array')` uniquement — validation profonde reportée à HANDLERS-03 (Phase 3).
- Migration **NON-régénérée intégralement** : seuls les SQL relatifs aux nouvelles tables ont été conservés. La diff de schéma révèle un drift préexistant sur `vector(512)` + index pgvector + indices `attribute_*` — à investiguer par Webfacto hors scope Phase 1.

## Endpoint sanity check

| Endpoint | Statut | Notes |
|----------|--------|-------|
| `GET /api/asset_transformations` | 200 | API Platform 4 → `totalItems`/`member` (pas `hydra:`) |
| `POST /api/asset_transformations` (avec step) | 201 | Cascade persist OK ; step créé via collection ; `versionHash: null` (attendu, viendra Plan 04) |
| `DELETE /api/asset_transformations/{id}` | 204 | OK |
| `GET /api/transformation_steps` | 404 | `operations: []` empêche l'exposition — Pitfall F respecté |

## Types TS

`pwa/src/types/api.d.ts` régénéré ; 34 occurrences `AssetTransformation`/`TransformationStep` trouvées.

## Open items handed to next plans

- **Plan 01-02** : implémenter `TransformationHasher::compute(Collection $steps): string` (sha1 canonical).
- **Plan 01-03** : ajouter `#[AppAssert\TransformationCode]` sur `AssetTransformation::code`. **NE PAS modifier** `AppAssert\Code` existant (Pitfall E).
- **Plan 01-04** : listener `TransformationHashListener` (onFlush capture + postFlush dispatch + recompute via hasher) + transport Messenger `transformations_backfill`.
- **Plan 01-05** : helper `TransformationStorageKey::forVariant()` + config PWA `AssetTransformation.json`.

## Webfacto reminder

Drift schéma détecté sur tables existantes (`asset.embedding`, indexes `attribute_*`). Hors scope Phase 1 — à investiguer avant déploiement partagé.
