---
phase: 05
plan: 04
type: execute
wave: 1
depends_on: []
files_modified:
  - pwa/package.json
  - pwa/src/components/asset_transformation/edit/StepsField.vue
  - pwa/src/components/asset_transformation/edit/steps/ResizeStepFields.vue
  - pwa/src/components/asset_transformation/edit/steps/CropStepFields.vue
  - pwa/src/components/asset_transformation/edit/steps/RotateStepFields.vue
  - pwa/src/components/asset_transformation/edit/steps/FormatConvertStepFields.vue
  - pwa/src/components/asset_transformation/edit/steps/AddBackgroundStepFields.vue
  - pwa/src/components/asset_transformation/edit/steps/RemoveBackgroundStepFields.vue
  - pwa/src/components/asset_transformation/edit/WarningBanner.vue
  - pwa/src/composables/useTransformedUrl.ts
  - pwa/src/composables/useTransformationWarnings.ts
  - pwa/src/config/AssetTransformation.json
autonomous: true
requirements: [EDITOR-01, EDITOR-02, EDITOR-03, EDITOR-07, EDITOR-08]
must_haves:
  truths:
    - "L'éditeur AssetTransformation affiche une liste de steps réordonnables en drag-and-drop (vuedraggable@4)"
    - "Chaque step affiche un sous-formulaire dédié par type (6 composants : resize, crop, rotate, format_convert, add_background, remove_background)"
    - "Une banner orange (`WarningBanner.vue`) apparaît si remove_background + ext JPEG sans add_background aval ; chip warning sur les steps incriminés"
    - "`useTransformedUrl(code, assetId, ext)` retourne la string `/t/{code}/{assetId}.{ext}` (préfixe via VITE_PUBLIC_TRANSFORMATION_BASE) — pas de fetch, pas de réactivité async (per D-12/D-13)"
    - "Le `steps` field dans AssetTransformation.json délègue à StepsField composant custom"
  artifacts:
    - path: "pwa/src/components/asset_transformation/edit/StepsField.vue"
      provides: "Orchestrateur drag-and-drop + dispatch composants par type"
      contains: "import draggable from 'vuedraggable'"
    - path: "pwa/src/composables/useTransformedUrl.ts"
      provides: "Pure string builder pour URL transformée"
      contains: "VITE_PUBLIC_TRANSFORMATION_BASE"
    - path: "pwa/src/composables/useTransformationWarnings.ts"
      provides: "Recalcul miroir EDITOR-08 (côté client, feedback temps réel)"
      contains: "remove-background-requires-png"
    - path: "pwa/src/components/asset_transformation/edit/WarningBanner.vue"
      provides: "v-alert warning consommant transformation.warnings[] + miroir client"
      contains: "v-alert"
    - path: "pwa/src/config/AssetTransformation.json"
      provides: "Config schema-driven déléguant `steps` à StepsField"
      contains: "StepsField"
  key_links:
    - from: "StepsField.vue"
      to: "componentRegistry[stepType] (6 composants steps/*)"
      via: "<component :is>"
      pattern: "componentRegistry"
    - from: "WarningBanner.vue"
      to: "useTransformationWarnings + transformation.warnings (serveur)"
      via: "merge des deux sources, dédup par code"
      pattern: "warnings"
    - from: "AssetTransformation.json"
      to: "StepsField.vue"
      via: "edit.fields[].steps = StepsField"
      pattern: "StepsField"
---

<objective>
Livrer la moitié **éditeur** de la PWA Phase 5 : drag-and-drop des steps, sous-formulaires dynamiques par type, warning EDITOR-08 visible (banner + chips), et composable `useTransformedUrl` (pure string).

Purpose : EDITOR-01 (CRUD), EDITOR-02 (drag-and-drop), EDITOR-03 (sous-formulaire par type), EDITOR-07 (`useTransformedUrl`), EDITOR-08 (warning). Indépendant de l'API preview (Plan 01) — chargée par Plan 05.
Output : Page d'édition `AssetTransformation` opérationnelle avec drag-and-drop + warnings visibles, sans preview (encore).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-RESEARCH.md

# Config existante à étendre
@pwa/src/config/AssetTransformation.json

