---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Roadmap re-créée et écrite (.planning/ROADMAP.md) après pivot architecture
last_updated: "2026-05-27T08:33:03.049Z"
last_activity: 2026-05-27 -- Phase 2 execution started
progress:
  total_phases: 7
  completed_phases: 1
  total_plans: 10
  completed_plans: 5
  percent: 50
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-26)

**Core value:** Gestion catalogue sans dépendance dev via introspection API
**Current focus:** Phase 2 — Python Image Service (classical endpoints)

## Current Position

Phase: 2 (Python Image Service (classical endpoints)) — EXECUTING
Plan: 1 of 5
Status: Executing Phase 2
Last activity: 2026-05-27 -- Phase 2 execution started

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:** —

*Updated after each plan completion*

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

### Pending Todos

None yet.

### Blockers/Concerns

- **Cadrage Webfacto requis** avant exposition publique en prod (route `/t/*`, coût S3, ressources embedder, transports Messenger dédiés, dimensionnement SD vs SDXL) — jalons naturels fin Phase 3, fin Phase 4 (deploy gate) et fin Phase 5.
- **Dimensionnement RAM prod** : embedder devra héberger CLIP (existant) + BiRefNet (~1 GB) + Stable Diffusion (~4-7 GB selon SD 1.5 vs SDXL). Mesure réelle requise dès Phase 4 ; arbitrage SD 1.5 vs SDXL à confirmer avec Webfacto avant Phase 5.
- **Latence SD CPU** : génération 30-120s/image en CPU. Tolérée via chemin async ; surveiller la profondeur de queue `transformations_ai` en prod.
- Choix CDN (CloudFront / Bunny / aucun) à clarifier avant ou pendant Phase 3 (affecte CORS, rate-limit, TTL 404, comportement 202/503).

## Session Continuity

Last session: 2026-05-26
Stopped at: Roadmap re-créée et écrite (.planning/ROADMAP.md) après pivot architecture
Resume file: None
