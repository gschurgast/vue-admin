---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
verified: 2026-05-27T00:00:00Z
status: human_needed
score: 16/16 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Smoke E2E réel : démarrer la stack (docker compose up -d), uploader un asset (POST /api/assets/upload), créer une AssetTransformation [resize{w:800}, format_convert{webp}], passer Asset.isPublic=true, puis curl GET /t/{code}/{id}.webp"
    expected: "HTTP 200 + body binaire WebP + headers Cache-Control: public, max-age=31536000, immutable + ETag déterministe + Cross-Origin-Resource-Policy: cross-origin"
    why_human: "Vérifie l'intégration bout-en-bout PHP↔embedder en runtime FrankenPHP (les tests phpunit utilisent MockHttpClient ; jamais d'HTTP réel sur embedder:8000)"
  - test: "2ème requête immédiate sur la même URL puis 3ème avec If-None-Match: \"{ETag}\""
    expected: "2ème : 200 servie depuis S3/cache (compteur embedder inchangé, latence < 50ms). 3ème : 304 sans body."
    why_human: "SC2 (cache hit) et D-19 (ETag déterministe) — confirmation comportementale via HTTP réel"
  - test: "Bascule TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0 + docker compose restart api ; curl /t/{code}/{id}.webp"
    expected: "HTTP 404 immédiat + Cache-Control: public, max-age=300 ; logs Doctrine ne montrent AUCUNE requête SQL pour asset_transformation"
    why_human: "SC5 (feature flag) — vérifier que la première instruction du controller court-circuite avant tout accès DB en conditions réelles"
  - test: "Charge concurrente réelle : 10 curl parallèles via xargs -P10 sur une variante froide nouvellement créée"
    expected: "Exactement 1 fichier S3 écrit, embedder logs montrent 1 seul appel par endpoint, les 9 autres requêtes reçoivent soit 200 (post-release) soit 503 + Retry-After: 2"
    why_human: "SC3 (lock anti-thundering-herd) — le test phpunit ConcurrencyLockTest utilise un Lock stub (Voie B) ; la garantie inter-process via Redis n'est prouvée qu'en runtime réel"
  - test: "Vérifier que les variants écrits en S3 ne sont PAS publics (visibility par défaut)"
    expected: "aws s3api get-object-acl montre uniquement le bucket-owner ; un GET direct sur l'URL S3 sans signature retourne 403"
    why_human: "WR-04 (fix appliqué) — confirmer en prod-like que la suppression de visibility:public protège bien contre la fuite après bascule isPublic=false"
  - test: "CORS preflight : curl -X OPTIONS -H 'Origin: https://example.com' -H 'Access-Control-Request-Method: GET' /t/{code}/{id}.webp"
    expected: "200 + Access-Control-Allow-Origin: * + Access-Control-Allow-Methods inclut GET + Access-Control-Expose-Headers inclut ETag, X-Transformation-Warnings"
    why_human: "ROUTE-10 / SC5 — comportement preflight nelmio_cors à confirmer en runtime (l'OOS note un héritage Allow-Headers du bloc ^/ à nettoyer si preflight strict requis)"
  - test: "Header X-Transformation-Warnings : créer une transformation [resize{w:800}, format_convert{format:'jpg'}] sans add_background ; curl /t/{code}/{id}.jpg"
    expected: "200 + X-Transformation-Warnings: alpha-flatten-on-jpeg"
    why_human: "HANDLERS-05 — couvert par WarningsDerivationTest pour la persistance, mais l'exposition runtime via header HTTP n'est validée que via le test unit du controller (mock storage). Confirmation E2E recommandée."
  - test: "Webfacto gating : valider le cas d'usage avant industrialisation / mise en production de la route /t/*"
    expected: "Cadrage besoin, faisabilité, sécurité, priorisation validés par la Webfacto (cf. CR-01 rotation JWT, dimensionnement S3, CDN, rate-limit, TTL 404)"
    why_human: "Exigence d'organisation Vente-Unique pour toute exposition publique en prod"
---

# Phase 3: PHP Orchestrator + Public Route + Cache + Lock (sync-only) — Verification Report

**Phase Goal:** Câbler le PHP comme orchestrateur thin des endpoints Python (handlers + DTOs validators), exposer la route publique `/t/{code}/{id}.{ext}` derrière feature flag, avec cache S3 versionné, lock anti-thundering-herd et headers immutables — uniquement pour des transformations **sans step AI**.

