---
phase: 04
slug: birefnet-endpoint-remove-background-deploy-gate
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-27
---

# Phase 04 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Frameworks** | pytest 8.x (embedder) + PHPUnit 13.x (api) |
| **Config files** | `embedder/pytest.ini`, `api/phpunit.dist.xml` |
| **Quick run command (python)** | `docker compose exec -T embedder pytest -q -m "not integration_ml"` |
| **Quick run command (php)** | `docker compose exec -T api vendor/bin/phpunit --testsuite=unit` |
| **Full suite command** | `docker compose exec -T embedder pytest -q && docker compose exec -T api vendor/bin/phpunit` |
| **Heavy E2E (Webfacto)** | `docker compose exec -T embedder pytest -q -m integration_ml` (charge les vrais modèles ONNX 1GB) |
| **Estimated runtime** | ~5-8s (quick), ~30s (full hors integration_ml), ~2-3 min (integration_ml) |

The `integration_ml` pytest marker is added in Wave 0 and gated to OFF by default — it runs only on demand for the Webfacto checklist (D-13 item 4).

---

## Sampling Rate

- **After every task commit:** Run quick suite (python + php selon le fichier touché)
- **After every plan wave:** Run full suite (les deux côtés)
- **Before phase verification:** Full suite verte + heavy E2E `integration_ml` au moins une fois sur 3+ assets réels
- **Max feedback latency:** < 10s pour quick, < 60s pour full hors integration_ml

---

## Per-Task Verification Map

