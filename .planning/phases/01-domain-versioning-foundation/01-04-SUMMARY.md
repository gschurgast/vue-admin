---
phase: 01-domain-versioning-foundation
plan: 04
date: 2026-05-27
status: complete
requirements: [TRANSFORM-03, TRANSFORM-04, TRANSFORM-05]
---

# Plan 01-04 — Listener + Purge message — SUMMARY

## Fichiers livrés

- `api/src/Message/PurgeTransformationVariantsMessage.php` — DTO readonly (transformationId, versionHash).
- `api/src/MessageHandler/PurgeTransformationVariantsHandler.php` — Handler **no-op** (log + ack). Vraie suppression S3 reportée en Phase 3+.
- `api/src/EventListener/TransformationHashListener.php` — Listener `#[AsDoctrineListener(event: onFlush)]` + `postFlush`.
- `api/config/packages/messenger.yaml` — transport `transformations_backfill` ajouté (DSN explicite `redis://redis:6379/messages_transformations_backfill` + group dédié).
- `api/tests/Integration/EventListener/TransformationHashListenerTest.php` — 5 tests d'intégration (in-memory transport).
- `api/.env.test` — DSN test DB séparé (`app_test`) + `MESSENGER_TRANSPORT_DSN=in-memory://`.

## Pitfalls RESEARCH adressés (code-level)

| Pitfall | Adresse |
|---------|---------|
| **A** — itérer Insertions+Updates+Deletions | Lignes 36-49 du listener (loop sur les 3 sources). |
| **B** — dispatch uniquement en postFlush | `$pendingPurges[]` accumulé en onFlush, dispatch dans `postFlush()`. |
| **C** — capture hash avant DELETE | Lignes 91-101 : on lit `$entity->getVersionHash()` dans `getScheduledEntityDeletions()` (entité encore en mémoire). |
| **D** — versionHash absent de `:write` | Déjà adressé Plan 01-01. Le listener est le seul appelant légitime de `setVersionHash()`. |
| **E** — constraint séparé | Déjà adressé Plan 01-03. |
| **G** — dédup par id | `$dirty[$key]` map avec `$entity->getId() ?? -spl_object_id($entity)` pour les entités encore sans id. |

### Bonus pattern : collection mutations (Pitfall A étendu)

`removeStep()` nullifie l'inverse `step->transformation = null` AVANT le flush, donc itérer sur `getScheduledEntityDeletions()` ne suffit pas pour retrouver le parent. Ajout d'une boucle sur `getScheduledCollectionUpdates()` + `getScheduledCollectionDeletions()` qui exposent `$collection->getOwner()` (= AssetTransformation parent). Sans cela, `testRemovingStepRecomputesHash` échouait.

## Transport Messenger

- DSN explicite `redis://redis:6379/messages_transformations_backfill` (pas d'override via `options.stream` — l'override ne fonctionnait pas, le stream restait `messages` partagé avec `async` → erreur "More than one group exists for stream 'messages'").
- Failed transport partagé `failed` (Redis).
- Retry strategy : 3× backoff 5s/10s/20s (max 120s).
- **No worker container in Phase 1** : les messages s'accumulent dans `messages_transformations_backfill`. Handler étant no-op, aucun impact. Phase 7 (OPS-04) livrera les workers dédiés.

## Tests (5/5 ✓)

| # | Test | Vérifie |
|---|------|---------|
| 1 | testCreateTransformationComputesHash | POST → hash 40 chars, 0 purge |
| 2 | testAddingStepRecomputesHashAndDispatchesPurge | PATCH ajoute step → hash change + 1 PurgeMessage (avec oldHash) |
| 3 | testRemovingStepRecomputesHash | removeStep → hash recompute |
| 4 | testDeletingTransformationDispatchesPurge | DELETE → 1 PurgeMessage (avec hash capturé avant) |
| 5 | testNoOpUpdateProducesNoPurge | Modif label only → hash inchangé, 0 purge |

Suite complète : **44 tests, 69 assertions, 0 failure** (1 deprecation Doctrine 4.0 issue d'une autre entité, hors scope).

## Smoke E2E confirmé via API

```
POST /api/asset_transformations → versionHash: c29144cefdc1077246ea6d300acb886066ce316f (40 chars)
PATCH add step               → versionHash change, queue+1
DELETE                       → 204, queue+1
```

## Cas non couverts (laissés pour Phase 3+)

- Flush imbriquée (postFlush qui déclenche un autre flush).
- Batch updates très volumineux (perf de la dédup).
- Race conditions multi-worker (concurrent admin edits) — relié à la stratégie de lock S3 Phase 3.

## Note pour Plan 05 (cohérence hash8)

Le handler log `hash8 = substr($message->versionHash, 0, 8)`. Le helper `TransformationStorageKey::forVariant()` utilise EXACTEMENT le même `substr($versionHash, 0, 8)`. Vérifié.

## Webfacto reminder

Le transport `transformations_backfill` doit être ajouté à la stack prod (workers dédiés, monitoring queue length, alerting). Phase 7 livre les workers, mais la queue commence à se remplir dès Phase 1 en prod — à cadrer.
