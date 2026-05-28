---
phase: 05-editor-pwa-warmup-gc-observability
plan: 04
subsystem: ui
tags: [vue, vuetify, vuedraggable, composables, schema-driven, editor]

requires:
  - phase: 01-domain-versioning-foundation
    provides: AssetTransformation + TransformationStep entities + schema PWA
provides:
  - "Éditeur drag-and-drop des steps via vuedraggable@4"
  - "6 sous-formulaires dynamiques {Type}StepFields.vue (registry par step.type)"
  - "WarningBanner.vue : miroir client de TransformationHashListener (EDITOR-08)"
  - "useTransformedUrl(code, assetId, ext) : composable pure string (sync, no fetch)"
  - "useTransformationWarnings(steps) : dédup merge serveur + client"
affects: [preview-panel, asset-transformation-list]

tech-stack:
  added: [vuedraggable@^4.1.0]
  patterns:
    - "Registry-driven sub-form rendering (step.type → component lookup)"
    - "Composable pure string builder (no Vue reactivity imports)"
    - "AssetTransformation.json schema-driven CRUD"

key-files:
  created:
    - pwa/src/composables/useTransformedUrl.ts
    - pwa/src/composables/useTransformationWarnings.ts
    - pwa/src/components/asset_transformation/edit/StepsField.vue
    - pwa/src/components/asset_transformation/edit/WarningBanner.vue
    - pwa/src/components/asset_transformation/edit/steps/ResizeStepFields.vue
    - pwa/src/components/asset_transformation/edit/steps/CropStepFields.vue
    - pwa/src/components/asset_transformation/edit/steps/RotateStepFields.vue
    - pwa/src/components/asset_transformation/edit/steps/FormatConvertStepFields.vue
    - pwa/src/components/asset_transformation/edit/steps/AddBackgroundStepFields.vue
    - pwa/src/components/asset_transformation/edit/steps/RemoveBackgroundStepFields.vue
    - pwa/src/config/AssetTransformation.json
  modified:
    - pwa/package.json
    - pwa/src/pages/edit/[resource]/[id].vue

key-decisions:
  - "useTransformedUrl : zéro import Vue reactivity (verified par grep), respecte D-12/D-13"
  - "Merge warnings serveur + client dédupliqué par (code, stepIndex), serveur canonique"
  - "AddBackground.assetId : NumberField (RelationField nécessite items pré-chargés indisponibles dans sub-form)"
  - "Glob componentModules étendu dans pages/edit/[resource]/[id].vue pour résoudre asset_transformation/edit/"
  - "resolvedExt dérivé : outputExt > formData.outputExt|ext > dernier format_convert.format > 'png'"

patterns-established:
  - "Pattern sub-form registry : map step.type → import.meta.glob component"
  - "Pattern composable URL builder pure : éviter reactivity → permet usage dans <img :src>"

requirements-completed: [EDITOR-01, EDITOR-02, EDITOR-03, EDITOR-07, EDITOR-08]

duration: ~8min
completed: 2026-05-28
---

# Plan 05-04 — PWA Editor (StepsField + WarningBanner + composables)

Livré la moitié éditeur de la PWA Phase 5 : drag-and-drop, sous-formulaires dynamiques par type, warning EDITOR-08, et composable URL builder.

## Déviations notables

1. **[Rule 3 - Blocking]** Glob `componentModules` ne couvrait pas `asset_transformation/edit/` → ajout d'un glob complémentaire dans `pages/edit/[resource]/[id].vue`.
2. **[Rule 1 - Pragmatic]** `AddBackground.assetId` → `NumberField` au lieu de `RelationField` (validation server-side conservée).
3. **i18n FR/EN** : consolidé dans Plan 05-05 (évite conflits inter-waves). `WarningBanner` utilise `te()` + fallback en attendant.
4. **SUMMARY.md** : créé manuellement par l'orchestrateur post-merge — l'executor a été refusé par la sandbox sur l'écriture finale.

## Verify automatisé exécuté

- `grep -E "^import.*(ref|watch|computed).*'vue'" pwa/src/composables/useTransformedUrl.ts` → vide ✓
- `grep -q "vuedraggable" pwa/package.json` → présent ✓
- `find pwa/src/components/asset_transformation/edit/steps -name "*.vue" | wc -l` → 6 ✓
- Aucun fichier sans `<script setup lang="ts">` ✓

## Non exécuté (post-merge sur main)

- `docker compose exec pwa npm run build`
- `docker compose exec pwa npx vue-tsc --noEmit`

## Out-of-scope (deferred)

- I18n complète 14 locales → Plan 05-05
- Asset picker enrichi (search + thumb) pour AddBackground → out-of-scope EDITOR