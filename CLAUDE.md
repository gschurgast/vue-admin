# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Starting the Project
```bash
docker compose up -d              # Start all services (API, PWA, Database, Redis)
docker compose down               # Stop all services
docker compose logs -f api        # Follow API logs
```

### Running Commands
```bash
docker compose exec api <command>    # PHP/Symfony commands
docker compose exec pwa <command>    # Node/npm commands
```

### Common Operations
```bash
# Database
docker compose exec api php bin/console doctrine:migrations:migrate
docker compose exec api php bin/console doctrine:schema:update --force  # Dev only
docker compose exec api php bin/console doctrine:fixtures:load

# Create migration after entity changes
docker compose exec api php bin/console make:migration

# Regenerate PWA TypeScript types from the OpenAPI spec
# (run after any API resource / serialization-group / DTO change)
make generate-types

# Clear cache
docker compose exec api php bin/console cache:clear

# PWA
docker compose exec pwa npm run build
docker compose exec pwa npm install <package>
```

### Access Points
- PWA: http://localhost:5173
- API: http://localhost:8080/api
- API Docs: http://localhost:8080/api/docs

## Architecture

This is a schema-driven admin application. The PWA fetches Hydra/OpenAPI documentation from the API and dynamically generates CRUD interfaces for all API Platform resources.

### Backend (api/src/)

| Directory | Purpose |
|-----------|---------|
| Entity/ | Doctrine ORM entities, grouped by domain (Product/, Attribute/, Collection/) |
| ApiResource/ | Request/Response DTOs for custom endpoints |
| State/ | API Platform processors and providers for custom operations |
| Service/ | Business logic (ChatService, ConversationService, TranslationService) |
| Validator/ | Custom validation constraints (Code.php + CodeValidator.php) |
| Enum/ | Backed enums (Market, AttributeType, Locale) |
| Attribute/ | Custom PHP attributes (MenuGroup for navigation grouping) |

### Frontend (pwa/src/)

| Directory | Purpose |
|-----------|---------|
| components/resource/ | Dynamic CRUD views (ResourceForm, ResourceList) |
| components/fields/ | Form field components (RelationField, RichTextField, etc.) |
| components/list/ | Table cell components for list views |
| components/[resource]/ | Resource-specific component overrides |
| config/ | JSON files defining field display per resource |
| composables/ | Vue composables for shared logic |
| services/apiPlatform.ts | API client with schema introspection |
| stores/ | Pinia stores (auth, resources) |
| locales/ | i18n translations (14 languages: ar_SA, da_DK, de_DE, en_US, es_ES, fr_FR, he_IL, it_IT, ja_JP, nb_NO, pl_PL, pt_PT, sv_SE, zh_CN) |

### Resource Configuration Pattern

Custom field rendering is configured via JSON in `pwa/src/config/ResourceName.json`:
```json
{
  "filters": {
    "fields": [
      { "fieldName": "" },
      { "dateField": "date-range" }
    ]
  },
  "list": {
    "component": "CustomListComponent",  // Optional custom component
    "fields": [
      { "fieldName": "" },               // Empty string = default component
      { "fieldName": "CustomCell" }      // Custom cell component
    ]
  },
  "edit": {
    "component": "CustomEditComponent",
    "fields": [
      { "fieldName": "CustomField" }
    ]
  },
  "show": {
    "component": "CustomShowComponent",
    "fields": [...]
  }
}
```

## Coding Standards

### PHP Entities
- Properties must be **private** with getters/setters (setters return `static`)
- Required class-level annotations: `#[MenuGroup()]`, `#[ApiFilter()]`
- Serialization: `#[Groups(['resource:read', 'resource:write'])]`
- Relations: `#[MaxDepth(1)]` to prevent infinite recursion
- Collections: initialize in constructor with `new ArrayCollection()`
- Code properties: `#[ORM\Column(length: 50, unique: true)]` + `#[AppAssert\Code]`
- **Boolean properties**: For `$isXxx` properties, use `isXxx()` getter (Symfony serializer will expose as `isXxx`)

