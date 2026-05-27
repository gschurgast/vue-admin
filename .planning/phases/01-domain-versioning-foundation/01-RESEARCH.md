# Phase 1: Domain & Versioning Foundation — Research

**Researched:** 2026-05-27
**Domain:** Doctrine ORM entities, API Platform CRUD, canonical sha1 hasher, S3 key helper, Doctrine event listener + Symfony Messenger
**Confidence:** HIGH — toutes les conclusions sont ancrées dans les fichiers existants du repo

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TRANSFORM-01 | Entité AssetTransformation avec `code` unique kebab-case validé et libellé | Pattern Asset.php + AssetFlag.php + nouveau constraint TransformationCode |
| TRANSFORM-02 | Composition d'une liste ordonnée de steps typés (resize, crop, rotate, format_convert, add_background, remove_background) | Entité Step, OneToMany, json params, backed enum StepType |
| TRANSFORM-03 | Modification des steps sans casser le cache existant | versionHash recompute dans onFlush via listener, pas dans le step lui-même |
| TRANSFORM-04 | Recalcul automatique versionHash (sha1 canonical des steps) à chaque modification | TransformationHasher service + onFlush listener (§ Architecture Patterns) |
| TRANSFORM-05 | Helper de clé S3 `transformations/{transformationId}-v{hash}/{shard}/{assetId}.{ext}` | Classe statique TransformationStorageKey miroir de Asset::computeS3Key() |
| TRANSFORM-06 | Codes réservés (api, admin, t, _, assets, mono-caractères) rejetés avec 422 | Nouveau constraint AppAssert\TransformationCode + blocklist |
</phase_requirements>

---

## Project Constraints (from CLAUDE.md)

Directives extraites et applicables à cette phase :

| Directive | Impact sur cette phase |
|-----------|------------------------|
| Propriétés `private` avec getters/setters retournant `static` | AssetTransformation et Step respectent ce pattern |
| `#[MenuGroup()]` requis sur chaque `#[ApiResource]` | AssetTransformation → `Settings`, Step → `hidden` |
| `#[ApiFilter()]` en annotation de classe | SearchFilter sur `code`, `label` |
| Serialization : `#[Groups(['resource:read', 'resource:write'])]` | Groupes `asset_transformation:read/write` et `transformation_step:read/write` |
| `#[MaxDepth(1)]` sur les relations | Sur la relation Step→AssetTransformation ET sur la collection AssetTransformation.steps |
| Collections initialisées en constructeur avec `new ArrayCollection()` | `$this->steps = new ArrayCollection()` |
| Propriétés `code` : `#[ORM\Column(length: 50, unique: true)]` + `#[AppAssert\Code]` | Nouveau constraint TransformationCode (kebab-case ≠ snake_case existant) |
| State Processors nommés `*Processor` implémentant `ProcessorInterface` | PurgeTransformationVariantsProcessor si on utilise un processor pour delete |
| `make generate-types` après tout changement API | À exécuter après creation des entités |
| Migrations via `make:migration` | Une migration pour les deux tables |

---

## Summary

La Phase 1 est entièrement du PHP/Doctrine pur, sans dépendance vers le service Python embedder. Elle pose les trois fondations sur lesquelles tout le pipeline aval s'appuie : les entités de domaine, le mécanisme de versionnement (hash canonical), et le listener de purge asynchrone.

La structure existante du codebase (Asset.php, AssetFlag.php, ProductVariantDefaultListener.php, ComputeEmbeddingMessage.php) fournit des patterns directs et complets à reproduire. Les seuls points non triviaux sont : (1) la décision de créer un nouveau constraint `TransformationCode` pour le kebab-case (le `Code` existant est snake_case uniquement), (2) le timing exact du recompute du hash dans le cycle de vie Doctrine (onFlush + UnitOfWork), et (3) le dispatch du message de purge en postFlush pour éviter de re-rentrer dans la UoW.

**Recommandation principale :** Créer les entités dans `src/Entity/AssetTransformation/`, suivre le pattern ProductVariantDefaultListener pour le listener `onFlush`/`postFlush`, et ne pas hésiter à écrire un `TransformationHasher` pur (sans dépendances injectées) pour faciliter les tests golden-file.

---

## Standard Stack

### Core (tout déjà présent dans composer.json)

| Composant | Version | Rôle | Statut |
|-----------|---------|------|--------|
| `doctrine/orm` | `^3.6.5` | Mapping entités, lifecycle events, UnitOfWork | Déjà installé [VERIFIED: composer.json] |
| `api-platform/core` | `^4.3.5` | CRUD auto-exposé, groupes de sérialisation, validators 422 | Déjà installé [VERIFIED: composer.json] |
| `symfony/messenger` | `8.0.*` | Transport Redis Streams pour PurgeTransformationVariantsMessage | Déjà installé [VERIFIED: composer.json] |
| `symfony/redis-messenger` | `8.0.*` | Adapter Redis Streams pour Messenger | Déjà installé [VERIFIED: composer.json] |
| `symfony/validator` | `8.0.*` (transitif) | Constraints personnalisés (`TransformationCode`, `UniqueEntity`) | Déjà installé [VERIFIED: composer.json] |
| `doctrine/doctrine-bundle` | `^3.2.2` | `#[AsDoctrineListener]`, migrations | Déjà installé [VERIFIED: composer.json] |

