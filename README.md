# Symfony 7.4 Boilerplate

A production-ready boilerplate built with **Symfony 7.4 LTS** and **PHP 8.4**, fully containerised with Docker Compose.

## Stack

| Service         | Image                          | Version |
|-----------------|--------------------------------|---------|
| PHP-FPM         | `php:8.4-fpm-alpine`           | 8.4     |
| Nginx           | `nginx:1.27-alpine`            | 1.27    |
| PostgreSQL      | `postgres:17-alpine`           | 17      |
| Redis           | `redis:7-alpine`               | 7       |
| RabbitMQ        | `rabbitmq:3-management-alpine` | 3       |
| Elasticsearch   | `elasticsearch:8.17.0`         | 8.17    |

## Project Layout

```
.
├── docker/
│   ├── nginx/default.conf          # Nginx virtual host config
│   └── php/
│       ├── php.ini                 # PHP runtime settings
│       └── opcache.ini             # OPcache settings
├── migrations/                     # Doctrine migration files
├── public/index.php                # Application entry point
├── src/
│   ├── Controller/
│   │   └── HealthController.php    # GET /health — checks all services
│   ├── Factory/
│   │   └── ElasticsearchClientFactory.php
│   ├── Message/
│   │   └── ExampleMessage.php      # Example Messenger message
│   ├── MessageHandler/
│   │   └── ExampleMessageHandler.php
│   ├── Service/
│   │   └── ElasticsearchService.php
│   └── Kernel.php
├── config/
│   ├── bundles.php
│   ├── routes.yaml
│   ├── services.yaml
│   └── packages/
│       ├── doctrine.yaml           # PostgreSQL / Doctrine ORM
│       ├── doctrine_migrations.yaml
│       ├── framework.yaml          # Sessions + Cache → Redis
│       ├── messenger.yaml          # Messenger transports → RabbitMQ
│       ├── serializer.yaml
│       ├── validator.yaml
│       └── web_profiler.yaml
├── tests/
├── .env                            # Environment defaults (commit this)
├── composer.json
├── docker-compose.yml
├── Dockerfile
└── phpunit.xml.dist
```

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (with Compose v2)

No local PHP or Composer installation is required — everything runs inside containers.

---

## Getting Started

### 1. Build the images

```bash
docker compose build
```

Add `--no-cache` to force a full rebuild without using any cached layers:

```bash
docker compose build --no-cache
```

### 2. Start all services

```bash
docker compose up -d
```

All six containers will start. Health checks are configured so dependent services wait until each dependency is truly ready.

### 3. Install PHP dependencies

```bash
docker compose exec app composer install
```

### 4. Run database migrations

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Verify everything is running

```bash
docker compose ps
```

---

## Useful Commands

### Symfony Console

```bash
# Run any arbitrary console command
docker compose exec app php bin/console <command>

# Clear the cache
docker compose exec app php bin/console cache:clear

# List all routes
docker compose exec app php bin/console debug:router

# List all services in the container
docker compose exec app php bin/console debug:container

# Generate a new migration after changing an Entity
docker compose exec app php bin/console doctrine:migrations:diff

# Apply pending migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### RabbitMQ — Consume Messages

```bash
docker compose exec app php bin/console messenger:consume async -vv
```

### Open a Shell Inside the Container

```bash
docker compose exec app sh
```

### Composer

```bash
# Add a new package
docker compose exec app composer require vendor/package

# Update dependencies
docker compose exec app composer update
```

### Logs

```bash
# All services
docker compose logs -f

# Single service
docker compose logs -f app
docker compose logs -f postgres
docker compose logs -f rabbitmq
docker compose logs -f elasticsearch
```

### Run Tests

```bash
docker compose exec app php bin/phpunit
```

### Restart All Containers

```bash
docker compose down && docker compose up -d
```

### Stop Everything

```bash
# Stop containers but keep volumes
docker compose down

# Stop and delete all volumes (wipes database, Redis, ES data)
docker compose down -v
```

---

## Service URLs

| Service                    | URL                                      | Credentials           |
|----------------------------|------------------------------------------|-----------------------|
| **Application**            | http://localhost:8080                    | —                     |
| **Health check endpoint**  | http://localhost:8080/health             | —                     |
| **Symfony Web Profiler**   | http://localhost:8080/_profiler          | —                     |
| **RabbitMQ Management UI** | http://localhost:15672                   | `guest` / `guest`     |
| **Elasticsearch REST API** | http://localhost:9200                    | —                     |
| **PostgreSQL**             | `localhost:5432` (use a DB client)       | `app` / `app` / `app` |
| **Redis**                  | `localhost:6379` (use `redis-cli`)       | —                     |

> Port numbers can be overridden in `.env` via `NGINX_PORT`, `POSTGRES_PORT`, `REDIS_PORT`, `RABBITMQ_PORT`, `RABBITMQ_MANAGEMENT_PORT`, `ELASTICSEARCH_PORT`.

### Quick Connectivity Checks

```bash
# Elasticsearch cluster health
curl http://localhost:9200/_cluster/health?pretty

# Elasticsearch indices
curl http://localhost:9200/_cat/indices?v

# Redis ping
docker compose exec redis redis-cli ping

# PostgreSQL query
docker compose exec postgres psql -U app -d app -c "SELECT version();"
```

---

## Environment Variables

Defaults live in `.env`. Override locally by creating `.env.local` (never commit this file).

| Variable                    | Default                                                | Description                        |
|-----------------------------|--------------------------------------------------------|------------------------------------|
| `APP_ENV`                   | `dev`                                                  | Symfony environment                |
| `APP_SECRET`                | `changeme_...`                                         | **Change before any deployment**   |
| `DATABASE_URL`              | `postgresql://app:app@postgres:5432/app`               | Doctrine DBAL connection string    |
| `REDIS_URL`                 | `redis://redis:6379`                                   | Cache adapter + session handler    |
| `MESSENGER_TRANSPORT_DSN`   | `amqp://guest:guest@rabbitmq:5672/%2f/messages`        | RabbitMQ Messenger transport       |
| `ELASTICSEARCH_URL`         | `http://elasticsearch:9200`                            | Elasticsearch client base URL      |

---

## Architecture Notes

### Redis
Used for two purposes via `framework.yaml`:
- **Application cache** (`cache.app` → `cache.adapter.redis`)
- **Session storage** (handler pointing to `REDIS_URL`)

### RabbitMQ
Wired through Symfony Messenger. Any class placed in `App\Message\` is automatically routed to the `async` AMQP transport. A dead-letter `failed` transport captures messages that exhaust all retries (3 attempts with ×2 backoff).

To dispatch a message from any service:
```php
$this->messageBus->dispatch(new \App\Message\ExampleMessage('hello'));
```

### Elasticsearch
The `ElasticsearchService` wraps the official `elastic/elasticsearch` PHP client and exposes `index`, `get`, `search`, `delete`, and `createIndex` methods. The underlying `Elastic\Elasticsearch\Client` is registered as a service via `ElasticsearchClientFactory` and can be injected directly when you need lower-level access.

### PostgreSQL
Doctrine ORM is configured with attribute-based mapping. Place entities in `src/Entity/`, generate migrations with `doctrine:migrations:diff`, and apply them with `doctrine:migrations:migrate`.
