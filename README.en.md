<div align="center">

<img src="public/logo.png" alt="Madong Logo" width="120" />

# Madong Admin Backend Framework

A high-performance admin backend platform built on PHP Webman, using a **front-end / back-end separation + dual front-end** architecture

[![PHP](https://img.shields.io/badge/PHP-8.2~8.4-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Webman](https://img.shields.io/badge/Webman-2.2-2488C9)](https://www.workerman.net/doc/webman)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7.0+-DC382D?logo=redis&logoColor=white)](https://redis.io)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue)](./LICENSE)

[![Docs](https://img.shields.io/badge/Docs-madong.tech-7C3AED)](https://madong.tech)
[![Demo](https://img.shields.io/badge/Demo-v5.madong.tech-10B981)](http://v5.madong.tech/admin)

[中文](./README.md) | **English**

</div>

## Introduction

Madong is a blazing-fast development platform built on the PHP **Webman** framework, providing a stable, high-performance API service layer for front-end applications. The backend uses a clear domain-layered architecture and a first-class plugin system; the front end supports **dual front-end UIs** — two selectable Admin UIs (Single / Aura) for the backend panel, plus an independent Web site front-end shared with the multi-tenant edition.

> The front end and back end are deployed separately. The front-end directory must be downloaded or created separately.

## Dual Front-End UI & Repositories

Madong supports **dual front-end UIs**: the admin panel offers two selectable UIs (Single / Aura), and the site front-end is shared with the multi-tenant edition. **Pull from GitHub by default**; if the network is restricted, replace the GitHub path in the table with an equivalent mirror repository.

| Module | Type | Default Repository (GitHub) | Other Mirrors |
| :--- | :--- | :--- | :--- |
| 🖥️ Admin · Single | Admin front-end · UI option 1 (`template/admin`) | [madong-code/madong-single](https://github.com/madong-code/madong-single) | Replaceable with a custom mirror |
| 🖥️ Admin · Aura | Admin front-end · UI option 2 (`template/admin`) | [madong-code/madong-aura](https://github.com/madong-code/madong-aura) | Replaceable with a custom mirror |
| 🌐 Web | Site front-end (`template/web`, shared with the multi-tenant edition) | [madong-code/web-nuxt](https://github.com/madong-code/web-nuxt) | Replaceable with a custom mirror |
| 🤖 Skills | AI development skills | [madong-code/madong-admin-skills](https://github.com/madong-code/madong-admin-skills) | Replaceable with a custom mirror |

> Pick either Admin UI for `template/admin`; the site uses `web-nuxt`, shared with the multi-tenant edition.
> The "Default Repository" in the table is the GitHub path submitted to the install / download commands. For example, downloading the admin:
> `php webman madong-download:frontend --admin` pulls from the GitHub repo above by default;
> for mirrors, configure `dependencies.npm.registry` etc., or specify a mirror repository address directly.

## Tech Stack

| Technology | Description |
|------------|-------------|
| PHP | 8.2 ~ 8.4, core development language |
| Webman | 2.2, high-performance PHP framework |
| MySQL | 8.0+, database |
| Redis | 7.0+, cache service |
| Composer | 2.x, dependency manager |
| Nginx / Apache | Web server |

## Project Structure

```
madong/
├── backend/    # Backend (CLI installer included; one-click install configures automatically)
└── template/   # Front-end templates (admin / web / install; download separately, see table above)
```

> For more modules, directories and developer docs, see the official documentation: [madong.tech](https://madong.tech)

## Core Features

- **Authentication**: JWT-based authentication mechanism
- **Authorization**: Fine-grained permission control system
- **Caching**: Integrated Redis cache
- **Communication**: Mail / SMS / notification infrastructure
- **File Upload**: Multiple storage backends supported
- **Internationalization**: Multi-language configuration support
- **Plugin System**: Third-party extensions (codegen, demo, etc.)
- **SSE Push**: Real-time Server-Sent Events progress feedback
- **Layered Architecture**: Clear DAO / Service layering
- **Code Generator**: Auto-generates controllers, models and service-layer code
- **Database Migration**: Versioned database management
- **Dual Front-End UI**: Two selectable Admin UIs plus an independent site front-end (shared with the multi-tenant edition)

## Requirements

- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx / Apache
- Composer 2.x+
- Node.js (with pnpm, for front-end builds)

## Installation & Deployment

### 1. Prepare the environment

Install: PHP 8.2 ~ 8.4, MySQL 8.0+, Redis 7.0+, Composer 2.x+, Node.js (with pnpm), Git.

### 2. Install backend dependencies

```bash
cd backend
composer install
```

### 3. Download front-end assets

**Option A: Command download (recommended)** — pulls from the GitHub repos in the table above by default:

```bash
cd backend
php webman madong-download:frontend                 # admin + web
php webman madong-download:frontend --admin         # admin only
php webman madong-download:frontend --web           # web only
php webman madong-download:frontend -b develop      # specific branch
php webman madong-download:frontend -f              # force overwrite
```

**Option B: Manual download** — clone from the GitHub URLs in the "Dual Front-End UI & Repositories" table; pick either Admin UI into `template/admin`, and the site into `template/web`.

### 4. Launch the installer

```
http://127.0.0.1:8500/install
```

### 5. Start the service

```bash
cd backend
php start.php start        # development
php start.php start -d     # production (daemonized)
```

The service runs at `http://127.0.0.1:8500` by default.

## Configuration

Database, Redis and other connection settings are written automatically by the one-click installer — no manual editing required.

> For more configuration options and advanced usage, see the official documentation: [madong.tech](https://madong.tech)

## Plugin Dependency Management

Plugins declare dependencies in `config/info.php` via `require.composer` (backend) and `npm_require.admin|web|common` (frontend). Install / uninstall is handled by the dependency pipeline in `core/business/plugin`:

- **Declaration merge**: merged into `composer.json` and `template/{admin,web}/package.json`; conflicts follow `dependencies.conflict.strategy` (`skip`/`override`/`fail`, default `skip`)
- **Real installation**: `dependencies.auto_install` defaults to `false` (merge only, print pending commands); set `true` to run `composer update` and `pnpm install` automatically
- **Network resilience**: `dependencies.network.retry` controls retries; `dependencies.composer.mirror` / `dependencies.npm.registry` configure mirrors
- **Reference counting**: a shared dependency is only removed when the last plugin using it is uninstalled
- **State persistence**: dependency state is stored in the plugin's `config/installed.php` (no database); a failed install does not abort the plugin install by default
- **Transaction rollback**: failed install / uninstall restores dependency declarations and template directories

If the network is unavailable during install, retry later:

```bash
php webman madong-plugin:install-deps <plugin> --status    # show status
php webman madong-plugin:install-deps <plugin>              # re-run install
```

In the admin panel (Module Market → Detail) a status badge and an "Install Dependencies" button appear when dependencies are pending / failed. The same data is available via `GET /adminapi/plugin/{key}/dependencies` and `GET /adminapi/plugin/{key}/dependencies/install` (SSE).

## AI Development Skills

A set of **Skills** for AI coding assistants, covering backend, front-end and cross-platform scenarios:

- **Repository**: [madong-code/madong-admin-skills](https://github.com/madong-code/madong-admin-skills) (see table above)
- **Structure**: `backend/` (backend scaffolding), `frontend/` (front-end scaffolding), `cross/` (cross-platform)
- **Usage**: clone locally, load the relevant skills into your AI assistant, and follow their SOPs

## Official Forum

Report bugs or suggest improvements in the community: http://www.madong.tech

## Contributing

Contributions are very welcome! [Open an Issue](https://github.com/madong-code/madong-admin/issues) or submit a Pull Request.

1. Fork the repository
2. Create your branch: `git checkout -b feature/xxxx`
3. Commit: `git commit -am 'feat(function): add xxxxx'`
4. Push: `git push origin feature/xxxx`
5. Open a Pull Request

## Commit Convention

Reference [Conventional Commits](https://www.conventionalcommits.org/): `feat` / `fix` / `style` / `perf` / `refactor` / `revert` / `test` / `docs` / `chore` / `ci` / `types` / `wip`.

## Community

- Documentation: https://madong.tech
- Discussion: [QQ group 690671595](https://qm.qq.com/q/dEfMqYL42c)

## License

Apache License
