# Madong — High-Performance Admin Backend Framework

## Introduction

Madong is a blazing-fast development platform built on the PHP **Webman** framework. It provides a stable, high-performance API service layer for front-end applications, with a clear domain-layered architecture and a first-class plugin system.

## Tech Stack

| Technology | Version | Description |
|------------|---------|-------------|
| PHP | 8.2 ~ 8.4 | Core development language |
| Webman | 2.2 | High-performance PHP framework |
| MySQL | 8.0+ | Database |
| Redis | 7.0+ | Cache service |
| Composer | 2.x | Dependency manager |
| Nginx / Apache | - | Web server |

## Project Structure

```
madong/                          # Project root
├── backend/                     # Backend directory (this directory)
│   ├── app/                    # Application directory (monolithic layered structure)
│   │   ├── adminapi/           # Admin API
│   │   │   ├── config/         # Admin API config
│   │   │   ├── controller/     # Admin controllers (organized by business domain)
│   │   │   ├── event/          # Admin events
│   │   │   ├── listener/       # Admin event listeners
│   │   │   ├── middleware/     # Admin middleware
│   │   │   ├── schema/         # Admin data structure definitions
│   │   │   ├── validate/       # Admin validators
│   │   │   └── CurrentUser.php # Current user context
│   │   ├── api/                # Front-end (site) API
│   │   │   ├── config/         # Site API config
│   │   │   ├── controller/     # Site controllers (organized by site)
│   │   │   ├── event/          # Site events
│   │   │   ├── listener/       # Site event listeners
│   │   │   ├── middleware/     # Site middleware
│   │   │   ├── schema/         # Site data structure definitions
│   │   │   ├── validate/       # Site validators
│   │   │   └── CurrentMember.php # Current member context
│   │   ├── dao/                # Data access layer (by domain: content/member_sign/ops/site/sys_admin_type/system)
│   │   ├── enum/               # Enum classes (content/system)
│   │   ├── model/              # Models (content/ops/system)
│   │   ├── service/            # Business service layer (admin/api/core)
│   │   ├── schema/             # Data structure definitions (response/plugin)
│   │   ├── command/            # CLI commands (plugin migration)
│   │   ├── event/              # Event definitions
│   │   ├── listener/           # Event listeners
│   │   ├── bootstrap/          # Bootstrap files
│   │   ├── middleware/         # Middleware
│   │   ├── process/            # Custom processes
│   │   ├── queue/              # Queue jobs
│   │   ├── scope/              # Model scopes
│   │   ├── install/            # Installation scripts
│   │   ├── exception/          # Exception handling
│   │   └── functions.php       # Global helper functions
│   ├── config/                  # Configuration files
│   │   ├── plugin/              # Plugin config (limiter/validation, etc.)
│   │   ├── route/               # Routing config
│   │   └── *.php                # Various config files
│   ├── core/                    # Core framework (domain-layered architecture)
│   │   ├── business/           # Business core (multi-tenancy/approval, etc.)
│   │   ├── communication/      # Communication (sms/email/notify)
│   │   ├── foundation/         # Foundations (cache/db/uuid/jwt/logger)
│   │   ├── infrastructure/     # Infrastructure (storage/queue, etc.)
│   │   ├── interface/          # Interface contracts (abstractions)
│   │   ├── io/                 # IO (upload / excel import-export)
│   │   ├── security/           # Security (captcha / exception)
│   │   └── functions.php        # Core helper functions
│   ├── plugin/                   # Plugin directory
│   │   ├── codegen/              # Code generation plugin (backend)
│   │   └── demo/                 # Demo plugin (runtime + front-end template source)
│   ├── public/                   # Static assets
│   ├── resource/                 # Resources (migrations / seeds / menu / translations)
│   ├── runtime/                  # Runtime files
│   ├── support/                  # Third-party support libraries
│   ├── tests/                    # Unit tests
│   ├── vendor/                   # Composer dependencies
│   ├── composer.json             # Composer config
│   ├── composer.lock             # Dependency lock file
│   ├── phpunit.xml               # Test config
│   └── README.md                 # Documentation (Chinese)
│
└── template/                     # Front-end template directory (download separately)
    ├── admin/                    # Admin front-end
    ├── web/                      # Site front-end
    └── install/                  # Installer front-end
```