**Verified:** 2026-05-27
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| #  | Truth | Status | Evidence |
|----|-------|--------|----------|
| 1  | GET /t/{code}/{id}.{ext} sur asset isPublic + transformation non-AI retourne 200 + image transformée + Cache-Control immutable + ETag | VERIFIED | PublicTransformationController.php:headers (8 patterns matchés : Cache-Control immutable, COOP cross-origin, lock:tx:, If-None-Match, X-Transformation-Warnings) ; route déclarée routes/transformations.yaml ; 12 tests unit verts ; SC1 ROADMAP |
| 2  | 2ème requête servie depuis S3 sans relancer PipelineRunner (compteur embedder = 0 sur 2ème hit) | VERIFIED | ConcurrencyLockTest::testSecondRequestServedFromCacheWithoutRunner (compteur == 1 sur 2 requêtes, ETag identique) ; PublicTransformationControllerTest cache-hit branch ; SC2 ROADMAP |
| 3  | Sous N concurrentes : 1 seule génération exécutée (Redis lock), waiters reçoivent 503 + Retry-After ou lisent depuis S3 | VERIFIED (programmatique) | ConcurrencyLockTest::testConcurrentColdRequestsGenerateOnce (Voie B Lock stub, == 1 strict sur 5 requêtes) ; SC3 ROADMAP. Validation runtime inter-process → human verification |
| 4  | Feature flag transformations.public_route.enabled=false → route 404 immédiat sans matching DB | VERIFIED | PublicTransformationController serve() check première instruction ; Test "feature flag off (no DB/runner)" vert ; param bindé via #[Autowire(param:)] |
| 5  | CORS : Access-Control-Allow-Origin: * + Cross-Origin-Resource-Policy: cross-origin sur /t/* | VERIFIED | nelmio_cors.yaml `^/t/`: allow_origin ['*'] + expose_headers [ETag, X-Transformation-Warnings] ; controller injecte Cross-Origin-Resource-Policy: cross-origin |
| 6  | Tous les rejets (asset non public, code inconnu, requires_async, extension invalide, flag off) retournent 404 — jamais 403 | VERIFIED | TransformationLookup throws NotFoundHttpException dans les 5 cas ; controller grep 0 occurrence AccessDenied/403 ; 7 tests TransformationLookupTest verts (tous NotFound) |
| 7  | If-None-Match correspondant à l'ETag déterministe → 304 sans relire S3 | VERIFIED | controller branche If-None-Match early-return + test "If-None-Match → 304 sans S3 ni runner" vert ; ETag déterministe "{txId}-v{hash8}-{assetId}-{ext}" |
| 8  | PipelineRunner exécute séquentiellement les handlers résolus par StepType | VERIFIED | PipelineRunner.php boucle foreach + handlersByType map ; testRunsStepsInOrderAndReturnsFinalBytes vert |
| 9  | Chaque handler appelle l'endpoint embedder via embedder.client (Retryable 3 retries 5xx+timeout only) | VERIFIED | services.yaml: ScopingHttpClient + RetryableHttpClient + GenericRetryStrategy (status 0/423/425/429/500/502/503/504/507/510 ; aucun 4xx) ; 6 tests HandlersHttpTest verts incl. retry-on-503 et no-retry-on-422 et retry-on-timeout |
| 10 | PipelineRunner applique min(stepTimeout, remainingMs) et lève si remainingMs ≤ 0 | VERIFIED | PipelineRunner remainingMs + min() + testRemainingMsDecreasesAcrossSteps + testHardCapExceededRaisesCapException (CODE_CAP_EXCEEDED) |
| 11 | PipelineRunner append format_convert implicite si extension diffère du dernier format | VERIFIED | PipelineRunner.makeVirtualFormatConvertStep + testAppendsImplicitFormatConvertWhenOutputExtDiffers (step virtuel non-persisté → versionHash invariant) |
| 12 | Persister un TransformationStep avec params invalides lève ValidationFailedException → 422 | VERIFIED | TransformationStepValidationListener (#[AsDoctrineListener] prePersist + preUpdate) → StepParamsFactory::fromStep → throw ValidationFailedException ; 14 tests StepParamsFactoryTest verts |
| 13 | Transformation se terminant en .jpg/.jpeg sans add_background reçoit warning alpha-flatten-on-jpeg persisté | VERIFIED | TransformationHashListener.computeWarnings ; WarningsDerivationTest 5 cas verts (jpg→warning, jpg+addBg→pas de warning, webp→pas de warning, params invalides→422, isPublic roundtrip) |
| 14 | Chaque StepType (resize/crop/rotate/format_convert/add_background) résolu par StepParamsFactory retourne le DTO PHP correct avec Assert appliqué | VERIFIED | 5 readonly DTOs (ResizeStepParams, CropStepParams, RotateStepParams, FormatConvertStepParams, AddBackgroundStepParams) + StepParamsFactory match StepType + ALLOW_EXTRA_ATTRIBUTES=false ; 14 tests verts |
| 15 | Colonne Asset.is_public existe et est exposée via Asset::isPublic() (default false) | VERIFIED | Asset.php:144 `private bool $isPublic = false` + isPublic():bool getter + migration Version20260527000001 `ALTER TABLE asset ADD COLUMN is_public BOOLEAN NOT NULL DEFAULT false` |
| 16 | StepHandlerInterface taggé app.step_handler + embedder.client services configurés | VERIFIED | StepHandlerInterface avec #[AutoconfigureTag('app.step_handler')] ; 5 implémentations via AbstractEmbedderStepHandler ; services.yaml embedder.scoping + embedder.client + embedder.retry_strategy (cap 8000 ms via %transformations.hard_cap_ms%) |

**Score:** 16/16 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php | Hydrate + valide les params par StepType | VERIFIED | Présent, match StepType + denormalize ALLOW_EXTRA_ATTRIBUTES=false + ValidatorInterface.validate + throw ValidationFailedException |
| api/src/EventListener/TransformationStepValidationListener.php | Doctrine prePersist/preUpdate qui valide via StepParamsFactory | VERIFIED | `#[AsDoctrineListener]` x2 events ; appelle StepParamsFactory::fromStep |
| api/migrations/Version20260527000001.php | warnings JSONB DEFAULT '[]' + is_public BOOLEAN DEFAULT false | VERIFIED | Les 2 ALTER TABLE présents en up() ; down() symétrique |
| api/src/Service/AssetTransformation/PipelineRunner.php | Orchestrateur séquentiel avec cap wall-clock 8s | VERIFIED | Présent ; remainingMs ; min(stepTimeout, remainingMs) ; throw CAP_EXCEEDED ; format_convert implicite ; final retiré (test substitution) |
| api/src/Service/AssetTransformation/StepHandler/StepHandlerInterface.php | Interface taggée app.step_handler | VERIFIED | Présente avec `#[AutoconfigureTag('app.step_handler')]` |
| api/config/services.yaml | embedder.client + autoconfigure StepHandlerInterface | VERIFIED | embedder.scoping (ScopingHttpClient) + embedder.client (RetryableHttpClient) + embedder.retry_strategy ; lock.transformations.{redis,store,factory} ; transformations.hard_cap_ms ; 5xx-only retry |
| api/src/Controller/PublicTransformationController.php | Route publique sync-only avec lock + cache S3 + headers immutables | VERIFIED | Présent, 9 patterns clés matchés ; W5 release-before-stream respecté |
| api/src/Service/AssetTransformation/VariantCache.php | Lecture/écriture Flysystem du cache S3 versionné | VERIFIED | Wrapper FilesystemOperator (has/read/write/delete) ; visibility:public retiré (WR-04 fix) |
| api/config/routes/transformations.yaml | Route /t/{code}/{id}.{ext} hors firewall JWT | VERIFIED | Route présente avec requirements regex strictes ; methods GET, HEAD |
| api/config/packages/nelmio_cors.yaml | Path ^/t/ avec wildcard origin + COEP cross-origin | VERIFIED | Bloc `^/t/` ajouté, allow_origin ['*'], expose_headers ETag + X-Transformation-Warnings |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| TransformationStepValidationListener::prePersist | StepParamsFactory::fromStep | LifecycleEventArgs | WIRED | Appel direct dans validate() |
| TransformationHashListener | AssetTransformation::setWarnings | onFlush computeChangeSet | WIRED | setWarnings invoqué après computeWarnings |
| Asset::isPublic | TransformationLookup | Getter consommé par route publique | WIRED | TransformationLookup::findOr404 check $asset->isPublic() |
| PipelineRunner | embedder.client | HttpClientInterface injecté dans chaque StepHandler | WIRED | AbstractEmbedderStepHandler::$embedderClient ; services.yaml binding |
| StepHandlerInterface | service tag | autoconfigure tag app.step_handler | WIRED | `#[AutoconfigureTag('app.step_handler')]` sur l'interface + AutowireIterator dans PipelineRunner |
| PublicTransformationController::serve | PipelineRunner::run | Injection service + appel après lock acquired + S3 miss | WIRED | Controller invoque $this->runner->run($tx, $originalBytes, $extNorm) dans la branche cache-miss post-lock |
| PublicTransformationController::serve | TransformationStorageKey::forVariant | Construction de la clé S3 canonique | WIRED | Appel TransformationStorageKey::forVariant() pour bâtir $storageKey |
| PublicTransformationController | symfony/lock RedisStore | LockFactory::createLock('lock:tx:{storageKey}', ttl) | WIRED | grep "lock:tx:" présent ; TTL = hardCapMs/1000 + 10s (WR-01 fix) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| PublicTransformationController | $originalBytes | Flysystem $assetsStorage->read($asset->getS3Key()) | Yes (assets.storage réel, FS dev / S3 prod) | FLOWING |
| PublicTransformationController | $result->bytes | PipelineRunner->run → AbstractEmbedderStepHandler->request → embedder HTTP | Yes (chaîne HTTP scoped à embedder:8000, retry 3x) | FLOWING |
| StreamedResponse | stream cache | VariantCache->read($storageKey) → Flysystem readStream | Yes (clé canonique TransformationStorageKey::forVariant) | FLOWING |
| AssetTransformation | warnings | TransformationHashListener::computeWarnings recalculé à chaque flush | Yes (heuristique alpha-flatten-on-jpeg ; testé WarningsDerivationTest) | FLOWING |

### Behavioral Spot-Checks

SKIPPED — Suite de tests phpunit déjà exécutée (99 tests / 218 assertions verts, rapportée par l'orchestrateur). La stack docker compose n'est pas démarrée dans cette session de vérification, et le contrat embedder réel relève de la vérification humaine (Phase 2 livre les endpoints Python ; Phase 3 mocke l'HTTP en tests).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| HANDLERS-01 | 03-02-PLAN, 03-03-PLAN | PipelineRunner orchestre des StepHandlerInterface taggés | SATISFIED | PipelineRunner.php + 5 handlers ; testRunsStepsInOrderAndReturnsFinalBytes |
| HANDLERS-02 | 03-02-PLAN | Chaque handler appelle endpoint Python via RetryableHttpClient (3 retries) | SATISFIED | services.yaml embedder.client = RetryableHttpClient + retry strategy 5xx/timeout-only ; HandlersHttpTest 6 verts |
| HANDLERS-03 | 03-01-PLAN | Chaque type de step a un DTO Validator | SATISFIED | 5 readonly DTOs + StepParamsFactory + StepParamsFactoryTest 14 verts |
| HANDLERS-05 | 03-01-PLAN | Format JPEG sans add_background aval déclenche alpha-flatten + warning | SATISFIED | TransformationHashListener::computeWarnings + WarningsDerivationTest 5 verts ; X-Transformation-Warnings runtime header |
| ROUTE-01 | 03-03-PLAN | GET /t/{code}/{id}.{ext} non authentifié retourne image | SATISFIED | route public_transformation_serve + firewall public_transformations security:false |
| ROUTE-02 | 03-03-PLAN | Extension détermine format ; autres → 404 | SATISFIED | routes/transformations.yaml requirements ext: 'png\|jpe?g\|webp\|avif' (router 404 sinon) |
| ROUTE-03 | 03-03-PLAN | Cache S3 préfixe `transformations/{txId}-v{hash}/{shard}/{assetId}.{ext}` | SATISFIED | TransformationStorageKey::forVariant + VariantCache.has/read/write ; testCacheHit |
| ROUTE-04 | 03-03-PLAN | 1ère requête : Redis lock + sync cap 8s | SATISFIED | LockFactory + cap %transformations.hard_cap_ms% (8000) ; ConcurrencyLockTest |
| ROUTE-07 | 03-03-PLAN | Cache-Control: public, max-age=31536000, immutable + ETag | SATISFIED | controller withCommonHeaders ; ETag déterministe `{txId}-v{hash8}-{assetId}-{ext}` |
| ROUTE-08 | 03-01-PLAN, 03-03-PLAN | Seuls assets isPublic=true accessibles ; sinon 404 | SATISFIED | Asset.isPublic column (migration) + TransformationLookup check + test "private asset → 404" |
| ROUTE-09 | 03-03-PLAN | Feature flag transformations.public_route.enabled | SATISFIED | param + #[Autowire(param:)] + check première instruction ; test "feature flag off" |
| ROUTE-10 | 03-03-PLAN | CORS pour `<img>` cross-origin sur /t/* | SATISFIED | nelmio_cors.yaml `^/t/` + Cross-Origin-Resource-Policy: cross-origin |

**Tous les 12 REQ-IDs déclarés dans les plans sont SATISFIED.** Aligné avec REQUIREMENTS.md (12 marqués Complete pour Phase 3).

**Orphaned check** : grep "Phase 3" REQUIREMENTS.md confirme aucune REQ-ID supplémentaire non couverte par les plans. Pas d'orphelin.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| api/.env | 45 | JWT_PASSPHRASE vidé (commit ba900c8) | Info | CR-01 corrigé ; valeur déplacée en .env.local gitignored ; rotation des clés JWT requise prod (cf REVIEW-FIX) |
| api/src/Service/AssetTransformation/PipelineRunner.php | 124-132 | catch TransportExceptionInterface mort en pratique | Info | IN-01 — defense in depth, non corrigé (hors scope REVIEW) ; pas bloquant |
| api/src/Controller/PublicTransformationController.php | 76-84 | Fallback `versionHash === '' ? str_repeat('0', 40)` | Info | IN-02 — non corrigé ; safety net silencieux qui masquerait un bug du listener |
| OOS — CORS preflight Allow-Headers héritage | n/a | Allow-Headers: content-type, authorization sur preflight | Info | OOS Plan 03-03 — non nuisible (bloc `^/t/` allow_headers: []), à nettoyer si preflight strict requis |
| pre-existing | n/a | Doctrine deprecation Table.uniqueConstraints | Info | Pré-existant, non lié à Phase 3 |
| pre-existing | n/a | antigravity-worker-1 Restarting | Info | Pré-existant, pas d'écriture Messenger en Phase 3 |

Aucun blocker. Aucun stub résiduel. Tous les 6 findings du code-review (1 CR + 5 WR) ont été corrigés (REVIEW-FIX status: all_fixed).

### Human Verification Required

8 items (cf frontmatter `human_verification`) — synthèse :

1. **Smoke E2E réel** — confirmer 200 + headers sur curl HTTP réel via stack docker.
2. **2ème requête + If-None-Match** — confirmer cache hit + 304 en runtime.
3. **Feature flag OFF runtime** — vérifier qu'aucune requête SQL n'est émise quand flag off.
4. **Charge concurrente Redis réelle** — 10 curl parallèles → exactement 1 fichier S3, embedder vu une seule fois (SC3 prouvée programmatiquement via Voie B Lock stub ; runtime inter-process à confirmer).
5. **Variants S3 privés** — confirmer que les variants S3 ne sont pas world-readable après WR-04 fix.
6. **CORS preflight runtime** — confirmer Access-Control-Allow-Origin + Methods + Expose-Headers.
7. **X-Transformation-Warnings runtime** — confirmer présence du header sur pipeline jpg sans add_background.
8. **Webfacto gating** — cadrage avant industrialisation/déploiement prod (CR-01 rotation JWT, dimensionnement S3, CDN, rate-limit, TTL 404).

### Gaps Summary

Aucun gap programmatique. Les 16 truths sont VERIFIED, les 10 artifacts sont VERIFIED, les 8 key links sont WIRED, les 4 traces de données FLOWING, les 12 requirements SATISFIED. La suite de tests rapportait 99/218 verts à la fin de Phase 3.

Le statut `human_needed` reflète uniquement la nature des truths : SC1/2/3/5 et ROUTE-10/X-Transformation-Warnings demandent une confirmation comportementale via HTTP réel (la suite phpunit utilise MockHttpClient + Lock stub Voie B). Le cadrage Webfacto est également un jalon obligatoire avant industrialisation, conformément aux instructions d'organisation Vente-Unique.

---

_Verified: 2026-05-27_
_Verifier: Claude (gsd-verifier)_
