# Asset Transformations — Operations Guide

> **⚠ Validation Webfacto requise avant industrialisation.** Cette doc liste les
> commandes ops, transports Messenger et métriques exposées par la pipeline
> AssetTransformation. Avant tout démarrage en production, ce cas d'usage doit
> être validé par la Webfacto (cadrage, faisabilité, sécurité, priorisation).

## Commandes ops

### `transformations:warm`

Pré-chauffe un variant pour une transformation et un asset cible. Manuel
uniquement. Dispatch un `WarmupTransformationVariantMessage` sur le transport
Messenger `transformations`.

```bash
docker compose exec api php bin/console transformations:warm <code> --asset-id=<id> [--ext=png]
```

Arguments :
- `code` (positional, required) : code kebab-case de l'`AssetTransformation`.
- `--asset-id` (required) : id de l'asset cible. Doit être `isPublic = true`.
- `--ext` (optional, default `png`) : extension de sortie à warmer.

Failure modes :
- `Transformation not found` (1) — code introuvable
- `Asset not found` (1)
- `Asset must be public to be warmed` (1) — aligné avec `/t/*` et preview

> **Bulk mode out-of-scope v1.0.** Pour pré-chauffer N assets sur la même
> transformation, itérer côté shell (`for id in 1 2 3; do ... done`).

### `transformations:gc`

Supprime les variants S3/Flysystem dont le `versionHash` n'est plus dans le set
des N plus récents (`--keep`). Le hash actif est **toujours** préservé.

```bash
docker compose exec api php bin/console transformations:gc [--dry-run] [--keep=2] [--force]
```

Options :
- `--dry-run` : énumère et calcule les tailles, **ne supprime rien**.
- `--keep` (default `2`) : nombre de hashs les plus récents à conserver par
  transformation (le hash actif compte dans le `--keep`).
- `--force` : skip la confirmation interactive (obligatoire en mode non-TTY,
  sinon la commande refuse de supprimer).

> Défaut `--keep=2` (rationale : rollback-friendly, garde version active +
> précédente). Supersedes la rédaction initiale D-15 (N=1) ; arbitré
> 2026-05-28 en faveur de l'alignement ROADMAP.

Chaque suppression émet un log JSON sur le channel `transformations_metrics` :
```json
{"metric":"transformations.gc.delete","value":1,"unit":"count","transformation_id":12,"transformation_code":"thumb-256","hash":"a3f7...","bytes":124567,"variants":3}
```

## Transports Messenger

| Transport | Routes | Failed transport | Workers |
|-----------|--------|------------------|---------|
| `async` | `ComputeEmbeddingMessage` (Phase 1) | `async_failed` | `worker` (default) |
| `transformations` | `WarmupTransformationVariantMessage` (Plan 05-02) | `transformations_failed` | `worker-transformations` |
| `transformations_backfill` | (réservé pour l'avenir — bulk pré-chauffe) | `transformations_backfill_failed` | `worker-transformations-backfill` |

Chaque transport a sa propre failed queue Redis (inspectable séparément via
`messenger:failed:show --transport=transformations_failed`). Retry 3× avec
backoff exponentiel sur tous les transports (voir `config/packages/messenger.yaml`).

### Workers Docker

Le `docker-compose.yml` provisionne 3 workers distincts :
- `worker` → `messenger:consume async`
- `worker-transformations` → `messenger:consume transformations`
- `worker-transformations-backfill` → `messenger:consume transformations_backfill`

Chaque worker a son cache dir séparé (`APP_CACHE_DIR=/app/var/cache/worker-<name>`).
Scaling : augmenter le `replicas` du worker concerné.

## Failed queues

Inspection :
```bash
docker compose exec api php bin/console messenger:failed:show --transport=transformations_failed
docker compose exec api php bin/console messenger:failed:retry  --transport=transformations_failed
```

## Observabilité — facets Datadog attendus

Tous les logs métriques sont émis sur le channel Monolog `transformations_metrics`
en JSON sur `stderr`. Docker logging driver remonte les logs vers la stack obs
cible. Le shipper côté Webfacto branchera l'ingestion.

| Metric | Unit | Tags | Source |
|--------|------|------|--------|
| `transformations.cache.hit` | count | `transformation_id`, `version_hash` | `PublicTransformationController` |
| `transformations.cache.miss` | count | `transformation_id`, `version_hash` | idem |
| `transformations.render.duration_ms` | ms | `transformation_id`, `step_type` | `PipelineRunner` |
| `transformations.lock.contention_ms` | ms | `transformation_id`, `version_hash` | `PipelineRunner` (post Phase 5+) |
| `transformations.embedder.timeout` | count | `step_type` | `PipelineRunner` (catch TransportException) |
| `transformations.embedder.inflight` | gauge | — | (déféré post-v1.0) |
| `transformations.embedder.last_inference_ms` | ms | — | (déféré post-v1.0) |
| `transformations.messenger.handled` | count | `transport`, `outcome` (success/failure) | `WarmupTransformationVariantHandler` |
| `transformations.gc.delete` | count | `transformation_id`, `transformation_code`, `hash`, `bytes`, `variants` | `transformations:gc` |

## Scheduling prod (OPS-06)

> **⚠ Validation Webfacto requise avant industrialisation.**

**Aucun hook de déploiement n'invoque ces commandes.** Les commandes
`transformations:warm` et `transformations:gc` sont strictement manuelles dans
la v1.0. Pas de cron, pas de hook post-deploy, pas de migration runtime.

Si la Webfacto souhaite planifier la `:gc` (typiquement nightly avec `--keep=2 --force`),
le cron doit :
1. Être validé en amont (cadrage besoin + impact stockage).
2. Toujours utiliser `--force` (sinon la commande refuse en non-TTY).
3. Privilégier d'abord un `--dry-run` pour calibrer le volume à supprimer.

Pour le warmup proactif post-publication d'asset, une queue automatique sur
`transformations_backfill` est envisageable mais doit également être validée
Webfacto (impact embedder load).

## Références plan / phase

- Plan canonique : `.planning/phases/05-editor-pwa-warmup-gc-observability/05-03-PLAN.md`
- Plan transports Messenger : `.planning/phases/05-editor-pwa-warmup-gc-observability/05-02-PLAN.md`
- Plan TransformationMetrics : `.planning/phases/05-editor-pwa-warmup-gc-observability/05-05-PLAN.md`
- Context phase 5 : `.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md`
- Decisions D-15 (--keep=2) + D-22 (Monolog) + D-24 (log shape) dans le même CONTEXT.md