> **Note**: The front end and back end are deployed separately. The front-end directory must be downloaded or created separately.

## Core Features

- **Authentication**: JWT-based authentication mechanism
- **Authorization**: Fine-grained permission control system
- **Caching**: Integrated Redis cache for better performance
- **Mail**: Built-in email sending support
- **File Upload**: Multiple storage backends supported
- **Internationalization**: Multi-language configuration support
- **Plugin System**: Third-party extensions (codegen, demo, etc.)
- **CLI Tools**: Extra operations such as plugin migration
- **SSE Push**: Real-time Server-Sent Events progress feedback
- **Layered Architecture**: Clear DAO/Service layering
- **Code Generator**: Auto-generates controllers, models and service-layer code
- **Database Migration**: Versioned database management
- **Monolithic Architecture**: Unified standard and multi-tenant editions, enabled on demand

## Requirements

- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx / Apache
- Composer 2.x+

## Installation & Deployment

### 1. Prepare the environment

Make sure the following software is installed:
- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx / Apache
- Composer 2.x+
- Git (used to download front-end assets)

### 2. Install the project

```bash
# Enter the backend directory
cd backend

# Install backend dependencies
composer install
```

### 3. Download front-end assets

**Option A: Download via command (recommended)**

```bash
# Enter the backend directory
cd backend

# Download all front-end code (admin + site)
php webman madong-download:frontend

# Download admin only
php webman madong-download:frontend --admin

# Download site only
php webman madong-download:frontend --web

# Download a specific branch
php webman madong-download:frontend -b develop

# Force overwrite update
php webman madong-download:frontend -f
```

**Option B: Manual download**

If Git is not installed or the command cannot be used, download the front-end code manually:

1. Get the front-end repositories from GitHub:
   - Admin: https://github.com/madong-code/madong-admin (template/admin)
   - Site:  https://github.com/madong-code/madong-web (template/web)

2. After extracting, place the code under the project root:
   ```
   madong/
   ├── backend/          # Backend code
   └── template/         # Front-end template code
       ├── admin/        # Admin front-end (from madong-admin)
       ├── web/          # Site front-end (from madong-web)
       └── install/      # Installer front-end
   ```

### 4. Launch the installer

After completing the steps above, visit the installer to finish system configuration:

```
http://127.0.0.1:8500/install
```

### 5. Start the service

```bash
# Enter the backend directory
cd backend

# Start in development mode
php start.php start

# Start in production mode (daemonized)
php start.php start -d
```

The service runs at `http://127.0.0.1:8500` by default.

## Configuration

### Database

Edit `config/database.php` to configure the database connection:

```php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'madong',
            'username' => 'root',
            'password' => '123456',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];
```

### Redis

Edit `config/redis.php` to configure the Redis connection:

```php
return [
    'default' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => '',
        'db' => 0,
    ],
];
```

## API Documentation

After starting the service, the API can be explored by:
- Inspecting controllers under `app/adminapi/controller` and `app/api/controller`
- Referring to the project documentation

## Development Guide

### Coding Standards

- Follow PSR-4 autoloading
- Follow PSR-12 coding style
- Use PHP 8.2+ features

### Module Development

1. Create a controller under `app/adminapi/controller` (organized by business domain) or `app/api/controller` (organized by site)
2. Create a service under the corresponding domain directory in `app/service`
3. Create a DAO under the corresponding domain directory in `app/dao`
4. Create a model under the corresponding domain directory in `app/model`
5. Add routes in `config/route.php`, `config/route/adminapi.php` or `config/route/api.php`
6. Test the API with Postman or similar tools

