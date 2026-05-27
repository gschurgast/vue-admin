---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Completed 04-03-PLAN.md (Dockerfile multi-stage + /health enrichi, 70 tests verts)
last_updated: "2026-05-27T14:39:57.216Z"
last_activity: 2026-05-27
progress:
  total_phases: 7
  completed_phases: 3
  total_plans: 18
  completed_plans: 16
  percent: 89
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-26)

**Core value:** Gestion catalogue sans dépendance dev via introspection API
**Current focus:** Phase 04 — birefnet-endpoint-remove-background-deploy-gate

## Current Position

Phase: 04 (birefnet-endpoint-remove-background-deploy-gate) — EXECUTING
Plan: 4 of 5
Status: Ready to execute
Last activity: 2026-05-27

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 3
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 03 | 3 | - | - |

**Recent Trend:** —

*Updated after each plan completion*
| Phase 03 P01 | 6m | 3 tasks | 15 files |
| Phase 03 P02 | 5m | 2 tasks | 14 files |
| Phase 03-php-orchestrator-public-route-cache-lock-sync-only P03 | 7m | 3 tasks | 14 files |
| Phase 04 P01 | 15m | 3 tasks | 12 files |
| Phase 04 P02 | 25m | 2 tasks | 8 files |
| Phase 04 P03 | 15m | 2 tasks | 4 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions (post-pivot 2026-05-26) affecting current work:

- **Python-first architecture** : toute la manipulation d'image dans le service `embedder` (un endpoint par step type) ; le PHP devient un orchestrateur thin via `StepHandlerInterface` + `RetryableHttpClient`. Plus d'Imagine PHP.
- **BiRefNet (MIT)** comme modèle par défaut de `remove_background` — usage commercial OK. `isnet-general-use` conservé en fallback léger. RMBG-1.4/2.0 rejetés (licence non-commerciale).
- **Stable Diffusion (inpainting via `diffusers` HF)** pour `add_background type:ai_prompt`. Modèle (~4-7 GB) intégré à l'image Docker, pas de download runtime.
- **Chemin async obligatoire** pour toute transformation contenant un step AI : 202 Accepted + Location + Retry-After, puis polling 503 jusqu'au 200. CPU 30-120s/image incompatible avec sync.
- **Sync-first 8s** conservé uniquement pour les transformations sans step AI.
- **4 transports Messenger** : `async` (CLIP existant, intouché), `transformations` (warmup live non-AI), `transformations_ai` (génération SD, queue lente dédiée), `transformations_backfill` (bulk).
- **Pas de GPU prod en v1.0** — CPU + chemin async accepté ; GPU = optimisation v1.1 après cadrage Webfacto.
- Route publique `/t/{code}/{id}.{ext}` (no JWT, CDN-friendly, conversion forcée par extension d'URL).
- Versioning par hash sha1 canonical (clés triées, defaults droppés). Pas de query `?v=`.
- Backfill lazy only (commande `transformations:warm` en Phase 7, pas de backfill auto au deploy).
- **Hard gate Phase 4 → Phase 5** : BiRefNet doit être live et stable en prod (RAM, latence, `/health`) avant tout déploiement de Stable Diffusion.
- **Soft gate Phase 5 → Phase 6** : l'endpoint `/img/generate-background` doit être déployé avant le step PHP `ai_prompt`.
- [Phase 03]: DTO Validators (5 readonly DTOs) + StepParamsFactory + Doctrine prePersist/preUpdate listener (D-14/D-15/D-16)
- [Phase 03]: Asset.is_public BOOLEAN default false matérialisé (ROUTE-08 prerequisite for /t/* public route)
- [Phase 03]: [Phase 03] D-06/D-07/D-08: embedder.client = Scoping(http://embedder:8000) -> RetryableHttpClient (3 retries 200/400/800ms, 5xx+transport only, never 4xx)
- [Phase 03]: [Phase 03] D-03/D-09: PipelineRunner enforces 8s wall-clock cap step-by-step via min(defaultTimeoutMs, remainingMs); virtual format_convert appended on ext mismatch (NOT persisted, versionHash invariant)
- [Phase 03-php-orchestrator-public-route-cache-lock-sync-only]: [Phase 03] D-01/D-04 Redis lock 'lock:tx:{storageKey}' TTL 10s; waiter loop 5s + 503 Retry-After if cache stays cold
- [Phase 03-php-orchestrator-public-route-cache-lock-sync-only]: [Phase 03] D-10/D-19/D-22 Route /t/* stateless firewall, 404 unifie (jamais 403), ETag deterministe {txId}-v{hash8}-{assetId}-{ext}
- [Phase 04]: [Phase 04] ONNX Runtime pinned to 1.22.0 inline-commented with bug #26261 reference; integration_ml marker registered for real-ONNX heavy tests
- [Phase 04]: Plan 04-02: mock_birefnet_session must patch BOTH core.bgremove_models AND routers.img_remove_background (router uses 'from … import …' so name binding is fixed at import time)
- [Phase 04]: [Phase 04] Plan 04-03: TestClient fixture must use context manager (with TestClient(app) as c) so FastAPI on_event(startup) hooks fire — sinon ORT warmup ne se déclenche pas en tests
- [Phase 04]: [Phase 04] Plan 04-03: Image embedder self-contained (638 MB models) via multi-stage Dockerfile + BuildKit cache mount sur HF cache

### Pending Todos

None yet.

### Blockers/Concerns

- **Cadrage Webfacto requis** avant exposition publique en prod (route `/t/*`, coût S3, ressources embedder, transports Messenger dédiés, dimensionnement SD vs SDXL) — jalons naturels fin Phase 3, fin Phase 4 (deploy gate) et fin Phase 5.
- **Dimensionnement RAM prod** : embedder devra héberger CLIP (existant) + BiRefNet (~1 GB) + Stable Diffusion (~4-7 GB selon SD 1.5 vs SDXL). Mesure réelle requise dès Phase 4 ; arbitrage SD 1.5 vs SDXL à confirmer avec Webfacto avant Phase 5.
- **Latence SD CPU** : génération 30-120s/image en CPU. Tolérée via chemin async ; surveiller la profondeur de queue `transformations_ai` en prod.
- Choix CDN (CloudFront / Bunny / aucun) à clarifier avant ou pendant Phase 3 (affecte CORS, rate-limit, TTL 404, comportement 202/503).

## Session Continuity

Last session: 2026-05-27T14:39:57.213Z
Stopped at: Completed 04-03-PLAN.md (Dockerfile multi-stage + /health enrichi, 70 tests verts)
Resume file: None
