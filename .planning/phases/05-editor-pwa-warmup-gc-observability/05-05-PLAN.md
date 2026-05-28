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
  - pwa/src/locales/ar_SA.json
  - pwa/src/locales/da_DK.json
  - pwa/src/locales/de_DE.json
  - pwa/src/locales/en_US.json
  - pwa/src/locales/es_ES.json
  - pwa/src/locales/fr_FR.json
  - pwa/src/locales/he_IL.json
  - pwa/src/locales/it_IT.json
  - pwa/src/locales/ja_JP.json
  - pwa/src/locales/nb_NO.json
  - pwa/src/locales/pl_PL.json
  - pwa/src/locales/pt_PT.json
  - pwa/src/locales/sv_SE.json
  - pwa/src/locales/zh_CN.json
  - api/src/Service/TransformationMetrics.php
  - api/config/packages/monolog.yaml
  - api/src/Controller/PublicTransformationController.php
  - api/src/Service/PipelineRunner.php
  - api/src/MessageHandler/WarmupTransformationVariantHandler.php
  - api/src/MessageHandler/RemoveBackgroundHandler.php
  - api/tests/Unit/Service/TransformationMetricsTest.php
  - api/tests/Unit/MessageHandler/RemoveBackgroundHandlerTest.php
autonomous: true
requirements: [EDITOR-04, EDITOR-05, OPS-05]
must_haves:
  truths:
    - "L'éditeur PWA affiche un PreviewPanel avec bouton 'Prévisualiser', picker d'asset (modal) + mémorisation localStorage par transformationId"
    - "Le panel affiche l'image preview (blob URL) ; rate-limit 429 affiche un message i18n explicite avec Retry-After"
    - "Service PHP TransformationMetrics émet des logs JSON sur channel monolog `transformations_metrics` avec structure {metric, value, unit, transformation_id, step_type?, transport?}"
    - "Points d'instrumentation câblés : PublicTransformationController (cache hit/miss), PipelineRunner (render duration + lock contention), WarmupTransformationVariantHandler (message handled outcome)"
    - "WARNING #1 : recordEmbedderTimeout($stepType) câblé dans le catch ConnectException/TransportException des step handlers HTTP appelant l'embedder (RemoveBackgroundHandler en cible canonique Phase 4)"
    - "WARNING #2 : recordEmbedderHealth($inflight, $lastInferenceMs) câblé post-call sur RemoveBackgroundHandler ; choix d'implémentation documenté (header X-BiRefNet-Inflight OU listener inline OU cron fallback) — voir Task 1"
    - "Monolog channel transformations_metrics écrit sur php://stderr en JsonFormatter (Datadog Logs ingest ready)"
    - "i18n couvre les 14 locales du projet (FR/EN traductions complètes ; 12 autres locales = copie EN comme fallback pour éviter clés brutes ; vraies traductions trackées en STATE follow-up)"
  artifacts:
    - path: "api/src/Service/TransformationMetrics.php"
      provides: "Façade Monolog channel transformations_metrics avec 7 méthodes recordX() (dont recordEmbedderTimeout + recordEmbedderHealth)"
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
      to: "PublicTransformationController.cache hit/miss + PipelineRunner.render duration + WarmupHandler.outcome + RemoveBackgroundHandler.embedder timeout/health"
      via: "injection + appel inline"
      pattern: "recordCacheHit|recordRenderDuration|recordMessageHandled|recordEmbedderTimeout|recordEmbedderHealth"
    - from: "Monolog channel transformations_metrics"
      to: "php://stderr (Docker logs → Datadog Logs ingest)"
      via: "JsonFormatter"
      pattern: "php://stderr"
---

<objective>
Livrer la moitié **preview + observabilité** : panel PWA preview avec picker d'asset + i18n 14 locales, et le service `TransformationMetrics` (façade Monolog channel) instrumenté dans les points clés (controller public, PipelineRunner, warmup handler, RemoveBackgroundHandler timeout + health).

Purpose : EDITOR-04 + EDITOR-05 côté PWA (consommant Plan 01) + OPS-05 (métriques structurées, incluant embedder timeout + health). Dépend du Plan 01 (API preview) et Plan 04 (StepsField pour intégration).
Output : Preview round-trip fonctionnel + logs JSON Datadog-ready sur stderr + métriques embedder câblées (timeout + birefnet_inflight).
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
@api/src/MessageHandler/RemoveBackgroundHandler.php