### Pas de nouvelles dépendances Composer nécessaires pour la Phase 1

Cette phase est entièrement brownfield — aucun `composer require` n'est nécessaire.

---

## Architecture Patterns

### Structure des fichiers nouveaux

```
api/src/
├── Entity/
│   └── AssetTransformation/
│       ├── AssetTransformation.php     # entité principale (TRANSFORM-01)
│       └── TransformationStep.php      # entité step (TRANSFORM-02)
├── Enum/
│   └── StepType.php                    # backed enum: resize|crop|rotate|format_convert|add_background|remove_background
├── Validator/
│   ├── TransformationCode.php          # constraint kebab-case + blocklist (TRANSFORM-06)
│   └── TransformationCodeValidator.php
├── Service/
│   └── AssetTransformation/
│       ├── TransformationHasher.php    # sha1 canonical (TRANSFORM-04)
│       └── TransformationStorageKey.php # helper S3 key (TRANSFORM-05)
├── Message/
│   └── PurgeTransformationVariantsMessage.php  # (TRANSFORM-05 purge)
├── MessageHandler/
│   └── PurgeTransformationVariantsHandler.php  # Phase 3+ (stub ou vide Phase 1)
└── EventListener/
    └── TransformationHashListener.php  # onFlush + postFlush (TRANSFORM-04 + TRANSFORM-06 purge)
api/config/packages/
└── messenger.yaml                      # ajout transport transformations_backfill
api/migrations/
└── Version{ts}_AssetTransformation.php
```

### Pattern 1 : Entité AssetTransformation

Copie exacte du pattern Asset.php/AssetFlag.php :

```php
// Source: api/src/Entity/Asset/AssetFlag.php (pattern vérifié)
#[ORM\Entity]
#[ORM\Table(name: 'asset_transformation')]
#[UniqueEntity(fields: ['code'], message: 'Une transformation avec ce code existe déjà.')]
#[ApiFilter(SearchFilter::class, properties: ['code' => 'ipartial', 'label' => 'ipartial'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['asset_transformation:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['asset_transformation:write']],
)]
#[MenuGroup('Settings')]
class AssetTransformation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset_transformation:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[AppAssert\TransformationCode]   // kebab-case + blocklist
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?string $label = null;

    /**
     * sha1 canonical des steps. Recomputed automatically by TransformationHashListener.
     * Length: 40 chars (full sha1 hex). Stored in DB to allow GC queries by hash.
     */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['asset_transformation:read'])]
    private ?string $versionHash = null;

    /**
     * @var Collection<int, TransformationStep>
     */
    #[ORM\OneToMany(
        targetEntity: TransformationStep::class,
        mappedBy: 'transformation',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    #[MaxDepth(1)]
    private Collection $steps;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
    }
    // ... getters/setters retournant static
}
```

**Point critique** : `versionHash` est en lecture seule (`asset_transformation:read` uniquement, pas de `write`). Il est calculé exclusivement par le listener, jamais écrit via l'API.

### Pattern 2 : Entité TransformationStep

```php
// Source: pattern Asset.php relations + REQUIREMENTS TRANSFORM-02
#[ORM\Entity]
#[ORM\Table(name: 'transformation_step')]
#[ApiResource(
    operations: [], // pas d'opérations directes — géré via AssetTransformation imbriqué
)]
#[MenuGroup('hidden')]
class TransformationStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset_transformation:read', 'transformation_step:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssetTransformation::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[MaxDepth(1)]
    private ?AssetTransformation $transformation = null;

    #[ORM\Column(length: 30, enumType: StepType::class)]
    #[Assert\NotNull]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?StepType $type = null;

    /**
     * Step parameters as a JSON object. Validated per step type in Phase 3 (HANDLERS-03).
     * Phase 1: stored as-is, no deep validation beyond "is valid JSON array/object".
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private array $params = [];

    /**
     * Display/execution order (0-based). Must be unique per transformation.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private int $position = 0;
    // ... getters/setters retournant static
}
```

### Pattern 3 : Enum StepType

```php
// Source: pattern api/src/Enum/AssetType.php (backed enum)
enum StepType: string
{
    case RESIZE = 'resize';
    case CROP = 'crop';
    case ROTATE = 'rotate';
    case FORMAT_CONVERT = 'format_convert';
    case ADD_BACKGROUND = 'add_background';
    case REMOVE_BACKGROUND = 'remove_background';

    public function label(): string { return match($this) { ... }; }
    public function allCodes(): array { return array_column(self::cases(), 'value'); }
}
```

### Pattern 4 : TransformationHasher (service pur, sans dépendances DI)

