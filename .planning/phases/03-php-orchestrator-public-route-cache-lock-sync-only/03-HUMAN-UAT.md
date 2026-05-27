---
status: partial
phase: 03-php-orchestrator-public-route-cache-lock-sync-only
source: [03-VERIFICATION.md]
started: 2026-05-27T00:00:00Z
updated: 2026-05-27T00:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Smoke E2E réel
expected: HTTP 200 + body binaire WebP + headers Cache-Control: public, max-age=31536000, immutable + ETag déterministe + Cross-Origin-Resource-Policy: cross-origin

Steps : démarrer la stack (`docker compose up -d`), uploader un asset (POST /api/assets/upload), créer une AssetTransformation [resize{w:800}, format_convert{webp}], passer Asset.isPublic=true, puis `curl GET /t/{code}/{id}.webp`

result: passed (2026-05-27) — `curl -I http://localhost:8080/t/test/2.webp` → HTTP 200, Content-Type: image/webp, Cache-Control: public, max-age=31536000, immutable, ETag: "7-v5677ea2c-2-webp", Cross-Origin-Resource-Policy: cross-origin. Steps en DB : resize{width:100} + format_convert{webp}. Pipeline E2E PHP↔embedder Python exécuté.

### 2. Cache hit + 304 conditionnel
expected: 2ème : 200 servie depuis S3/cache (compteur embedder inchangé, latence < 50ms). 3ème : 304 sans body.

Steps : 2ème requête immédiate sur la même URL puis 3ème avec `If-None-Match: "{ETag}"`

result: passed (2026-05-27, scripts/smoke-phase-03.sh) — 2ème requête HTTP 200 en 29ms (cache hit), 3ème requête avec If-None-Match retourne HTTP 304 + body=0B.

### 3. Feature flag OFF runtime
expected: HTTP 404 immédiat + Cache-Control: public, max-age=300 ; logs Doctrine ne montrent AUCUNE requête SQL pour asset_transformation

Steps : Bascule `TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0` + `docker compose restart api` ; `curl /t/{code}/{id}.webp`

result: passed (2026-05-27, scripts/smoke-phase-03.sh) — `TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0` dans api/.env.local + `docker compose restart api` → HTTP 404, Cache-Control: max-age=300, public, 0 SQL hits sur `asset_transformation` confirmé via `docker compose logs`.

### 4. Charge concurrente Redis inter-process
expected: Exactement 1 fichier S3 écrit, embedder logs montrent 1 seul appel par endpoint, les 9 autres requêtes reçoivent soit 200 (post-release) soit 503 + Retry-After: 2

Steps : 10 curl parallèles via `xargs -P10` sur une variante froide nouvellement créée

result: passed (2026-05-27) — Cold variant supprimée (rm -rf transformations/7-v5677ea2c), puis 10 curl parallèles via xargs -P10 → distribution : 1 × HTTP 200 + 9 × HTTP 503. Exactement 1 fichier écrit (transformations/7-v5677ea2c/0/2.webp). Embedder logs : 2 calls (POST /img/resize + POST /img/format-convert), tous depuis le même socket source (un seul worker PHP-FPM). Preuve runtime du lock Redis inter-process anti-thundering-herd (SC3).

### 5. Variants S3 non-publics (WR-04 fix)
expected: `aws s3api get-object-acl` montre uniquement le bucket-owner ; un GET direct sur l'URL S3 sans signature retourne 403

result: [pending]

### 6. CORS preflight runtime
expected: 200 + Access-Control-Allow-Origin: * + Access-Control-Allow-Methods inclut GET + Access-Control-Expose-Headers inclut ETag, X-Transformation-Warnings

Steps : `curl -X OPTIONS -H 'Origin: https://example.com' -H 'Access-Control-Request-Method: GET' /t/{code}/{id}.webp`

result: passed (2026-05-27, scripts/smoke-phase-03.sh) — Preflight OPTIONS → HTTP 200, Allow-Origin: https://example.com, Allow-Methods: GET, HEAD, OPTIONS. Sur la réponse GET (header normal, pas preflight) : Access-Control-Expose-Headers: etag, x-transformation-warnings.

### 7. Header X-Transformation-Warnings runtime
expected: 200 + `X-Transformation-Warnings: alpha-flatten-on-jpeg`

Steps : créer une transformation [resize{w:800}, format_convert{format:'jpg'}] sans add_background ; `curl /t/{code}/{id}.jpg`

result: [pending]

### 8. Webfacto gating
expected: Cadrage besoin, faisabilité, sécurité, priorisation validés par la Webfacto (rotation JWT, dimensionnement S3, CDN, rate-limit, TTL 404)

result: [pending]

## Summary

total: 8
passed: 5
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps