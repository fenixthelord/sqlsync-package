# ⚠️ DEPRECATED — Do Not Install

> **`sqlsync/laravel-sqlsync` is no longer maintained as a standalone package.**

## What happened?

All functionality originally provided by this package has been **absorbed directly into the main product**, [`fenixthelord/sqlsync-store`](https://github.com/fenixthelord/sqlsync-store).

The rebuilt integration is cleaner, more secure, and requires zero manual setup:

| Legacy (this package) | Current (in `sqlsync-store`) |
|---|---|
| `SqlSync\LaravelSqlSync\Http\Middleware\AgentAuth` — HMAC with one shared secret in `.env` | `App\Auth\AgentTokenGuard` — per-device Bearer tokens, no shared secret |
| `composer require sqlsync/laravel-sqlsync` + `.env` config | Nothing to install — built in |
| Manual `SQLSYNC_AGENT_SECRET` + `SQLSYNC_MULTI_TENANT` env vars | None — auto-detected via `stancl/tenancy` |
| Migrations published from package | Included in project migrations |

## What should I do?

- **New projects**: Use [`sqlsync-store`](https://github.com/fenixthelord/sqlsync-store) directly. Do not `composer require` this package.
- **Old projects that installed this package**: The functionality is duplicated inside `sqlsync-store`. Remove the composer requirement and the related env vars — the built-in `sqlsync-store` implementation supersedes them.

## Why keep this repo public?

For historical reference and to keep any existing `composer.lock` files resolvable. The code will not receive updates.

The full legacy documentation is preserved at [`README-legacy.md`](./README-legacy.md).

---

_Last active development: pre-2026. Superseded by `sqlsync-store`._
