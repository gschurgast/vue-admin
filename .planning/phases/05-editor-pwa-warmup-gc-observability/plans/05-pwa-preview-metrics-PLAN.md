---
phase: 05
plan: 05
type: execute
wave: 2
depends_on: [01, 04]
files_modified:
  - pwa/src/components/asset_transformation/edit/PreviewPanel.vue
  - pwa/src/components/asset_transformation/edit/AssetPickerDialog.vue
  - pwa/src/composables/usePreviewUrl.ts
  - pwa/src/locales/fr_FR.json
  - pwa/src/locales/en_US.json
  - api/src/Service/TransformationMetrics.php
  - api/config/packages/monolog.yaml
  - api/src/Controller/PublicTransformationController.php
  - api/src/Service/PipelineRunner.php
  - api/src/MessageHandler/WarmupTransformationVariantHandler.php
  - api/tests/Unit/Service/TransformationMetricsTest.php
autonomous: true
requirements: [EDITOR-04, EDITOR-05, OPS-05]
must_haves:
  truths:
    - "L'éditeur PWA affiche un PreviewPanel avec bouton 'Prévisualiser', picker d'asset (modal) + mémorisation localStorage par transformationId"
    - "Le panel affiche l'image preview (blob URL) ; rate-limit 429 affiche un message i18n explicite avec Retry-After"
    - "Service PHP TransformationMetrics émet des logs JSON sur channel monolog `transformations_metrics` avec structure {metric, value, unit, transformation_id, step_type?, transport?}"
    - "Points d'instrumentation câblés : PublicTransformationController (cache hit/miss), PipelineRunner (render duration + lock contention), WarmupTransformationVariantHandler (message handled outcome)"
    - "Monolog channel transformations_metrics écrit sur php://stderr en JsonFormatter (Datadog Logs ingest ready)"
    - "i18n FR + EN couvrent toutes les chaînes Phase 5 (preview, picker, warnings codes)"
  artifacts:
    - path: "api/src/Service/TransformationMetrics.php"
      provides: "Façade Monolog channel transformations_metrics avec méthodes recordX()"
      contains: "class TransformationMetrics"
    - path: "api/config/packages/monolog.yaml"
      provides: "Channel transformations_metrics + handler JsonFormatter → php://stderr"
      contains: "transformations_metrics"
    - path: "pwa/src/components/asset_transformation/edit/PreviewPanel.vue"
      provides: "Bouton preview + image affichée + gestion 429"
      contains: "PreviewPanel"
    - path: "pwa/src/components/asset_transformation/edit/AssetPickerDialog.vue"
      provides: "Modal v-dialog + AssetGrid standalone en mode select"
      contains: "AssetPickerDialog"
    - path: "pwa/src/composables/usePreviewUrl.ts"
      provides: "Blob URL ref-counted depuis POST preview (clone useAssetUrl)"
      contains: "usePreviewUrl"
  key_links:
    - from: "PreviewPanel.vue"
      to: "POST /api/asset_transformations/preview"
      via: "usePreviewUrl (axios POST avec JWT + body JSON)"
      pattern: "asset_transformations/preview"
    - from: "AssetPickerDialog.vue"
      to: "AssetGrid (standalone) + @select event"
      via: "import + slot dans v-dialog"
      pattern: "AssetGrid"
    - from: "TransformationMetrics"
      to: "PublicTransformationController.cache hit/miss + PipelineRunner.render duration + WarmupHandler.outcome"
      via: "injection + appel inline"
      pattern: "recordCacheHit|recordRenderDuration|recordMessageHandled"
    - from: "Monolog channel transformations_metrics"
      to: "php://stderr (Docker logs → Datadog Logs ingest)"
      via: "JsonFormatter"
      pattern: "php://stderr"
---

<objective>
Livrer la moitié **preview + observabilité** : panel PWA preview avec picker d'asset + i18n FR/EN, et le service `TransformationMetrics` (façade Monolog channel) instrumenté dans les points clés (controller public, PipelineRunner, warmup handler).

Purpose : EDITOR-04 + EDITOR-05 côté PWA (consommant Plan 01) + OPS-05 (métriques structurées). Dépend du Plan 01 (API preview) et Plan 04 (StepsField pour intégration).
Output : Preview round-trip fonctionnel + logs JSON Datadog-ready sur stderr.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-RESEARCH.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/plans/01-preview-api-base-PLAN.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/plans/04-pwa-editor-steps-PLAN.md

# Pattern composable blob URL ref-counted
@pwa/src/composables/useAssetUrl.ts