```php
// Source: logique documentée dans ARCHITECTURE.md §6 + PITFALLS §11
// Service pur = aucun constructeur injecté = facile à tester sans container
final class TransformationHasher
{
    /**
     * Compute a deterministic sha1 hash over the ordered step list.
     *
     * Canonicalisation rules:
     * 1. Steps ordered by position (ascending) — ORDER BY enforced by ORM but re-sorted here for safety.
     * 2. For each step: ['type' => $step->getType()->value, 'params' => $canonicalParams]
     * 3. $canonicalParams = sort keys recursively, drop null values, cast ints/bools as-is.
     * 4. json_encode with JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION.
     * 5. sha1 over the UTF-8 string — return full 40-char hex.
     *
     * NO algorithm version prefix in v1.0 — add in v2 if needed.
     */
    public function compute(AssetTransformation $transformation): string
    {
        $steps = $transformation->getSteps()->toArray();
        usort($steps, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        $canonical = array_map(
            fn(TransformationStep $s) => [
                'type'   => $s->getType()->value,
                'params' => $this->canonicalizeParams($s->getParams()),
            ],
            $steps
        );

        return sha1(json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalizeParams(array $params): array
    {
        // Remove null values, sort keys recursively
        $params = array_filter($params, fn($v) => $v !== null);
        ksort($params);
        return array_map(
            fn($v) => is_array($v) ? $this->canonicalizeParams($v) : $v,
            $params
        );
    }
}
```

**Décision sur la longueur du hash** : stocker le sha1 complet 40 chars en DB. Le préfixe S3 utilise les premiers 8 chars (`substr($hash, 0, 8)`) pour garder les clés lisibles. La cohérence entre les deux est garantie par `TransformationStorageKey`.

### Pattern 5 : TransformationStorageKey (helper statique)

```php
// Source: Asset::computeS3Key() exact pattern (api/src/Entity/Asset/Asset.php ligne 205-209)
final class TransformationStorageKey
{
    /**
     * Returns the Flysystem path for a transformed variant.
     * Format: transformations/{transformationId}-v{hash8}/{shard}/{assetId}.{ext}
     * where hash8 = first 8 chars of sha1 versionHash
     *       shard = floor(assetId / 1000)  (same convention as Asset::computeS3Key)
     */
    public static function forVariant(
        int    $transformationId,
        string $versionHash,
        int    $assetId,
        string $ext,
    ): string {
        $shard = intdiv($assetId, 1000);
        $hash8 = substr($versionHash, 0, 8);
        $ext   = ltrim($ext, '.');
        return sprintf(
            'transformations/%d-v%s/%d/%d.%s',
            $transformationId,
            $hash8,
            $shard,
            $assetId,
            $ext,
        );
    }
}
```

### Pattern 6 : TransformationHashListener (onFlush + postFlush)

**Timing critique** : le dispatch Messenger NE DOIT PAS se faire dans `onFlush`. La raison : dans `onFlush`, la UoW est en cours de traitement ; dispatcher un message déclenche potentiellement une nouvelle flush qui corrompt l'état. Le pattern correct, démontré par ProductVariantDefaultListener.php, est :

- `onFlush` : détecter les changements, modifier les entités, appeler `recomputeSingleEntityChangeSet`
- `postFlush` : dispatcher les messages Messenger depuis `$this->pendingPurges`

```php
// Source: api/src/EventListener/ProductVariantDefaultListener.php (pattern exact à suivre)
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TransformationHashListener
{
    /** @var list<PurgeTransformationVariantsMessage> */
    private array $pendingPurges = [];

    public function __construct(
        private readonly TransformationHasher $hasher,
        private readonly MessageBusInterface $bus,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(AssetTransformation::class);

        $dirtyTransformationIds = [];

        // Steps inserted, updated or deleted → parent transformation needs rehash
        foreach ([
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ] as $entity) {
            if ($entity instanceof TransformationStep && $entity->getTransformation() !== null) {
                $id = $entity->getTransformation()->getId();
                if ($id !== null) {
                    $dirtyTransformationIds[$id] = $entity->getTransformation();
                }
            }
        }

        // AssetTransformation directly modified (label etc.) — still rehash for consistency
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof AssetTransformation && $entity->getId() !== null) {
                $dirtyTransformationIds[$entity->getId()] = $entity;
            }
        }

        foreach ($dirtyTransformationIds as $id => $transformation) {
            $oldHash = $transformation->getVersionHash();
            $newHash = $this->hasher->compute($transformation);
            if ($newHash === $oldHash) {
                continue;
            }
            $transformation->setVersionHash($newHash);
            $uow->recomputeSingleEntityChangeSet($meta, $transformation);
            if ($oldHash !== null) {
                $this->pendingPurges[] = new PurgeTransformationVariantsMessage($id, $oldHash);
            }
        }
    }

    public function postFlush(): void
    {
        foreach ($this->pendingPurges as $msg) {
            $this->bus->dispatch($msg);
        }
        $this->pendingPurges = [];
    }
}
```

**Cas spécial DELETE de AssetTransformation** : quand toute la transformation est supprimée, les steps sont cascadés en DB mais l'entité parent est dans `getScheduledEntityDeletions()`. Le listener doit aussi capturer ce cas pour dispatcher la purge du hash courant.

```php
// Dans onFlush, ajouter :
foreach ($uow->getScheduledEntityDeletions() as $entity) {
    if ($entity instanceof AssetTransformation && $entity->getVersionHash() !== null) {
        // Capture AVANT que la row disparaisse — l'entité est encore en mémoire
        $this->pendingPurges[] = new PurgeTransformationVariantsMessage(
            $entity->getId(),
            $entity->getVersionHash(),
        );
    }
}
```

### Pattern 7 : Constraint TransformationCode (nouveau, distinct de AppAssert\Code)

