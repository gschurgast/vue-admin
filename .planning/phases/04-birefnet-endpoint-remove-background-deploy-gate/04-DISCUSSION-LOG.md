# Phase 04: BiRefNet Endpoint + remove_background — DEPLOY GATE - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-27
**Phase:** 04-birefnet-endpoint-remove-background-deploy-gate
**Areas discussed:** Runtime + variantes BiRefNet, Sémantique timeout & fallback isnet, Preprocessing entrée, Observabilité & deploy gate

---

## Runtime + variantes BiRefNet

### Q1: Runtime BiRefNet — quelle techno embarquer dans l'image embedder ?

| Option | Description | Selected |
|--------|-------------|----------|
| ONNX Runtime | onnxruntime ~50MB au lieu de torch ~600MB. Perf CPU sup, op fusion, quantization possible. Poids ONNX officiels. | ✓ |
| PyTorch (impl de référence) | torch + torchvision, ~600MB plus gros, plus simple à débugger. | |
| ONNX + fallback PyTorch | Les deux deps, image lourde mais robuste. | |

**User's choice:** ONNX Runtime
**Rationale:** Recommandé. Pas de PyTorch nécessaire, BiRefNet publie ONNX, gain ~600MB sur l'image, perf CPU supérieure.

### Q2: Quel checkpoint BiRefNet shipper ?

| Option | Description | Selected |
|--------|-------------|----------|
| BiRefNet base general | DIS5K ~200-400MB, généraliste, bon compromis pour photo produit. | ✓ |
| BiRefNet-massive | ~800MB et 2× plus lent — risque dépassement <3s. | |
| BiRefNet-portrait | Spécialisé personnes, mauvais pour catalogue habitat. | |
| BiRefNet HR | Optimisé grandes images >2K. | |

**User's choice:** BiRefNet base general
**Rationale:** Adapté au catalogue Habitat/produits. Massive trop lourd, portrait/HR pas pertinent.

---

## Sémantique timeout & fallback isnet

### Q3: Quand activer le fallback isnet-general-use ?

| Option | Description | Selected |
|--------|-------------|----------|
| Sur timeout per-request seulement | Si BiRefNet dépasse N secondes → abort + rerun isnet dans la même requête. Activé par `fallbackOnTimeout: true`. | ✓ |
| Opt-in client direct | Client demande `model: "isnet-general-use"` explicitement, pas de fallback. | |
| Auto si /health dégradé | Bascule globale si OOM ou inflight élevé. | |

**User's choice:** Sur timeout per-request seulement

### Q4: Quel timeout per-step BiRefNet côté Python ?

| Option | Description | Selected |
|--------|-------------|----------|
| 5s hard | Cap 5s, laisse 3s pour I/O + format_convert avant cap PHP 8s. | ✓ |
| 3s hard (cible BGREMOVE-05) | Cap strict sur la cible, plus de fallbacks en pratique. | |
| Pas de timeout côté Python | Le cap PHP 8s gère tout. Risque embedder qui mouline après abandon client. | |

**User's choice:** 5s hard

### Q5: Cas worst-case > 8s (cap Phase 3)

| Option | Description | Selected |
|--------|-------------|----------|
| 503 + Retry-After | Cap PHP existant gère ; Python n'a pas à le gérer (timeout 5s < cap 8s). | ✓ |
| Pre-fallback si image > seuil | Passer directement en isnet sans tenter BiRefNet sur grosses images. | |
| Async path dès Phase 4 | Anticiper Phase 5 — mais crée dépendance circulaire (Phase 5 gated par Phase 4 deploy). | |

**User's choice:** 503 + Retry-After (laisse Phase 3 gérer)

---

## Preprocessing entrée

### Q6: Dimensions max acceptées en entrée ?

| Option | Description | Selected |
|--------|-------------|----------|
| 4096×4096 + downscale auto | Refuse >4K (413). Entre 2048² et 4096² → downscale à 2048 long-edge avant inference. Output upscalé à dim originale. | ✓ |
| 2048×2048 hard (refuse plus grand) | Strict, force le client à resize avant. | |
| Pas de limite | Risque OOM sur 8K+. | |

**User's choice:** 4096×4096 + downscale auto

### Q7: Image déjà RGBA en entrée (alpha pré-existant)

| Option | Description | Selected |
|--------|-------------|----------|
| Run BiRefNet quand même, l'alpha BiRefNet remplace | Comportement uniforme quel que soit le mime d'entrée. | ✓ |
| Skip si alpha pré-existant | Économise du compute mais inconsistent. | |
| Param client `respect_alpha: bool` | Le client décide. | |

**User's choice:** Run BiRefNet quand même

### Q8: Format de sortie

| Option | Description | Selected |
|--------|-------------|----------|
| PNG RGBA strict | Conversion ultérieure via step format_convert PHP. Sépare les concerns. | ✓ |
| Param `format: png\|webp\|avif` | Endpoint accepte format de sortie. Duplique la logique de conversion. | |

**User's choice:** PNG RGBA strict

---

## Observabilité & deploy gate

### Q9: Shape de /health attendu après Phase 4

| Option | Description | Selected |
|--------|-------------|----------|
| Enrichi avec BiRefNet status | JSON {status, clip, birefnet:{loaded,model,inflight,last_inference_ms}, isnet:{loaded}}. | ✓ |
| Minimal {status, birefnet.loaded} | Booleans seulement, métriques séparées. | |
| /health + /metrics Prometheus | Endpoint Prometheus complet. | |

**User's choice:** /health enrichi

### Q10: Métriques exposées pour le suivi prod

| Option | Description | Selected |
|--------|-------------|----------|
| Logs structurés + /health inflight | JSON par requête avec model/latency/fallback/dims. Datadog parse. Pas de Prometheus pour cette phase. | ✓ |
| Endpoint /metrics Prometheus complet | Histogrammes, counters, gauges via prometheus_client. | |

**User's choice:** Logs structurés (cohérent avec l'usage Datadog interne)

### Q11: Contenu checklist Webfacto signoff (deploy gate hard)

| Option | Description | Selected |
|--------|-------------|----------|
| Checklist exhaustive signée | 6 items : build+push, /health staging→prod, RAM ≥3GB 24h, latence p95 sur 3+ assets, handler PHP testé staging, rate-limit CDN. | ✓ |
| Checklist minimale | /health=ok + 1 asset test. Reste validé en prod. | |

**User's choice:** Checklist exhaustive signée

---

## Claude's Discretion

Aucun "you decide" explicite — toutes les questions ont eu une réponse fermée. Les zones laissées au planner/executor sont nommées dans CONTEXT.md §"Claude's Discretion" : version exacte des poids BiRefNet, stratégie multi-stage Dockerfile, lib de download (huggingface_hub vs wget), format précis des logs.

## Deferred Ideas

Aucune dérive de scope pendant la discussion. Items reportés (déjà documentés dans ROADMAP.md) :
- add_background ai_prompt → Phase 6
- Async path 202 → Phase 5
- /metrics Prometheus → considérer Phase 7
- Quantization ONNX, warmup async, multi-process → optimisations futures
