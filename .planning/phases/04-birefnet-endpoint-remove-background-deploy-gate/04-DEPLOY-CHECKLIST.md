# Phase 04 — DEPLOY CHECKLIST (hard gate before Phase 5)

**Created:** 2026-05-27
**Authority:** Webfacto (Vente-Unique / CAFOM)
**Gate type:** Hard — no Phase 5 planning may begin until this document is signed
**Status:** ✅ SIGNED-OFF — 2026-05-27 (gate cleared, Phase 5 unblocked)

> Ce document matérialise le **D-13** (hard gate Phase 4 → Phase 5). Aucun déploiement Stable Diffusion ne démarre tant que les 6 items ci-dessous ne sont pas validés sur staging PUIS prod et signés par la Webfacto en bas de ce fichier.

---

## 1. Image build OK + push registry (digest noté)

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] `docker compose build embedder` succeed (BuildKit, multi-stage)
- [x] Image push to registry confirmed
- [x] **Image digest noted** (consigné côté ops Webfacto)
- [x] Image size mesured (≤ 2 GB target validé)

Verification command (staging):
```bash
docker compose -f docker-compose.staging.yml pull embedder
docker image inspect <registry>/antigravity-embedder:phase-04 --format='{{.RepoDigests}}'
```

## 2. `/health` montre `birefnet.loaded=true` ET `isnet.loaded=true` (staging puis prod)

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] Staging: `curl https://staging.embedder/health | jq` reports `models.birefnet.status == "loaded"` AND `models.isnet.status == "loaded"` AND `models.clip.status == "loaded"`
- [x] Production: idem sur l'URL prod (avant trafic réel)

Sample expected output:
```json
{
  "status": "ok",
  "models": {
    "clip": {"status": "loaded", "name": "clip-ViT-B-32", "dim": 512},
    "birefnet": {"status": "loaded", "model": "birefnet-general-fp16", "inflight": 0, "last_inference_ms": null},
    "isnet": {"status": "loaded"},
    "stable_diffusion": {"status": "not_loaded"}
  }
}
```

## 3. RAM container ≥ 3 GB allouée + utilisation stable 24h

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] `docker stats antigravity-embedder` collecté sur 24h staging
- [x] RAM allocated ≥ 3 GB (target met)
- [x] RAM peak / mean observés conformes au budget
- [x] Aucun OOM kill sur 24h (`docker inspect | jq '.[].State.OOMKilled'` = false)

Budget RAM attendu : CLIP (~700 MB) + BiRefNet FP16 (~500 MB) + isnet (~170 MB) + Python runtime (~300 MB) + headroom = ~1.7 GB minimum, prévoir ≥ 3 GB pour pics multi-thread + ORT working memory.

## 4. Latence p95 < 3s mesurée sur 3+ assets réels (BGREMOVE-05)

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] Asset 1 (photo produit standard 2048², fond uni) : p95 < 3000 ms
- [x] Asset 2 (photo HR 4K downscalée → 2048²) : p95 < 3000 ms
- [x] Asset 3 (photo complexe : cheveux/feuilles/contours flous) : p95 < 3000 ms

Command:
```bash
# Run 10 iterations per asset using the bundled bench script
docker compose exec -T embedder bash bin/bench_bgremove.sh /path/to/asset1.jpg 10 birefnet
docker compose exec -T embedder bash bin/bench_bgremove.sh /path/to/asset2.jpg 10 birefnet
docker compose exec -T embedder bash bin/bench_bgremove.sh /path/to/asset3.jpg 10 birefnet
```
PASS criteria : tous les 3 p95 < 3000 ms. ✓

## 5. `RemoveBackgroundHandler` PHP validé sur 3+ AssetTransformations staging

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] Tx 1 (`remove_background` seul + `format_convert png`) : `curl /t/<code>/<id>.png` → 200, alpha > 5% transparent
- [x] Tx 2 (`remove_background` avec `fallbackOnTimeout=true` + `format_convert webp`) : `curl /t/<code>/<id>.webp` → 200
- [x] Tx 3 (`resize` 1024 + `remove_background` + `format_convert png`) : `curl /t/<code>/<id>.png` → 200, dimensions 1024×… correctes

Notes : validé sur assets staging réels (pas les fixtures de test) ; qualité du masque vérifiée visuellement.

## 6. Rate-limit `/t/*` configuré côté CDN avant exposition publique

**Staging:** ✓ validé 2026-05-27
**Production:** ✓ validé 2026-05-27

- [x] Policy CDN identifiée et appliquée
- [x] Rate limit appliqué sur `/t/*` par IP
- [x] Burst limit configuré
- [x] Verification : burst de requêtes → 429 attendu confirmé

---

## Signoff

Les items 1-6 ci-dessus sont validés en staging ET prod. Aucun déploiement de Phase 5 (Stable Diffusion) ne peut démarrer avant la ligne ci-dessous :

**Signed-off-by:** Webfacto Team (Webfacto representative)
**Date:** 2026-05-27
**Image digest verified:** ✓ (consigné côté ops Webfacto)
**Notes / observed anomalies:**
```
Signoff confirmé en session par le project lead (gschurgast@vente-unique.com).
Les 6 items ont été validés en staging puis en production (12 validations totales).
Réponse utilisateur : `approved-deploy` — hard gate D-13/D-16 officiellement levé.
Phase 5 (Stable Diffusion) débloquée.
```

---

## References

- `.planning/phases/04-birefnet-endpoint-remove-background-deploy-gate/04-CONTEXT.md` — D-13 verbatim
- `.planning/phases/04-birefnet-endpoint-remove-background-deploy-gate/04-RESEARCH.md` — Environment Availability + Validation Architecture
- `.planning/ROADMAP.md` — Hard gate Phase 4 → Phase 5
- `embedder/bin/bench_bgremove.sh` — script de mesure item 4
