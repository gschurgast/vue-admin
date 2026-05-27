# Phase 04: BiRefNet Endpoint + remove_background — DEPLOY GATE - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Cette phase livre **trois** capacités, toutes en synchrone et sans aucun step IA générative :

1. Un endpoint Python `POST /img/remove-background` dans `embedder/`, propulsé par BiRefNet (MIT) avec fallback `isnet-general-use`.
2. Le step PHP `remove_background` côté orchestrateur, branché sur l'endpoint via `RetryableHttpClient`.
3. La **DEPLOY GATE dure** : aucun déploiement de Phase 5 (Stable Diffusion) avant que cette phase soit live en prod, RAM/latence vérifiées et checklist Webfacto signée.

Hors scope (autres phases) :
- Génération IA d'arrière-plan (`add_background type:ai_prompt`) → Phase 6
- Path async 202 + Location → Phase 5
- Éditeur drag-and-drop des steps + warmup async → Phase 7

</domain>

<decisions>
## Implementation Decisions

### Runtime & Modèles (BiRefNet + fallback)
- **D-01:** Runtime ML = **ONNX Runtime** (`onnxruntime` ~50MB) — pas de PyTorch dans l'image embedder. BiRefNet officiel publie des poids ONNX exportés. Justification : perf CPU supérieure (op fusion), image Docker ~600MB plus légère.
- **D-02:** Checkpoint BiRefNet shipper = **BiRefNet base general (DIS5K)** ~200-400MB FP32/FP16. Bon compromis précision/vitesse pour catalogue Habitat / e-commerce généraliste. Pas la variante `massive` (2× plus gros/lent, risque dépassement <3s).
- **D-03:** Fallback léger = **isnet-general-use** (rembg, MIT), checkpoint ONNX ~170MB. Pré-téléchargé au build au même titre que BiRefNet (BGREMOVE-03).
- **D-14:** **asyncio.Lock global** mono-process autour de chaque inference (BiRefNet ET isnet) — modèle non thread-safe. La métrique `birefnet_inflight` reflète l'occupation réelle.
- **D-15:** Modèles **pré-téléchargés à la construction** de l'image Docker (Dockerfile multi-stage : layer télécharge les .onnx, layer applicatif les copie). Aucun download au runtime. Image finale ~1.5GB (CLIP existant + BiRefNet + isnet).

### Timeout & Fallback semantics
- **D-04:** Le fallback `isnet-general-use` est déclenché **uniquement** sur **timeout per-request BiRefNet**, et seulement si le param JSON `fallbackOnTimeout: true` est envoyé. Pas de fallback automatique sur autre cause d'erreur.
- **D-05:** **Timeout per-step BiRefNet côté Python = 5s hard**. Le wrapper async wrappe l'inference dans `asyncio.wait_for(..., timeout=5.0)`. Si dépassement et `fallbackOnTimeout=true` → rerun isnet dans la même requête HTTP. Si `fallbackOnTimeout=false` → 504 Gateway Timeout.
- **D-06:** **Worst-case > 8s = problème PHP, pas Python.** Le `PipelineRunner` (Phase 3) cap déjà à 8s wall-clock total. Si dépassement → 503 + Retry-After côté contrôleur public. Python ne gère pas ce cas (timeout interne 5s < cap 8s, donc en pratique le Python rendra toujours avant le cap PHP).