### ApiResources (DTOs)
- Named **PascalCase** ending with **Request** (e.g., `ChatRequest`)
- Placed in `src/ApiResource/`
- Use **public** properties with `#[Groups(['resource:read', 'resource:write'])]`
- If resource has GET collection operation: add `#[MenuGroup('hidden')]` to hide from navigation
- Define operations inline: `#[ApiResource(operations: [new Post(...)])]`

### State Processors
- Named with **Processor** suffix (e.g., `ChatRequestProcessor`)
- Implement `ProcessorInterface`
- Check operation type: `if ($operation instanceof Post)`
- Type check data: `if (!$data instanceof ExpectedClass)`

### Enums
- Backed enums: `enum Name: string`
- Implement: `label()`, `allCodes()`, `toArray()`
- Use `match()` expressions

### Vue Components
- Use `<script setup lang="ts">`
- Props: `defineProps<Props>()` with `withDefaults()`
- Emits: `defineEmits<{ (e: 'event', data: Type): void }>()`

### Composables
- Prefix with `use`: `useResource()`, `useLocales()`
- Return object: `{ refs, computed, functions }`

## Security

- JWT authentication via `/api/login`
- Roles: `ROLE_USER`, `ROLE_ADMIN`
- Operation security: `new Post(security: "is_granted('ROLE_ADMIN')")`

## Environment

API keys are stored in `api/.env.local` (not passed via Docker):
- `OPENAI_API_KEY` - Required for AI Assistant chat features
- `OPENAI_ORGANIZATION` - OpenAI organization ID

## Tech Stack

| Layer | Technology |
|-------|------------|
| API | PHP 8.4, Symfony 7.3, API Platform 4, Doctrine ORM |
| PWA | Vue 3, Vuetify 3, Vite, Pinia, TypeScript |
| Database | PostgreSQL 16 + **pgvector** (image embeddings) |
| Cache | Redis 7 (conversation storage + Messenger transport) |
| Queue | Symfony Messenger over Redis Streams |
| Asset storage | Flysystem (local FS in dev/test, AWS S3 in prod) |
| Embedder | Python 3.11 + sentence-transformers (CLIP ViT-B/32) |
| Auth | JWT (lexik/jwt-authentication-bundle) |

### Docker services

| Service | Role |
|---------|------|
| `api` | Symfony app (FrankenPHP) |
| `pwa` | Vite dev server |
| `database` | `pgvector/pgvector:pg16` — Postgres with `vector` extension |
| `redis` | Conversation cache + Messenger transport |
| `embedder` | Python/FastAPI CLIP service (only reachable in-cluster on `embedder:8000`) |
| `worker` | Long-lived `messenger:consume async` process (reuses the api image) |

## AI Assistant

The PWA includes an AI chat assistant with the following features:

### Voice Input
- Uses Web Speech API for real-time speech recognition
- Automatically sends message when user stops speaking (`autoSend` mode)
- Restarts recording after receiving AI response for hands-free conversation
- Language detection based on current locale (e.g., `fr_FR` → `fr-FR`)
- Component: `pwa/src/components/VoiceInput.vue`

### Conversation Management
- Conversations stored in Redis with 24h TTL (max 50 messages)
- Conversation ID stored in localStorage (`chat_conversation_id`)
- New conversation: generates new UUID and deletes Redis history
- Endpoints:
  - `POST /api/chat` - Send message with `conversationId`
  - `DELETE /api/conversations/{conversationId}` - Clear conversation

## Asset Management

End-to-end management of images / PDF / video / documents with S3-compatible
storage, drag-and-drop upload, and CLIP-based visual deduplication.

### Entities

- `Asset` — `code`, `type` (enum `AssetType`: image/pdf/video/doc), `mimeType`,
  `filename`, `size`, `s3Key`, `s3Bucket`, dimensions, `duration`, `checksum`
  (SHA-256), `embedding` (vector(512)), `embeddingStatus`
  (`pending|ready|failed|skipped`), `embeddingModel`, `duplicateOf` (self-FK), flags
- `AssetFlag` — `code`, `label`, `color`. Free-form tags (validated, hero, nsfw, …)
- `AssetSimilarity` — undirected edge `(assetA.id < assetB.id)` with `score` (cosine)

