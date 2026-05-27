---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
fixed_at: 2026-05-27T00:00:00Z
review_path: .planning/phases/03-php-orchestrator-public-route-cache-lock-sync-only/03-REVIEW.md
iteration: 1
findings_in_scope: 6
fixed: 6
skipped: 0
status: all_fixed
---

# Phase 03 : Rapport de correction de revue

**Fixed at:** 2026-05-27
**Source review:** .planning/phases/03-php-orchestrator-public-route-cache-lock-sync-only/03-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 6 (1 Critical + 5 Warnings ; 6 Info hors scope)
- Fixed: 6
- Skipped: 0

## Fixed Issues

### CR-01 : Secret JWT engagé dans `.env` (versionné)

**Files modified:** `api/.env`, `api/.env.local`
**Commit:** ba900c8
**Applied fix:** `JWT_PASSPHRASE` vidé dans `api/.env` (versionné) et déplacé dans `api/.env.local` (gitignored) avec un commentaire indiquant la rotation via `secrets:set` ou AWS Secrets Manager. Le setup dev reste fonctionnel (la passphrase existe encore localement), mais la valeur n'est plus poussée sur git. Note pour la prod : rotation des clés JWT (private/public.pem) requise si elles ont jamais été versionnées.

### WR-01 : TTL du lock < pire cas wall-clock

**Files modified:** `api/src/Controller/PublicTransformationController.php`
**Commit:** 5aef3ef
**Applied fix:** Injection de `transformations.hard_cap_ms` dans le contrôleur via `Autowire(param: ...)` avec défaut 8000 ms (compat tests). Le TTL du lock devient `hardCapMs/1000 + 10s` (typiquement 18 s), couvrant le cap pipeline + marge I/O S3. Évite l'expiration de la clé Redis mid-génération qui laisserait passer un second worker.

### WR-02 : `CropStepParams` n'exige pas `aspectRatio` avec `anchor` seul

**Files modified:** `api/src/Service/AssetTransformation/StepParams/CropStepParams.php`
**Commit:** 4f7ce17
**Applied fix:** Ajout d'une violation explicite dans `validateExactlyOneShape` : si la branche aspect est détectée (anchor présent) mais `aspectRatio` absent, on remonte « aspect crop requires "aspectRatio". ». Défense en profondeur restaurée : l'embedder ne reçoit plus de payload aspect-only invalide.

### WR-03 : Waiter busy-wait `usleep(250ms)` (DoS amplifier PHP-FPM)

**Files modified:** `api/src/Controller/PublicTransformationController.php`
**Commit:** c5070ee
**Applied fix:** Choix Option 1 du rapport : remplacement de la boucle `while (deadline) { usleep + cache->has }` par une réponse immédiate `503 + Retry-After: 2` quand `acquire(false)` échoue. Plus de slots PHP-FPM gelés ; le CDN/client refera la requête. Note : requires human verification — les tests d'intégration `ConcurrencyLockTest::testWaiterReceives503WhenCacheStaysCold` et le test unit `testCacheMissAcquireFailsAndCacheNeverAppearsReturns503` attendent un 503 final donc devraient passer ; à confirmer par lancement de la suite.

### WR-04 : `VariantCache::write` force `visibility: public` (fuite après bascule isPublic=false)

**Files modified:** `api/src/Service/AssetTransformation/VariantCache.php`
**Commit:** f5d0df7
**Applied fix:** Suppression de `'visibility' => 'public'` dans l'option d'écriture Flysystem. Les variants sont désormais privés sur S3 ; ils restent accessibles uniquement via le contrôleur applicatif qui honore `Asset.isPublic`. Une bascule `isPublic` à `false` ferme immédiatement l'accès. Note : si demain un déploiement CDN-front-of-S3 est ciblé, prévoir un mécanisme de purge variants (similaire à `PurgeTransformationVariantsMessage`) côté `isPublic` toggle.

### WR-05 : `AbstractEmbedderStepHandler::run` perd `TransportExceptionInterface` sur `getContent()`

**Files modified:** `api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php`
**Commit:** 36a2e28
**Applied fix:** Bloc `try { request + status + getContent(false) + getHeaders + getContent }` puis `catch (TransportExceptionInterface)` qui wrap uniformément en `CODE_EMBEDDER_ERROR`. La construction du message d'erreur 4xx/5xx (qui appelle `getContent(false)`) et la lecture du corps succès sont maintenant protégées. Le commentaire dans le catch explique le périmètre.

---

_Fixed: 2026-05-27_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