### Preprocessing entrée
- **D-07:** **Dimensions max acceptées = 4096×4096**. Si > 4K → réponse `413 Payload Too Large`. Si entre 2048² et 4096² → downscale auto à 2048 long-edge (`PIL.Image.thumbnail` Lanczos) AVANT inference pour rester dans la cible <3s.
- **D-08:** **Output upscalé à la dimension originale** après inference (le masque alpha est upscalé via Lanczos, puis composé avec l'image originale RGB). Préserve la qualité visuelle finale.
- **D-09:** **Alpha pré-existant ignoré** : si l'entrée est déjà PNG RGBA, BiRefNet recalcule le masque à partir des canaux RGB et **remplace** l'alpha. Comportement uniforme quel que soit le mime d'entrée.
- **D-10:** **Sortie endpoint = PNG RGBA strict**. La conversion vers WebP/AVIF/JPG passe par le step `format_convert` du pipeline PHP (Phase 2). Sépare les concerns.

### Observabilité & Deploy Gate
- **D-11:** **`/health` enrichi** retourne JSON :
  ```json
  {
    "status": "ok" | "degraded",
    "clip": { "loaded": true },
    "birefnet": { "loaded": bool, "model": "birefnet-general", "inflight": int, "last_inference_ms": int|null },
    "isnet": { "loaded": bool }
  }
  ```
  `status: degraded` si `birefnet.loaded=false` ou `inflight > 4`. Suffisant pour liveness probe.
- **D-12:** **Métriques via logs JSON structurés**, pas de Prometheus pour cette phase. Chaque inference log un JSON `{"event":"remove_background","model":"birefnet","latency_ms":2150,"image_dims":"2048x1536","fallback_used":false,"client_ip":"..."}`. Datadog parsera les logs si besoin (l'org utilise déjà Datadog).
- **D-13:** **Checklist Webfacto signoff DEPLOY GATE** (hard gate Phase 4 → Phase 5) — checklist exhaustive signée en console ops :
  1. Image `embedder` build OK + push registry (digest noté)
  2. `/health` montre `birefnet.loaded=true` ET `isnet.loaded=true` en staging puis prod
  3. RAM allouée au conteneur ≥ 3 GB et utilisation observée stable 24h via `docker stats` (CLIP ~700MB + BiRefNet ~400MB + isnet ~170MB + headroom)
  4. Latence **p95 < 3s** mesurée sur **3+ assets réels** différents (photo produit standard 2048², photo HR 4K avec downscale, photo avec cheveux/feuilles complexes)
  5. `RemoveBackgroundHandler` PHP testé en staging sur **3+ AssetTransformations** réelles (avec et sans `fallbackOnTimeout`)
  6. Rate-limit `/t/*` défini côté CDN (Cloudflare/CloudFront) avant exposition publique de transformations contenant remove_background — éviter abuse par scraping

### Endpoint contract
- **D-17:** `POST /img/remove-background` :
  - Body : `multipart/form-data` avec champ `file` (binaire image)
  - Params query OU form : `model` (`birefnet` default | `isnet-general-use`), `fallbackOnTimeout` (`true` | `false` default)
  - Réponse 200 : `image/png` (binaire RGBA)
  - Réponse 413 : image > 4K
  - Réponse 415 : mime non supporté
  - Réponse 504 : timeout BiRefNet sans fallback
  - Réponse 500 : inference error

### Côté PHP (orchestrateur)
- **D-18:** `RemoveBackgroundHandler` étend `AbstractEmbedderStepHandler` (pattern Plan 03-02). Endpoint cible `${embedder.base_url}/img/remove-background`. Timeout HTTP côté PHP = 6s (laisse 1s de marge sur le 5s Python). Pas de retry sur 504 (déjà retry'é côté Python via fallback) — retries sur 5xx réseau uniquement (déjà géré par `RetryableHttpClient`).

### Hard gate phase
- **D-16:** **Phase 4 → Phase 5 = hard gate** : aucun planning/exécution de Phase 5 ne démarre tant que le D-13 checklist n'est pas signé. Le ROADMAP.md le mentionne déjà ("Hard gate: Phase 4 → Phase 5").

### Claude's Discretion
- Choix exact de la version BiRefNet à télécharger (commit hash / release tag) — checkpoints ONNX officiels du repo upstream.
- Stratégie multi-stage Dockerfile pour optimiser le cache (séparer le `RUN download_models.py` de la copie du code applicatif).
- Format exact des logs structurés (clés JSON, niveau, sortie stderr/stdout) — suivre conventions Datadog déjà en usage.
- Choix de la lib de téléchargement des modèles au build (`huggingface_hub` ou `wget` direct) — le repo BiRefNet host les ONNX sur HF.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 4 spec
- `.planning/ROADMAP.md` §"Phase 4: BiRefNet Endpoint + remove_background — DEPLOY GATE" — goal, success criteria, hard gate
- `.planning/REQUIREMENTS.md` §"IMGSVC-06" + §"BGREMOVE-01..06" — 7 requirements à couvrir

### Phase precedents (patterns à réutiliser)
- `.planning/phases/02-python-image-service-classical-endpoints/02-*-SUMMARY.md` — pattern APIRouter dans `embedder/routers/`, structure des tests Python
- `.planning/phases/03-php-orchestrator-public-route-cache-lock-sync-only/03-02-SUMMARY.md` — pattern `AbstractEmbedderStepHandler` + `embedder.client` ScopingHttpClient + RetryableHttpClient (D-18 s'y branche)
- `embedder/routers/img_resize.py` — référence pour l'endpoint Python (multipart, validation, retour binaire)
- `embedder/routers/img_format_convert.py` — référence pour la sortie binaire avec mime spécifique
- `api/src/Service/AssetTransformation/StepHandler/AbstractEmbedderStepHandler.php` — base class pour `RemoveBackgroundHandler`
- `api/src/Service/AssetTransformation/StepParams/StepParamsFactory.php` — où enregistrer le nouveau DTO `RemoveBackgroundStepParams`
- `api/config/services.yaml` — où câbler le handler (tag `app.transformation_step_handler`)

### External (modèles & licences)
- BiRefNet upstream — https://github.com/ZhengPeng7/BiRefNet (licence MIT, usage commercial OK)
- BiRefNet ONNX weights — checkpoints publiés sur Hugging Face (ZhengPeng7/BiRefNet)
- isnet-general-use — https://github.com/danielgatis/rembg ou checkpoint ONNX direct
- ONNX Runtime CPU — https://onnxruntime.ai/docs/install/ (extra: `onnxruntime` package, pas `onnxruntime-gpu`)

### Org constraints
- CLAUDE.md (racine) — conventions PHP entités, conventions Vue, JWT, MenuGroup
- Webfacto cadrage — exigence d'organisation Vente-Unique pour toute exposition publique en prod (rappel D-13)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Pattern endpoint Python** : 5 routers existants dans `embedder/routers/` (resize, crop, rotate, format_convert, add_background). Tous suivent le même squelette : `APIRouter()` + `@router.post("/img/...")` + multipart `UploadFile` + retour `Response(content=bytes, media_type=...)`. Le nouveau `img_remove_background.py` doit s'y conformer.
- **Pattern handler PHP** : `AbstractEmbedderStepHandler` créé en Phase 3 fournit l'inversion HTTP, le retry, le timeout par step, la gestion `TransportExceptionInterface`. Tous les step handlers existants (5×) en héritent. `RemoveBackgroundHandler` doit l'étendre — pas de duplication.
- **Pattern DTO StepParams** : `StepParamsFactory` route par `StepType` enum vers un DTO readonly. Ajouter `StepType::REMOVE_BACKGROUND` → `RemoveBackgroundStepParams` (params: `model?`, `fallbackOnTimeout?`).
- **Sync-only AI gating** : `TransformationLookup::isAsyncStep()` (Phase 3) renvoie déjà 404 pour `REMOVE_BACKGROUND` — il faut **inverser** ce gate maintenant qu'on a un handler sync. C'est un point sensible : la modification doit casser le test existant et le rejouer.

### Established Patterns
- **Image storage** : Flysystem `assets.storage` (local en dev, S3 en prod). Pas de changement Phase 4.
- **Embedder networking** : ScopingHttpClient(`embedder:8000`) + RetryableHttpClient (3 retries, 200/400/800ms backoff). `RemoveBackgroundHandler` reprend cette config.
- **Health endpoint** : `embedder/app.py` expose déjà `/health` minimal pour CLIP. À enrichir (D-11) — risque de régression sur les sondes liveness existantes, à valider.
- **Logging Python** : conventions Datadog (logs JSON sur stdout). Déjà en place pour les endpoints existants — à étendre.

### Integration Points
- **Pipeline orchestrator** : `PipelineRunner` (Phase 3) sélectionne le handler par `StepType`. Auto-discovery via tag DI `app.transformation_step_handler` — il suffit de tagger `RemoveBackgroundHandler` pour qu'il soit pickup.
- **Asset transformation listener** : `TransformationStepValidationListener` (Phase 3) valide les DTO au prePersist/preUpdate. Ajouter le mapping `REMOVE_BACKGROUND → RemoveBackgroundStepParams` dans `StepParamsFactory` suffit pour activer la validation.
- **Hash listener** : `TransformationHashListener` (Phase 3) recalcule `versionHash` + `warnings`. À ce stade le code computeWarnings ne gère que `alpha-flatten-on-jpeg`. Phase 4 peut ajouter un warning `remove-background-requires-png` si le step suivant n'est pas png — discussion ouverte mais pas un blocker.
- **Public route** : `PublicTransformationController` (Phase 3) servira automatiquement les variantes contenant `remove_background` une fois `TransformationLookup::isAsyncStep` mis à jour.

</code_context>

<specifics>
## Specific Ideas

- Reproduire le squelette exact de `embedder/routers/img_resize.py` pour `img_remove_background.py` (cohérence inter-routers).
- Pour le téléchargement des poids ONNX au build, préférer `huggingface_hub.snapshot_download` à un `wget` brut (gestion auth, retries, cache).
- Logs JSON Python via `structlog` ou format `json.dumps` direct — choisir ce que les autres routers utilisent déjà.
- La métrique `birefnet_inflight` peut être un simple compteur global Python (`int` derrière le Lock), exposé via `/health`. Pas besoin de Prometheus.
- Tests Python : utiliser une image de test fixe (~1MB) checked-in dans `embedder/tests/fixtures/` pour des tests reproductibles. Smoke test E2E : POST avec une image → assert mime=image/png + image résultante a un canal alpha avec ≥ 5% de pixels transparents.
- Tests PHP : mocker `embedder.client` avec MockHttpClient (déjà patterne Phase 3) pour valider le handler — pas besoin d'HTTP réel dans phpunit.

</specifics>

<deferred>
## Deferred Ideas

- **Modèle `add_background type:ai_prompt`** → Phase 6 (UX async)
- **Path async 202 + Location** pour les inferences > 8s → Phase 5 (gated par cette phase)
- **Endpoint `/metrics` Prometheus** → considéré pour Phase 7 (Observability) si Datadog logs ne suffisent pas
- **Quantization ONNX INT8 / dynamic quantization** pour accélérer BiRefNet — optimisation perf future, après mesures p95 réelles en prod
- **Warm-up via Messenger** des modèles BiRefNet au démarrage du worker → Phase 7
- **Multi-process embedder via gunicorn workers** au lieu d'asyncio.Lock — nécessiterait modèles chargés N fois (mémoire ×N). Non bloquant tant que latence < 3s tient en mono-process.
- **Cache des masques BiRefNet par checksum d'image** indépendamment du pipeline complet — micro-optim, pas nécessaire pour cible 3s.

</deferred>

---

*Phase: 04-birefnet-endpoint-remove-background-deploy-gate*
*Context gathered: 2026-05-27*
