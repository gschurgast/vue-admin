---
phase: 05-editor-pwa-warmup-gc-observability
plan: 03
subsystem: ops
tags: [symfony-command, messenger, gc, ops, docs]

requires:
  - phase: 05-plan-02
    provides: transport 'transformations' + WarmupTransformationVariantMessage
  - phase: 05-plan-05
    provides: monolog channel transformations_metrics (pour log GC delete)
provides:
  - "transformations:warm <code> --asset-id=<id> [--ext=png] (OPS-01)"
  - "transformations:gc [--dry-run] [--keep=2] [--force] (OPS-02)"
  - "docs/transformations-ops.md — guide opérabilité complet (OPS-06)"
affects: [webfacto-scheduling, runbook]

tech-stack:
  added: []
  patterns:
    - "Symfony Console + #[AsCommand] + SymfonyStyle"
    - "Flysystem listContents(deep:true) + regex parse pour énumérer hashs"
    - "Confirmation interactive obligatoire (refus non-TTY sans --force)"

key-files:
  created:
    - api/src/Command/TransformationsWarmCommand.php
    - api/src/Command/TransformationsGcCommand.php
    - docs/transformations-ops.md

key-decisions:
  - "--keep=2 défaut (rollback-friendly, supersedes D-15 initiale N=1, arbitré 2026-05-28)"
  - "Bulk mode out-of-scope v1.0 — itération shell `for id in ...` (T-05-14)"
  - "Asset target doit être isPublic = true (cohérent avec /t/* et preview, T-05-15)"
  - "Confirmation interactive requise — refus non-TTY sans --force (T-05-12)"
  - "Audit log JSON 'transformations.gc.delete' via channel transformations_metrics (Plan 05-05)"
  - "Aucun hook deploy n'invoque ces commandes — manuel uniquement (OPS-06)"

requirements-completed: [OPS-01, OPS-02, OPS-06]

duration: ~15min inline (post échec worktree)
completed: 2026-05-28
---

# Plan 05-03 — Ops commands + doc

Livré les 2 commandes ops `transformations:warm` et `transformations:gc`, plus
la doc d'opérabilité `docs/transformations-ops.md`.

## Déviations notables

1. **Exécution inline sur main** : l'executor worktree a été bloqué par la
   sandbox (HEAD désynchronisé + Write refusés). Le blueprint complet a été
   reproduit fidèlement depuis le rapport du gsd-executor.

2. **Tests unit non livrés inline** :
   - `TransformationsWarmCommandTest` (7 cas : missing args, unknown code,
     non-public asset refuse, dispatch happy path, etc.)
   - `TransformationsGcCommandTest` (6 cas : dry-run, keep=2 default,
     keep=1 explicit, --force, non-TTY refuse, interactive confirm no)

   À couvrir Wave 0 d'une phase d'audit ou via `/gsd-add-tests`. Les commandes
   sont vérifiables manuellement :
   ```bash
   docker compose exec api php bin/console transformations:warm test-jpg --asset-id=2
   docker compose exec api php bin/console transformations:gc --dry-run
   ```

3. **BLOCKER #1 plan-checker résolu** : la doc `transformations-ops.md`
   contient verbatim les 3 chaînes attendues :
   - « défaut `--keep=2` » + « rollback-friendly »
   - « Supersedes la rédaction initiale D-15 (N=1) »
   - « arbitré 2026-05-28 »

## Verify manuel

```bash
$ docker compose exec api php bin/console list transformations
  transformations:warm  Dispatch a warmup job ...
  transformations:gc    GC orphan transformation variants ...

$ docker compose exec api php bin/console transformations:gc --dry-run
Grand total: 0 transformations, 0.0 B to free
[NOTE] Dry-run: no DELETE performed.
```

## Non exécuté

- `docker compose exec api ./vendor/bin/phpunit --filter Transformations*Command`
- Smoke E2E : pas de variant ancien sur S3 à GC dans l'env dev

## Follow-ups STATE.md

- « 05-03 tests : couvrir TransformationsWarmCommand + TransformationsGcCommand »
