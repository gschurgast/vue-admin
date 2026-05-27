---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
reviewed: 2026-05-27T00:00:00Z
depth: standard
files_reviewed: 33
files_reviewed_list:
  - api/.env
  - api/composer.json
  - api/config/packages/nelmio_cors.yaml
  - api/config/packages/security.yaml
  - api/config/routes/transformations.yaml
  - api/config/services.yaml
  - api/migrations/Version20260527000001.php
  - api/src/Controller/PublicTransformationController.php
  - api/src/Entity/Asset/Asset.php
  - api/src/Entity/AssetTransformation/AssetTransformation.php
  - api/src/EventListener/TransformationHashListener.php
  - api/src/EventListener/TransformationStepValidationListener.php
  - api/src/Service/AssetTransformation/PipelineResult.php
  - api/src/Service/AssetTransformation/PipelineRunner.php
  - api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/AddBackgroundStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/CropStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/FormatConvertStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/HandlerResult.php
  - api/src/Service/AssetTransformation/StepHandler/ResizeStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/RotateStepHandler.php
  - api/src/Service/AssetTransformation/StepHandler/StepHandlerInterface.php
  - api/src/Service/AssetTransformation/StepParams/AddBackgroundStepParams.php
  - api/src/Service/AssetTransformation/StepParams/CropStepParams.php
  - api/src/Service/AssetTransformation/StepParams/FormatConvertStepParams.php
  - api/src/Service/AssetTransformation/StepParams/ResizeStepParams.php
  - api/src/Service/AssetTransformation/StepParams/RotateStepParams.php
  - api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php
  - api/src/Service/AssetTransformation/StepParams/UnsupportedStepTypeException.php
  - api/src/Service/AssetTransformation/TransformationLookup.php
  - api/src/Service/AssetTransformation/TransformationPipelineException.php
  - api/src/Service/AssetTransformation/VariantCache.php
  - api/tests/Integration/AssetTransformation/WarningsDerivationTest.php
  - api/tests/Integration/EventListener/TransformationHashListenerTest.php
  - api/tests/Integration/Transformation/ConcurrencyLockTest.php
  - api/tests/Unit/Controller/PublicTransformationControllerTest.php
  - api/tests/Unit/Service/AssetTransformation/PipelineRunnerTest.php
  - api/tests/Unit/Service/AssetTransformation/StepHandler/HandlersHttpTest.php
  - api/tests/Unit/Service/AssetTransformation/StepParams/StepParamsFactoryTest.php
  - api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php
findings:
  critical: 1
  warning: 5
  info: 6
  total: 12
status: issues_found
---

# Phase 03 : Rapport de revue de code

**Reviewed:** 2026-05-27
**Depth:** standard
**Files Reviewed:** 33 (sources + config + tests + migration)
**Status:** issues_found

## Résumé

La Phase 03 met en place l'orchestrateur PHP synchrone (PipelineRunner + StepHandlers HTTP scopés à l'embedder), la route publique `GET /t/{code}/{id}.{ext}` derrière un feature flag, un cache S3 versionné, et un lock Redis pour la mutualisation cold-cache.

Points forts :

- Garde-fous SSRF solides : `ScopingHttpClient` borné à `EMBEDDER_BASE_URL`, et DTOs `AddBackgroundStepParams` qui rejettent tout champ `url` via `ALLOW_EXTRA_ATTRIBUTES => false`.
- IDOR mitigé par la convention « tout rejet = 404 » dans `TransformationLookup` (couvert par 5 tests).
- Validation par DTO + Doctrine listener qui couvre les chemins API/fixtures/console.
- W5 (release du lock AVANT le streaming) bien testé.
- Cache `Cache-Control: public, max-age=31536000, immutable` + ETag déterministe correctement implémentés.
- `versionHash` purge bien dispatchée à la deletion (Pitfall C) et sur changement.

Issues notables : un secret JWT commit dans `.env` (Critical), un risque de double-génération si le pipeline dépasse le TTL du lock (Warning), un busy-wait du waiter qui peut amplifier la pression sur PHP-FPM (Warning), une faille de validation `CropStepParams` (Warning), des variants S3 marqués `visibility: public` qui restent accessibles même après bascule de `isPublic=false` sur l'asset source (Warning), et un trou de log dans `AbstractEmbedderStepHandler::run` sur `getContent()` (Warning).

