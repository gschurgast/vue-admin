# Phase 3: PHP Orchestrator + Public Route + Cache + Lock (sync-only) — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-27
**Phase:** 03-php-orchestrator-public-route-cache-lock-sync-only
**Areas discussed:** Lock + 8s cap + AI gating, HTTP client embedder + pipeline I/O,
Route semantics (404 / feature flag / CORS), DTO Validators + alpha-flatten warning,
Cross-cutting (ETag, streaming)

---

## Lock + 8s cap + AI gating

### Q1 — Implémentation du lock anti-thundering-herd

| Option | Description | Selected |
|--------|-------------|----------|
| Symfony Lock + Redis store | symfony/lock + RedisStore, clé `lock:tx:{storageKey}`, TTL auto-extend, InMemoryStore en test | ✓ |
| Predis SET NX EX direct | Lock manuel via SET NX EX 8, perd auto-extend + release safety | |
| Symfony Lock + cluster-safe (RedLock) | Multi-instance Redis, overkill mono-Redis | |

### Q2 — Comportement du waiter au-delà du 8s cap

| Option | Description | Selected |
|--------|-------------|----------|
| Wait → 503 + Retry-After:2 | Attend release, check S3, sinon 503 + Retry-After. Pas de re-gen concurrente | ✓ |
| Poll S3 toutes les 200ms | Boucle polling S3 (max 8s). Plus simple mais charge S3 | |
| 504 Gateway Timeout direct | UX médiocre pour `<img>` | |

### Q3 — Gestion d'une transformation `requires_async` frappée en Phase 3

| Option | Description | Selected |
|--------|-------------|----------|
| 404 silencieux | Uniforme avec autres not-found, Phase 5 introduira 202+Location | ✓ |
| 501 Not Implemented | Casse l'invariant "jamais d'erreur sur route publique" | |
| Rejeter à la persistance | Conflit avec TRANSFORM-07 déjà planifié Phase 6 | |

### Q4 — TTL et auto-extend du lock

| Option | Description | Selected |
|--------|-------------|----------|
| TTL 10s + auto-extend par heartbeat | Marge sur cap 8s, Symfony Lock natif | ✓ |
| TTL fixe 15s sans extend | Risque blocage 15s si crash | |
| TTL 30s sans extend | Blocage long si crash | |

---

## HTTP client embedder + pipeline I/O

### Q5 — Construction du client HTTP

| Option | Description | Selected |
|--------|-------------|----------|
| RetryableHttpClient + ScopingHttpClient | Service `embedder.client` scope http://embedder:8000, retry 3× | ✓ |
| HttpClient brut par handler | Duplique la logique retry | |
| EmbedderClient custom wrapper | Type-safe mais moins flexible | |

### Q6 — Timeouts par step

| Option | Description | Selected |
|--------|-------------|----------|
| Par step type + env override | resize/crop/rotate=2s, format=3s, add_bg=4s, override env | ✓ |
| Timeout global 5s identique | Trop grossier | |
| Pas de timeout par step, cap global seul | Step lent monopolise tout | |

### Q7 — Transport binaire entre steps

| Option | Description | Selected |
|--------|-------------|----------|
| Bytes mémoire (string PHP) | Simple, cap 50MP déjà côté embedder, ~10-40 MB max | ✓ |
| Streams (resources PHP) | Plus efficient mais multipart streaming verbeux | |
| Temp files (tmpfile()) | I/O disque par step → latence | |

### Q8 — Retry policy

| Option | Description | Selected |
|--------|-------------|----------|
| Retry 3× expo (200/400/800ms) sur 5xx/timeout | Pas de retry sur 4xx, backoff borné sous cap 8s | ✓ |
| Retry 2× linéaire 500ms | Moins résistant aux blips | |
| Fail fast | Embedder hoquette = cache jamais chaud | |

---

## Route semantics (404 / feature flag / CORS)

### Q9 — Cas qui doivent retourner 404 (jamais 403)

| Option | Description | Selected |
|--------|-------------|----------|
| Tous unifiés 404 | Code inconnu, asset inexistant/non-public, requires_async, ext invalide, flag off | ✓ |
| 404 sauf extension invalide → 400 | Crée canal d'info fingerprint | |
| 404 sauf erreur infra → 502/503 | (Pas exclusif : on garde 502/503 pour pannes — voir D-11) | |

### Q10 — Mécanisme du feature flag

