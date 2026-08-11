# AGENTS.md

NexusPHP (`xiaomlove/nexusphp`) — a private-tracker (PT) site: legacy NexusPHP procedural PHP fused with Laravel 12 + Filament 5.

## Architecture

- **Hybrid codebase.** Most user-facing pages are legacy procedural scripts in `public/*.php` driven by `include/` (plain PHP globals, no PSR). New code is Laravel: `App\` → `app/`, `Nexus\` → `nexus/`.
- **`bootstrap/app.php` is the shared bootstrap for both worlds.** It requires `include/constants.php`, `include/globalfunctions.php`, `include/functions.php`, boots `\Nexus\Nexus::boot()` (also initializes `$GLOBALS['hook']` / `$GLOBALS['plugin']`), then builds the Laravel app. Every `public/*.php` script depends on this.
- **Legacy page conventions** (keep when touching `public/*.php`): first line `ob_start();` (do not delete), then `require_once("../include/bittorrent.php");`, `dbconn();`, `get_langfile_path()`, `loggedinorreturn();`. Style is old globals/helpers: `sql_query()`, `mysql_fetch_assoc()`, `get_setting()`, `nexus_env()`, `$CURUSER`, `stderr()`. Do not rewrite these to Laravel idioms.
- **Multi-platform.** `\Nexus\Nexus` supports `PLATFORM_USER` / `PLATFORM_ADMIN` / `PLATFORM_TRACKER`; the platform is auto-detected at boot from the `platform` request header (see `setPlatform()` in `nexus/Nexus.php`).
- **Routes are partial.** `routes/*.php` only cover newer Laravel controllers; most URLs hit `public/*.php` directly, never Laravel routes.
- **DB layer.** Legacy `nexus/Database/*` (DBMysqli, DBPdo, NexusDB, ClickHouse) coexists with Eloquent. Since v1.6 all tables are created by `database/migrations/`; `_db/` is a read-only legacy reference. Settings are DB-backed and read via `get_setting()`; `config/allconfig.php` holds legacy defaults.
- **Admin panel** is Filament 5 (`app/Filament`) with Livewire in `app/Livewire`. One-off data migrations/upgrades live in `app/Console/Commands/Upgrade/` and run via `php artisan nexus:update`.

## Commands

- Requires PHP >=8.2 <8.6 with many extensions (bcmath, gmp, pcntl, posix, redis, intl, ...). `vendor/` and `node_modules/` are not checked in — run `composer install` (post-install copies `.env`, generates passport keys, runs `filament:upgrade`).
- Tests: `php artisan test` (Unit + Feature; `phpunit.xml` uses array/cache/sync drivers, DB config commented out — no MySQL needed by default).
- Frontend: Laravel Mix + Tailwind 3 — `npm run dev` / `npm run watch` / `npm run prod`.

## Conventions

- StyleCI: `laravel` preset with `no_unused_imports` disabled; `.editorconfig` = 4-space indent, LF.
- Git: default remote branch is `php8` (origin/HEAD); other remote branches: `1.8`, `laravel11`.
- Docs: https://doc.nexusphp.org (also linked from `_doc/`). READMEs are Chinese-first; `README-EN.md` is the English mirror. Lang files live in `lang/`.