# Picker base : AssetGrid standalone
@pwa/src/components/asset/list/AssetGrid.vue

# Points d'instrumentation API
@api/src/Controller/PublicTransformationController.php
@api/src/Service/PipelineRunner.php
@api/src/MessageHandler/WarmupTransformationVariantHandler.php

# Monolog config existante
@api/config/packages/monolog.yaml

<interfaces>
TransformationMetrics public surface (Pattern 6 RESEARCH) :

```php
final class TransformationMetrics {
    public function recordCacheHit(int $txId, string $hash): void;
    public function recordCacheMiss(int $txId, string $hash): void;
    public function recordRenderDuration(int $txId, string $stepType, int $durationMs): void;
    public function recordLockContention(int $txId, string $hash, int $waitMs): void;
    public function recordEmbedderTimeout(string $stepType): void;
    public function recordMessageHandled(string $transport, string $outcome): void;
}
```

Log shape (per D-24) :
```json
{"metric":"transformations.cache.hit","value":1,"unit":"count","transformation_id":12,"version_hash":"a3f7..."}
```

PreviewRequest payload (Plan 01) :
```json
{"assetId":42, "ext":"png", "steps":[{"type":"resize","params":{"width":256}}]}
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| PWA → POST /preview | JWT user + rate-limit (Plan 01) |
| API → Monolog stderr | Pas de réseau externe ; Docker logging driver capture |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-21 | Information Disclosure | Logs JSON exposent PII | mitigate | TransformationMetrics n'accepte QUE des IDs numériques + step types + durées (signature stricte). Aucun email/path asset/payload utilisateur loggé. |
| T-05-22 | DoS | Logs verbeux saturent stderr | mitigate | level=info, pas de debug ; un log par événement (pas de boucle de log par step) |
| T-05-23 | Information Disclosure | Preview asset privé via picker | mitigate | AssetGrid liste déjà uniquement les assets accessibles à l'user authentifié ; preview endpoint refuse asset non-public (T-05-03 Plan 01) |
| T-05-24 | Tampering | Preview blob URL fuit en cache navigateur | mitigate | Réponse serveur a Cache-Control: no-store (Plan 01) ; blob URL ref-counted révoqué à l'unmount (pattern useAssetUrl) |
| T-05-25 | UX integrity | 429 silent fail | mitigate | PreviewPanel parse Retry-After et affiche message i18n + countdown |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 — TransformationMetrics + monolog.yaml + tests + instrumentation points</name>
  <files>api/src/Service/TransformationMetrics.php, api/config/packages/monolog.yaml, api/src/Controller/PublicTransformationController.php, api/src/Service/PipelineRunner.php, api/src/MessageHandler/WarmupTransformationVariantHandler.php, api/tests/Unit/Service/TransformationMetricsTest.php</files>
  <behavior>
    - TransformationMetricsTest::testRecordCacheHitEmitsStructuredLog() — mock LoggerInterface (channel `transformations_metrics`) reçoit `info('cache_event', ['metric' => 'transformations.cache.hit', 'value' => 1, 'unit' => 'count', 'transformation_id' => 12, 'version_hash' => 'abc'])`
    - Couverture des 6 méthodes recordX (1 test par méthode minimum)
    - PublicTransformationController invoke `metrics.recordCacheHit/Miss` aux 2 branches du cache check (T-05-21 : que des int + string)
    - PipelineRunner invoke `metrics.recordRenderDuration` après chaque step + `recordLockContention` quand attente lock > 0
    - WarmupTransformationVariantHandler invoke `metrics.recordMessageHandled('transformations', 'success'|'failure')` en bloc try/catch
    - Monolog handler `transformations_metrics` configuré path: `php://stderr`, formatter: `monolog.formatter.json`, level: info, channels: `[transformations_metrics]`
  </behavior>
  <action>
    1. Étendre `api/config/packages/monolog.yaml` (Pattern 6 RESEARCH) :
       ```yaml
       monolog:
         channels: ['transformations_metrics']
         handlers:
           transformations_metrics:
             type: stream
             path: 'php://stderr'   # Pitfall 8 RESEARCH : stderr pas fichier
             level: info
             channels: ['transformations_metrics']
             formatter: monolog.formatter.json
       ```
       Préserver les handlers existants ; ajouter à la fin du `handlers:` map.
    2. Créer `App\Service\TransformationMetrics` (per D-22). Injection :
       ```php
       public function __construct(
         #[Autowire(service: 'monolog.logger.transformations_metrics')]
         private LoggerInterface $logger,
       ) {}
       ```
       Implémenter les 6 méthodes (signatures dans `<interfaces>`). Chaque méthode appelle `$this->logger->info('<event_name>', [structured fields])`. Restriction T-05-21 : signatures strictes typées (int/string), aucune array libre.
    3. Tests unit : 1 fichier `TransformationMetricsTest.php` couvrant les 6 méthodes via mock `LoggerInterface` + assertion sur l'appel `info` (args exacts).
    4. Câbler l'injection dans les 3 consommateurs (PublicTransformationController, PipelineRunner, WarmupTransformationVariantHandler) :
       - **Controller** : entourer le check cache S3 — `if (cache exists) { $metrics->recordCacheHit($txId, $hash); ... } else { $metrics->recordCacheMiss($txId, $hash); ... }`
       - **PipelineRunner** : autour de chaque step `$t0 = hrtime(true); handler->handle(); $metrics->recordRenderDuration($txId, $stepType, intval((hrtime(true)-$t0)/1e6));`. Pour `recordLockContention`, instrumenter dans la boucle d'attente du lock Redis (waitMs cumulé).
       - **WarmupTransformationVariantHandler** : try/catch wrapping `run()` ; émettre outcome `success` ou `failure` (sans détails d'exception PII).
    5. Pas de modification du `bypassCache` path : on n'instrumente pas les preview (per T-05-21 spirit : éviter inflation de logs sur endpoints non-critiques).
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="TransformationMetricsTest" && docker compose exec api php bin/console debug:container monolog.logger.transformations_metrics</automated>
  </verify>
  <done>
    - 6 méthodes testées + green
    - Channel monolog `transformations_metrics` listé dans `debug:container`
    - Smoke : un hit sur `/t/...` (Phase 3) produit un log JSON sur stderr containing `"metric":"transformations.cache.hit"`
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: PreviewPanel.vue + AssetPickerDialog.vue + usePreviewUrl.ts</name>
  <files>pwa/src/components/asset_transformation/edit/PreviewPanel.vue, pwa/src/components/asset_transformation/edit/AssetPickerDialog.vue, pwa/src/composables/usePreviewUrl.ts</files>
  <behavior>
    - PreviewPanel affiche `PageActionBtn kind=primary` "Prévisualiser" (i18n)
    - Click → POST `/api/asset_transformations/preview` avec `{assetId, ext, steps}` (steps depuis prop) → blob URL affichée dans `<img>` (T-05-24 ref-counted)
    - Si 429 → affiche v-alert avec message i18n + Retry-After secondes (T-05-25)
    - AssetPickerDialog : `<v-dialog max-width="1200">` + `<AssetGrid>` standalone à l'intérieur (cf. CLAUDE.md « Custom list components: standalone mode »). Émet `select(assetId)` à la sélection. Picker mémorise dernier choix via `@vueuse/core useLocalStorage` clé `preview_asset_{transformationId}` (per D-07).
    - usePreviewUrl clone le pattern useAssetUrl mais via POST JSON ; cache keyed par `hash(JSON.stringify({assetId, ext, steps}))` ; révoque les blob URLs à l'unmount.
  </behavior>
  <action>
    1. Créer `usePreviewUrl.ts` :
       - Map module-level `cache: Map<string, { blobUrl: string; refCount: number }>`
       - Function `usePreviewUrl(payload: PreviewPayload)` retourne `{ url: Ref<string|null>, error: Ref<{status:number, retryAfter?:number}|null>, isLoading: Ref<boolean>, refresh: () => Promise<void> }`
       - POST via axios `apiPlatform.client` avec `responseType: 'blob'` ; capture 429 → set error.retryAfter depuis header `Retry-After`
       - onUnmounted : decrement refCount, si 0 → `URL.revokeObjectURL(blobUrl)` + delete cache entry
    2. Créer `AssetPickerDialog.vue` :
       - props `modelValue: boolean` (open/close), `transformationId: number`
       - emit `update:modelValue` + `select(assetId: number)`
       - `<v-dialog v-model="open" max-width="1200">` + `<v-card>` + slot AssetGrid (utiliser `<AssetGrid>` standalone). AssetGrid expose actuellement event `@view` ; on intercepte pour traiter comme `select` (pas de modif d'AssetGrid). Si AssetGrid n'expose pas un event clair pour "select", ajouter un mode `selectable` minimal (prop `selectMode: boolean` + emit `select(id)`) — cette modif locale est tolérée mais à mentionner dans le SUMMARY pour traçabilité.
       - `useLocalStorage('preview_asset_' + props.transformationId, null as number|null)` pour persister.
    3. Créer `PreviewPanel.vue` :
       - props `steps: Step[]`, `outputExt: string`, `transformationId: number`
       - state local `selectedAssetId` (lecture initiale localStorage)
       - "Choose asset" → ouvre AssetPickerDialog ; sur select → met à jour selectedAssetId + ferme dialog
       - "Prévisualiser" → `usePreviewUrl({assetId: selectedAssetId, ext: outputExt, steps})` puis appelle `refresh()`. Affiche `<v-progress-circular>` pendant loading.
       - Si `error.value?.status === 429` → `<v-alert type="warning">` avec i18n `preview.rate_limited` + countdown Retry-After.
       - `<img :src="url" v-if="url">` (blob URL).
    4. Intégrer le PreviewPanel dans la page d'édition AssetTransformation : ajouter au composant `edit` (config `AssetTransformation.json` → `edit.component` custom OU slot dans `StepsField` Plan 04 — pragmatique : ajouter en bas du StepsField via slot ou directement dans le edit page). Plus simple : exposer une prop `<StepsField>` et mounter PreviewPanel à côté ; cf. config edit JSON (le `edit.component` peut référencer un wrapper `AssetTransformationEdit.vue` qui compose StepsField + PreviewPanel).
    5. Verify : `npm run build` + smoke manuel.
  </action>
  <verify>
    <automated>docker compose exec pwa npm run build && docker compose exec pwa npx vue-tsc --noEmit</automated>
  </verify>
  <done>
    - PreviewPanel + AssetPickerDialog + usePreviewUrl présents
    - Build PWA vert
    - Smoke local : click "Prévisualiser" sur une transformation existante avec asset public affiche l'image preview
  </done>
</task>

<task type="auto">
  <name>Task 3: i18n FR + EN pour Phase 5 (preview, picker, warnings)</name>
  <files>pwa/src/locales/fr_FR.json, pwa/src/locales/en_US.json</files>
  <action>
    Ajouter les clés suivantes dans `pwa/src/locales/fr_FR.json` et `pwa/src/locales/en_US.json` (uniquement ces 2 locales par décision pragmatique ; les autres 12 langues seront couvertes par le flux i18n standard du projet ou un follow-up — noter dans SUMMARY) :
    - `preview.button`, `preview.loading`, `preview.choose_asset`, `preview.no_asset_selected`, `preview.rate_limited` (avec `{seconds}` placeholder), `preview.error_generic`
    - `asset_picker.title`, `asset_picker.search_placeholder`, `asset_picker.select`, `asset_picker.cancel`
    - `asset_transformation.warnings.title`, `asset_transformation.warnings.remove-background-requires-png`, `asset_transformation.warnings.alpha-flatten-on-jpeg`
    - `asset_transformation.steps.add`, `asset_transformation.steps.remove`, `asset_transformation.steps.type.<each>` (6 types)
    FR exemples :
    - `preview.button` : "Prévisualiser"
    - `preview.rate_limited` : "Trop de previews — réessayez dans {seconds}s."
    EN équivalents naturels.
    Conserver l'organisation hiérarchique existante des fichiers locale. Pas de string en clair dans les composants Phase 5.
  </action>
  <verify>
    <automated>node -e "const fr=require('./pwa/src/locales/fr_FR.json'); const en=require('./pwa/src/locales/en_US.json'); const keys=['preview.button','preview.rate_limited','asset_picker.title','asset_transformation.warnings.remove-background-requires-png']; for (const k of keys) { const get=(o,p)=>p.split('.').reduce((a,x)=>a?.[x],o); if (!get(fr,k)||!get(en,k)) { console.error('Missing',k); process.exit(1) } } console.log('OK')"</automated>
  </verify>
  <done>
    - Toutes les clés présentes dans FR et EN
    - PWA build reste vert
    - SUMMARY note les 12 autres locales en follow-up (decision documented)
  </done>
</task>

</tasks>

<verification>
- `docker compose exec api ./vendor/bin/phpunit --filter="TransformationMetricsTest"` vert
- `docker compose exec pwa npm run build` vert
- Smoke E2E manuel : édition transformation → drag-and-drop + warning + preview round-trip + 429 visible si spam
- `docker compose logs api 2>&1 | tail -20 | grep -E '"metric":"transformations\.'` après un hit `/t/*` retourne des logs JSON
</verification>

<success_criteria>
EDITOR-04 et EDITOR-05 livrés côté UX, OPS-05 livré côté instrumentation. Phase 5 complète après merge de ce plan.
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-05-SUMMARY.md`
</output>
