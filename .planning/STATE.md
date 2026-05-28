---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: Ready to discuss/plan
stopped_at: Phase 5 context gathered
last_updated: "2026-05-28T07:22:37.912Z"
last_activity: 2026-05-27 — roadmap restructurée, AI reportée hors v1.0
progress:
  total_phases: 5
  completed_phases: 4
  total_plans: 18
  completed_plans: 18
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-27)

**Core value:** Gestion catalogue sans dépendance dev via introspection API
**Current focus:** Phase 05 — editor-pwa-warmup-gc-observability (à discuter/planifier)

## Current Position

Phase: 5
Plan: Not started
Status: Ready to discuss/plan
Last activity: 2026-05-27 — roadmap restructurée, AI reportée hors v1.0

Progress: [████████░░] 80%

## Performance Metrics

**Velocity:**

- Total plans completed: 18
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01 | 5 | - | - |
| 02 | 5 | - | - |
| 03 | 3 | - | - |
| 04 | 5 | - | - |

**Recent Trend:** —

*Updated after each plan completion*
| Phase 03 P01 | 6m | 3 tasks | 15 files |
| Phase 03 P02 | 5m | 2 tasks | 14 files |
| Phase 03-php-orchestrator-public-route-cache-lock-sync-only P03 | 7m | 3 tasks | 14 files |
| Phase 04 P01 | 15m | 3 tasks | 12 files |
| Phase 04 P02 | 25m | 2 tasks | 8 files |
| Phase 04 P03 | 15m | 2 tasks | 4 files |
| Phase 04 P04 | 12m | 3 tasks | 10 files |
| Phase 04 P05 | 4m | 2 tasks | 5 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions (post-pivot 2026-05-26) affecting current work:

- **Python-first architecture** : toute la manipulation d'image dans le service `embedder` (un endpoint par step type) ; le PHP devient un orchestrateur thin via `StepHandlerInterface` + `RetryableHttpClient`. Plus d'Imagine PHP.
- **BiRefNet (MIT)** comme modèle par défaut de `remove_background` — usage commercial OK. `isnet-general-use` conservé en fallback léger. RMBG-1.4/2.0 rejetés (licence non-commerciale).
- **Drop Stable Diffusion hors v1.0** (2026-05-27) — cadrage Webfacto requis (RAM 4-7 GB, CPU 30-180s, transport AI dédié). Reporté en Future Requirements.
- **Sync-only 8s en v1.0** — tous les step types restants sont sync-compatibles ; pas de chemin async nécessaire.
- **3 transports Messenger** (au lieu de 4) : `async` (CLIP existant), `transformations` (warmup live), `transformations_backfill` (bulk). Drop de `transformations_ai`.
- Route publique `/t/{code}/{id}.{ext}` (no JWT, CDN-friendly, conversion forcée par extension d'URL).
- Versioning par hash sha1 canonical (clés triées, defaults droppés). Pas de query `?v=`.
- Backfill lazy only (commande `transformations:warm` en Phase 5, pas de backfill auto au deploy).
- **Hard gate déploiement prod** : BiRefNet doit être live et stable (RAM, latence, `/health`) — signoff D-13 Webfacto fait en Phase 4.
- [Phase 03]: DTO Validators (5 readonly DTOs) + StepParamsFactory + Doctrine prePersist/preUpdate listener (D-14/D-15/D-16)
- [Phase 03]: Asset.is_public BOOLEAN default false matérialisé (ROUTE-08 prerequisite for /t/* public route)
- [Phase 03]: embedder.client = Scoping(http://embedder:8000) -> RetryableHttpClient (3 retries 200/400/800ms, 5xx+transport only, never 4xx)
- [Phase 03]: PipelineRunner enforces 8s wall-clock cap step-by-step via min(defaultTimeoutMs, remainingMs); virtual format_convert appended on ext mismatch (NOT persisted, versionHash invariant)
- [Phase 03]: Redis lock 'lock:tx:{storageKey}' TTL 10s; waiter loop 5s + 503 Retry-After if cache stays cold
- [Phase 03]: Route /t/* stateless firewall, 404 unifie (jamais 403), ETag deterministe {txId}-v{hash8}-{assetId}-{ext}
- [Phase 04]: ONNX Runtime pinned to 1.22.0 inline-commented with bug #26261 reference; integration_ml marker registered for real-ONNX heavy tests
- [Phase 04]: Plan 04-02: mock_birefnet_session must patch BOTH core.bgremove_models AND routers.img_remove_background (router uses 'from … import …' so name binding is fixed at import time)
- [Phase 04]: Plan 04-03: TestClient fixture must use context manager (with TestClient(app) as c) so FastAPI on_event(startup) hooks fire
- [Phase 04]: Plan 04-03: Image embedder self-contained (638 MB models) via multi-stage Dockerfile + BuildKit cache mount sur HF cache
- [Phase 04]: Plan 04-04: RemoveBackgroundHandler timeout 6000ms (D-18, 5s Python + 1s margin), env override EMBEDDER_TIMEOUT_REMOVE_BACKGROUND_MS
- [Phase 04]: Plan 04-04: isAsyncStep gate retiré complètement avec drop AI v1.0 — toutes transformations sync
- [Phase 04]: Plan 04-05: Stacked warnings — remove-background-requires-png complements alpha-flatten-on-jpeg ; D-13 Webfacto signoff materialised in 04-DEPLOY-CHECKLIST.md

### Pending Todos

None yet.

### Blockers/Concerns

- **Cadrage Webfacto requis** avant exposition publique en prod (route `/t/*`, coût S3, ressources embedder, transports Messenger, CDN). Hard gate D-13 Phase 4 signé.
- **Dimensionnement RAM prod** : embedder en v1.0 = CLIP + BiRefNet + isnet (~2-3 GB). Validé en staging Phase 4.
- Choix CDN (CloudFront / Bunny / aucun) à clarifier en Phase 5 ou cadrage post-v1.0.
- **Stable Diffusion reporté hors v1.0** — à reconsidérer après v1.0 si demande métier confirmée + ressources Webfacto allouées (RAM, GPU possible).

## Session Continuity

Last session: 2026-05-28T07:22:37.904Z
Stopped at: Phase 5 context gathered
Resume file: .planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
