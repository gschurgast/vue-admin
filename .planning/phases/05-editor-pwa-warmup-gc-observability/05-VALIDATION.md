---
phase: 05
slug: editor-pwa-warmup-gc-observability
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-28
---

# Phase 05 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework (API)** | PHPUnit 11 + Symfony test pack (installé Phase 1 plan 01-02) |
| **Framework (PWA)** | `vue-tsc --noEmit` (type-check) + `npm run build` (aucun runner vitest installé — décision implicite, follow-up post-v1.0) |
| **Config file (API)** | `api/phpunit.xml.dist` |
| **Quick run command (API)** | `docker compose exec api ./vendor/bin/phpunit --filter=<className>` |
| **Full suite command (API)** | `docker compose exec api ./vendor/bin/phpunit` |
| **Quick run command (PWA)** | `docker compose exec pwa npx vue-tsc --noEmit` |
| **Full suite command (PWA)** | `docker compose exec pwa npm run build` |
| **Estimated runtime** | ~60-120s (full API suite) ; ~30s (PWA build) |

---

## Sampling Rate

- **After every task commit:** Run quick command for the touched class/file
- **After every plan wave:** Run full suite (API phpunit + PWA build)
- **Before `/gsd-verify-work`:** Full suite green + manual smoke (drag-and-drop, preview round-trip, 429 spam)
- **Max feedback latency:** ~120s (full API suite)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 05-01-01 | 01 | 1 | EDITOR-04, EDITOR-05 | T-05-01, T-05-04 | rate_limiter.yaml + PipelineRunner.bypassCache : no S3 write, no Redis lock when bypass | unit + integration stub | `phpunit --filter="PreviewBypassCacheTest\|PipelineRunner"` | ❌ W0 | ⬜ pending |
| 05-01-02 | 01 | 1 | EDITOR-04, EDITOR-05 | T-05-01, T-05-03, T-05-06, T-05-07 | DTO + Processor : 200 + binary + no-store + noindex ; 429 + Retry-After ; 404 si !isPublic ; ext allowlist | integration | `phpunit --filter="PreviewEndpointTest\|PreviewRateLimitTest\|PreviewBypassCacheTest"` | ❌ W0 | ⬜ pending |
| 05-02-01 | 02 | 1 | OPS-03, OPS-04 | T-05-09, T-05-10 | 3 transports + 3 failed queues + routing intact pour async (CLIP) | smoke + unit | `phpunit --filter="MessengerTransportsTest\|MessengerFailedQueuesTest\|WarmupTransformationVariantHandlerTest"` | ❌ W0 | ⬜ pending |
| 05-02-02 | 02 | 1 | OPS-04 | T-05-11 | 3 workers Docker running avec APP_CACHE_DIR distinct | infra smoke | `docker compose config --quiet && docker compose ps --status running \| grep -E "worker-transformations(-backfill)?" \| wc -l \| grep -q 2` | ❌ W0 | ⬜ pending |
| 05-03-01 | 03 | 2 | OPS-01 | T-05-14, T-05-15 | Warm command : --asset-id REQUIRED ; refuse non-public ; dispatch correct | unit (Command) | `phpunit --filter="TransformationsWarmCommandTest"` | ❌ W0 | ⬜ pending |
| 05-03-02 | 03 | 2 | OPS-02 | T-05-12, T-05-13 | GC : dry-run énumère hashes orphelins via Flysystem listing ; --keep=2 défaut ; confirmation TTY ; hash actif toujours gardé | unit (Command) | `phpunit --filter="TransformationsGcCommandTest"` | ❌ W0 | ⬜ pending |
| 05-03-03 | 03 | 2 | OPS-06 | T-05-16 | docs/transformations-ops.md ≥ 6 sections H2 + mention explicite no-auto-backfill + Webfacto signoff | doc presence | `test -f docs/transformations-ops.md && grep -E "^## " docs/transformations-ops.md \| wc -l \| awk '$1>=6'` | ❌ W0 | ⬜ pending |
| 05-04-01 | 04 | 1 | EDITOR-07, EDITOR-08 | T-05-18, T-05-20 | useTransformedUrl pure (no reactivity import) ; useTransformationWarnings code aligné serveur | type-check + grep | `npm run build && grep -q "vuedraggable" pwa/package.json && ! grep -E "^import.*(ref\|watch\|computed).*'vue'" pwa/src/composables/useTransformedUrl.ts` | ❌ W0 | ⬜ pending |
| 05-04-02 | 04 | 1 | EDITOR-03, EDITOR-08 | T-05-17, T-05-20 | 6 composants StepFields + WarningBanner type-check vert | type-check | `npx vue-tsc --noEmit && find pwa/src/components/asset_transformation/edit/steps -name "*.vue" \| wc -l \| grep -q 6` | ❌ W0 | ⬜ pending |
| 05-04-03 | 04 | 1 | EDITOR-01, EDITOR-02, EDITOR-03 | T-05-19 | StepsField drag-and-drop + AssetTransformation.json route steps→StepsField | build | `npm run build` | ❌ W0 | ⬜ pending |
| 05-05-01 | 05 | 2 | OPS-05 | T-05-21, T-05-22 | TransformationMetrics 6 méthodes + monolog channel transformations_metrics + instrumentation 3 points | unit + container check | `phpunit --filter="TransformationMetricsTest" && php bin/console debug:container monolog.logger.transformations_metrics` | ❌ W0 | ⬜ pending |
| 05-05-02 | 05 | 2 | EDITOR-04, EDITOR-05 | T-05-23, T-05-24, T-05-25 | PreviewPanel + AssetPickerDialog + usePreviewUrl : blob ref-counted, 429 visible avec Retry-After | type-check + build | `npm run build && npx vue-tsc --noEmit` | ❌ W0 | ⬜ pending |
| 05-05-03 | 05 | 2 | EDITOR-04, EDITOR-08 | — | i18n FR + EN clés preview/picker/warnings présentes | i18n check | `node -e "..."` (cf. plan 05 Task 3) | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `api/tests/Integration/PreviewEndpointTest.php` — couvre EDITOR-04 (200 + no-store + binary) — Plan 01 Task 1 stub, Task 2 finalise
- [ ] `api/tests/Integration/PreviewRateLimitTest.php` — couvre EDITOR-04 (429 + Retry-After) — Plan 01
- [ ] `api/tests/Integration/PreviewBypassCacheTest.php` — couvre EDITOR-05 (pas d'écriture S3) — Plan 01
- [ ] `api/tests/Smoke/MessengerTransportsTest.php` — couvre OPS-03 — Plan 02
- [ ] `api/tests/Smoke/MessengerFailedQueuesTest.php` — couvre OPS-04 — Plan 02
- [ ] `api/tests/Unit/MessageHandler/WarmupTransformationVariantHandlerTest.php` — Plan 02
- [ ] `api/tests/Unit/Command/TransformationsWarmCommandTest.php` — couvre OPS-01 — Plan 03
- [ ] `api/tests/Unit/Command/TransformationsGcCommandTest.php` — couvre OPS-02 — Plan 03
- [ ] `api/tests/Unit/Service/TransformationMetricsTest.php` — couvre OPS-05 — Plan 05
- [ ] PWA test runner (vitest/@vue/test-utils) **non installé en Phase 5** — décision pragmatique (aucun runner en Phase 1-4) ; couverture via `vue-tsc --noEmit` + `npm run build` + smoke manuel. Follow-up post-v1.0 noté.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Drag-and-drop reorder steps in UI | EDITOR-02 | Aucun runner E2E installé ; vuedraggable + touch events non testables en unit | Ouvrir `http://localhost:5173/resource/asset_transformations/{id}/edit`, drag un step, recharger, vérifier ordre persisté |
| Sous-formulaire dynamique par type | EDITOR-03 | Composants Vuetify non testés en headless | Ajouter chaque type de step via menu, vérifier le formulaire dédié apparaît |
| Warning banner JPEG + remove_background | EDITOR-08 | Composé runtime + i18n | Créer transformation avec steps `[remove_background, format_convert(jpg)]`, vérifier banner orange + chip warning |
| Preview round-trip (clic → image) | EDITOR-04, EDITOR-05 | Browser blob URL + JWT auth | Cliquer "Prévisualiser" sur transformation existante avec asset public, vérifier image affichée + Network tab montre `no-store` |
| 429 message visible | EDITOR-04 | Rate-limit en condition réelle (Redis) | Cliquer 11× rapidement "Prévisualiser", vérifier v-alert avec countdown Retry-After |
| Worker logs Datadog-ready | OPS-05 | Vérification format JSON sur stderr | `docker compose logs api 2>&1 \| tail -30 \| grep '"metric":"transformations\.'` après hit `/t/*` |
| `transformations:gc --dry-run` output format | OPS-02 | Output console multi-lignes ergonomique | `docker compose exec api php bin/console transformations:gc --dry-run` (sur env seed) |
| OPS-06 no auto backfill | OPS-06 | Vérif absence de hook | `grep -r "transformations:warm" .github/ docker-compose*.yml deploy/ 2>/dev/null` doit être vide hors docs |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (9 fichiers test API explicitement listés)
- [x] No watch-mode flags
- [x] Feedback latency < 120s (full API suite)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending (status: planned, awaiting execution)