| Option | Description | Selected |
|--------|-------------|----------|
| Param Symfony bindé sur env var | `%env(bool:TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED)%`, redéploiement requis | ✓ |
| Service FeatureFlag avec store DB/Redis | Hot-toggle mais surdimensionné pour 1 flag | |
| Fichier JSON | Changes non-tracés | |

### Q11 — Périmètre exact du cap 8s

| Option | Description | Selected |
|--------|-------------|----------|
| Wall clock du PipelineRunner::run() | Démarre à acquisition lock, ajuste timeout HTTP résiduel | ✓ |
| Cap sur chaque step individuel seul | 4×3s = 12s, casse SC | |
| Cap géré par FrankenPHP max_execution_time | Imprécis, kill brutal — filet de sécurité seulement | |

### Q12 — CORS sur `/t/*`

| Option | Description | Selected |
|--------|-------------|----------|
| Wildcard `*` GET seulement | Ressource publique, CORP cross-origin, pas de credentials | ✓ |
| Whitelist via env var | Limite consommation cross-site (or c'est l'objet) | |
| Mêmes règles que nelmio actuel | Trop restrictif (probablement scopé /api) | |

---

## DTO Validators + alpha-flatten warning

### Q13 — Architecture des DTO Validators

| Option | Description | Selected |
|--------|-------------|----------|
| 1 readonly class par step + Validator Symfony | Service/AssetTransformation/StepParams/, factory + Assert | ✓ |
| Constraint custom dispatching via StepType | Erreurs moins claires | |
| JSON Schema externe | Réinvente la roue PHP | |

### Q14 — Hook de validation à la persistance

| Option | Description | Selected |
|--------|-------------|----------|
| Doctrine prePersist/preUpdate | Couvre fixtures, console, API. 422 surfacé par API Platform | ✓ |
| API Platform Validator Constraint | Ne couvre pas les writes hors API | |
| State Processor dédié | Couplage API Platform uniquement | |

### Q15 — Où surfacer le warning alpha-flatten

| Option | Description | Selected |
|--------|-------------|----------|
| Colonne `warnings` JSONB + header HTTP debug | Recalcul au flush via TransformationHashListener, PWA via read group, header X-Transformation-Warnings | ✓ |
| Logger structuré seul | Pas de surface utilisateur | |
| Header HTTP seul | Oblige re-read transformation pour la PWA | |

### Q16 — Où est appliqué l'alpha-flatten implicite

| Option | Description | Selected |
|--------|-------------|----------|
| /img/format-convert (Phase 2) | Déjà livré, pipeline append format_convert si JPEG, juste le warning | ✓ |
| Step add_background blanc injecté en mémoire | Duplique logique Phase 2 | |
| 2 appels HTTP add-bg + format-convert | Inutile | |

---

## Cross-cutting

### Q17 — Stratégie ETag

| Option | Description | Selected |
|--------|-------------|----------|
| ETag déterministe `"{txId}-v{hash8}-{assetId}"` | Sans recalcul binaire, 304 instantané | ✓ |
| ETag = sha1 du binaire S3 | Read+hash sur chaque réponse | |
| Pas d'ETag, juste Cache-Control immutable | 304 plus rapide que 200 sur revalidation CDN | |

### Q18 — Streaming S3 → client

| Option | Description | Selected |
|--------|-------------|----------|
| StreamedResponse + Flysystem readStream | Compatible local FS et S3, Symfony-natif | ✓ |
| Redirect 302 S3 presigné | Expose bucket, headers immutable plus durs | |
| BinaryFileResponse | Casse en prod S3 | |

---

## Claude's Discretion

Décisions laissées à l'implémentation (cf. CONTEXT.md `Claude's Discretion`) :
- Découpage exact PipelineRunner / PipelineBuilder
- Nommage suffix `Handler`
- Logging structuré au sein des handlers (suivre patterns existants)
- nelmio_cors config vs middleware custom pour `/t/*`
- Tests E2E utilisant ou non `docker compose exec`

## Deferred Ideas

Reportées Phase 7 ou backlog (cf. CONTEXT.md `<deferred>`) :
- Transport Messenger `transformations` (warmup) → Phase 7
- Métriques OPS-05 → Phase 7
- Commandes `transformations:warm` / `:gc` → Phase 7
- Preview JWT + composable `useTransformedUrl` → Phase 7
- Hot-toggle feature flag → backlog post-v1
- GPU BiRefNet, CDN front → backlog post-v1
