---
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
plan: 03
subsystem: api
tags: [public-route, redis-lock, s3-cache, cors, feature-flag, etag, streamed-response]
dependency_graph:
  requires:
    - Plan 03-01 Asset.isPublic column (default false) — public-route gate
    - Plan 03-01 AssetTransformation.warnings JSONB — exposed as X-Transformation-Warnings
    - Plan 03-02 PipelineRunner + 5 StepHandlers (sync execution)
    - Plan 03-02 TransformationPipelineException::CODE_* semantic codes
    - Phase 1 TransformationStorageKey::forVariant — canonical S3 key
  provides:
    - PublicTransformationController — GET /t/{code}/{id}.{ext} (sync-only)
    - VariantCache — Flysystem read/write helper for variant blobs
    - TransformationLookup::findOr404 — strict 404 gate for (code, asset, AI-step)
    - lock.transformations.factory — Redis-backed LockFactory (TTL 10s)
  affects:
    - api/config/routes/transformations.yaml (new file, /t/* hors firewall JWT)
    - api/config/packages/nelmio_cors.yaml (path ^/t/ ajouté)
    - api/config/packages/security.yaml (firewall public_transformations stateless)
    - api/config/services.yaml (lock infra + feature flag + variant services)
    - api/.env (TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=1, REDIS_HOST/PORT)
tech-stack:
  added:
    - symfony/lock v8 (RedisStore + LockFactory)
    - phpredis natif (\Redis) — distinct du Predis utilisé par ConversationService
  patterns:
    - Stateless Symfony firewall (security: false) pour endpoint public
    - Feature flag bindé par #[Autowire(param:)] + check first-instruction
    - StreamedResponse + Flysystem readStream pour dev FS / prod S3 identique
    - ETag déterministe sans recalcul binaire pour 304 If-None-Match
    - Lock release AVANT stream (W5) — couvre génération, pas streaming
key-files:
  created:
    - api/src/Controller/PublicTransformationController.php
    - api/src/Service/AssetTransformation/VariantCache.php
    - api/src/Service/AssetTransformation/TransformationLookup.php
    - api/config/routes/transformations.yaml
    - api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php
    - api/tests/Unit/Controller/PublicTransformationControllerTest.php
    - api/tests/Integration/Transformation/ConcurrencyLockTest.php
  modified:
    - api/config/packages/nelmio_cors.yaml
    - api/config/packages/security.yaml
    - api/config/services.yaml
    - api/.env
    - api/composer.json
    - api/composer.lock
    - api/src/Service/AssetTransformation/PipelineRunner.php (drop final)
decisions:
  - D-01/D-04 Redis lock via symfony/lock RedisStore — clé `lock:tx:{storageKey}`, TTL 10s, autoRelease
  - D-04 waiter behaviour — 5s loop re-check S3 puis 503 + Retry-After, AUCUNE génération concurrente
  - D-10 404 unifié sur tous les rejets (jamais 403) — T-03-11 IDOR mitigated
  - D-19 ETag déterministe "{txId}-v{hash8}-{assetId}-{ext}" identique cache miss / cache hit
  - D-22 firewall `public_transformations` stateless (security: false), pas de JWT
  - W5 lock.release() AVANT le streamFromCache (couvre génération, pas streaming)
  - Test concurrence Voie B (Lock stub) plutôt que Voie A (pcntl_fork) — fork instable dans phpunit
metrics:
  duration: "~7 minutes"
  completed: "2026-05-27"
  tasks: 3
  files_created: 7
  files_modified: 7
  tests_added: 24
requirements:
  - HANDLERS-01
  - ROUTE-01
  - ROUTE-02
  - ROUTE-03
  - ROUTE-04
  - ROUTE-07
  - ROUTE-08
  - ROUTE-09
  - ROUTE-10
---

# Phase 3 Plan 03: Public Route + Cache + Lock (sync-only) Summary

Route publique `GET /t/{code}/{id}.{ext}` complete: feature flag, firewall
stateless, CORS dédié, lock Redis anti-thundering-herd, cache S3 versionné,
ETag déterministe + 304, headers immutables, 404 unifié (jamais 403). 5 SC
de Phase 3 couvertes.

## What Was Built

### Route + firewall + CORS

`api/config/routes/transformations.yaml` déclare la route avec requirements
regex stricts (T-03-12 path traversal mitigé) :

```
GET|HEAD /t/{code}/{id}.{ext}
  code: [a-z][a-z0-9-]{1,62}[a-z0-9]
  id:   \d+
  ext:  png|jpe?g|webp|avif
```

`security.yaml` ajoute un firewall stateless `public_transformations`
(pattern `^/t/`, `security: false`) avant le firewall `main` — pas de JWT,
pas de user provider.

`nelmio_cors.yaml` ajoute le bloc `^/t/` : `allow_origin: ['*']`,
`expose_headers: ['ETag', 'X-Transformation-Warnings']`, pas de credentials.

### TransformationLookup

`findOr404(code, assetId): [tx, asset]` — toutes les conditions de rejet
lèvent `NotFoundHttpException` (jamais `AccessDeniedException`) :

| Rejection | Cause |
|-----------|-------|
| Code inconnu | `findOneBy(['code' => $code])` retourne null |
| Asset inexistant | `$em->find(Asset::class, $id)` retourne null |
| `Asset.isPublic = false` | T-03-11 IDOR mitigation (D-10) |
| Step `REMOVE_BACKGROUND` | D-05 sync-only AI gating (Phase 4) |
| Step `ADD_BACKGROUND` avec `params.type='ai_prompt'` | D-05 (Phase 5/6) |

### VariantCache

Wrapper minimal sur `assets.storage` Flysystem : `has`, `read`, `write`,
`delete`. Même filesystem que les assets originaux — local FS en dev/test,
S3 en prod. Clé canonique produite par `TransformationStorageKey::forVariant`.

### PublicTransformationController

Workflow (D-04 + W5 release ordering) :

```
1. !enabled                                 → 404 (no DB)
2. lookup throws                            → 404
3. If-None-Match == ETag                    → 304 (no S3)
4. cache.has(key)                           → stream from S3
5. cache miss → lock.acquire(false)
   ├── false → waiter loop ≤5s
   │           ├── cache appears  → stream
   │           └── deadline       → 503 + Retry-After
   └── true  → re-check cache
       ├── hit (race won by other) → release + stream
       └── miss → load original   ─┐
                  runner.run()    │
                  cache.write()   │
                  release         │
                  stream          │  W5 : release AVANT le stream
```

Errors mapping :

| Exception code | HTTP | Headers |
|----------------|------|---------|
| `CODE_CAP_EXCEEDED` | 503 | `Retry-After: 2` |
| `CODE_EMBEDDER_ERROR` | 502 | — |
| `CODE_UNSUPPORTED_STEP` | 404 | `Cache-Control: max-age=300` |

Headers sur 200 :

- `Cache-Control: public, max-age=31536000, immutable` (D-21)
- `ETag: "{txId}-v{hash8}-{assetId}-{ext}"` (D-19)
- `Content-Type: image/{jpeg|png|webp|avif}` (jpg → image/jpeg)
- `Cross-Origin-Resource-Policy: cross-origin` (D-13)
- `X-Transformation-Warnings: code1, code2` si warnings non-vides

### Lock infrastructure

`config/services.yaml` :

- `lock.transformations.redis` : client `\Redis` natif sur `REDIS_HOST:REDIS_PORT`
- `lock.transformations.store` : `Symfony\Component\Lock\Store\RedisStore`
- `lock.transformations.factory` : `LockFactory` injecté dans le controller

`when@test` garde un **vrai** RedisStore (le service `redis` du docker-compose
est joignable depuis le container api) afin que la concurrence soit réellement
simulable (W2). Pas de fallback InMemoryStore.

### Feature flag

`TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED` env var, défaut `0` (OFF en prod),
overridé par `.env` (dev/test) à `1`. Bindé en `bool` dans le controller via
`#[Autowire(param: 'transformations.public_route.enabled')]`. Check en
**première instruction** du `serve()` — aucune lookup DB ni acquisition de
service quand off (D-12, T-03-17).

## Tasks Completed

| Task | Description | Commit |
|------|-------------|--------|
| 1 | Lookup + VariantCache + routes + CORS + security + lock infra | `5f2fbcc` |
| 2 | PublicTransformationController + tests unit | `e43f047` |
| 3 | Tests de concurrence + fix env() defaults Plan 02 | `5bb0342` |

## Verification

```
docker compose exec -T api ./vendor/bin/phpunit
→ Tests: 99, Assertions: 218, Deprecations: 1 (pré-existante)

docker compose exec -T api php bin/console debug:router public_transformation_serve
→ GET|HEAD /t/{code}/{id}.{ext}
  requirements: code='[a-z][a-z0-9-]{1,62}[a-z0-9]', id='\d+', ext='png|jpe?g|webp|avif'

docker compose exec -T api php bin/console debug:container lock.transformations.factory
→ Class: Symfony\Component\Lock\LockFactory (public)
  Arguments: Service(lock.transformations.store)

curl -sI http://localhost:8080/t/unknown-code/1.png
→ HTTP/1.1 404 Not Found
  Cache-Control: max-age=300, public

curl -sI -H "Origin: https://example.com" http://localhost:8080/t/unknown-code/1.png
→ HTTP/1.1 404 Not Found
  Access-Control-Allow-Origin: https://example.com
  Access-Control-Expose-Headers: etag, x-transformation-warnings
```

### Tests added (24 total)

**TransformationLookupTest (7)** — toutes les rejections sont NotFound, jamais AccessDenied :
- unknown code, missing asset, private asset, REMOVE_BACKGROUND, ADD_BACKGROUND ai_prompt
- nominal sync-safe + ADD_BACKGROUND color autorisé

**PublicTransformationControllerTest (12)** — chaque branche couverte :
- feature flag off (no DB/runner), missing asset, cache hit, cache miss → generate,
  cache miss → waiter then 200, cache miss waiter → 503 Retry-After,
  pipeline cap → 503, pipeline embedder error → 502 sans Retry-After,
  If-None-Match → 304 sans S3 ni runner, warnings header,
  StreamedResponse lazy (read non invoqué dans le handler),
  W5 lock.release() avant la consommation du stream

**ConcurrencyLockTest (5)** — SC3 gating prouvée :
- testConcurrentColdRequestsGenerateOnce — Voie B Lock stub, exactly 1 runner call sur 5 requêtes
- testSecondRequestServedFromCacheWithoutRunner — compteur = 1 sur 2 requêtes + ETag identique
- testCapExceededReturns503WithRetryAfter
- testEtagIdenticalAcrossPaths
- testWaiterReceives503WhenCacheStaysCold

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] symfony/lock not installed**
- **Found during:** Task 1 — `composer show symfony/lock` retournait "Package not found"
- **Issue:** Le plan suppose `symfony/lock` disponible (RedisStore + LockFactory) ; aucune commande d'install dans le plan
- **Fix:** `composer require symfony/lock` (v8.0.9) — la recipe ajoute aussi `config/packages/lock.yaml` (`framework.lock: '%env(LOCK_DSN)%'`) et `LOCK_DSN=flock` dans .env (gardé, ne gêne pas le store dédié)
- **Files:** `api/composer.{json,lock}`, `api/.env`, `api/config/packages/lock.yaml`, `api/symfony.lock`
- **Commit:** `5f2fbcc`

**2. [Rule 1 — Bug] env(default:<param>:VAR) fallbacks Plan 02 cassent en runtime**
- **Found during:** Task 3 (smoke test `curl /t/unknown-code/1.png` → 500)
- **Issue:** Plan 02 déclarait `env(transformations_default_cap_ms): '8000'` — c'est la **valeur par défaut d'une env var**, PAS un paramètre. Le processeur `default:` du `%env()%` cherche un **paramètre** ; au runtime → "parameter `transformations_default_cap_ms` not found". Les tests passaient parce que phpunit ne matérialise pas les envs comme l'app HTTP.
- **Fix:** Conversion des 6 defaults en paramètres simples (`transformations_default_cap_ms: '8000'`). Pattern aligné sur l'existant `app.embedder_url_default`.
- **Files:** `api/config/services.yaml`
- **Commit:** `5bb0342`

**3. [Rule 2 — Critical] `final` removed from 3 service classes for test substitution**
- **Found during:** Task 2 (PHPUnit `Cannot extend final class TransformationLookup`)
- **Issue:** `final` empêchait l'override par sous-classes spy/stub. L'alternative (interfaces + decorators) aurait été plus invasive sans bénéfice runtime.
- **Fix:** Drop `final` de `TransformationLookup`, `VariantCache`, `PipelineRunner`. Services restent identiques pour la DI ; aucun consumer prod n'étend ces classes.
- **Files:** `api/src/Service/AssetTransformation/TransformationLookup.php`, `VariantCache.php`, `PipelineRunner.php`
- **Commit:** `e43f047`

**4. [Rule 1 — Bug] Plan 03-03 acceptance check `InMemoryStore` was wrong inversion**
- **Found during:** Task 1 lecture du plan
- **Issue:** Le plan indique W2 doit garder un VRAI RedisStore en test (inversion explicite du conseil initial `InMemoryStore`). Le `when@test` réplique donc le RedisStore (pas d'InMemoryStore), pour permettre la simulation concurrente réelle (W2 GATING).
- **Fix:** Conforme W2 — RedisStore en test, pas d'InMemoryStore. Acceptance check `grep -E "InMemoryStore" api/config/services.yaml` retourne vide.
- **Commit:** `5f2fbcc`

### Voie B chosen for concurrency test (intentional deviation)

Le plan offre Voie A (`pcntl_fork`) **OU** Voie B (Lock stub forçant
`acquire=false`). Voie B retenue : `pcntl_fork` à l'intérieur de phpunit
fuite les FDs DBAL et cause des erreurs de connexion sporadiques. La logique
de course du controller (acquire → re-check → run → release → stream) est
exercée identiquement par Voie B, qui vérifie également que **EXACTEMENT** 1
appel `runner->run()` est effectué sur N=5 requêtes (assertion stricte `== 1`,
pas `<= 1`).

## Known Stubs

Aucun. La route est entièrement câblée :

- `PublicTransformationController` consomme `TransformationLookup`,
  `VariantCache`, `PipelineRunner` (Plan 02), `LockFactory` réels
- Flysystem `assets.storage` réutilisé (pas de mock résiduel)
- Tests utilisent des subclasses spy explicites (jamais de mock laissé en prod)

## Out-of-scope Items (deferred-items)

- **CORS preflight** ajoute par défaut `Access-Control-Allow-Headers:
  content-type, authorization` (héritage du bloc `^/`: null). Ce header
  n'est pas nuisible (le path `^/t/` impose `allow_headers: []` sur les
  vraies requêtes), mais à nettoyer si on veut un preflight strict. Non
  bloquant pour SC5.
- **Worker container `antigravity-worker-1`** en Restarting — pré-existant
  Plan 02, pas lié à cette route.
- **Doctrine deprecation Table.uniqueConstraints** — pré-existante.

## Threat Flags

Aucun nouveau threat hors du registre. Tous les vecteurs sont mitigés :

- T-03-11 (IDOR) — `isPublic` strict + 404 unifié
- T-03-12 (path traversal) — router requirements regex
- T-03-13 (DoS thundering herd) — Redis lock + cap 8s + waiter 503
- T-03-15 (cache key poisoning) — clé entièrement server-side
- T-03-17 (bypass feature flag) — check première instruction
- T-03-19 (DoS via 404 enumeration) — Cache-Control: max-age=300 sur 404

## Self-Check: PASSED

Files verified:
- FOUND: api/src/Controller/PublicTransformationController.php
- FOUND: api/src/Service/AssetTransformation/VariantCache.php
- FOUND: api/src/Service/AssetTransformation/TransformationLookup.php
- FOUND: api/config/routes/transformations.yaml
- FOUND: api/tests/Unit/Service/AssetTransformation/TransformationLookupTest.php
- FOUND: api/tests/Unit/Controller/PublicTransformationControllerTest.php
- FOUND: api/tests/Integration/Transformation/ConcurrencyLockTest.php

Commits verified:
- FOUND: 5f2fbcc (Task 1)
- FOUND: e43f047 (Task 2)
- FOUND: 5bb0342 (Task 3)
