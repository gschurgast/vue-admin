# API Platform + Vue.js Dynamic Admin

A full-stack application with automatic CRUD generation.

## Architecture

- **API**: API Platform (Symfony/PHP) in `api/`
- **PWA**: Vue.js + Vuetify in `pwa/`
- **Database**: PostgreSQL
- **Infrastructure**: Docker Compose

## Features

✨ **Dynamic CRUD Generation**: Automatically generates admin interfaces for all API Platform resources

🔍 **Schema Introspection**: Uses Hydra documentation to discover resources and their properties

📋 **Complete CRUD Operations**: List, Create, Edit, and Delete for all resources

🎨 **Beautiful UI**: Material Design interface with Vuetify

## Getting Started

### Prerequisites

- Docker and Docker Compose

### Installation

1. **Start the services**:
   ```bash
   docker compose up -d --build
   ```

2. **Create the database schema**:
   ```bash
   docker compose exec api php bin/console doctrine:database:create --if-not-exists
   docker compose exec api php bin/console doctrine:schema:update --force
   ```

3. **Install PWA dependencies** (if not already done):
   ```bash
   docker compose exec pwa npm install
   ```

4. **Restart the PWA** (to ensure it's running):
   ```bash
   docker compose restart pwa
   ```

### Access the Application

- **PWA (Admin Interface)**: http://localhost:5173
- **API**: http://localhost:8080/api
- **API Documentation**: http://localhost:8080/api/docs

## Project Structure

```
.
├── api/                    # API Platform (Symfony)
│   ├── src/
│   │   └── Entity/        # Doctrine entities (API resources)
│   └── Dockerfile         # Custom API image with PostgreSQL support
├── pwa/                    # Vue.js PWA
│   ├── src/
│   │   ├── components/    # Vue components
│   │   ├── services/      # API service layer
│   │   ├── stores/        # Pinia stores
│   │   └── views/         # Page views
│   └── package.json
└── docker-compose.yml      # Docker orchestration
```

## How It Works

### 1. Schema Discovery

The PWA fetches the Hydra documentation from the API (`/api`) to discover available resources and their properties.

### 2. Dynamic Components

The `ResourceView` component dynamically:
- Generates data tables based on resource properties
- Creates forms with appropriate field types
- Handles CRUD operations via the API

### 3. API Platform Integration

The PWA uses the JSON-LD format and Hydra conventions to:
- List resources with pagination
- Create new resources
- Update existing resources
- Delete resources

## Adding New Resources

Simply create a new Doctrine entity in `api/src/Entity/` and add the `#[ApiResource]` attribute:

```php
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    public ?string $name = null;

    #[ORM\Column]
    public ?float $price = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
```

Then update the database schema:

```bash
docker compose exec api php bin/console doctrine:schema:update --force
```

The new resource will automatically appear in the admin interface! 🎉

## Development

### API Development

```bash
# Access the API container
docker compose exec api sh

# Run Symfony commands
php bin/console make:entity
php bin/console doctrine:migrations:migrate
```

### PWA Development

```bash
# Access the PWA container
docker compose exec pwa sh

# Add new dependencies
npm install <package>

# Build for production
npm run build
```

## Technologies Used

- **Backend**: Symfony 7, API Platform 4, Doctrine ORM
- **Frontend**: Vue 3, Vuetify 3, Pinia, Vue Router
- **Database**: PostgreSQL 16
- **Server**: FrankenPHP
- **HTTP Client**: Axios

## License

MIT
