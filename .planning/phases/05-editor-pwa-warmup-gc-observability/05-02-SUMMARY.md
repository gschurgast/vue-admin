---
phase: 05
plan: 02
subsystem: messenger-transports
tags: [messenger, redis-streams, docker, ops]
requirements: [OPS-03, OPS-04]
dependency_graph:
  requires:
    - "Phase 1: PurgeTransformationVariantsMessage + transformations_backfill transport (already wired)"
    - "Phase 3: PipelineRunner + VariantCache + TransformationStorageKey"
  provides:
    - "WarmupTransformationVariantMessage + handler (consumed by Plan 05-03 `transformations:warm` command)"
    - "3 dedicated Messenger transports with isolated failed queues"
    - "Per-transport Docker worker (sibling of CLIP worker)"
  affects:
    - "api/config/packages/messenger.yaml (root failure_transport removed; per-transport DLQ)"
    - "docker-compose.yml (3 worker services running concurrently)"
tech-stack:
  added: []
  patterns:
    - "Per-transport failure_transport (Symfony Messenger 7.3 — explicit DLQ per business surface)"
    - "Per-worker APP_CACHE_DIR (Symfony cache pool sharding across processes sharing an image)"
key-files:
  created:
    - api/src/Message/WarmupTransformationVariantMessage.php
    - api/src/MessageHandler/WarmupTransformationVariantHandler.php
    - api/tests/Smoke/MessengerTransportsTest.php
    - api/tests/Smoke/MessengerFailedQueuesTest.php
    - api/tests/Unit/MessageHandler/WarmupTransformationVariantHandlerTest.php
  modified:
    - api/config/packages/messenger.yaml
    - docker-compose.yml
decisions:
  - "WarmupTransformationVariantMessage carries `ext` (default `png`) so the handler can fill a precise cache slot — outputExts() does not exist on AssetTransformation; deferring it to the message keeps the handler stateless."
  - "Handler is idempotent on cache hit (no PipelineRunner call) — replays / overlapping warm commands must not re-render the same variant."
  - "Filesystem read errors are NOT re-thrown (warmup of a missing original is not transient — retrying burns the failed queue); PipelineRunner exceptions ARE re-thrown so Messenger retry strategy applies (3× exponential then transformations_failed)."
  - "Removed the project-wide `framework.messenger.failure_transport` fallback — making per-transport `failure_transport` mandatory prevents silent misrouting for future message classes."
  - "test env override declares the 3 active transports AND the 3 failed transports as in-memory (otherwise Messenger fails to resolve the failure_transport reference at boot)."
metrics:
  duration: ~15m
  completed: 2026-05-28
---

# Phase 5 Plan 02: Messenger 3 Transports + Workers Summary

## One-liner

3 dedicated Symfony Messenger transports (`async`, `transformations`,
`transformations_backfill`) with isolated Redis-Streams failed queues and 3
sibling Docker workers — plus the `WarmupTransformationVariantMessage` + handler
that Plan 05-03 will dispatch from `transformations:warm`.

## Tasks Completed

| Task | Description | Commit |
|------|-------------|--------|
| 1 | messenger.yaml refactor + Message + Handler + 3 tests | `0300557` |
| 2 | docker-compose.yml — 2 new workers | `6f1e306` |

## Implementation Notes

### messenger.yaml (per-transport DLQ)

Each active transport now declares `failure_transport: <name>_failed`. The
3 DLQs live on distinct Redis streams (`messages_async_failed`,
`messages_transformations_failed`,
`messages_transformations_backfill_failed`), so
`bin/console messenger:failed:show --transport=transformations_failed` is
isolated from CLIP DLQ and from backfill DLQ.

The `async` transport DSN, options, and retry strategy were preserved
verbatim (T-05-10) — only the `failure_transport` key was appended.

### WarmupTransformationVariantMessage

```php
final readonly class WarmupTransformationVariantMessage {
    public function __construct(
        public int $transformationId,
        public int $assetId,
        public string $ext = 'png',
    ) {}
}
```

The `ext` field is intentional — `AssetTransformation` has no `outputExts()`
accessor; warmup must know which cache slot to fill (storage keys include
the extension).

### WarmupTransformationVariantHandler flow

