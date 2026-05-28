---
phase: 05
plan: 03
type: execute
wave: 2
depends_on: [02]
files_modified:
  - api/src/Command/TransformationsWarmCommand.php
  - api/src/Command/TransformationsGcCommand.php
  - docs/transformations-ops.md
  - api/tests/Unit/Command/TransformationsWarmCommandTest.php
  - api/tests/Unit/Command/TransformationsGcCommandTest.php
autonomous: true
requirements: [OPS-01, OPS-02, OPS-06]
must_haves:
  truths:
    - "`php bin/console transformations:warm {code} --asset-id=N` dispatch un WarmupTransformationVariantMessage sur transport=transformations ; refuse sans --asset-id (per D-14)"
    - "Validation : code existe, asset existe et isPublic=true ; sinon Command::FAILURE avec message explicite"
    - "`php bin/console transformations:gc --dry-run` énumère les hashes non-actifs via Flysystem::listContents et affiche le résumé per D-16"
    - "`--keep=N` (défaut N=2) garde les N derniers hashes par mtime décroissant (hash actif inclus de force)"
    - "Sans --dry-run et sans --force en TTY interactif : demande confirmation avant DELETE"
    - "Aucun hook de déploiement n'invoque ces commandes (OPS-06 : backfill manuel uniquement)"
  artifacts:
    - path: "api/src/Command/TransformationsWarmCommand.php"
      provides: "Commande Symfony Console transformations:warm"
      contains: "name: 'transformations:warm'"
    - path: "api/src/Command/TransformationsGcCommand.php"
      provides: "Commande Symfony Console transformations:gc"
      contains: "name: 'transformations:gc'"
    - path: "docs/transformations-ops.md"
      provides: "Documentation ops : commandes, transports, failed queues, scheduling Webfacto"
      contains: "transformations:warm"
  key_links:
    - from: "TransformationsWarmCommand"
      to: "MessageBus → transport=transformations"
      via: "$bus->dispatch(WarmupTransformationVariantMessage)"
      pattern: "WarmupTransformationVariantMessage"
    - from: "TransformationsGcCommand"
      to: "Flysystem::listContents('transformations/{txId}-v', deep:true)"
      via: "scan préfixe + DELETE par hash non-actif"
      pattern: "listContents.*transformations"
---

<objective>
Livrer les **commandes ops** `transformations:warm` et `transformations:gc` + documentation `docs/transformations-ops.md`. Couvre OPS-01 (warm), OPS-02 (gc avec `--keep=2` par défaut, per révision D-15) et OPS-06 (aucun backfill auto au deploy — documenté).

Purpose : Outillage ops pour Webfacto. Consomme le `WarmupTransformationVariantMessage` du Plan 02.
Output : 2 commandes Symfony Console + doc ops complète.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-CONTEXT.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/05-RESEARCH.md
@.planning/phases/05-editor-pwa-warmup-gc-observability/plans/02-messenger-transports-warmup-PLAN.md

# Helper TransformationStorageKey (Phase 1)
@api/src/Service/TransformationStorageKey.php

# Pattern Symfony Console existant
# (chercher dans api/src/Command/ pour un blueprint)

<interfaces>
Message dispatché (déclaré Plan 02) :

```php
final readonly class WarmupTransformationVariantMessage {
    public function __construct(public int $transformationId, public int $assetId) {}
}
```

