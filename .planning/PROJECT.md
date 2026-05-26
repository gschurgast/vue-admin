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
- Handlers Imagine pour resize/crop/rotate/format_convert/add_background
- Suppression d'arrière-plan via le service `embedder` étendu (rembg + u2net)
- Éditeur drag-and-drop des steps avec preview live dans la PWA
- Warmup async (Messenger), commande de GC du cache, métriques

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
| bgremover intégré au service `embedder` | Éviter un 3e container ML, mutualiser ressources | — Pending |
| Imagine pour transformations classiques | Mature, intégration Symfony native | — Pending |

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
*Last updated: 2026-05-26 after initialisation GSD brownfield + démarrage milestone v1.0 Asset Transformations*