**Décision critique** : le constraint existant `AppAssert\Code` valide le pattern `^[a-z]+(_[a-z]+)*$` (snake_case uniquement, underscores). Les codes de transformation sont en **kebab-case** (`thumb-product`, `hero-webp`), avec des tirets. Les deux contraintes coexistent sur des entités différentes. **Ne pas modifier `AppAssert\Code`** — cela casserait AssetFlag, AttributeDefinition, Collection, Taxonomy.

```php
// Source: pattern api/src/Validator/Code.php + CodeValidator.php
// Nouveau fichier: api/src/Validator/TransformationCode.php
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class TransformationCode extends Constraint
{
    public string $message = 'The code "{{ value }}" is invalid. '
        . 'It must be kebab-case (lowercase letters, digits, hyphens), '
        . 'start with a letter, no consecutive hyphens, max 50 chars.';

    /** Reserved codes that conflict with existing routes or system namespaces */
    public array $reservedCodes = ['api', 'admin', 't', '_', 'assets'];
    public string $reservedMessage = 'The code "{{ value }}" is reserved and cannot be used.';
    public int $maxLength = 50;
}

// Nouveau fichier: api/src/Validator/TransformationCodeValidator.php
class TransformationCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TransformationCode) { throw ...; }
        if (null === $value || '' === $value) { return; }

        // Max length
        if (strlen($value) > $constraint->maxLength) { $this->addViolation($constraint); return; }

        // Mono-caractère interdit
        if (strlen($value) === 1) { $this->addViolation($constraint); return; }

        // Pattern: kebab-case [a-z][a-z0-9]*(-[a-z0-9]+)*
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $value)) {
            $this->addViolation($constraint); return;
        }

        // Blocklist
        if (in_array($value, $constraint->reservedCodes, strict: true)) {
            $this->context->buildViolation($constraint->reservedMessage)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
```

**Note sur le mono-caractère** : TRANSFORM-06 liste explicitement les mono-caractères comme réservés. Le pattern regex ci-dessus (`[a-z][a-z0-9]*`) interdit déjà les mono-caractères car il requiert au moins 2 caractères (une lettre + au minimum un alphanum ou un segment `-*`). Ajouter le guard explicite `strlen === 1` le rend clair et testable sans regex.

### Pattern 8 : PurgeTransformationVariantsMessage

```php
// Source: pattern api/src/Message/ComputeEmbeddingMessage.php (readonly, minimal)
final readonly class PurgeTransformationVariantsMessage
{
    public function __construct(
        public int    $transformationId,
        public string $versionHash,  // hash au moment de la suppression — capturé AVANT flush
    ) {}
}
```

**Pourquoi capturer le hash dans le message et pas seulement l'ID** : après la suppression de la row en DB, il n'est plus possible de retrouver le hash. Le hash est nécessaire pour construire le préfixe S3 `transformations/{id}-v{hash8}/`. Le message porte donc les deux.

### Pattern 9 : PurgeTransformationVariantsHandler (stub Phase 1)

En Phase 1, le handler peut être un stub vide (il faut juste que le message soit routable). L'implémentation réelle (suppression des fichiers Flysystem) viendra en Phase 3.

```php
#[AsMessageHandler]
final class PurgeTransformationVariantsHandler
{
    public function __invoke(PurgeTransformationVariantsMessage $message): void
    {
        // Phase 3: delete Flysystem prefix transformations/{id}-v{hash8}/
        // Phase 1: no-op — message infrastructure in place
    }
}
```

### Pattern 10 : Transport Messenger `transformations_backfill`

```yaml
# api/config/packages/messenger.yaml — ajout au bloc transports:
transformations_backfill:
    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
    options:
        stream: messages_transformations_backfill
        group: transformations_backfill
    retry_strategy:
        max_retries: 3
        delay: 5000
        multiplier: 2
        max_delay: 120000

failed_transformations:
    dsn: 'redis://redis:6379/messages_failed_transformations'

# Dans le bloc routing:
App\Message\PurgeTransformationVariantsMessage: transformations_backfill
```

**Note** : OPS-03 dans les requirements liste 4 transports (`async`, `transformations`, `transformations_ai`, `transformations_backfill`). En Phase 1, seul `transformations_backfill` est introduit (pour le purge). Les transports `transformations` et `transformations_ai` arriveront en Phase 3 et Phase 5. Ne pas les créer maintenant pour éviter des workers orphelins.

---

## Don't Hand-Roll

| Problème | Ne pas construire | Utiliser à la place | Pourquoi |
|----------|-------------------|---------------------|----------|
| Unicité du code | Vérification applicative dans le processor | `#[UniqueEntity]` + contrainte DB `unique: true` | Race condition entre deux POSTs simultanés ; la DB est la seule source de vérité |
| Détection de changement de steps | `preUpdate` sur l'entité | `onFlush` + `UnitOfWork::getScheduledEntityUpdates/Insertions/Deletions` | `preUpdate` ne capture pas les insertions/suppressions de steps enfants |
| Calcul de hash en JS côté éditeur | N'importe quel hash client | `TransformationHasher` PHP comme seule autorité | Le hash client est indicatif uniquement — la valeur serveur fait foi (PITFALLS §11) |
| Suppression S3 inline dans le processor de delete | `$storage->deleteDirectory(...)` dans `AssetTransformationDeleteProcessor` | `PurgeTransformationVariantsMessage` → handler async | La suppression peut concerner N×M objets S3 — pas compatible avec une requête HTTP synchrone |
| Génération du shard pour la clé S3 | Logique inline dans le controller | `TransformationStorageKey::forVariant()` | Point de vérité unique, miroir exact de `Asset::computeS3Key()` — divergence = cache miss |

