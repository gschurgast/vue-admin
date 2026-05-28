---
phase: 05
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - api/src/ApiResource/PreviewRequest.php
  - api/src/State/PreviewRequestProcessor.php
  - api/src/Service/PipelineRunner.php
  - api/config/packages/rate_limiter.yaml
  - api/tests/Integration/PreviewEndpointTest.php
  - api/tests/Integration/PreviewRateLimitTest.php
  - api/tests/Integration/PreviewBypassCacheTest.php
autonomous: true
requirements: [EDITOR-04, EDITOR-05]
must_haves:
  truths:
    - "POST /api/asset_transformations/preview retourne 200 + binaire image/* + Cache-Control: no-store + X-Robots-Tag: noindex pour un user JWT (ROLE_USER)"
    - "L'endpoint est rate-limité 10 req/min/user (token bucket). 11e requête retourne 429 + Retry-After"
    - "La preview NE touche PAS le cache S3 (aucun fichier créé sous transformations/) et NE prend PAS de lock Redis"
    - "Steps inline sont validés via DTO validators Phase 3 (StepParamsFactory)"
    - "Asset target !isPublic → 404 STRICT (uniforme avec route publique /t/* Phase 3 ; pas d'ownership check ce phase, out-of-scope)"
  artifacts:
    - path: "api/src/ApiResource/PreviewRequest.php"
      provides: "DTO POST /api/asset_transformations/preview (hidden menu)"
      contains: "class PreviewRequest"
    - path: "api/src/State/PreviewRequestProcessor.php"
      provides: "Processor : rate-limit + auth + bypass-cache PipelineRunner + stream binaire"
      contains: "class PreviewRequestProcessor implements ProcessorInterface"
    - path: "api/config/packages/rate_limiter.yaml"
      provides: "framework.rate_limiter.preview_endpoint token_bucket 10/min"
      contains: "preview_endpoint"
    - path: "api/src/Service/PipelineRunner.php"
      provides: "Mode ephemeral (bypassCache=true) : pas de lecture cache, pas d'écriture S3, pas de lock"
      contains: "bypassCache"
  key_links:
    - from: "PreviewRequestProcessor"
      to: "PipelineRunner::runEphemeral (ou run(..., bypassCache: true))"
      via: "injection + appel direct"
      pattern: "bypassCache"
    - from: "PreviewRequestProcessor"
      to: "LimiterFactory $previewLimiter"
      via: "autowire par nom (preview_endpoint)"
      pattern: "previewLimiter->create"
    - from: "PreviewRequestProcessor"
      to: "StepParamsFactory (Phase 3)"
      via: "validation steps inline"
      pattern: "StepParamsFactory"
---

<objective>
Livrer la **base API** de l'expérience preview server-authoritative : DTO `PreviewRequest`, processor avec rate-limit token-bucket par user JWT, et extension du `PipelineRunner` pour un mode `bypassCache` (pas de read S3, pas de write S3, pas de Redis lock).

Purpose : EDITOR-04 (preview endpoint JWT/no-store/rate-limited) et EDITOR-05 (server-authoritative, jamais sur S3). Ces fondations sont consommées par le Plan 05 (panel PWA preview).
Output : Endpoint POST `/api/asset_transformations/preview` opérationnel, testé (200/429/no-write), prêt pour intégration PWA.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-RESEARCH.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-VALIDATION.md

# Blueprint DTO + Processor existant
@api/src/ApiResource/ChatRequest.php
@api/src/State/ChatRequestProcessor.php

# Cible à étendre (mode bypassCache)
@api/src/Service/PipelineRunner.php

# Validation steps inline (Phase 3)
@api/src/Validator/StepParamsFactory.php

<interfaces>
Pattern DTO + Processor (extrait de ChatRequest/ChatRequestProcessor) :

```php
#[ApiResource(operations: [new Post(
    uriTemplate: '/asset_transformations/preview',
    processor: PreviewRequestProcessor::class,
    security: "is_granted('ROLE_USER')",
)])]
#[MenuGroup('hidden')]
final class PreviewRequest {
    public int $assetId;
    public string $ext;        // png|jpg|jpeg|webp|avif
    public array $steps;       // [{type, params}]
}
```

PipelineRunner contract attendu après extension :

