# Antigravity

## What This Is

Application d'administration schema-driven (API Platform + Vue 3 / Vuetify) qui génère dynamiquement les écrans CRUD à partir de la documentation Hydra/OpenAPI de l'API. Pile e-commerce/PIM (produits, attributs, collections, assets) avec assistant IA intégré et déduplication visuelle CLIP des assets. Cible : équipes internes Vente-Unique / CAFOM (PIM, marketing, contenu).

## Core Value

Permettre aux équipes métier de gérer le catalogue (produits, attributs, assets) sans dépendance dev grâce à l'introspection automatique de l'API et aux composants de rendu personnalisables par resource.

## Requirements

### Validated

<!-- Capacités déjà livrées et confirmées dans le code (inférées du CLAUDE.md + git log). -->

- ✓ Génération dynamique des CRUD à partir du schéma API Platform — v0
- ✓ Authentification JWT (`/api/login`, rôles `ROLE_USER` / `ROLE_ADMIN`) — v0
- ✓ Gestion des produits, attributs, collections (entités Doctrine + UI) — v0
- ✓ Attributs localisables avec sélecteur de locale partagé (`useFormLocale`) — v0
- ✓ Traduction en masse des valeurs d'attribut (`POST /api/translate_pav_requests`) — v0
- ✓ Assistant IA conversationnel (chat + voix, Redis 24h TTL) — v0
- ✓ Gestion des Assets (upload, déduplication SHA-256 + visuelle CLIP, stockage S3/local) — v0
- ✓ Pipeline async d'embedding CLIP (Messenger + Redis Streams + pgvector HNSW) — v0
- ✓ Détection de similarité ≥0.95 (duplicate) et ≥0.75 (similar) sur les images — v0
- ✓ Composants list/show/edit personnalisables par resource via JSON config — v0
- ✓ i18n 14 langues — v0
- ✓ Domaine versioning : entités AssetTransformation/TransformationStep + StepType enum + listener `TransformationHashListener` qui calcule `versionHash` (SHA-1 canonique des steps) — Phase 1
- ✓ Service Python `embedder` étendu avec 5 endpoints classiques (`/img/resize`, `/img/crop`, `/img/rotate`, `/img/format-convert`, `/img/add-background`) via Pillow — Phase 2
- ✓ Orchestrateur PHP synchrone : `PipelineRunner` (cap 8s), 5 `StepHandler`s HTTP vers embedder, DTO validators par step type — Phase 3
- ✓ Route publique `GET /t/{code}/{id}.{ext}` derrière feature flag, lock Redis anti-thundering-herd, cache S3 versionné, ETag déterministe, header `X-Transformation-Warnings` — Phase 3
- ✓ Endpoint `POST /img/remove-background` (BiRefNet FP16 + isnet fallback) ONNX Runtime baked-in Docker, asyncio.Lock + timeout 5s, `/health` enrichi, handler PHP sync `RemoveBackgroundHandler` ; hard gate D-13 signé Webfacto — Phase 4

### Active

<!-- Scope du milestone en cours — défini ci-dessous (## Current Milestone). -->

(Voir Current Milestone)

### Out of Scope

- Exposition publique des assets originaux — sécurité (les binaires restent derrière JWT)
- Refonte de l'authentification (SSO, OAuth) — pas prioritaire pour ce milestone
- Mobile natif — la PWA suffit aux usages internes

## Current Milestone: v1.0 Asset Transformations

**Goal:** Permettre aux utilisateurs de définir des pipelines de transformations d'assets (resize, crop, rotate, format conversion, add/remove background) exposés via une URL publique cacheable `/t/{code}/{id}.{ext}`.

**Target features:**
- Définition de transformations nommées (`code`) composées de steps ordonnées paramétrables
- Route publique avec conversion forcée par extension d'URL (png/jpg/webp/avif) et cache S3 versionné par hash de steps
- **Toute la manipulation d'image en Python** dans le service `embedder` (perf supérieure à Imagine PHP) — un endpoint par step type
- Steps classiques (resize, crop, rotate, format_convert, add_background color/asset) via Pillow / OpenCV
- Suppression d'arrière-plan via **BiRefNet** (licence MIT, usage commercial OK)
- Éditeur drag-and-drop des steps avec preview live dans la PWA
- Warmup async (Messenger), commande de GC du cache, métriques

**Reporté hors v1.0 :** Ajout de fond par IA (Stable Diffusion add_background type:ai_prompt) — cadrage Webfacto requis (RAM 4-7 GB, latence CPU 30-180s, transport Messenger dédié `transformations_ai`, possible GPU). Voir Future Requirements dans REQUIREMENTS.md.

## Context

- Codebase brownfield mature (Symfony 7.3 / API Platform 4 / Vue 3 / Vuetify 3)
- Stockage : Flysystem (local en dev/test, S3 en prod), pgvector pour les embeddings
- Service Python `embedder/` déjà en place pour CLIP — sera étendu plutôt que dupliqué
- Worker Messenger déjà opérationnel pour les jobs async (embeddings)
- Tous les binaires d'assets passent actuellement par `/api/assets/{id}/content` (JWT)

## Constraints

- **Tech stack** : PHP 8.4 / Symfony 7.3 / API Platform 4 — pas de changement de stack
- **Sécurité** : Route `/t/*` publique mais l'asset original reste protégé. Pas de listing public.
- **Stockage** : Variantes stockées dans le même bucket S3 sous préfixe `transformations/{transformationId}-v{hash}/{shard}/{id}.{ext}`
- **Performance** : Première requête sync (verrou Redis anti-thundering-herd), requêtes suivantes servies depuis S3
- **Cadrage Webfacto** : Validation requise avant mise en prod (exposition publique, coût S3, ressources embedder)

## Key Decisions

| Décision | Rationale | Outcome |
|----------|-----------|---------|
| Route publique `/t/{code}/{id}.{ext}` | Cache CDN-friendly, immutable, l'original reste protégé | — Pending |
| Conversion forcée par extension d'URL | Une URL = un format déterministe, simplifie le caching | — Pending |
| Steps modifiables + versioning par hash sha1 | UX éditeur sans casser le cache, GC séparé pour les orphelins | — Pending |
| Toute la manipulation d'image en Python (pas Imagine PHP) | Perf supérieure ; mutualisation du container `embedder` ; accès direct aux modèles ML | — Pending |
| Un endpoint Python par step type | Modularité, debugging facilité ; le PHP orchestre via HTTP | — Pending |
| BiRefNet (MIT) pour remove_background | RMBG-1.4/2.0 non-commercial — incompatible avec usage commercial Vente-Unique | ✓ Phase 4 |
| Drop Stable Diffusion (add_background ai_prompt) de v1.0 | Cadrage Webfacto requis (RAM 4-7 GB, CPU 30-180s, transport AI dédié) — reporté hors v1.0 | ✓ 2026-05-27 |
| Sync-only 8s en v1.0 (cap dur) | Tous les step types restants sont sync-compatibles ; pas de chemin async nécessaire | ✓ 2026-05-27 |
| 3 transports Messenger (au lieu de 4) | Drop de `transformations_ai` : `async` (CLIP) / `transformations` (warmup) / `transformations_backfill` (bulk) | ✓ 2026-05-27 |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-27 — Drop SD/AI hors v1.0, renumérotation Phase 7 → Phase 5 (Editor PWA + Warmup + GC + Observability)*