Rappel cadrage : avant industrialisation ou déploiement prod de cette route, ce cas d'usage doit être validé par la Webfacto (cadrage besoin, faisabilité, sécurité, priorisation).

## Critical Issues

### CR-01 : Secret JWT engagé dans `.env` (versionné)

**File:** `api/.env:45`
**Issue:** `JWT_PASSPHRASE=dd482d92b4d349eba5cdf19127538252c2a6b33b50a047418f0bc818fe0e08df` est un secret 32 bytes hex hardcodé dans un fichier versionné. Même si `.env` documente « DO NOT DEFINE PRODUCTION SECRETS », le passphrase actuel est exploitable contre n'importe quel environnement utilisant les clés JWT générées avec lui. Combiné à `JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem`, un attaquant lisant le repo peut signer des JWT valides si la clé privée fuite (ou si elle est aussi versionnée).
**Fix:**
```dotenv
# .env (committed)
JWT_PASSPHRASE=
```
Puis dans `.env.local` (gitignored) ou via Symfony secrets/Vault/AWS Secrets Manager :
```bash
php bin/console secrets:set JWT_PASSPHRASE
# ou : openssl rand -hex 32 > /tmp/passphrase && set in env CI/CD
```
Vérifier également que `config/jwt/*.pem` est dans `.gitignore` et faire tourner les clés (re-générer privée/publique) si elles ont jamais touché git.

## Warnings

### WR-01 : Le TTL du lock (10 s) est inférieur au pire cas wall-clock (cap 8 s + I/O S3 + retries)

**File:** `api/src/Controller/PublicTransformationController.php:97`
**Issue:** Le lock est créé avec `ttl: 10.0` et `autoRelease: true`, mais le worker qui le détient peut consommer jusqu'à `hardCapMs=8000` ms pour la pipeline, plus le `cache->write()` vers S3 (peut dépasser 1-2 s en prod avec une variante de plusieurs Mo et la couche réseau). Si la durée totale franchit 10 s, Redis expire la clé, un second requérant acquire(false) → true et lance une seconde génération concurrente. Coût : double appel embedder + risque d'écrasement S3.
**Fix:**
- Faire dépendre le TTL du hard cap + une marge I/O explicite :
```php
$lockTtlSeconds = ($this->hardCapMs / 1000.0) + 10.0; // 8 + 10 = 18 s
$lock = $this->lockFactory->createLock('lock:tx:'.$storageKey, ttl: $lockTtlSeconds, autoRelease: true);
```
- Et/ou rafraîchir le lock entre `runner->run()` et `cache->write()` via `$lock->refresh()` si la première étape a consommé beaucoup de budget.

### WR-02 : `CropStepParams::validateExactlyOneShape` n'exige pas `aspectRatio` quand seul `anchor` est fourni

**File:** `api/src/Service/AssetTransformation/StepParams/CropStepParams.php:36-66`
**Issue:** Le branch « aspect shape » est détecté par `$hasAspect = $this->aspectRatio !== null || $this->anchor !== null;`. Un payload `{"anchor": "center"}` (sans `aspectRatio`) passe `$hasAspect = true`, ne tombe pas dans « both shapes » ni « no shape », et n'a aucune contrainte « aspectRatio required » → DTO validé. Le step partira à l'embedder qui devra rejeter en 422. Défense en profondeur cassée et fail tardif.
**Fix:**
```php
if ($hasAspect && $this->aspectRatio === null) {
    $ctx->buildViolation('aspect crop requires "aspectRatio".')
        ->atPath('aspectRatio')
        ->addViolation();
}
```

### WR-03 : Le waiter loop fait un busy-wait `usleep(250 ms)` qui peut épuiser les workers PHP-FPM sous contention

