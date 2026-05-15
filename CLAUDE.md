# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Full dev environment (server + queue + logs + vite, all concurrent)
composer run dev

# First-time setup
composer run setup

# Run all tests
composer run test
# or
php artisan test

# Run single test or filter
php artisan test --filter TestClassName
php artisan test --filter test_method_name

# Code formatting (Laravel Pint)
./vendor/bin/pint

# Database migrations
php artisan migrate
php artisan migrate:fresh --seed
```

## Architecture

**Stack**: Laravel 13.8, PHP 8.3+, SQLite (dev) / MySQL (prod: `siat_api`), Queue via database driver.

**Bootstrap**: `bootstrap/app.php` uses Laravel 13's fluent config API — no `Kernel.php`. Middleware, routing, and exception handling all configured here.

**Current state**: Fresh skeleton. `routes/api.php` does not exist yet — run `php artisan install:api` to scaffold it along with Sanctum token authentication. `routes/web.php` only returns a Blade welcome view.

**Health check**: `GET /up` (registered in bootstrap/app.php, no controller needed).

**Testing**: PHPUnit 12, SQLite in-memory DB, sync queue, array cache/session/mail. All configured in `phpunit.xml` — no `.env.testing` needed.

**Queue & Cache**: Both use `database` driver in dev. Three default migrations: `users`, `cache`, `jobs` tables.

## REST API Setup Checklist (not yet done)

1. `php artisan install:api` — adds `routes/api.php` + Sanctum
2. Add CORS config if consumed by external frontend
3. Register `routes/api.php` in `bootstrap/app.php` under `->withRouting(api: ...)`
4. Version routes under `/api/v1/`