Storage key (déjà existant Phase 1) :
```php
TransformationStorageKey::buildPrefix(int $txId, string $hash): string;
// returns "transformations/{txId}-v{hash}"
```
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| ops CLI (humain ou cron) → MessageBus / Flysystem | Accès local au container API, pas de surface publique |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-12 | Tampering (data loss) | gc supprime variants encore servis | mitigate | `--dry-run` par défaut visuel + confirmation interactive si stdin TTY + log JSON de chaque DELETE (audit, voir Plan 05 metrics) |
| T-05-13 | Tampering (logique fausse) | gc lit uniquement le hash actif → ne supprime rien | mitigate | Enumération via `Flysystem::listContents` du préfixe `transformations/{txId}-v` ; tests unit avec FlysystemMock vérifient découverte des hashes orphelins (Pitfall 6 RESEARCH) |
| T-05-14 | DoS | warm sans --asset-id → bulk infini | mitigate | `--asset-id` REQUIRED (D-14) ; refuse Command::FAILURE si absent |
| T-05-15 | Information Disclosure | warm sur asset privé (isPublic=false) | mitigate | Check `$asset->isPublic()` AVANT dispatch ; refuse Command::FAILURE sinon |
| T-05-16 | EoP (deploy hook bypass) | Backfill auto au déploiement | accept | OPS-06 documenté dans `docs/transformations-ops.md` ; pas de hook code (Webfacto contrat) |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Wave 0 stubs + TransformationsWarmCommand</name>
  <files>api/src/Command/TransformationsWarmCommand.php, api/tests/Unit/Command/TransformationsWarmCommandTest.php, api/tests/Unit/Command/TransformationsGcCommandTest.php</files>
  <behavior>
    - WarmCommandTest::testRequiresAssetId() — sans `--asset-id` → Command::FAILURE + message "asset-id is required" (T-05-14)
    - WarmCommandTest::testRejectsUnknownCode() — code inexistant → FAILURE + "Transformation not found"
    - WarmCommandTest::testRejectsNonPublicAsset() — `isPublic=false` → FAILURE + "Asset must be public" (T-05-15)
    - WarmCommandTest::testDispatchesMessage() — happy path → MessageBus mock reçoit `WarmupTransformationVariantMessage($txId, $assetId)` une fois
    - GcCommandTest stub (markTestIncomplete) — sera vert en Task 2
  </behavior>
  <action>
    1. Créer `TransformationsWarmCommand` avec `#[AsCommand(name: 'transformations:warm', description: 'Dispatch a warmup job for one transformation+asset pair.')]`. Pattern Example 2 RESEARCH.
    2. Configure :
       - argument `code` REQUIRED
       - option `--asset-id` REQUIRED (per D-14, refuse l'omission explicitement, T-05-14)
    3. Execute :
       - Vérifie `$assetId` non vide
       - `$tx = $transformations->findOneBy(['code' => $code])` → null = FAILURE
       - `$asset = $assets->find($assetId)` → null = FAILURE
       - `if (!$asset->isPublic()) return FAILURE` (T-05-15)
       - `$bus->dispatch(new WarmupTransformationVariantMessage($tx->getId(), $asset->getId()))`
       - Log + return SUCCESS
    4. Tests unit complets via `CommandTester` + mocks (MessageBusInterface, repositories).
    5. Stub `TransformationsGcCommandTest` (markTestIncomplete, signatures).
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="TransformationsWarmCommandTest"</automated>
  </verify>
  <done>
    - `docker compose exec api php bin/console transformations:warm product-thumb --asset-id=1` dispatch un message (vérifiable via worker logs)
    - Tests warm green ; gc stubs incomplete
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: TransformationsGcCommand (--dry-run + --keep=2 + --force)</name>
  <files>api/src/Command/TransformationsGcCommand.php, api/tests/Unit/Command/TransformationsGcCommandTest.php</files>
  <behavior>
    - GcCommandTest::testDryRunEnumeratesNonActiveHashes() — 3 hashes en S3, 1 actif → dry-run liste 2 hashes à supprimer + tailles + count, aucun DELETE appelé (T-05-13)
    - GcCommandTest::testKeepTwoDefault() — `--keep=2` (défaut) : hash actif + 1 plus récent gardés ; les autres supprimés
    - GcCommandTest::testKeepOneExplicit() — `--keep=1` : seul le hash actif est gardé
    - GcCommandTest::testForceDeletesWithoutPrompt() — `--force` + sans --dry-run → DELETE appelés sans prompt
    - GcCommandTest::testInteractivePromptNoExecutesNoDeletes() — sans --force ni --dry-run en TTY, réponse "no" → 0 DELETE (T-05-12)
  </behavior>
  <action>
    1. Créer `TransformationsGcCommand` avec `#[AsCommand(name: 'transformations:gc', description: 'GC orphan transformation variants (keep N most recent hashes).')]`.
    2. Options :
       - `--dry-run` (no value, défaut implicite false) — print only, no delete
       - `--keep=N` (défaut **2**, per D-15 révisé alignement ROADMAP) — INT validé > 0
       - `--force` — skip interactive confirmation (pour cron)
    3. Logique (cf. Example 3 RESEARCH) :
       - Itère sur toutes les `AssetTransformation` du repo
       - Pour chaque tx : `$prefix = "transformations/{$tx->getId()}-v"` ; `$items = $fs->listContents($prefix, deep: true)`
       - Group par hash via regex `#transformations/\d+-v([0-9a-f]+)/#` → map hash → {bytes, count, mtime}
       - Sort par mtime desc ; force-include `$tx->getVersionHash()` en position 0 (hash actif TOUJOURS gardé même si mtime ancien)
       - `$keep = array_slice($sortedHashes, 0, $keepN, preserve_keys: true)` (avec actif forcé)
       - `$toDelete = array_diff_key($all, $keep)`
       - Output per-tx (D-16) + grand total
       - Si !`--dry-run` : si !`--force` et `$input->isInteractive()` → `$io->confirm("Proceed with DELETE?")` → si "no" abort (T-05-12) ; sinon `$fs->deleteDirectory("transformations/{txId}-v{hash}")` par hash à delete + log JSON par DELETE
    4. Tests unit avec `Flysystem InMemoryFilesystemAdapter` (lib `league/flysystem-memory` déjà dispo via composer-test). Seed 3 hashes, asserter dry-run + keep logic + interactive prompt via `CommandTester::setInputs(['no'])`.
  </action>
  <verify>
    <automated>docker compose exec api ./vendor/bin/phpunit --filter="TransformationsGcCommandTest"</automated>
  </verify>
  <done>
    - `docker compose exec api php bin/console transformations:gc --dry-run` produit sortie format D-16
    - `--keep=2` est le défaut affiché dans `php bin/console transformations:gc --help`
    - Tests green
  </done>
</task>

<task type="auto">
  <name>Task 3: docs/transformations-ops.md</name>
  <files>docs/transformations-ops.md</files>
  <action>
    Créer `docs/transformations-ops.md` avec sections :
    1. **Commandes ops** : usage de `transformations:warm` (avec note `--asset-id` requis, bulk reporté) et `transformations:gc` (avec note `--keep=2` par défaut = hash actif + 1 précédent pour rollback rapide ; superseded D-15 init N=1).
    2. **Transports Messenger** : tableau des 3 transports (async/transformations/transformations_backfill) avec DSN, retry policy, message types routés. Lien vers `messenger.yaml`.
    3. **Failed queues** : commandes `messenger:failed:show --transport=X` et `messenger:failed:retry --transport=X` pour chaque transport. Note inspection Redis Streams.
    4. **Métriques (facets Datadog attendus)** : liste des `metric` names émis par `TransformationMetrics` (cf. Plan 05) + structure JSON commune `{metric, value, unit, transformation_id, step_type, transport, ...}`.
    5. **Embedder /health** : note sur D-23, scrape périodique recommandé (commande TBD ou listener inline).
    6. **Scheduling prod (à valider Webfacto)** : section OPS-06 explicite « aucun hook de déploiement n'invoque ces commandes » + recommandations cron (ex: `transformations:gc --force` weekly). Préfixer la section par `> ⚠ Validation Webfacto requise avant industrialisation`.
    7. **Workers Docker** : map service docker-compose → transport + commande `messenger:consume`.
    Format Markdown structuré (h2/h3), commandes en blocs ` ```bash `. Pas de placeholder TBD non motivé.
  </action>
  <verify>
    <automated>test -f docs/transformations-ops.md && grep -E "^## " docs/transformations-ops.md | wc -l | awk '$1>=6'</automated>
  </verify>
  <done>
    - Fichier présent avec au moins 6 sections H2
    - Mention explicite OPS-06 (no auto backfill) + rappel Webfacto
    - Section facets Datadog référence le format JSON commun de TransformationMetrics
  </done>
</task>

</tasks>

<verification>
- `docker compose exec api ./vendor/bin/phpunit --filter="Transformations(Warm|Gc)Command"` green
- `docker compose exec api php bin/console transformations:warm --help` montre `--asset-id` REQUIRED
- `docker compose exec api php bin/console transformations:gc --help` montre `--keep=2` default
- `docs/transformations-ops.md` lisible
</verification>

<success_criteria>
OPS-01, OPS-02, OPS-06 livrés. Doc ops fournit à Webfacto le matériel pour cadrer le scheduling prod.
</success_criteria>

<output>
After completion, create `.planning/phases/05-editor-pwa-warmup-gc-observability/05-03-SUMMARY.md`
</output>