**File:** `api/src/Controller/PublicTransformationController.php:99-108`
**Issue:** Quand `acquire(false)` échoue, le worker reste vivant jusqu'à 5 s à boucler `usleep` + `cache->has()`. Sous un burst N requêtes concurrentes ciblant la même variante cold, N-1 workers sont gelés 5 s chacun ; sur un PHP-FPM à 16-32 workers, c'est un DoS amplifier (les utilisateurs sur d'autres URLs n'ont plus de worker). En plus, ces workers ré-interrogent S3 (`cache->has()`) jusqu'à 20 fois chacun = pression sur Flysystem/S3.
**Fix:** Option 1 — répondre immédiatement 503 + Retry-After plutôt qu'attendre :
```php
if (!$lock->acquire(false)) {
    return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '2']);
}
```
Option 2 (compromis) — réduire le deadline à 1-2 s et le pas à 100 ms ; le CDN/client refera la requête.
Option 3 (mieux) — utiliser `acquire(true)` avec un timeout court côté Redis pour libérer dès que le générateur publie, plutôt qu'un sondage S3.

### WR-04 : `VariantCache::write` force `visibility: 'public'` — fuite des variants après bascule `isPublic=false`

**File:** `api/src/Service/AssetTransformation/VariantCache.php:33-39`
**Issue:** Le S3 Flysystem adapter traduit `'visibility' => 'public'` en `ACL: public-read`. La route applicative honore `Asset.isPublic`, mais l'objet S3 reste accessible via son URL S3 publique directe. Si un admin passe `isPublic` de `true` à `false` (par ex. pour cause de droit d'auteur ou contenu sensible), tous les variants déjà calculés restent téléchargeables sur S3.
**Fix:** Soit ne pas forcer la visibilité publique et laisser le contrôleur streamer (déjà ce qui est fait via `streamFromCache`) :
```php
$this->storage->write($key, $bytes, ['ContentType' => $contentType]);
```
Soit, si l'objectif est CDN-front-of-S3 (variants servis directement par CloudFront sur le bucket), prévoir un mécanisme de purge variants déclenché par le passage de `isPublic` à `false` (similaire à `PurgeTransformationVariantsMessage`).

### WR-05 : `AbstractEmbedderStepHandler::run` ne capture pas `TransportExceptionInterface` autour de `getContent()`

**File:** `api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php:62, 73`
**Issue:** Le `try/catch (TransportExceptionInterface)` n'entoure que `getStatusCode()`. Or `$response->getContent(false)` (ligne 62, dans la branche 4xx/5xx pour construire le message d'erreur) et `$response->getContent()` (ligne 73, succès) peuvent eux-mêmes lever `TransportExceptionInterface` (timeout au moment du buffering du corps, RST côté serveur après les headers). Si ça arrive, l'exception remonte non-typée jusqu'au `PipelineRunner::run` qui la re-wrap en `CODE_EMBEDDER_ERROR` — donc fonctionnellement OK, mais le log de l'AbstractEmbedderStepHandler avec l'URL+endpoint est perdu. Pour la cohérence des erreurs (et un meilleur message), wrapper le bloc complet :
**Fix:**
```php
try {
    $response = $this->embedderClient->request(...);
    $status = $response->getStatusCode();
    if ($status >= 400) {
        $body = $response->getContent(false);
        throw new TransformationPipelineException(
            sprintf('embedder %s → %d: %s', $path, $status, $body),
            TransformationPipelineException::CODE_EMBEDDER_ERROR,
        );
    }
    $rh = $response->getHeaders(false);
    $bytes = $response->getContent();
} catch (TransportExceptionInterface $e) {
    throw new TransformationPipelineException(
        sprintf('embedder %s transport error after retries: %s', $path, $e->getMessage()),
        TransformationPipelineException::CODE_EMBEDDER_ERROR,
        $e,
    );
}
```

## Info

### IN-01 : `PipelineRunner::run` contient un `catch (TransportExceptionInterface)` mort

**File:** `api/src/Service/AssetTransformation/PipelineRunner.php:124-132`
**Issue:** `$handler->run(...)` (impl. `AbstractEmbedderStepHandler`) traduit déjà `TransportExceptionInterface` en `TransformationPipelineException`. Le `catch` ici n'est jamais atteint en production avec les handlers actuels — code mort en pratique, sauf pour de futurs handlers non-HTTP qui propageraient l'exception. Soit le laisser comme filet de sécurité (commenter pourquoi), soit le retirer pour réduire la confusion.
**Fix:** Ajouter un commentaire « defense in depth » ou supprimer le `catch`.

### IN-02 : Empty `versionHash` peut faire collisionner les `storageKey` et ETag