# Fields réutilisables (Phase 1+)
@pwa/src/components/fields/NumberField.vue
@pwa/src/components/fields/EnumField.vue
@pwa/src/components/fields/RelationField.vue

# Page actions (CLAUDE.md)
@pwa/src/components/common/PageActionBtn.vue
@pwa/src/components/common/PageActionsFooter.vue

# Codes warnings serveur (Phase 4 plan 04-05)
# - 'remove-background-requires-png' (recommandation)
# - 'alpha-flatten-on-jpeg' (info)

<interfaces>
Type Step (extrait du schéma AssetTransformation existant) :

```typescript
type StepType = 'resize' | 'crop' | 'rotate' | 'format_convert' | 'add_background' | 'remove_background'
interface Step {
  id?: string  // local UUID for drag-and-drop key
  type: StepType
  params: Record<string, unknown>
}
interface Warning { code: string; message?: string; stepIndex?: number }
```

Pattern composable use* (extrait useAssetUrl.ts) :
- import.meta.env typé Vite
- Pas de réactivité pour useTransformedUrl (per D-12)
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| PWA → API (CRUD AssetTransformation) | JWT user, déjà sécurisé Phase 1 |
| PWA composant ↔ user input | Validation côté serveur reste source de vérité ; warnings client = miroir non bloquant |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-17 | Input Validation | Step params arbitrary objects | mitigate | DTO validators serveur (Phase 3 StepParamsFactory) rejettent à la persistance ; client UI guide via NumberField/EnumField bornes |
| T-05-18 | Information Disclosure | useTransformedUrl exposant un assetId privé | accept | Route /t/* serveur retourne 404 si isPublic=false (déjà mitigé Phase 3) ; composable ne sait pas si l'asset est public, c'est OK |
| T-05-19 | UX integrity | Drag-and-drop sur mobile cassé | mitigate | vuedraggable@4 wraps Sortable.js (touch events natifs supportés) |
| T-05-20 | Tampering (warning silenced) | Logique miroir client cache un warning serveur | mitigate | WarningBanner FUSIONNE warnings serveur + miroir client (union), dédup par code ; serveur reste source canonique |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 — install vuedraggable@4 + interfaces TS + composables</name>
  <files>pwa/package.json, pwa/src/composables/useTransformedUrl.ts, pwa/src/composables/useTransformationWarnings.ts</files>
  <behavior>
    - `useTransformedUrl('product-thumb', 42, 'webp')` → `'/t/product-thumb/42.webp'` (sans prefix env)
    - Avec `VITE_PUBLIC_TRANSFORMATION_BASE=https://cdn.example.com` → `'https://cdn.example.com/t/product-thumb/42.webp'`
    - Pas d'import `ref` / `watch` / `computed` dans useTransformedUrl.ts (T-05 Pitfall 5 RESEARCH ; vérifiable par grep)
    - `useTransformationWarnings(steps, outputExt)` retourne `Warning[]` avec code `remove-background-requires-png` si conditions remplies (T-05-20 miroir)
  </behavior>
  <action>
    1. Installer : `docker compose exec pwa npm install vuedraggable@4` (per Pitfall 1 RESEARCH — pas `@next`). Vérifier `package.json` contient `"vuedraggable": "^4.x"`.
    2. Créer `pwa/src/composables/useTransformedUrl.ts` (Example 1 RESEARCH) :
       ```typescript
       const base = (import.meta.env.VITE_PUBLIC_TRANSFORMATION_BASE as string | undefined) ?? ''
       export function useTransformedUrl(code: string, assetId: number, ext: string): string {
         return `${base}/t/${code}/${assetId}.${ext}`
       }
       ```
       Pas d'import autre que types. JSDoc explicite « pure string, no fetch, sync transformations only ».
    3. Créer `pwa/src/composables/useTransformationWarnings.ts` (per D-05 + Open Q5 RESEARCH). Implémente UNE règle miroir :
       - Si `outputExt ∈ ['jpg','jpeg']` ET `steps` contient un step `remove_background` ET aucun step `add_background` après celui-ci → renvoyer `[{ code: 'remove-background-requires-png', stepIndex: <indexOfRemoveBg> }]`.
       - Aligner le code STRICTEMENT avec celui émis par le serveur Phase 4 plan 04-05.
       - Pure function (pas de réactivité ; le composant appelant l'enveloppera dans un computed si besoin).
    4. Aucun test runner installé côté PWA (cf. Wave 0 Gaps RESEARCH) → validation via `vue-tsc --noEmit` (type-check) et smoke manuel dans la console navigateur. Verify automatisé = grep + tsc.
  </action>
  <verify>
    <automated>docker compose exec pwa npm run build && grep -q "vuedraggable" pwa/package.json && ! grep -E "^import.*(ref|watch|computed).*'vue'" pwa/src/composables/useTransformedUrl.ts</automated>
  </verify>
  <done>
    - vuedraggable@4.x dans dependencies
    - useTransformedUrl pure (aucun import Vue reactivity)
    - useTransformationWarnings exposé, type-check OK
    - `npm run build` PWA reste vert
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: 6 composants {Type}StepFields.vue + WarningBanner.vue</name>
  <files>pwa/src/components/asset_transformation/edit/steps/ResizeStepFields.vue, pwa/src/components/asset_transformation/edit/steps/CropStepFields.vue, pwa/src/components/asset_transformation/edit/steps/RotateStepFields.vue, pwa/src/components/asset_transformation/edit/steps/FormatConvertStepFields.vue, pwa/src/components/asset_transformation/edit/steps/AddBackgroundStepFields.vue, pwa/src/components/asset_transformation/edit/steps/RemoveBackgroundStepFields.vue, pwa/src/components/asset_transformation/edit/WarningBanner.vue</files>
  <behavior>
    - Chaque composant accepte `defineProps<{ modelValue: Record<string,unknown> }>()` et émet `update:modelValue` (v-model 2-way) — typage strict
    - ResizeStepFields : NumberField width, NumberField height, EnumField mode (fit/cover/contain), BooleanField upscale
    - CropStepFields : NumberField x, y, width, height (mode rect) — ou aspectRatio + anchor (laisser switch v-select dans le composant)
    - RotateStepFields : NumberField angle (0-360), CodeField background (hex color)
    - FormatConvertStepFields : EnumField format (png/jpg/jpeg/webp/avif), NumberField quality (1-100)
    - AddBackgroundStepFields : EnumField type (color/asset), conditionnel : CodeField color OU RelationField (Asset) assetId (per D-02, T-05 IMGSVC-05)
    - RemoveBackgroundStepFields : EnumField model (birefnet/isnet-general-use, defaut birefnet), BooleanField fallbackOnTimeout
    - WarningBanner reçoit `Warning[]` en prop et affiche `<v-alert type="warning" variant="tonal">` listant les codes traduits via i18n keys ; vide si aucun warning
  </behavior>
  <action>
    1. Pour chaque composant `{Type}StepFields.vue` : `<script setup lang="ts">` + `defineProps` + `defineEmits` + bindings v-model sur les champs `fields/*`. Aucun composant nouveau de champ : uniquement réassemblage. Le format est `Record<string,unknown>` pour rester compatible avec le JSON serveur.
    2. `WarningBanner.vue` : props `warnings: Warning[]`, slot `default` (titre i18n par défaut). i18n keys : `asset_transformation.warnings.remove-background-requires-png`, `asset_transformation.warnings.alpha-flatten-on-jpeg`. Pas d'inline strings — toutes les chaînes via `useI18n().t()`. Note dans le code : i18n FR/EN ajoutés dans Plan 05 (Task 3) pour ne pas multiplier les conflits sur les locale files.
    3. Tous les composants : pas de fetch direct ; lecture/écriture via props/emits uniquement.
    4. Verify automatisé : `vue-tsc --noEmit` + grep que chaque composant utilise `<script setup lang="ts">`.
  </action>
  <verify>
    <automated>docker compose exec pwa npx vue-tsc --noEmit && find pwa/src/components/asset_transformation/edit/steps -name "*.vue" | wc -l | grep -q 6</automated>
  </verify>
  <done>
    - 6 composants steps/* présents, type-check vert
    - WarningBanner.vue présent et utilisable
    - `npm run build` reste vert
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: StepsField.vue (orchestrateur drag-and-drop) + intégration AssetTransformation.json</name>
  <files>pwa/src/components/asset_transformation/edit/StepsField.vue, pwa/src/config/AssetTransformation.json</files>
  <behavior>
    - StepsField rend `<draggable v-model="local" item-key="id" handle=".drag-handle" animation="200">` (Example 4 RESEARCH)
    - Chaque step item affiche : icône drag, label type, `<component :is="componentRegistry[type]" v-model="local[index].params" />`, chip warning si l'index figure dans `warnings.filter(w => w.stepIndex === index)`
    - Boutons "Add step" (v-menu par type) et "Remove step" par row (PageActionBtn kind=ghost/danger)
    - Émet `update:modelValue` (steps array) — intégration v-model standard avec resource form
    - AssetTransformation.json : champ `steps` dans `edit.fields` → `{ "steps": "StepsField" }` (custom component) ; warnings banner ajouté dans `edit.component` ou en bandeau via slot global
  </behavior>
  <action>
    1. Créer `StepsField.vue` :
       ```vue
       <script setup lang="ts">
       import draggable from 'vuedraggable'
       import { computed } from 'vue'
       import ResizeStepFields from './steps/ResizeStepFields.vue'
       // ... 5 autres imports
       import { useTransformationWarnings } from '@/composables/useTransformationWarnings'

       const props = defineProps<{
         modelValue: Step[]
         outputExt?: string  // injected via parent edit page (current format_convert ext or default)
         serverWarnings?: Warning[]  // from transformation.warnings (server source of truth)
       }>()
       const emit = defineEmits<{ (e: 'update:modelValue', steps: Step[]): void }>()

       const componentRegistry = { resize: ResizeStepFields, crop: CropStepFields, rotate: RotateStepFields, format_convert: FormatConvertStepFields, add_background: AddBackgroundStepFields, remove_background: RemoveBackgroundStepFields }

       const local = computed({
         get: () => props.modelValue,
         set: (v) => emit('update:modelValue', v),
       })

       const clientWarnings = computed(() => useTransformationWarnings(props.modelValue, props.outputExt ?? 'png'))
       const allWarnings = computed(() => /* merge dedup by code */ [...(props.serverWarnings ?? []), ...clientWarnings.value])
       </script>
       ```
       Template : `<WarningBanner :warnings="allWarnings" />` + `<draggable v-model="local" item-key="id" handle=".drag-handle">` + per-item `<v-icon class="drag-handle">mdi-drag-vertical</v-icon>` + chip warning + `<component :is="componentRegistry[element.type]" v-model="local[index].params" />` + bouton remove.
       Add step menu : 6 entries (1 par type) qui push un nouveau step avec `id: crypto.randomUUID()`, `type`, `params: {}`.
    2. Mettre à jour `pwa/src/config/AssetTransformation.json` :
       - `edit.fields` : `{ "code": "", "label": "", "steps": "StepsField" }` (custom)
       - Si la résolution dynamique du composant nécessite un import-map → enregistrer `StepsField` dans le mécanisme PWA existant (cf. pattern Asset.json `AssetGrid`)
    3. Verify : `npm run build` + smoke manuel (en local) sur la page d'édition d'une transformation existante (Phase 1 a déjà câblé le routing).
  </action>
  <verify>
    <automated>docker compose exec pwa npm run build</automated>
  </verify>
  <done>
    - StepsField.vue rend une liste drag-and-drop (validation manuelle via `docker compose up` + browser)
    - AssetTransformation.json route `steps` vers StepsField
    - `npm run build` vert
    - No `<v-btn>` brut (uniquement PageActionBtn)
  </done>
</task>

</tasks>

<verification>
- `docker compose exec pwa npm run build` vert
- `docker compose exec pwa npx vue-tsc --noEmit` vert
- Manual : sur `http://localhost:5173/resource/asset_transformations/{id}/edit`, drag-and-drop fonctionne, ajout/suppression de step OK, warning EDITOR-08 visible si remove_background + format_convert jpg sans add_background aval
</verification>

<success_criteria>
EDITOR-01/02/03/07/08 livrés. Aucune dépendance Plan 01/02/03. Prêt pour intégration preview (Plan 05).
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-04-SUMMARY.md`
</output>
