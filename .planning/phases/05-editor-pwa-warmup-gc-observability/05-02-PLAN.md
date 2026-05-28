---
phase: 05
plan: 02
type: execute
wave: 1
depends_on: []
files_modified:
  - api/config/packages/messenger.yaml
  - api/src/Message/WarmupTransformationVariantMessage.php
  - api/src/MessageHandler/WarmupTransformationVariantHandler.php
  - docker-compose.yml
  - api/tests/Smoke/MessengerTransportsTest.php
  - api/tests/Smoke/MessengerFailedQueuesTest.php
  - api/tests/Unit/MessageHandler/WarmupTransformationVariantHandlerTest.php
autonomous: true
requirements: [OPS-03, OPS-04]
must_haves:
  truths:
    - "3 transports Messenger sont déclarés et opérationnels : async (intouché), transformations (warmup live), transformations_backfill (purge/bulk)"
    - "Chaque transport a sa propre failed queue distincte : async_failed, transformations_failed, transformations_backfill_failed"
    - "WarmupTransformationVariantMessage est routé sur transport=transformations et son handler appelle PipelineRunner avec cache write (warmup remplit le cache)"
    - "docker-compose.yml expose 3 workers distincts (worker pour async, worker-transformations, worker-transformations-backfill) qui consomment leur transport respectif"
  artifacts:
    - path: "api/config/packages/messenger.yaml"
      provides: "3 transports + 3 failed transports + routing per-message"
      contains: "transformations:"
    - path: "api/src/Message/WarmupTransformationVariantMessage.php"
      provides: "Message DTO {transformationId, assetId}"
      contains: "class WarmupTransformationVariantMessage"
    - path: "api/src/MessageHandler/WarmupTransformationVariantHandler.php"
      provides: "Handler invoque PipelineRunner avec cache write (bypassCache=false)"
      contains: "AsMessageHandler"
    - path: "docker-compose.yml"
      provides: "Services worker-transformations + worker-transformations-backfill"
      contains: "worker-transformations"
  key_links:
    - from: "WarmupTransformationVariantMessage"
      to: "transport=transformations"
      via: "routing dans messenger.yaml"
      pattern: "App\\\\Message\\\\WarmupTransformationVariantMessage: transformations"
    - from: "Worker docker"
      to: "transport spécifique"
      via: "command `messenger:consume <transport>`"
      pattern: "messenger:consume"
---

<objective>
Câbler **3 transports Messenger distincts** (`async`, `transformations`, `transformations_backfill`) avec **failed queues séparées**, créer le `WarmupTransformationVariantMessage` + handler dispatché par le Plan 03 (commande `transformations:warm`), et provisionner les **workers Docker dédiés**.

Purpose : OPS-03 (3 transports) + OPS-04 (workers + failed séparées). Indépendant du Plan 01 (preview). Consommé par Plan 03 (commande warm).
Output : `docker compose up -d worker-transformations` consomme le transport `transformations`, `messenger:failed:show --transport=transformations_failed` est inspectable.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-RESEARCH.md

# Config Messenger existante (à étendre)
@api/config/packages/messenger.yaml

# docker-compose actuel (à étendre)
@docker-compose.yml

# Pattern Message + Handler existant (Phase 1 purge)
@api/src/Message/PurgeTransformationVariantsMessage.php