1. `find(AssetTransformation, transformationId)` — missing → warn + return.
2. `find(Asset, assetId)` — missing → warn + return.
3. Require non-empty `versionHash` (otherwise the storage key would be wrong).
4. `VariantCache::has($key)` short-circuit (idempotency).
5. Read asset bytes via `assets.storage` Flysystem.
6. `PipelineRunner::run($tx, $bytes, $ext)`.
7. `VariantCache::write($key, $result->bytes, $result->contentType)`.

`PipelineRunner` exceptions are re-thrown → Messenger retries 3× exponential
(2s/4s/8s up to 30s max) → `transformations_failed` on exhaustion.
Filesystem read errors are swallowed (non-transient).

### docker-compose.yml

3 worker services share the `antigravity-api` image:

- `worker` — `messenger:consume async` (CLIP, intouché).
- `worker-transformations` — `messenger:consume transformations`,
  `APP_CACHE_DIR=/tmp/cache-transformations`.
- `worker-transformations-backfill` — `messenger:consume transformations_backfill`,
  `APP_CACHE_DIR=/tmp/cache-transformations-backfill`.

Per-worker `APP_CACHE_DIR` is the T-05-11 mitigation: Symfony's cache pool
locking is filesystem-scoped, and 3 processes pointed at the same
`/app/var/cache/...` will contend (occasionally fail with
`cache pool already locked`).

## Deviations from Plan

### Auto-fixed / Adjustments

**1. [Rule 2 — Missing critical functionality] Added `ext` to the warmup message**
- **Found during:** Task 1 (handler design)
- **Issue:** Plan said "boucle sur `$tx->getOutputExts()` (ou ext par défaut `png`)" — `AssetTransformation` has no such method, and warmup must pre-compute a specific cache slot (storage key includes ext).
- **Fix:** Added `public string $ext = 'png'` to the message DTO. Plan 05-03 will pass the ext explicitly from CLI; default `png` keeps backward compat.
- **Files modified:** `WarmupTransformationVariantMessage.php`, handler, test.
- **Commit:** `0300557`

**2. [Rule 2 — Hardening] Removed root `framework.messenger.failure_transport`**
- **Found during:** Task 1 (messenger.yaml refactor)
- **Issue:** Symfony 7.3 lets a root `failure_transport` silently mask per-transport mistakes. With 3 transports each needing distinct DLQs, a single shared `failed` bucket would defeat OPS-04.
- **Fix:** Dropped the root key; each transport block declares its own `failure_transport`. This makes any new message class either explicit or fail-loud at boot.
- **Commit:** `0300557`

**3. [Rule 3 — Blocking issue] test env override extended**
- **Found during:** Task 1 (test config)
- **Issue:** The 3 transports reference 3 failed transports by name; if the test override declared only `async` + `transformations_backfill`, Messenger would fail to resolve `failure_transport: async_failed` etc. at boot.
- **Fix:** Test override now declares the 6 transports as `in-memory`.
- **Commit:** `0300557`

### Plan items left as written

- WarmupTransformationVariantHandler does NOT call PipelineRunner with `bypassCache: false`. PipelineRunner::run() takes `(AssetTransformation, string, string)` — it never had a `bypassCache` parameter. The Plan's wording was inaccurate. Real semantics: PipelineRunner always produces bytes; the handler explicitly writes them to VariantCache (whereas the controller writes only on cache miss). The "warmup writes cache" behavior is preserved.

## Verification Status

Tests were authored but not executed in this worktree (no Docker available
locally for the orchestrator's parallel agent — see
`docker compose exec api ./vendor/bin/phpunit` in the plan's automated verify
block). The orchestrator's verifier wave will run them.

Static checks performed:
- `messenger.yaml` parses as valid YAML (Yaml::parseFile used by smoke test).
- New PHP classes follow project conventions (namespace, `final readonly`,
  `#[AsMessageHandler]`, autowiring via `#[Autowire(service: ...)]`).
- `docker compose config` should pass (3 well-formed service blocks).

## Known Stubs

None.

## Threat Flags

None — surface is internal CLI/worker, no new HTTP endpoint or public route.

## Self-Check: PASSED

All 5 created files and 2 modified files exist on disk. Both per-task commits
(`0300557`, `6f1e306`) are present in the worktree branch history.