```php
public function run(array $steps, Asset $asset, string $ext, bool $bypassCache = false): string;
// Si bypassCache=true : pas de lecture cache S3, pas d'écriture S3, pas de lock Redis.
// Retourne le binaire directement.
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| client (PWA) → API /preview | JWT user présenté, steps inline non persistés, binaire renvoyé |
| API → embedder (Python) | Appels HTTP internes via RetryableHttpClient (déjà mitigé Phase 3) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-01 | DoS | PreviewRequestProcessor | mitigate | RateLimiter token_bucket 10/min/user, retour 429 + Retry-After (per D-10) |
| T-05-02 | Tampering (SSRF) | step add_background type:asset inline | mitigate | StepParamsFactory (Phase 3) accepte uniquement assetId numérique, jamais d'URL |
| T-05-03 | Information Disclosure | Preview d'asset privé | mitigate | Check STRICT `$asset->isPublic()` AVANT render — si `false` → 404 (aligné route publique /t/* Phase 3). PAS d'ownership check ce phase (out-of-scope ; follow-up potentiel tracké STATE.md). |
| T-05-04 | Tampering (cache poisoning) | Pipeline bypass | mitigate | bypassCache=true : aucune écriture S3, lock Redis non pris (testé via PreviewBypassCacheTest) |
| T-05-05 | Spoofing | JWT replay | accept | JWT TTL standard + rate-limit par user identifier limite l'impact |
| T-05-06 | Information Disclosure | Preview persistée dans CDN/proxy | mitigate | Cache-Control: no-store + X-Robots-Tag: noindex (per D-09) |
| T-05-07 | Input Validation | Ext invalide → mauvais Content-Type | mitigate | Allowlist `[png, jpg, jpeg, webp, avif]` validée dans DTO Asserts |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 — Tests + rate_limiter.yaml + PipelineRunner bypassCache</name>
  <files>api/config/packages/rate_limiter.yaml, api/src/Service/PipelineRunner.php, api/tests/Integration/PreviewEndpointTest.php, api/tests/Integration/PreviewRateLimitTest.php, api/tests/Integration/PreviewBypassCacheTest.php</files>
  <behavior>
    - PreviewEndpointTest::testPostReturns200WithBinaryAndNoStore() — POST avec JWT valide + steps valides → 200, Content-Type image/png, Cache-Control: no-store, X-Robots-Tag: noindex
    - PreviewEndpointTest::testPostUnauthenticatedReturns401() — sans JWT → 401
    - PreviewEndpointTest::testNonPublicAssetReturns404() — asset.isPublic=false → 404 STRICT (T-05-03 ; pas 403 pour rester uniforme avec /t/*)
    - PreviewRateLimitTest::testEleventhRequestReturns429() — 11 requêtes en 60s → la 11e renvoie 429 + Retry-After
    - PreviewBypassCacheTest::testNoS3WriteUnderTransformationsPrefix() — après POST preview, `Flysystem::listContents('transformations/', deep:true)` n'augmente pas
    - PipelineRunner::run() avec `bypassCache:true` n'appelle PAS `lockFactory->createLock()` ni `storage->write()` (assert via mock)
  </behavior>
  <action>
    1. Créer `api/config/packages/rate_limiter.yaml` (per D-10) :
       ```yaml
       framework:
         rate_limiter:
           preview_endpoint:
             policy: 'token_bucket'
             limit: 10
             rate: { interval: '1 minute', amount: 10 }
       ```
    2. Étendre `PipelineRunner` — ajouter paramètre `bool $bypassCache = false` à la méthode `run()`. Quand true : (a) ne calcule pas de storageKey pour read, (b) ne prend pas le lock Redis, (c) exécute les step handlers séquentiellement avec cap 8s identique, (d) retourne le binaire sans écriture S3. Aucun refactor lourd : early-return avant les checks cache/lock. Référence T-05-04.
    3. Stubs PHPUnit pour les 3 tests d'intégration (Wave 0 pre-RED) : signatures + xfail (`markTestIncomplete('Wave 0 stub — Task 2 implements')`) pour PreviewEndpointTest et PreviewRateLimitTest. PreviewBypassCacheTest peut déjà passer en testant uniquement PipelineRunner avec bypassCache=true (sans HTTP).
    4. Pas de commit séparé Wave 0 — combiné avec Task 2 pour atomicité du PR.
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="PreviewBypassCacheTest|PipelineRunner" -x</automated>
  </verify>
  <done>
    - rate_limiter.yaml committé, `php bin/console debug:autowiring preview` liste `LimiterFactory $previewLimiter`
    - PipelineRunner::run accepte `bypassCache:bool` (unit test green : aucune écriture Flysystem, aucun lock pris)
    - 3 fichiers de test stub présents (2 incomplete, 1 green sur bypass)
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: PreviewRequest DTO + PreviewRequestProcessor (rate-limit + stream binaire + isPublic STRICT)</name>
  <files>api/src/ApiResource/PreviewRequest.php, api/src/State/PreviewRequestProcessor.php, api/tests/Integration/PreviewEndpointTest.php, api/tests/Integration/PreviewRateLimitTest.php</files>
  <behavior>
    - PreviewEndpointTest passe au vert (200 + binaire + no-store + noindex)
    - PreviewRateLimitTest passe au vert (429 + Retry-After après 10 hits/min)
    - DTO validators : assetId int>0, ext in allowlist `[png,jpg,jpeg,webp,avif]`, steps array non vide, chaque step validé via StepParamsFactory
    - Asset !isPublic → 404 STRICT (uniforme avec route publique /t/* Phase 3 ; pas d'ownership check)
  </behavior>
  <action>
    1. Créer `PreviewRequest` (per D-08) avec `#[ApiResource]` + `#[MenuGroup('hidden')]` + `#[Post(security: "is_granted('ROLE_USER')", processor: PreviewRequestProcessor::class)]`. Propriétés publiques `int $assetId`, `string $ext`, `array $steps` avec `#[Groups(['preview:write'])]` + Assert\NotBlank + Assert\Choice ext.
    2. Créer `PreviewRequestProcessor implements ProcessorInterface` injectant : `PipelineRunner`, `AssetRepository`, `LimiterFactory $previewLimiter` (autowire par nom, per D-10), `Security`, `StepParamsFactory`. Logique :
       - `$user = $security->getUser()` → identifier
       - `$limit = $previewLimiter->create($user->getUserIdentifier())->consume(1)` ; si `!isAccepted()` → return new Response('', 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())])
       - `$asset = $assets->find($data->assetId)` ; si null → 404
       - **WARNING #3 plan-checker : check STRICT `isPublic`** — si `!$asset->isPublic()` → 404 (T-05-03). Aligné avec la route publique `/t/*` de Phase 3 : un asset non public ne peut être prévisualisé via `/api/asset_transformations/preview`. **PAS de check ownership** ce phase ; out-of-scope ; tracker en STATE.md comme follow-up potentiel (« autoriser preview d'assets non publics pour leur owner »).
       - foreach $data->steps : `$stepParamsFactory->build($step['type'], $step['params'])` → propage 422 si invalide
       - `$binary = $runner->run($validatedSteps, $asset, $data->ext, bypassCache: true)`
       - return new Response($binary, 200, ['Content-Type' => "image/{$data->ext}", 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'])
    3. Implémenter les tests d'intégration restants (PreviewEndpointTest + PreviewRateLimitTest) en utilisant `ApiTestCase` Symfony + JWT fixture (cf. Phase 3 test pattern). Ajouter `testNonPublicAssetReturns404` (T-05-03) avec fixture asset isPublic=false.
    4. Lancer `make generate-types` pour exposer `PreviewRequest` dans `pwa/src/types/api.d.ts` (consommé Plan 05).
    5. **Suivi follow-up** : créer une entrée dans `.planning/STATE.md` (section follow-ups) « Phase 5 — out-of-scope : preview d'asset non public par son owner (ownership check). Aligné actuellement sur route /t/* (404 strict). À revoir si demande métier explicite. »
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="PreviewEndpointTest|PreviewRateLimitTest|PreviewBypassCacheTest"</automated>
  </verify>
  <done>
    - POST /api/asset_transformations/preview opérationnel via curl avec JWT (200 + binary)
    - 11e requête en 60s → 429 + Retry-After
    - Asset !isPublic → 404 strict (test green)
    - Aucune écriture sous `transformations/` durant les tests
    - `pwa/src/types/api.d.ts` régénéré contient le schéma `PreviewRequest`
    - STATE.md follow-up ownership tracké
  </done>
</task>

</tasks>

<verification>
- `docker compose exec api ./vendor/bin/phpunit --filter=Preview` : 3 fichiers tests green
- `docker compose exec api php bin/console debug:autowiring preview` liste `LimiterFactory $previewLimiter`
- Manual smoke : `curl -X POST /api/asset_transformations/preview -H "Authorization: Bearer $JWT" -d '{"assetId":1,"ext":"png","steps":[{"type":"resize","params":{"width":256}}]}'` → image binaire
</verification>

<success_criteria>
EDITOR-04 et EDITOR-05 livrés au niveau API. PipelineRunner.bypassCache prêt pour consommation Plan 05 PWA. Aucune régression sur la route publique `/t/*` (Phase 3). Politique isPublic strict alignée avec /t/* ; ownership check tracké comme follow-up STATE.
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-01-SUMMARY.md`
</output>