### S3 / storage layout

Storage key built from the asset id only (type is in the DB column, not the path):

```
{shard}/{id}.{ext}   where shard = floor(id / 1000)
0/42.jpg     0/512.pdf     15/15234.mp4
```

Adapter is selected by environment via Flysystem (`config/packages/flysystem.yaml`):
- `dev` → local FS at `api/var/assets`
- `test` → local FS at `api/var/assets-test`
- `prod` → AWS S3 via `assets.s3_client` (`config/services.yaml`)

Prod env vars: `S3_ASSETS_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`,
optional `S3_ENDPOINT` / `S3_USE_PATH_STYLE` (MinIO / staging).

### Upload pipeline

`AssetUploader::doUpload()` (called by `AssetController::upload`) executes:

1. Validate mime + size against `AssetType::allowedMimeTypes()` / `maxSize()`
2. SHA-256 checksum + **byte-exact dedup**: same checksum + existing `s3Key` →
   return existing asset (no new row, no new file)
3. Best-effort metadata extraction (`AssetMetadataExtractor`):
   - Images raster: `getimagesize()`
   - SVG: XML `width`/`height` or `viewBox` fallback
   - Video: `getID3->analyze()` for width/height/duration
4. Persist (phase 1) → flush to get `id`
5. Compute S3 key, stream the file to Flysystem (phase 2)
6. On storage failure: rollback the half-persisted row, raise `AssetUploadException`
7. Dispatch `ComputeEmbeddingMessage($id)` for **image** assets only (async)

### Async embedding & semantic deduplication

- `embedder/` (Python/FastAPI/CLIP ViT-B/32, sentence-transformers) exposes
  `POST /embed` → 512-d L2-normalised vector. Model pre-downloaded at build time.
  Not exposed publicly.
- Symfony Messenger routes `App\Message\ComputeEmbeddingMessage` to the `async`
  transport (Redis Streams). Failed transport at `messages_failed`. Retry 3× with
  exponential backoff (`config/packages/messenger.yaml`).
- `ComputeEmbeddingHandler` (in `MessageHandler/`):
  1. Loads asset + binary from Flysystem
  2. POST to embedder, stores 512-d vector + `embedding_model`
  3. ANN query in pgvector (HNSW index, cosine ops):
     ```sql
     SELECT id, 1 - (embedding <=> :vec) AS similarity
     FROM asset WHERE id <> :id AND embedding IS NOT NULL
       AND embedding_status = 'ready'
     ORDER BY embedding <=> :vec LIMIT 10
     ```
  4. **≥ 0.95** → mark `duplicate_of_id` on the new asset (kept for traceability)
  5. **≥ 0.75** → upsert into `asset_similarity` (canonical `(a_id < b_id)`)
- Worker container: `php bin/console messenger:consume async --time-limit=3600 --memory-limit=512M`
- Thresholds live as constants on `ComputeEmbeddingHandler`:
  - `DUPLICATE_THRESHOLD = 0.95`
  - `SIMILAR_THRESHOLD = 0.75`

### Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/api/assets/upload` | user | Multipart, field `files[]` (or `file`). Returns `{ results: [{ success, duplicate, asset }] }` (201 if all OK, 207 partial) |
| `GET` | `/api/assets/{id}/content` | user | Streams the binary via Flysystem (works identically dev FS / prod S3). `?download=1` for attachment |
| `GET` | `/api/assets/{id}/similar?min=0.75&limit=20` | user | JSON `{ status, duplicateOfId, results: [{ id, similarity, ... }] }` |
| Standard CRUD | `/api/assets`, `/api/asset_flags` | mixed | Generated by API Platform; DELETE on Asset cleans the storage object via `AssetDeleteProcessor` |

### Frontend

- `pwa/src/config/Asset.json` — standalone list (`AssetGrid`), custom show
  (`AssetShow`), edit fields
- `pwa/src/components/asset/list/AssetGrid.vue` — drag-and-drop multi-file zone,
  parallel uploads with per-file progress bar, status badges
  (`pending` / `duplicate`) on tiles, pagination, refresh