# Monolog config existante
@api/config/packages/monolog.yaml

<interfaces>
TransformationMetrics public surface (Pattern 6 RESEARCH — révisé WARNING #1/#2 plan-checker) :

```php
final class TransformationMetrics {
    public function recordCacheHit(int $txId, string $hash): void;
    public function recordCacheMiss(int $txId, string $hash): void;
    public function recordRenderDuration(int $txId, string $stepType, int $durationMs): void;
    public function recordLockContention(int $txId, string $hash, int $waitMs): void;
    public function recordEmbedderTimeout(string $stepType): void;
    public function recordMessageHandled(string $transport, string $outcome): void;
    public function recordEmbedderHealth(int $inflight, ?int $lastInferenceMs): void;  // WARNING #2
}
```

Log shape (per D-24) :
```json
{"metric":"transformations.cache.hit","value":1,"unit":"count","transformation_id":12,"version_hash":"a3f7..."}
{"metric":"transformations.embedder.timeout","value":1,"unit":"count","step_type":"remove_background"}
{"metric":"transformations.embedder.inflight","value":2,"unit":"gauge"}
{"metric":"transformations.embedder.last_inference_ms","value":2840,"unit":"ms"}
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
| T-05-22 | DoS | Logs verbeux saturent stderr | mitigate | level=info, pas de debug ; un log par événement (pas de boucle de log par step) ; embedder health post-call uniquement (pas de polling) |
| T-05-23 | Information Disclosure | Preview asset privé via picker | mitigate | AssetGrid liste déjà uniquement les assets accessibles à l'user authentifié ; preview endpoint refuse asset non-public (T-05-03 Plan 01, isPublic strict) |
| T-05-24 | Tampering | Preview blob URL fuit en cache navigateur | mitigate | Réponse serveur a Cache-Control: no-store (Plan 01) ; blob URL ref-counted révoqué à l'unmount (pattern useAssetUrl) |
| T-05-25 | UX integrity | 429 silent fail | mitigate | PreviewPanel parse Retry-After et affiche message i18n + countdown |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 — TransformationMetrics (7 méthodes) + monolog.yaml + instrumentation points (incl. embedder timeout + health)</name>
  <files>api/src/Service/TransformationMetrics.php, api/config/packages/monolog.yaml, api/src/Controller/PublicTransformationController.php, api/src/Service/PipelineRunner.php, api/src/MessageHandler/WarmupTransformationVariantHandler.php, api/src/MessageHandler/RemoveBackgroundHandler.php, api/tests/Unit/Service/TransformationMetricsTest.php, api/tests/Unit/MessageHandler/RemoveBackgroundHandlerTest.php</files>
  <behavior>
    - TransformationMetricsTest couvre les **7 méthodes** : recordCacheHit, recordCacheMiss, recordRenderDuration, recordLockContention, recordEmbedderTimeout, recordMessageHandled, **recordEmbedderHealth** (WARNING #2).
    - PublicTransformationController invoke `metrics.recordCacheHit/Miss` aux 2 branches du cache check (T-05-21 : que des int + string).
    - PipelineRunner invoke `metrics.recordRenderDuration` après chaque step + `recordLockContention` quand attente lock > 0.
    - WarmupTransformationVariantHandler invoke `metrics.recordMessageHandled('transformations', 'success'|'failure')` en bloc try/catch.
    - **WARNING #1** : `RemoveBackgroundHandlerTest::testEmbedderTimeoutCallsMetric()` simule `ConnectException` (ou `TransportException` avec code timeout) sur l'appel HTTP embedder → assert `metrics.recordEmbedderTimeout('remove_background')` appelée exactement 1 fois.
    - **WARNING #2** : `RemoveBackgroundHandlerTest::testEmbedderHealthRecordedAfterCall()` simule un succès → assert `metrics.recordEmbedderHealth($inflight, $lastInferenceMs)` appelée avec les valeurs parsées depuis la réponse / header.
    - Monolog handler `transformations_metrics` configuré path: `php://stderr`, formatter: `monolog.formatter.json`, level: info, channels: `[transformations_metrics]`.
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
       Implémenter les **7 méthodes** (signatures dans `<interfaces>`). Chaque méthode appelle `$this->logger->info('<event_name>', [structured fields])`. Restriction T-05-21 : signatures strictes typées (int/string), aucune array libre.
       - `recordEmbedderHealth(int $inflight, ?int $lastInferenceMs)` émet DEUX logs distincts : un gauge `transformations.embedder.inflight` (value=$inflight, unit=gauge) ET un timing `transformations.embedder.last_inference_ms` si non-null. Cela évite de logger un payload composite et reste compatible Datadog measure facets.
    3. Tests unit : 1 fichier `TransformationMetricsTest.php` couvrant les 7 méthodes via mock `LoggerInterface` + assertion sur l'appel `info` (args exacts). Pour `recordEmbedderHealth(null lastInferenceMs)` : assert UN seul log (gauge inflight) émis.
    4. Câbler l'injection dans les 4 consommateurs :
       - **PublicTransformationController** : entourer le check cache S3 — `if (cache exists) { $metrics->recordCacheHit($txId, $hash); ... } else { $metrics->recordCacheMiss($txId, $hash); ... }`
       - **PipelineRunner** : autour de chaque step `$t0 = hrtime(true); handler->handle(); $metrics->recordRenderDuration($txId, $stepType, intval((hrtime(true)-$t0)/1e6));`. Pour `recordLockContention`, instrumenter dans la boucle d'attente du lock Redis (waitMs cumulé).
       - **WarmupTransformationVariantHandler** : try/catch wrapping `run()` ; émettre outcome `success` ou `failure` (sans détails d'exception PII).
       - **RemoveBackgroundHandler** (WARNING #1 + #2) :
         - Injecter `TransformationMetrics`.
         - **Timeout (WARNING #1)** : wrapper l'appel HTTP `httpClient->request(...)` (ou `embedder->removeBackground(...)`) dans try/catch capturant `Symfony\Component\HttpClient\Exception\TransportException` ET `Symfony\Component\HttpClient\Exception\TimeoutException` (et `ConnectException` si applicable). Dans le catch : `$metrics->recordEmbedderTimeout('remove_background')`. Puis re-throw pour préserver le flux d'erreur existant Phase 4.
         - **Health post-call (WARNING #2)** :
           - Stratégie retenue (la plus pragmatique, à documenter SUMMARY) : **lire le header `X-BiRefNet-Inflight` et `X-BiRefNet-Last-Inference-Ms` retournés par la réponse `/embed` de l'embedder Python**. Si les headers existent → `$metrics->recordEmbedderHealth((int)$response->getHeaders()['x-birefnet-inflight'][0], (int)$response->getHeaders()['x-birefnet-last-inference-ms'][0] ?? null)`.
           - Si l'embedder ne renvoie PAS encore ces headers (Phase 4 livre `/health` mais pas forcément les headers sur `/embed`) : **sous-task additionnelle** — ajouter dans `embedder/main.py` un middleware FastAPI simple qui exporte `inflight` (compteur asyncio.Lock) et `last_inference_ms` (dernier timing) sur CHAQUE réponse `/embed`. Modification minimale (~15 lignes Python), aucun secret nécessaire.
           - **Fallback si la modif Python est jugée hors-scope par l'exécuteur** : créer une commande `transformations:health-collect` (Symfony Console) qui GET `http://embedder:8000/health` (déjà enrichi Phase 4) et appelle `$metrics->recordEmbedderHealth(...)`. Verify automated : `phpunit tests/Unit/Command/TransformationsHealthCollectCommandTest.php`. Webfacto pilote ensuite via cron (documenté `docs/transformations-ops.md` section 5).
           - **Choix DOIT être documenté dans 05-05-SUMMARY.md** avec rationale (friction Python vs friction infra cron).
    5. Pas de modification du `bypassCache` path : on n'instrumente pas les preview (per T-05-21 spirit : éviter inflation de logs sur endpoints non-critiques).
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="TransformationMetricsTest|RemoveBackgroundHandlerTest" && docker compose exec api php bin/console debug:container monolog.logger.transformations_metrics</automated>
  </verify>
  <done>
    - 7 méthodes testées + green (incl. recordEmbedderTimeout + recordEmbedderHealth)
    - RemoveBackgroundHandlerTest verifie WARNING #1 (timeout sim → metric appelée) et WARNING #2 (post-call health metric appelée)
    - Channel monolog `transformations_metrics` listé dans `debug:container`
    - Smoke : un hit sur `/t/...` (Phase 3) produit un log JSON sur stderr containing `"metric":"transformations.cache.hit"`
    - Stratégie embedder health (header X-BiRefNet-Inflight OU cron fallback) documentée dans SUMMARY
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: PreviewPanel.vue + AssetPickerDialog.vue + usePreviewUrl.ts</name>
  <files>pwa/src/components/asset_transformation/edit/PreviewPanel.vue, pwa/src/components/asset_transformation/edit/AssetPickerDialog.vue, pwa/src/composables/usePreviewUrl.ts</files>
  <behavior>
    - PreviewPanel affiche `PageActionBtn kind=primary` "Prévisualiser" (i18n)
    - Click → POST `/api/asset_transformations/preview` avec `{assetId, ext, steps}` (steps depuis prop) → blob URL affichée dans `<img>` (T-05-24 ref-counted)
    - Si 429 → affiche v-alert avec message i18n + Retry-After secondes (T-05-25)
    - Si 404 (asset !isPublic, cf. Plan 01 T-05-03) → affiche v-alert i18n "asset non public, choisir un autre asset"
    - AssetPickerDialog : `<v-dialog max-width="1200">` + `<AssetGrid>` standalone à l'intérieur (cf. CLAUDE.md « Custom list components: standalone mode »). Émet `select(assetId)` à la sélection. Picker mémorise dernier choix via `@vueuse/core useLocalStorage` clé `preview_asset_{transformationId}` (per D-07).
    - usePreviewUrl clone le pattern useAssetUrl mais via POST JSON ; cache keyed par `hash(JSON.stringify({assetId, ext, steps}))` ; révoque les blob URLs à l'unmount.
  </behavior>
  <action>
    1. Créer `usePreviewUrl.ts` :
       - Map module-level `cache: Map<string, { blobUrl: string; refCount: number }>`
       - Function `usePreviewUrl(payload: PreviewPayload)` retourne `{ url: Ref<string|null>, error: Ref<{status:number, retryAfter?:number}|null>, isLoading: Ref<boolean>, refresh: () => Promise<void> }`
       - POST via axios `apiPlatform.client` avec `responseType: 'blob'` ; capture 429 → set error.retryAfter depuis header `Retry-After` ; capture 404 → set error.status=404
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
       - Si `error.value?.status === 404` → `<v-alert type="info">` avec i18n `preview.asset_not_public`.
       - `<img :src="url" v-if="url">` (blob URL).
    4. Intégrer le PreviewPanel dans la page d'édition AssetTransformation : ajouter au composant `edit` (config `AssetTransformation.json` → `edit.component` custom OU slot dans `StepsField` Plan 04 — pragmatique : ajouter en bas du StepsField via slot ou directement dans le edit page). Plus simple : exposer une prop `<StepsField>` et mounter PreviewPanel à côté ; cf. config edit JSON (le `edit.component` peut référencer un wrapper `AssetTransformationEdit.vue` qui compose StepsField + PreviewPanel).
    5. Verify : `npm run build` + smoke manuel.
  </action>
  <verify>
    <automated>docker compose exec pwa npm run build && docker compose exec pwa npx vue-tsc --noEmit</automated>
  </verify>
  <done>
    - PreviewPanel + AssetPickerDialog + usePreviewUrl présents
    - Gestion 429 ET 404 visible côté UX
    - Build PWA vert
    - Smoke local : click "Prévisualiser" sur une transformation existante avec asset public affiche l'image preview
  </done>
</task>

<task type="auto">
  <name>Task 3: i18n 14 locales (FR/EN traduits, 12 autres = fallback EN pour éviter clés brutes)</name>
  <files>pwa/src/locales/ar_SA.json, pwa/src/locales/da_DK.json, pwa/src/locales/de_DE.json, pwa/src/locales/en_US.json, pwa/src/locales/es_ES.json, pwa/src/locales/fr_FR.json, pwa/src/locales/he_IL.json, pwa/src/locales/it_IT.json, pwa/src/locales/ja_JP.json, pwa/src/locales/nb_NO.json, pwa/src/locales/pl_PL.json, pwa/src/locales/pt_PT.json, pwa/src/locales/sv_SE.json, pwa/src/locales/zh_CN.json</files>
  <action>
    **WARNING #4 plan-checker** : couvrir les **14 locales** du projet pour éviter l'affichage de clés brutes en cas de changement de locale.

    Stratégie (documentée pragmatique) :
    - **FR (fr_FR.json)** et **EN (en_US.json)** : traductions complètes natives.
    - **12 autres locales** (`ar_SA, da_DK, de_DE, es_ES, he_IL, it_IT, ja_JP, nb_NO, pl_PL, pt_PT, sv_SE, zh_CN`) : copier les valeurs **EN** verbatim (fallback). Cela évite l'affichage des clés brutes (`preview.button` au lieu d'un mot lisible) tout en restant honnête sur l'absence de traduction.
    - Tracker un follow-up STATE.md : « Phase 5 — traduire les 12 locales secondaires (preview, picker, warnings) en vraies traductions natives (post-v1.0) ».

    Clés à ajouter dans **CHAQUE** des 14 fichiers (organisation hiérarchique alignée avec celle existante) :
    - `preview.button`, `preview.loading`, `preview.choose_asset`, `preview.no_asset_selected`, `preview.rate_limited` (avec `{seconds}` placeholder), `preview.asset_not_public`, `preview.error_generic`
    - `asset_picker.title`, `asset_picker.search_placeholder`, `asset_picker.select`, `asset_picker.cancel`
    - `asset_transformation.warnings.title`, `asset_transformation.warnings.remove-background-requires-png`, `asset_transformation.warnings.alpha-flatten-on-jpeg`
    - `asset_transformation.steps.add`, `asset_transformation.steps.remove`, `asset_transformation.steps.type.resize`, `.crop`, `.rotate`, `.format_convert`, `.add_background`, `.remove_background`

    FR exemples :
    - `preview.button` : "Prévisualiser"
    - `preview.rate_limited` : "Trop de previews — réessayez dans {seconds}s."
    - `preview.asset_not_public` : "Cet asset n'est pas public et ne peut pas être prévisualisé."

    EN exemples (= valeurs copiées dans les 12 autres locales) :
    - `preview.button` : "Preview"
    - `preview.rate_limited` : "Too many previews — retry in {seconds}s."
    - `preview.asset_not_public` : "This asset is not public and cannot be previewed."

    Conserver l'organisation hiérarchique existante des fichiers locale. Pas de string en clair dans les composants Phase 5.

    **Suivi follow-up** : créer une entrée dans `.planning/STATE.md` (section follow-ups) : « Phase 5 i18n — traduire les 12 locales secondaires (ar_SA, da_DK, de_DE, es_ES, he_IL, it_IT, ja_JP, nb_NO, pl_PL, pt_PT, sv_SE, zh_CN) actuellement en fallback EN pour preview/picker/warnings (WARNING banner Phase 5). Trackable post-v1.0. »
  </action>
  <verify>
    <automated>node -e "const fs=require('fs');const locales=['ar_SA','da_DK','de_DE','en_US','es_ES','fr_FR','he_IL','it_IT','ja_JP','nb_NO','pl_PL','pt_PT','sv_SE','zh_CN'];const keys=['preview.button','preview.rate_limited','preview.asset_not_public','asset_picker.title','asset_transformation.warnings.remove-background-requires-png','asset_transformation.steps.type.remove_background'];const get=(o,p)=>p.split('.').reduce((a,x)=>a?.[x],o);for (const loc of locales){const data=JSON.parse(fs.readFileSync('./pwa/src/locales/'+loc+'.json','utf8'));for(const k of keys){if(!get(data,k)){console.error('Missing',k,'in',loc);process.exit(1)}}}console.log('OK 14 locales')"</automated>
  </verify>
  <done>
    - Toutes les clés présentes dans les **14 locales**
    - FR + EN traduits natif ; 12 autres = copies EN (fallback assumé)
    - PWA build reste vert
    - STATE.md follow-up « traduire 12 locales secondaires » créé
  </done>
</task>

</tasks>

<verification>
- `docker compose exec api ./vendor/bin/phpunit --filter="TransformationMetricsTest|RemoveBackgroundHandlerTest"` vert
- `docker compose exec pwa npm run build` vert
- Smoke E2E manuel : édition transformation → drag-and-drop + warning + preview round-trip + 429 visible si spam + 404 visible si asset !isPublic
- `docker compose logs api 2>&1 | tail -20 | grep -E '"metric":"transformations\.'` après un hit `/t/*` retourne des logs JSON
- Présence des 14 locales avec toutes les clés requises
</verification>

<success_criteria>
EDITOR-04 et EDITOR-05 livrés côté UX, OPS-05 livré côté instrumentation (incluant embedder timeout + health metrics — WARNINGS #1 et #2). i18n 14 locales couvertes (WARNING #4). Phase 5 complète après merge de ce plan.
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-05-SUMMARY.md`
</output>
