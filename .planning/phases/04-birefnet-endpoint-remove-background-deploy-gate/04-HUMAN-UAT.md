---
status: resolved
phase: 04-birefnet-endpoint-remove-background-deploy-gate
source: [04-VERIFICATION.md, 04-DEPLOY-CHECKLIST.md]
started: 2026-05-27T00:00:00Z
updated: 2026-05-27T00:00:00Z
resolved_via: approved-deploy checkpoint (Plan 04-05 Task 3) — Webfacto signoff
---

## Current Test

[resolved — Webfacto signed off via `approved-deploy` at Plan 04-05 checkpoint]

## Tests

### 1. Latence p95 BiRefNet < 3000ms sur 3+ assets réels
expected: p95 < 3 s sur photo produit 2048² (D-13 item 4, BGREMOVE-05)
result: passed (Webfacto staging+prod measurement signed off — see 04-DEPLOY-CHECKLIST.md)

### 2. RAM container ≥ 3 GB allouée + stable 24h sans OOM
expected: docker stats stable, pas d'OOMKilled sur 24h staging (D-13 item 3)
result: passed (Webfacto signoff)

### 3. Rate-limit /t/* configuré côté CDN avant exposition publique
expected: Policy CDN appliquée, vérifiée via burst > N requêtes → 429 (D-13 item 6)
result: passed (Webfacto signoff)

### 4. Visual quality du masque BiRefNet sur 3+ AssetTransformations staging
expected: Qualité visuelle acceptable sur photos produit complexes (D-13 item 5)
result: passed (Webfacto signoff)

## Summary

total: 4
passed: 4
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

None — all hard-gate D-13 items validated by Webfacto before close of Plan 04-05.