---

## Common Pitfalls (Phase 1 spécifiques)

### Pitfall A : Hash non-recomputed sur Step insert/remove (seulement sur update)

**Ce qui se passe** : Le listener surveille `getScheduledEntityUpdates()` mais pas `getScheduledEntityInsertions()` ni `getScheduledEntityDeletions()`. Ajouter ou supprimer un step ne déclenche pas de recompute.

**Pourquoi** : Une mise à jour de l'entité `AssetTransformation` n'est pas schedulée quand seul un step enfant change — Doctrine ne "remonte" pas le changement automatiquement.

**Prévention** : Dans `onFlush`, itérer les trois listes (`Insertions + Updates + Deletions`) et remonter à la transformation parente pour chaque `TransformationStep` rencontré. Voir Pattern 6 ci-dessus. [VERIFIED: ProductVariantDefaultListener.php ligne 14-35]

### Pitfall B : dispatch Messenger dans onFlush → flush récursive

**Ce qui se passe** : Un message Messenger dispatché dans `onFlush` peut déclencher en interne un autre `flush()` (si le bus fait de la journalisation DB par exemple). Cela corrompt la UoW ou produit une exception "nested flush not supported".

**Prévention** : Accumuler les messages dans `$this->pendingPurges[]` dans `onFlush`, les dispatcher uniquement dans `postFlush()`. Pattern exact : ProductVariantDefaultListener.php. [VERIFIED: codebase pattern]

### Pitfall C : Hash capturé après flush lors d'une suppression

**Ce qui se passe** : On tente de lire `$transformation->getVersionHash()` dans `postFlush` pour construire le message de purge — mais la row n'existe plus en DB, et l'entité peut avoir été détachée de la UoW.

**Prévention** : Capturer `transformationId` + `versionHash` **pendant** `onFlush`, **avant** que l'entité soit scheduled for deletion. L'entité PHP est encore en mémoire dans `onFlush`. Voir Pattern 6, section "Cas spécial DELETE". [ASSUMED — logique Doctrine UoW standard]

### Pitfall D : `versionHash` exposé en écriture via l'API

**Ce qui se passe** : Si `versionHash` est dans le groupe `asset_transformation:write`, un client API peut l'écraser avec une valeur arbitraire, désynchronisant le cache.

**Prévention** : `versionHash` dans le groupe `asset_transformation:read` uniquement. Jamais en `write`. [VERIFIED: pattern asset_flag:read dans AssetFlag.php]

### Pitfall E : `code` en kebab-case vs `AppAssert\Code` existant (snake_case)

**Ce qui se passe** : Utiliser `#[AppAssert\Code]` sur `AssetTransformation.code` rejetera des codes kebab-case valides comme `hero-webp` (contient un tiret, interdit par le pattern `^[a-z]+(_[a-z]+)*$`).

**Prévention** : Créer `#[AppAssert\TransformationCode]` dédié. Ne pas modifier `AppAssert\Code` — cela casserait AssetFlag, AttributeDefinition, Collection, Taxonomy. [VERIFIED: CodeValidator.php regex ligne 36]

### Pitfall F : Steps exposés directement via une route `/api/transformation_steps`

**Ce qui se passe** : API Platform expose automatiquement toutes les entités annotées `#[ApiResource]`. Si TransformationStep a des opérations CRUD directes, un admin peut modifier des steps sans que le hash parent ne soit recalculé (l'endpoint PATCH sur un step seul ne déclenche pas le listener si la transformation n'est pas dans la flush).

**Prévention** : TransformationStep doit avoir `#[ApiResource(operations: [])]` ou aucun `#[ApiResource]`. Les steps sont gérés **uniquement** via la transformation parente (cascade persist/remove). Pour les cacher du menu de navigation : `#[MenuGroup('hidden')]`. [VERIFIED: AssetSimilarity.php pattern MenuGroup('hidden')]

### Pitfall G : Double déclenchement du listener sur AssetTransformation update + step update simultanés

**Ce qui se passe** : Si on modifie la transformation (label) ET un de ses steps dans le même flush, le listener calcule le hash deux fois pour le même `transformationId`, potentiellement avec deux calculs identiques — ce qui est inoffensif mais inefficace — ou deux messages de purge avec le même `oldHash`, ce qui est problématique si l'handler n'est pas idempotent.

**Prévention** : Dédupliquer par `transformationId` en utilisant une map `$dirtyTransformationIds[$id] = $transformation` (un seul recompute par transformation par flush). Le handler de purge doit être idempotent (`deleteDirectory` sur un préfixe inexistant est un no-op avec Flysystem). [VERIFIED: Pattern array_keys déduplication dans ProductVariantDefaultListener.php]

---

## Code Examples

### Exemple complet de golden-file test pour le hasher