**File:** `api/src/Controller/PublicTransformationController.php:76-84`
**Issue:** `$versionHash === '' ? str_repeat('0', 40) : $versionHash;` — si une transformation arrive en base avec un versionHash NULL (n'arrive en théorie qu'en cas de bug du listener), tous ces cas partagent la même clé S3 et la même ETag. Cela ne devrait jamais se produire mais le silent fallback masque le problème.
**Fix:** Lever un 500 ou logger un warning explicite plutôt qu'un placeholder déterministe :
```php
if ($versionHash === '') {
    $this->logger->error('versionHash empty on tx', ['txId' => $tx->getId()]);
    return $this->notFoundShort();
}
```

### IN-03 : Aucun garde sur la taille du payload envoyé à l'embedder

**File:** `api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php:35-50`
**Issue:** `$bytes` (asset original ou sortie du step précédent) est envoyé sans cap explicite. Un asset uploadé légitimement à `maxSize()` (cf. `AssetType`) peut atteindre plusieurs Mo voire centaines de Mo selon le type. Au mieux l'embedder rejette en 413, au pire la requête HTTP timeout. Plan 02 a déjà des caps côté upload, donc défense en profondeur seulement.
**Fix:** Ajouter une assertion défensive `assert(strlen($bytes) < 64 * 1024 * 1024)` ou un check explicite avec log si > seuil, dans `AbstractEmbedderStepHandler::run`.

### IN-04 : Légère asymétrie d'ordre dans `TransformationLookup::findOr404` — micro-oracle de timing

**File:** `api/src/Service/AssetTransformation/TransformationLookup.php:33-58`
**Issue:** L'ordre est : (1) lookup tx par code, (2) scan async-step sur tx.steps, (3) lookup asset, (4) check isPublic. Un attaquant peut distinguer par timing :
- code inconnu → 1 requête DB
- code connu + step AI → 1 requête + iterate steps
- code connu sans AI + asset inconnu → 2 requêtes
- code connu sans AI + asset private → 2 requêtes + load isPublic
Pas exploitable en pratique (différence sub-ms), mais à mentionner. Pour T-03-11 strict, on pourrait toujours faire les 2 lookups avant de décider.
**Fix:** Optionnel — uniformiser l'ordre :
```php
$tx = $repo->findOneBy(['code' => $code]);
$asset = $this->em->find(Asset::class, $assetId);
if (!$tx instanceof AssetTransformation || !$asset instanceof Asset) {
    throw new NotFoundHttpException();
}
// puis les checks AI step + isPublic ensemble
```

### IN-05 : `TransformationStepValidationListener::preUpdate` valide même si seuls des champs non-params ont changé

**File:** `api/src/EventListener/TransformationStepValidationListener.php:38-50`
**Issue:** À chaque `preUpdate` sur n'importe quel champ de `TransformationStep` (par ex. juste la `position`), le listener relance `StepParamsFactory::fromStep` qui denormalize + valide. Pas un bug (la validation reste vraie), mais c'est du travail dupliqué si seul `position` change. Optimisation : tester `$args->hasChangedField('params')` avant de revalider.
**Fix:**
```php
public function preUpdate(PreUpdateEventArgs $args): void
{
    if (!$args->hasChangedField('params') && !$args->hasChangedField('type')) {
        return;
    }
    $this->validate($args->getObject());
}
```

### IN-06 : `Cross-Origin-Resource-Policy: cross-origin` est défini sur le 304 aussi — vérifier la conformité CDN

**File:** `api/src/Controller/PublicTransformationController.php:179-195`
**Issue:** `withCommonHeaders` est appelée pour les `Response::HTTP_NOT_MODIFIED`, qui rajoute `Content-Type`, `Cross-Origin-Resource-Policy`, `Cache-Control`. RFC 7232 §4.1 dit qu'un 304 ne DOIT renvoyer que certains headers (`ETag`, `Cache-Control`, `Vary`...) ; `Content-Type` sur un 304 est ignoré mais inoffensif. À vérifier sur le CDN cible (CloudFront strip Content-Type sur 304 en général). Non bloquant.
**Fix:** Pas d'action requise sauf si le CDN se plaint ; documenter la décision dans le code.

---

_Reviewed: 2026-05-27_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