## Live Demo

Admin panel: http://v5.madong.tech/admin
Account: admin
Password: 123456

## Changelog

### v5.1.0 (current)

- Refactored the backend into the **Madong monolithic admin architecture**, unifying standard and multi-tenant editions
- **Core layering**: `core` is restructured into business / communication / foundation / infrastructure / interface / io / security layers, removing the old flat core module
- **Application layer reorg**: `app` is split into `adminapi` (controllers/events/listeners/validators by domain) and `api` (site) entry points; `dao` / `model` / `enum` / `service` / `schema` are layered by domain, with `command` plugin migration, `event`/`listener` and `bootstrap` modules added
- **Plugin cleanup**: removed the `plugin/example` sample plugin, trimmed the `plugin/demo` front-end template source, and added the `plugin/codegen` code generation plugin
- **Migrations**: renamed migration files (2026_04_01 → 2026_07_01), added system/member/codegen/site/review table migrations, and substantially updated menu and seed data
- **Config & install**: added `limiter` (renamed from `rate-limiter`), added the `validation` plugin config, updated installer assets and `composer` dependencies
- **Tests**: added `phpunit.xml` unit test configuration

### 5.0

- Rebuilt the backend architecture on the Webman framework
- Implemented JWT authentication
- Integrated Redis caching
- Optimized database operations
- Enhanced the plugin system with third-party extensions
- Completed the configuration system with multi-environment support
- Added internationalization (multi-language) support
- Improved error handling and logging
- Added file upload with multiple storage backends
- Improved CLI tooling with more operations
- Added SSE real-time push for plugin install/uninstall progress
- Introduced the Service and DAO layered architecture
- Added a code generator to boost productivity
- Unified plugin install/uninstall lifecycle via `Install.php`
- Added enum conventions for unified status codes and business enums

## AI Development Skills

Madong ships a set of **Skills** for AI coding assistants, covering backend, front-end and cross-platform scenarios to automate module development, code generation and CRUD scaffolding.

- **Repository**: https://github.com/madong-code/madong-skills
- **Structure**:
  - `backend/`: backend skills (controller / service / DAO / migration scaffolding)
  - `frontend/`: front-end skills (page / form / list scaffolding)
  - `cross/`: cross-platform skills (conventions, commit messages, sync scripts)
- **Usage**: clone the skills repository locally, load the relevant skills into your AI coding assistant, and follow their SOPs.

## Official Forum

Report bugs or suggest improvements in the community: http://www.madong.tech

## Contributing

Contributions are very welcome! [Open an Issue](https://github.com/madong-code/madong/issues) or submit a Pull Request.

**Pull Request:**

1. Fork the repository
2. Create your branch: `git checkout -b feature/xxxx`
3. Commit your changes: `git commit -am 'feat(function): add xxxxx'`
4. Push your branch: `git push origin feature/xxxx`
5. Open a Pull Request

## Commit Convention

- Reference: [Git](https://www.conventionalcommits.org/) · [Vue](https://github.com/vuejs/vue/blob/dev/.github/COMMIT_CONVENTION.md) · [Angular](https://github.com/conventional-changelog/conventional-changelog/tree/master/packages/conventional-changelog-angular)

  - `feat`     add a new feature
  - `fix`      fix a problem / bug
  - `style`    code style only, no runtime impact
  - `perf`     performance improvement
  - `refactor` refactoring
  - `revert`   revert a change
  - `test`     add or update tests
  - `docs`     documentation / comments
  - `chore`    dependency or scaffolding changes
  - `ci`       continuous integration
  - `types`    type definition changes
  - `wip`      work in progress

## Community

- Documentation: https://madong.tech
- Discussion: [Join the group chat: 690671595](https://qm.qq.com/q/dEfMqYL42c)

## License

Apache License