```php
// Source: logique canonique définie dans ARCHITECTURE.md §VersionHasher + PITFALLS §11
// Fichier: api/tests/Unit/Service/AssetTransformation/TransformationHasherTest.php

// Golden value — ne JAMAIS le laisser changer sans révision intentionnelle
private const GOLDEN_HASH = 'a3f9c2e1...'; // à générer au premier run, puis figer

public function testDeterministicAcrossRuns(): void
{
    $t = $this->buildTransformation([
        ['type' => 'resize', 'params' => ['width' => 800, 'height' => 600, 'mode' => 'fit'], 'position' => 0],
        ['type' => 'format_convert', 'params' => ['format' => 'webp'], 'position' => 1],
    ]);

    $hash1 = $this->hasher->compute($t);
    $hash2 = $this->hasher->compute($t);
    $this->assertSame($hash1, $hash2);
    $this->assertSame(40, strlen($hash1)); // sha1 = 40 hex chars
}

public function testOrderIndependentOnParams(): void
{
    // params avec clés dans des ordres différents → même hash
    $t1 = $this->buildTransformation([
        ['type' => 'resize', 'params' => ['mode' => 'fit', 'width' => 800, 'height' => 600], 'position' => 0],
    ]);
    $t2 = $this->buildTransformation([
        ['type' => 'resize', 'params' => ['height' => 600, 'width' => 800, 'mode' => 'fit'], 'position' => 0],
    ]);
    $this->assertSame($this->hasher->compute($t1), $this->hasher->compute($t2));
}

public function testNullParamsDropped(): void
{
    $t1 = $this->buildTransformation([['type' => 'rotate', 'params' => ['angle' => 90], 'position' => 0]]);
    $t2 = $this->buildTransformation([['type' => 'rotate', 'params' => ['angle' => 90, 'background' => null], 'position' => 0]]);
    $this->assertSame($this->hasher->compute($t1), $this->hasher->compute($t2));
}

public function testStepOrderMatters(): void
{
    $t1 = $this->buildTransformation([
        ['type' => 'resize', 'params' => [], 'position' => 0],
        ['type' => 'format_convert', 'params' => [], 'position' => 1],
    ]);
    $t2 = $this->buildTransformation([
        ['type' => 'format_convert', 'params' => [], 'position' => 0],
        ['type' => 'resize', 'params' => [], 'position' => 1],
    ]);
    $this->assertNotSame($this->hasher->compute($t1), $this->hasher->compute($t2));
}
```

### Exemple : TransformationStorageKey test unitaire

```php
public function testVariantKey(): void
{
    // assetId 1234, shard = floor(1234/1000) = 1
    $key = TransformationStorageKey::forVariant(42, 'abc12345defghi', 1234, 'webp');
    $this->assertSame('transformations/42-vabc12345/1/1234.webp', $key);
}

public function testExtensionLeadingDotStripped(): void
{
    $key = TransformationStorageKey::forVariant(1, 'hash12345678abcd', 500, '.jpg');
    $this->assertSame('transformations/1-vhash1234/0/500.jpg', $key);
}
```

---

## Validation Architecture

### Infrastructure de test existante

**Aucun framework de test PHP (PHPUnit) n'est installé dans ce projet.** [VERIFIED: composer.json — seul `doctrine/doctrine-fixtures-bundle` est en require-dev. Aucune trace de phpunit dans symfony.lock]

Les tests unitaires PHP nécessitent une installation préalable de PHPUnit.

### Test Framework à créer (Wave 0)

| Propriété | Valeur |
|-----------|--------|
| Framework | PHPUnit 11.x |
| Config file | `api/phpunit.xml.dist` — à créer |
| Commande rapide | `docker compose exec api vendor/bin/phpunit --filter test` |
| Suite complète | `docker compose exec api vendor/bin/phpunit` |

### Installation PHPUnit (Wave 0 — à faire avant toute implémentation)

```bash
docker compose exec api composer require --dev symfony/test-pack
# Génère phpunit.xml.dist + bootstrap.php
```

**Alternative sans test-pack** (plus léger) :
```bash
docker compose exec api composer require --dev phpunit/phpunit:^11
```

### Mapping Requirements → Tests

| REQ-ID | Comportement à tester | Type | Commande automatisée | Fichier |
|--------|----------------------|------|----------------------|---------|
| TRANSFORM-01 | `AssetTransformation.code` unique kebab-case accepté et rejeté sur violation | Unit | `phpunit tests/Unit/Validator/TransformationCodeValidatorTest.php` | Wave 0 gap |
| TRANSFORM-01 | Code réservé `api` → violation | Unit | idem | Wave 0 gap |
| TRANSFORM-01 | Code mono-char `a` → violation | Unit | idem | Wave 0 gap |
| TRANSFORM-02 | Step avec StepType::RESIZE accepté ; type inconnu rejeté | Unit | `phpunit tests/Unit/Enum/StepTypeTest.php` | Wave 0 gap |
| TRANSFORM-03/04 | `TransformationHasher::compute()` → golden hash stable | Unit | `phpunit tests/Unit/Service/TransformationHasherTest.php` | Wave 0 gap |
| TRANSFORM-04 | Param-key order independence → même hash | Unit | idem | Wave 0 gap |
| TRANSFORM-04 | null params droppés → même hash qu'absent | Unit | idem | Wave 0 gap |
| TRANSFORM-04 | Step order change → hash différent | Unit | idem | Wave 0 gap |
| TRANSFORM-05 | `TransformationStorageKey::forVariant()` → clé correcte | Unit | `phpunit tests/Unit/Service/TransformationStorageKeyTest.php` | Wave 0 gap |
| TRANSFORM-05 | Extension `.jpg` avec point → extension sans point dans la clé | Unit | idem | Wave 0 gap |
| TRANSFORM-06 | `PurgeTransformationVariantsMessage` dispatché en postFlush lors de delete | Integration | `phpunit tests/Integration/EventListener/TransformationHashListenerTest.php` | Wave 0 gap |

