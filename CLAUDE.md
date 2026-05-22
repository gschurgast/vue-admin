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
| Database | PostgreSQL 16 |
| Cache | Redis 7 (conversation storage) |
| Auth | JWT (lexik/jwt-authentication-bundle) |

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
