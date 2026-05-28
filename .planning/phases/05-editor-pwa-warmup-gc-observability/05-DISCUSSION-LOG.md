# Phase 5: Editor PWA, Warmup, GC, Observability - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-28
**Phase:** 05-editor-pwa-warmup-gc-observability
**Areas discussed:** Éditeur + sous-formulaire, Preview (UX + endpoint), Commandes ops (warm/GC), Observabilité

---

## Sélection des zones

| Option | Description | Selected |
|--------|-------------|----------|
| Éditeur + sous-formulaire | DnD lib, pattern step type, warning EDITOR-08, placement composants | ✓ |
| Preview (UX + endpoint) | Trigger, asset picker, payload, rate-limit, format réponse | ✓ |
| Commandes ops (warm/GC) | Bulk, sémantique keep, dry-run, scheduling | ✓ |
| Observabilité | Backend metrics, instrumentation, embedder, failed queues | ✓ |

---

## Éditeur + sous-formulaire

### Librairie drag-and-drop

| Option | Description | Selected |
|--------|-------------|----------|
| vuedraggable (SortableJS) | Wrapper officiel Vue 3, ~10kB, déclaratif, touch events | ✓ |
| Sortable.js direct | Bas niveau, impératif | |
| HTML5 DnD natif | Zéro dep, mais touch mobile fragile | |

### Pattern sous-formulaire par step type

| Option | Description | Selected |
|--------|-------------|----------|
| Composants Vue dédiés par type | Un composant par StepType, réutilise field components | ✓ |
| Schema-driven (JSON) | Renderer générique sur config | |
| Renderer hybride (switch) | Compromis | |

### Affichage warning EDITOR-08

| Option | Description | Selected |
|--------|-------------|----------|
| Banner non bloquant + chip sur steps | Source = warnings JSONB du listener Phase 3 | ✓ |
| Validation bloquante | Save désactivé tant que non résolu | |
| Toast après save | Faible visibilité | |

### Placement des composants

| Option | Description | Selected |
|--------|-------------|----------|
| components/asset_transformation/edit/ | Suit pattern existant, custom component sur champ steps | ✓ |
| Composant custom global edit | Remplace le formulaire générique | |

---

## Preview (UX + endpoint)

### Trigger

| Option | Description | Selected |
|--------|-------------|----------|
| Bouton manuel "Prévisualiser" | Explicite, économise BiRefNet et rate-limit | ✓ |
| Auto avec debounce 800ms | Fluide mais coûteux | |
| Hybride (auto sauf si remove_background) | Comportement variable | |

### Choix de l'asset de test

| Option | Description | Selected |
|--------|-------------|----------|
| Picker explicite + localStorage par transformation | Mémorise `preview_asset_{transformationId}` | ✓ |
| Picker sans mémorisation | Friction à chaque ouverture | |
| Premier asset isPublic par défaut | Non représentatif | |

### Payload preview

| Option | Description | Selected |
|--------|-------------|----------|
| Steps inline (DTO PreviewRequest) | Preview avant save, steps non persistés | ✓ |
| Transformation persistée + assetId | Oblige à sauver d'abord | |

### Format réponse + rate-limit

| Option | Description | Selected |
|--------|-------------|----------|
| Stream binaire + 10/min/user | Content-Type image/*, no-store, RateLimiter token bucket | ✓ |
| Base64 JSON + 10/min/user | Plus lourd (+33%), debug facile | |
| Stream binaire + 30/min/user | Risque CPU BiRefNet | |

---

## Commandes ops (warm/GC)

### Comportement de `transformations:warm`

| Option | Description | Selected |
|--------|-------------|----------|
| Warm tous les assets isPublic en chunks | Bulk via transformations_backfill | |
| Warm uniquement avec --asset-id (requis) | Pas de mode bulk v1.0 | ✓ |
| Warm tous + --batch-size + --transport | Configurable | |

**Notes :** Le bulk reviendra dans un milestone ultérieur (cf. Deferred Ideas).

### Sémantique de `gc --keep=N`

| Option | Description | Selected |
|--------|-------------|----------|
| Garder le versionHash actif uniquement (--keep=1 default) | Supprime tous les hashes != actif | ✓ |
| Garder par asset (N variants par asset) | Sur-engineering | |
| Garder par date | Ambigu | |

### Sortie de `gc --dry-run`

| Option | Description | Selected |
|--------|-------------|----------|
| Liste complète + résumé par transformation | Hashes à supprimer + variants + taille | ✓ |
| Résumé uniquement | Manque d'audit | |

### Scheduling

| Option | Description | Selected |
|--------|-------------|----------|
| Manuel uniquement (v1.0) | Doc dans transformations-ops.md, Webfacto décide | ✓ |
| Symfony Scheduler configuré mais désactivé | Code mort | |

---

## Observabilité

### Backend de métriques

| Option | Description | Selected |
|--------|-------------|----------|
| Datadog StatsD (dogstatsd-php) | Agent + lib | |
| Logs JSON structurés (Monolog) | Datadog Logs dérive les métriques côté plateforme | ✓ |
| Prometheus (/metrics) | Pull-based, pas dans l'écosystème | |

### Instrumentation PHP

| Option | Description | Selected |
|--------|-------------|----------|
| Service TransformationMetrics injecté | Méthodes nommées, testable | ✓ |
| EventDispatcher + listeners | Découplé mais plus d'indirection | |
| Middleware HTTP + Messenger | Granularité limitée | |

### Métriques embedder

| Option | Description | Selected |
|--------|-------------|----------|
| Exposées via /health enrichi (existant) | PHP scrape /health et émet logs | ✓ |
| DogStatsD client Python | Ajoute dep + secret DD côté embedder | |

### Inspection failed queues

| Option | Description | Selected |
|--------|-------------|----------|
| CLI (messenger:failed:show --transport=X) | Doc dans ops | ✓ |
| UI admin (page list/replay) | Hors scope v1.0 | |

---

## Claude's Discretion

- Schéma exact `PreviewRequest` (champs, validation détaillée).
- Modal asset picker (probable extension `AssetGrid` en mode select).
- Granularité tags Monolog (nommage cohérent `metric`).
- Choix entre commande `transformations:health-collect` vs listener inline pour scrape `/health`.
- Stratégie de tests (unit `TransformationMetrics`, integration preview/rate-limit, smoke E2E DnD).

## Deferred Ideas

- Mode bulk `transformations:warm` sans `--asset-id`.
- Scheduling automatique (cron) des ops commandes — décision Webfacto post-livraison.
- UI admin pour failed queues.
- Dashboards Datadog packagés par la phase.
- Auto-preview avec debounce (reconsidérable si retours utilisateurs).
- Add-background AI (déjà reporté hors v1.0).