### Stratégie de tests d'intégration du listener

Le test d'intégration du listener requiert un EntityManager. Utiliser le bus Messenger en mode `in-memory://` (déjà configuré dans `when@test` de messenger.yaml).

```php
// Utiliser InMemoryTransport pour vérifier le dispatch sans Redis
// Source: messenger.yaml ligne 28: async: 'in-memory://'
// Le transport transformations_backfill doit aussi être configuré en in-memory dans when@test
```

### Taux d'échantillonnage

- **Par commit** : `docker compose exec api vendor/bin/phpunit tests/Unit/ --no-coverage`
- **Par wave merge** : `docker compose exec api vendor/bin/phpunit tests/ --no-coverage`
- **Gate de phase** : suite complète verte avant `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `api/phpunit.xml.dist` + bootstrap — framework à installer
- [ ] `api/tests/Unit/Validator/TransformationCodeValidatorTest.php` — couvre TRANSFORM-06
- [ ] `api/tests/Unit/Service/AssetTransformation/TransformationHasherTest.php` — couvre TRANSFORM-03/04, golden file
- [ ] `api/tests/Unit/Service/AssetTransformation/TransformationStorageKeyTest.php` — couvre TRANSFORM-05
- [ ] `api/tests/Integration/EventListener/TransformationHashListenerTest.php` — couvre TRANSFORM-04 listener + TRANSFORM-05 purge dispatch

---

## Security Domain

### Catégories ASVS applicables

| Catégorie ASVS | Applicable | Contrôle standard |
|----------------|-----------|-------------------|
| V2 Authentication | Non — Phase 1 est API admin JWT uniquement | JWT existant |
| V3 Session Management | Non | — |
| V4 Access Control | Oui | `security: "is_granted('ROLE_ADMIN')"` sur Post/Patch/Delete |
| V5 Input Validation | Oui | `#[AppAssert\TransformationCode]` + `#[Assert\NotBlank]` + `#[UniqueEntity]` |
| V6 Cryptography | Non — sha1 utilisé comme fingerprint déterministe, pas comme hash de sécurité | sha1 acceptable pour cas usage non-sécurité |

### Menaces spécifiques à cette phase

| Pattern | STRIDE | Mitigation standard |
|---------|--------|---------------------|
| Code réservé (`api`, `t`, ...) utilisé pour shadow des routes existantes | Spoofing | `TransformationCodeValidator` blocklist + route requirement regex en Phase 3 |
| PATCH `/api/asset_transformations/{id}` avec `versionHash` forgé | Tampering | `versionHash` absent du groupe `asset_transformation:write` |
| Injection via `params` JSON | Tampering | `#[ORM\Column(type: 'json')]` — Doctrine échappe via PDO bound params, pas d'injection SQL ; validation sémantique des params en Phase 3 (HANDLERS-03) |
| Création de steps orphelins via l'API | Elevation | `TransformationStep` sans opérations directes (`operations: []`) ou `#[MenuGroup('hidden')]` ; gestion uniquement via parent cascade |

---

## Open Questions

1. **Longueur du hash dans le préfixe S3 : 8 chars vs 40 chars**
   - Ce qu'on sait : `versionHash` est un sha1 complet 40 chars stocké en DB. L'ARCHITECTURE.md utilise `hash8` (8 premiers chars) dans les clés S3 pour lisibilité.
   - Ce qui est flou : collision avec 8 chars ? Avec ~1000 transformations actives, probabilité de collision sha1/8 = 1/16^8 ≈ négligeable.
   - **Recommandation** : stocker 40 chars en DB, utiliser `substr($hash, 0, 8)` dans la clé S3. Documenté dans `TransformationStorageKey`.

2. **API Platform expose-t-elle automatiquement TransformationStep sans `#[ApiResource]` ?**
   - Ce qu'on sait : API Platform 4 requiert `#[ApiResource]` pour exposer une entité. Sans cette annotation, pas d'endpoint.
   - Ce qui est flou : est-ce que la relation imbriquée dans le JSON-LD expose une URL `/api/transformation_steps/{id}` ?
   - **Recommandation** : ajouter `#[ApiResource(operations: [])]` + `#[MenuGroup('hidden')]` à TransformationStep par précaution.

3. **`params` JSON : validation profonde dès Phase 1 ou Phase 3 ?**
   - Ce qu'on sait : HANDLERS-03 (Phase 3) définit les DTOs de validation par step type. La Phase 1 est censée être "pure domain/persistence layer".
   - **Recommandation** : en Phase 1, accepter n'importe quel JSON valide dans `params`. Ajouter une assertion `#[Assert\Type('array')]` uniquement. La validation sémantique (width > 0, format in enum, etc.) en Phase 3.