# PipelineRunner (cache write côté warmup)
@api/src/Service/PipelineRunner.php
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| ops CLI → MessageBus | Dispatch de jobs warmup (Plan 03) ; aucune surface publique |
| Worker → Redis Streams | Polling continu, retry exponentiel, failed queue isolée |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-08 | DoS | Saturation Redis failed queue | mitigate | max_retries:3 + multiplier:2 + max_delay:30000ms ; alerte Datadog sur taille stream (doc Plan 05) |
| T-05-09 | Tampering | Message routé sur mauvais transport | mitigate | Tests smoke MessengerTransportsTest assert routing config explicite |
| T-05-10 | DoS | Touche au transport `async` casse CLIP en flight | mitigate | Aucune modification du bloc `async` existant ; test smoke vérifie ComputeEmbeddingMessage reste routé sur async |
| T-05-11 | EoP | Worker process partage cache file Symfony | mitigate | APP_CACHE_DIR distinct par worker container (env var) — voir Pitfall 7 RESEARCH.md |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 + messenger.yaml refactor + Message/Handler</name>
  <files>api/config/packages/messenger.yaml, api/src/Message/WarmupTransformationVariantMessage.php, api/src/MessageHandler/WarmupTransformationVariantHandler.php, api/tests/Smoke/MessengerTransportsTest.php, api/tests/Smoke/MessengerFailedQueuesTest.php, api/tests/Unit/MessageHandler/WarmupTransformationVariantHandlerTest.php</files>
  <behavior>
    - MessengerTransportsTest : `$container->getParameter('messenger.transport.transformations')` existe ; routing `WarmupTransformationVariantMessage → transformations` ; routing `ComputeEmbeddingMessage → async` intact (T-05-10)
    - MessengerFailedQueuesTest : chaque transport déclare `failure_transport: <name>_failed` distinct ; les 3 failed transports sont DSN différents
    - WarmupTransformationVariantHandlerTest : handler reçoit `WarmupTransformationVariantMessage($txId, $assetId)`, charge l'asset+transformation, appelle `PipelineRunner::run($steps, $asset, $defaultExt, bypassCache: false)` (warmup PRE-REMPLIT le cache, per A6 RESEARCH)
  </behavior>
  <action>
    1. Étendre `api/config/packages/messenger.yaml` (per D-18/D-19 + Pattern 5 RESEARCH). Ajouter :
       - transport `transformations` (DSN `redis://redis:6379/messages_transformations`, options `group: transformations`, `failure_transport: transformations_failed`, retry max=3 delay=2000 multiplier=2 max_delay=30000)
       - transport `transformations_backfill` (DSN `redis://redis:6379/messages_transformations_backfill`, `failure_transport: transformations_backfill_failed`, retry max=3 delay=5000 multiplier=2 max_delay=120000)
       - failed transports : `async_failed`, `transformations_failed`, `transformations_backfill_failed` (chacun DSN Redis distinct)
       - sur le bloc `async` existant, ajouter UNIQUEMENT `failure_transport: async_failed` (ne pas toucher au DSN/options, T-05-10)
       - routing : `App\Message\WarmupTransformationVariantMessage: transformations` ; `App\Message\PurgeTransformationVariantsMessage: transformations_backfill` (déjà câblé Phase 1 — vérifier la cible)
    2. Créer `WarmupTransformationVariantMessage` (readonly DTO) : `public int $transformationId, public int $assetId`. Suit le pattern `PurgeTransformationVariantsMessage`.
    3. Créer `WarmupTransformationVariantHandler` avec `#[AsMessageHandler]`. Injecte `AssetTransformationRepository`, `AssetRepository`, `PipelineRunner`, `LoggerInterface`. Logique : charge tx + asset → si manquant log warning + return (idempotent) → boucle sur `tx->getOutputExts()` (ou ext par défaut `png`) → `$runner->run($tx->getSteps(), $asset, $ext, bypassCache: false)` (cache write activé). Catch exception → re-throw pour retry Messenger.
    4. Écrire les 3 tests : 2 smoke + 1 unit. WarmupTransformationVariantHandlerTest utilise mock PipelineRunner pour vérifier `bypassCache: false` (warmup remplit cache).
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="MessengerTransportsTest|MessengerFailedQueuesTest|WarmupTransformationVariantHandlerTest"</automated>
  </verify>
  <done>
    - `php bin/console debug:messenger` liste 3 transports + routing correct
    - `php bin/console messenger:failed:show --transport=transformations_failed` retourne vide (config OK)
    - Tests green
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Docker workers dédiés (worker-transformations + worker-transformations-backfill)</name>
  <files>docker-compose.yml</files>
  <behavior>
    - `docker compose config` valide la déclaration des 3 services workers
    - Chaque worker consomme `messenger:consume <transport>` avec `--time-limit=3600 --memory-limit=512M`
    - APP_CACHE_DIR distinct par worker (T-05-11) pour éviter conflits cache pool
    - `docker compose up -d worker-transformations` démarre et reste sain (healthcheck ou log "Consuming messages")
  </behavior>
  <action>
    1. Dans `docker-compose.yml`, vérifier le service `worker` existant (consomme `async`) et **conserver son comportement actuel** (per D-18 « intouché »).
    2. Ajouter 2 services clonés (mêmes image/volumes/depends_on que `worker` actuel) :
       - `worker-transformations` : `command: php bin/console messenger:consume transformations --time-limit=3600 --memory-limit=512M -vv` + `environment: APP_CACHE_DIR=/tmp/cache-transformations`
       - `worker-transformations-backfill` : idem avec transport `transformations_backfill` + `APP_CACHE_DIR=/tmp/cache-transformations-backfill`
    3. Restart policy : `restart: unless-stopped` (alignement service `worker` actuel).
    4. Vérifier `docker compose config` (lint) puis `docker compose up -d worker-transformations worker-transformations-backfill` et `docker compose logs --tail=20 worker-transformations` doit contenir un log Messenger valide.
    5. Commit atomique séparé de la Task 1 (config Docker = surface infra distincte).
  </action>
  <verify>
    <automated>docker compose config --quiet && docker compose up -d worker-transformations worker-transformations-backfill && sleep 3 && docker compose ps --status running | grep -E "worker-transformations(-backfill)?" | wc -l | grep -q 2</automated>
  </verify>
  <done>
    - 3 services worker* listés `running` dans `docker compose ps`
    - Logs `worker-transformations` confirment connexion Redis Streams sur `messages_transformations`
    - Aucun cache pool conflict (pas d'erreur `cache pool already locked` dans logs)
  </done>
</task>

</tasks>

<verification>
- `docker compose exec api php bin/console debug:messenger` → 3 transports listés
- `docker compose exec api php bin/console messenger:failed:show --transport=transformations_failed` → vide (config valide)
- `docker compose ps` → 3 services worker running
- Tests `phpunit --filter=Messenger` green
</verification>

<success_criteria>
OPS-03 et OPS-04 livrés. `WarmupTransformationVariantMessage` + handler prêts pour consommation Plan 03 (commande warm). Aucune régression sur CLIP `async`.
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-02-SUMMARY.md`
</output>