> Cette table sera densifiée par le planner ; les lignes ci-dessous établissent la couverture cible par REQ-ID. Le planner doit fournir le `Task ID` exact (`04-XX-YY`), le `Plan`, et le `Wave` quand il génère les PLAN.md.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 04-XX-W0a | 01 | 0 | All | — | Fixtures + markers | infra | `pytest --collect-only -m integration_ml` | ❌ W0 | ⬜ pending |
| 04-XX-W0b | 01 | 0 | All | — | Fixture image multi-format | infra | `ls embedder/tests/fixtures/*.{png,jpg}` | ❌ W0 | ⬜ pending |
| 04-XX-W0c | 01 | 0 | All | — | MockHttpClient pour /img/remove-background | infra | `grep -l "remove-background" api/tests/Unit/Service/AssetTransformation/StepHandler/*.php` | ❌ W0 | ⬜ pending |
| 04-XX-01 | 02 | 1 | BGREMOVE-01, BGREMOVE-02 | — | BiRefNet ONNX session chargée au startup | unit | `pytest embedder/tests/test_remove_background.py::test_birefnet_session_loads` | ❌ W0 | ⬜ pending |
| 04-XX-02 | 02 | 1 | BGREMOVE-04 | T-04-01 (DoS via inférences concurrentes) | asyncio.Lock sérialise les inférences | unit | `pytest embedder/tests/test_remove_background.py::test_lock_serializes_inflight` | ❌ W0 | ⬜ pending |
| 04-XX-03 | 02 | 1 | IMGSVC-06 | T-04-02 (Param injection) | DTO `model` enum strict | unit | `pytest embedder/tests/test_remove_background.py::test_unknown_model_rejected_422` | ❌ W0 | ⬜ pending |
| 04-XX-04 | 02 | 1 | BGREMOVE-05 | — | Timeout 5s hard + fallback isnet | unit | `pytest embedder/tests/test_remove_background.py::test_birefnet_timeout_falls_back_to_isnet` (mock timeout) | ❌ W0 | ⬜ pending |
| 04-XX-05 | 02 | 1 | BGREMOVE-05 | — | Timeout 5s SANS fallback → 504 | unit | `pytest embedder/tests/test_remove_background.py::test_timeout_without_fallback_returns_504` | ❌ W0 | ⬜ pending |
| 04-XX-06 | 02 | 1 | (preprocessing D-07) | T-04-03 (OOM via huge image) | 413 sur > 4096² | unit | `pytest embedder/tests/test_remove_background.py::test_image_over_4k_returns_413` | ❌ W0 | ⬜ pending |
| 04-XX-07 | 02 | 1 | (preprocessing D-07) | — | Downscale auto 2048-4096 | unit | `pytest embedder/tests/test_remove_background.py::test_image_3000px_downscaled_then_upscaled` | ❌ W0 | ⬜ pending |
| 04-XX-08 | 02 | 1 | (D-10) | — | Output toujours PNG RGBA | unit | `pytest embedder/tests/test_remove_background.py::test_output_is_png_rgba` | ❌ W0 | ⬜ pending |
| 04-XX-09 | 02 | 1 | (D-09) | — | Alpha pré-existant remplacé | unit | `pytest embedder/tests/test_remove_background.py::test_rgba_input_alpha_replaced` | ❌ W0 | ⬜ pending |
| 04-XX-10 | 03 | 2 | (D-11) | — | /health expose birefnet.loaded + inflight + last_inference_ms | unit | `pytest embedder/tests/test_health.py::test_health_includes_birefnet_status` | ❌ W0 | ⬜ pending |
| 04-XX-11 | 03 | 2 | (D-11) | — | /health passe en `degraded` si birefnet.loaded=false | unit | `pytest embedder/tests/test_health.py::test_health_degraded_when_birefnet_not_loaded` | ❌ W0 | ⬜ pending |
| 04-XX-12 | 03 | 2 | (D-12) | — | Logs JSON structurés par requête | unit | `pytest embedder/tests/test_remove_background.py::test_structured_log_emitted` | ❌ W0 | ⬜ pending |
| 04-XX-13 | 04 | 2 | BGREMOVE-06 | — | RemoveBackgroundHandler appelle /img/remove-background | unit | `phpunit --filter RemoveBackgroundHandlerTest` | ❌ W0 | ⬜ pending |
| 04-XX-14 | 04 | 2 | BGREMOVE-06 | T-04-04 (SSRF via params) | DTO `RemoveBackgroundStepParams` valide model + fallbackOnTimeout | unit | `phpunit --filter RemoveBackgroundStepParamsTest` | ❌ W0 | ⬜ pending |
| 04-XX-15 | 04 | 2 | BGREMOVE-06 | — | StepParamsFactory route REMOVE_BACKGROUND → DTO | unit | `phpunit --filter StepParamsFactoryTest::testRemoveBackgroundRouting` | ⬜ existing | ⬜ pending |
| 04-XX-16 | 04 | 2 | (D-16 / sync gate) | T-04-05 (404 leak inversé) | TransformationLookup ne 404 plus REMOVE_BACKGROUND sync | unit | `phpunit --filter TransformationLookupTest::testRemoveBackgroundSyncIsServed` | ⬜ existing | ⬜ pending |
| 04-XX-17 | 04 | 2 | (D-16) | — | TransformationLookup 404 toujours ADD_BACKGROUND ai_prompt | unit | `phpunit --filter TransformationLookupTest::testAddBackgroundAiPromptStill404` | ⬜ existing | ⬜ pending |
| 04-XX-18 | 04 | 2 | BGREMOVE-03 | — | Dockerfile multi-stage avec model layer | smoke | `docker build embedder --target model-downloader && docker images \| grep antigravity-embedder` | ❌ W0 | ⬜ pending |
| 04-XX-19 | 05 | 3 | (D-13 checklist) | — | Webfacto deploy gate documenté | docs | `grep -E "Webfacto.*sign|RAM ≥ 3 GB" .planning/phases/04-*/04-DEPLOY-CHECKLIST.md` | ❌ W0 | ⬜ pending |
| 04-XX-E2E1 | 02 | 1 | BGREMOVE-01..05 | — | E2E réel BiRefNet sur fixture image | integration_ml | `pytest -m integration_ml embedder/tests/test_remove_background_e2e.py::test_birefnet_real_inference` | ❌ W0 | ⬜ pending |
| 04-XX-E2E2 | 02 | 1 | BGREMOVE-05 | — | Latence p95 < 3s sur 3 photos produits 2048² | integration_ml | `pytest -m integration_ml embedder/tests/test_remove_background_e2e.py::test_birefnet_latency_p95` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `embedder/tests/test_remove_background.py` — stubs pour BGREMOVE-01..05 + IMGSVC-06 (12 fonctions vides marquées xfail)
- [ ] `embedder/tests/test_remove_background_e2e.py` — stubs marqués `@pytest.mark.integration_ml`
- [ ] `embedder/tests/test_health.py` — stubs /health enrichi (D-11)
- [ ] `embedder/tests/fixtures/product_2048.png` — photo produit Habitat réelle (~2 MB)
- [ ] `embedder/tests/fixtures/product_3000.jpg` — photo HR pour test downscale
- [ ] `embedder/tests/fixtures/product_4500.jpg` — photo > 4K pour test 413
- [ ] `embedder/tests/fixtures/product_with_alpha.png` — image déjà RGBA pour test D-09
- [ ] `embedder/tests/conftest.py` — fixture `mock_birefnet_session`, `mock_isnet_session`, `temp_models_dir`
- [ ] `embedder/pytest.ini` — déclarer `markers: integration_ml: requires real ONNX models (~1GB, slow)`
- [ ] `api/tests/Unit/Service/AssetTransformation/StepHandler/RemoveBackgroundHandlerTest.php` — stubs avec MockHttpClient
- [ ] `api/tests/Unit/Service/AssetTransformation/StepParams/RemoveBackgroundStepParamsTest.php` — stubs DTO
- [ ] `pip install onnxruntime==1.22.0 huggingface_hub` dans `embedder/requirements.txt` (pin ORT pour éviter bug #26261)

*Ces fichiers stubs sont créés en Wave 0 puis remplis Wave 1-2.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| RAM stable < 3GB sur 24h en staging | D-13 item 3 | Nécessite déploiement staging + monitoring sur durée | `docker stats antigravity-embedder` sur 24h, capturer min/max/avg ; doit rester < 3GB |
| Latence p95 < 3s sur 3+ photos produits Habitat réelles en prod | D-13 item 4, BGREMOVE-05 | Mesure prod-like uniquement, pas en CI | Choisir 3 photos catalogue, faire 10 requêtes chacune, mesurer p95 < 3000ms |
| RemoveBackgroundHandler validé sur 3+ AssetTransformations staging | D-13 item 5 | Nécessite stack staging + données réelles | Créer 3 tx avec/sans `fallbackOnTimeout`, curl `/t/{code}/{id}.png` → assert 200 + alpha > 5% transparent |
| Rate-limit `/t/*` configuré côté CDN | D-13 item 6 | Configuration externe à l'app | Vérifier policy Cloudflare/CloudFront via console ou `curl -I` répétés → 429 attendu après seuil |
| Webfacto signoff documenté | D-13 final | Validation organisationnelle, pas technique | Document `.planning/phases/04-*/04-DEPLOY-CHECKLIST.md` signé (entrée commit avec auteur Webfacto) |

---

## Threat Model (preview pour planner)

Le planner doit générer un `<threat_model>` complet dans PLAN.md. Threats anticipés ici pour la table de mapping :

- **T-04-01** : DoS via inférences concurrentes saturant CPU/RAM → mitigation D-14 (asyncio.Lock) + healthcheck `inflight`
- **T-04-02** : Param injection (`model` non whitelisté) → mitigation enum Pydantic strict
- **T-04-03** : OOM via image > 4K ou bombe (10x compressed) → mitigation D-07 (413 si > 4096²) + read size cap multipart
- **T-04-04** : SSRF côté PHP (handler appelle endpoint arbitraire) → mitigation ScopingHttpClient `embedder:8000` déjà en place (Phase 3)
- **T-04-05** : 404 leak via inversion `isAsyncStep` (révèle existence tx) → mitigation conserver le pattern 404-unified de Phase 3 (TransformationLookup)
- **T-04-06** : Resource leak ONNX session (instanciation par requête) → mitigation singleton chargé au startup
- **T-04-07** : Path traversal via filename multipart → mitigation Pydantic UploadFile + ne pas écrire sur disque (in-memory bytes)
- **T-04-08** : Exposition publique sans rate-limit → mitigation D-13 item 6 (CDN rate-limit avant exposition publique)

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (12 fichiers stubs + pin ORT)
- [ ] No watch-mode flags (tous les `pytest`/`phpunit` runs sont one-shot)
- [ ] Feedback latency < 10s quick, < 60s full
- [ ] `nyquist_compliant: true` set in frontmatter after Wave 0 complete

**Approval:** pending