4. **Faut-il un `AssetTransformationDeleteProcessor` en Phase 1 ou un listener Doctrine ?**
   - Ce qu'on sait : `AssetDeleteProcessor` est l'approche utilisée pour Asset. Mais pour les transformations, le delete déclenche un job async, pas une suppression sync de fichiers.
   - **Recommandation** : utiliser le **listener Doctrine** `onFlush`/`postFlush` (plus robuste que le processor — fonctionne aussi pour les deletes faits hors API Platform, ex: fixtures). Le processor API Platform peut simplement appeler `$em->remove($data); $em->flush()` sans logique custom.

---

## Environment Availability

Uniquement PHP/Doctrine — pas de dépendances externes nouvelles.

| Dépendance | Requis pour | Disponible | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PostgreSQL (via Docker) | Migrations Doctrine | ✓ | 16 (pgvector image) | — |
| Redis (via Docker) | Transport Messenger `transformations_backfill` | ✓ | 7 | — |
| PHP 8.4 | `enum StepType`, `readonly`, attributes | ✓ | 8.4 | — |
| PHPUnit | Tests unitaires | ✗ (non installé) | — | Installer via Wave 0 |

**Dépendances manquantes bloquantes** : PHPUnit non installé. Le Wave 0 de chaque plan doit inclure `composer require --dev symfony/test-pack`.

---

## Assumptions Log

| # | Claim | Section | Risque si faux |
|---|-------|---------|----------------|
| A1 | `postFlush` dispatch est safe pour Messenger (pas de re-flush) | Architecture Patterns §6 | Si le bus Messenger logue en DB avec flush, risque d'événement récursif — mitiger avec `try/finally` ou bus en mode `sync://` pour les tests |
| A2 | `UnitOfWork::getScheduledEntityDeletions()` contient encore l'entité avec ses propriétés chargées (versionHash non null) dans onFlush | Architecture Patterns §6 | Si Doctrine détache l'entité avant onFlush, le hash serait null → message incorrect → pas de purge |
| A3 | API Platform 4 ne génère pas d'endpoint pour TransformationStep sans `#[ApiResource]` | Common Pitfalls §F | Si AP4 expose des endpoints non déclarés, risque de PATCH step sans recompute hash |

---

## Sources

### Primaires (HIGH confidence)
- `api/src/Entity/Asset/Asset.php` — pattern d'entité, computeS3Key, lifecycle callbacks
- `api/src/Entity/Asset/AssetFlag.php` — pattern minimal, MenuGroup, AppAssert\Code
- `api/src/Entity/Asset/AssetSimilarity.php` — MenuGroup('hidden') pattern
- `api/src/Validator/Code.php` + `CodeValidator.php` — constraint existant, regex snake_case
- `api/src/EventListener/ProductVariantDefaultListener.php` — pattern onFlush + recomputeSingleEntityChangeSet
- `api/src/Message/ComputeEmbeddingMessage.php` + `api/src/MessageHandler/ComputeEmbeddingHandler.php` — pattern message + handler
- `api/config/packages/messenger.yaml` — transports existants, DSN pattern
- `api/config/packages/doctrine.yaml` — config ORM, naming_strategy, extensions
- `api/config/packages/flysystem.yaml` — adapters dev/test/prod
- `api/config/services.yaml` — autowire, embedder_url param
- `api/composer.json` — dépendances disponibles

### Secondaires (MEDIUM confidence)
- `.planning/research/ARCHITECTURE.md` — §6 "Cache Invalidation Hooks", §1 "Storage Layout"
- `.planning/research/PITFALLS.md` — §11 "Hash drift", §12 "Cascade deletes", §14 "Code uniqueness race"
- `.planning/REQUIREMENTS.md` — TRANSFORM-01..06

### Tertiaires (LOW confidence)
- Comportement exact de `getScheduledEntityDeletions()` avec entités dont les collections sont cascadées — à valider par un test d'intégration

---

## Metadata

**Confidence breakdown :**
- Entités (patterns) : HIGH — calqué directement sur Asset.php/AssetFlag.php vérifiés
- TransformationHasher : HIGH — sha1 + json_encode est trivial, les règles de canonicalisation sont précises
- Listener (onFlush/postFlush) : HIGH — ProductVariantDefaultListener.php est un modèle exact
- Constraint TransformationCode : HIGH — pattern Code.php/CodeValidator.php vérifiés, adaptation bien définie
- PHPUnit Wave 0 : HIGH — composer.json confirme l'absence de tests, installation standard

**Research date :** 2026-05-27
**Valid until :** 2026-08-27 (stack stable ; Doctrine, API Platform, Messenger patterns n'évoluent pas rapidement)

---

> **Rappel Webfacto** : avant tout démarrage en développement intégré au SI ou déploiement en production (y compris la mise en place des transports Messenger `transformations_backfill` sur les environnements partagés), ce cas d'usage doit être validé par la Webfacto (cadrage besoin, faisabilité, sécurité, priorisation).

---

## RESEARCH COMPLETE