- `pwa/src/components/asset/show/AssetShow.vue` — inline preview
  (`<img>` / `<video>` / `<iframe>` for PDF), download button, "Visually similar"
  grid with cosine-% chips, warning banner if `duplicateOfId` set
- `pwa/src/composables/useAssetUrl.ts` — authenticated fetch + reference-counted
  blob URL cache (revoked on unmount). Use it for any asset binary in `<img>`,
  `<video>`, `<iframe>`.

### Operational notes

- **HNSW index** on `asset.embedding` uses `vector_cosine_ops` (m=16 / ef_construction=64).
  Defaults are fine up to several million rows; reindex periodically or switch to
  IVFFlat with `lists=sqrt(N)` past that.
- **Scaling the worker**: increase replicas of the `worker` service.
  Redis Streams consumer groups distribute jobs automatically.
- **Failure transport**: jobs that exhaust retries land in the `failed` Redis
  queue; inspect with `php bin/console messenger:failed:show` and replay with
  `messenger:failed:retry`.
- **Re-embedding** after model upgrade: bump `EMBEDDING_MODEL` env var on the
  Python service, then dispatch `ComputeEmbeddingMessage` for assets whose
  `embedding_model` differs from the new value.

### Custom list components: `standalone` mode

Custom list components can opt out of the parent page's data loading by setting
`list.standalone: true` in the resource JSON config. The component then manages
its own pagination/filtering/fetching, but must:

1. Accept the parent's events: `@view`, `@edit`, `@delete`
2. Expose a `refresh()` method via `defineExpose({ refresh })` — the parent
   `pages/resource/[resource].vue` calls it through a template ref after
   successful deletion so the standalone grid stays in sync

## Localized attributes

Some attributes are translated per locale (`AttributeDefinition.isLocalizable`).
The PWA stores attribute values under a `(attributeDefinitionId, locale)` pair
and the form locale is shared by all fields on the edit page.

- `pwa/src/composables/useFormLocale.ts`:
  - `provideFormLocale(initial)` — call at the top of the edit page to expose the
    current editing locale to descendants
  - `useFormLocale()` — call in any field to read/write the current form locale;
    falls back to the UI locale when no provider is mounted
- `pwa/src/components/common/LocaleSelectorBar.vue` — UI chip above the form;
  switching locales reloads the values from `ProductAttributeValuesProvider`
  (filtered by locale)
- `POST /api/translate_pav_requests` (DTO `TranslatePavRequest`, processor
  `TranslatePavProcessor`) — bulk-translate a localizable attribute value to a
  list of target locales in one round-trip. Used by the "translate to all"
  button surfaced by `AttributeSection` when the attribute is localizable.

Implication for new attribute fields: read/write through `useFormLocale()`
rather than the UI locale, otherwise edits leak across languages.

## Page actions

All page-level CTAs (Save / Cancel / Create / Delete / Filter…) go through two
shared primitives — do **not** drop bare `<v-btn>` into pages.

- `pwa/src/components/common/PageActionBtn.vue` — typed `kind` prop
  (`primary | success | danger | secondary | ghost`) maps to a consistent color
  + variant pairing. Use it everywhere a page action button is needed.
- `pwa/src/components/common/PageActionsFooter.vue` — sticky bottom bar for
  **edit** pages. Slot a row of `PageActionBtn`s; the footer handles bleed,
  blur, drop shadow, and right-alignment via flex `gap: 16px`.
- **List/show pages** lift their primary actions into the app bar via the
  `<ResourceAppBar><template #actions>` slot — same `PageActionBtn`, same gap.

No manual `mr-2` / `ml-2` between buttons: layout gap is centralised.

## Generated TypeScript types

Prefer the generated OpenAPI types over hand-typed shapes when working with API
payloads in the PWA:

- Single source of truth: `pwa/src/types/api.d.ts`, regenerated by
  `make generate-types` from `/api/docs.json`
- Import the operation/component types directly:
  ```ts
  import type { components } from '@/types/api'
  type Product = components['schemas']['Product.jsonld-product:read']
  ```
- Run `make generate-types` after **any** API change touching serialization
  groups, DTOs, or operations — CI relies on the committed file being in sync.
