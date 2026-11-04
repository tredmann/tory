# Tory

Laravel 13 · Filament 5 · Livewire 4 · Laravel AI SDK · FrankenPHP (PHP 8.5) · Postgres 18

The Filament panel is the whole app and answers at `/`.

Development happens entirely in Docker. You only need Docker Desktop (or compatible) on the host.

## Getting started

```sh
git clone <repo> tory && cd tory
docker compose up --build -d
```

On first start the `app` container copies `.env.example` to `.env`, generates `APP_KEY`, installs Composer dependencies and runs the migrations. The `vite` container installs npm dependencies and starts the dev server.

| Service | URL / port | What it runs |
|---|---|---|
| `app` | http://localhost:8000 | FrankenPHP (classic mode) + `queue:listen` + `schedule:work` under supervisord |
| `vite` | http://localhost:5173 | Vite dev server with HMR |
| `db` | `localhost:5432` (`tory` / `tory` / `secret`) | Postgres 18 |

Mail uses the `log` driver: verification and password-reset links appear in `storage/logs/laravel.log`.

## Create a user

Register at http://localhost:8000/register, or create one from the CLI:

```sh
docker compose exec app php artisan make:filament-user --name=Admin --email=admin@example.com --password=secret
```

Users created from the CLI are not email-verified, and the panel requires verification. Either follow the verification link that is written to `storage/logs/laravel.log` on first login, or mark the user verified directly:

```sh
docker compose exec app php artisan tinker --execute='App\Models\User::firstWhere("email", "admin@example.com")->markEmailAsVerified();'
```

## AI

`laravel/ai` is installed with Anthropic as the default provider (`config/ai.php`). Set `ANTHROPIC_API_KEY` in `.env`. The `App\Ai\Agents\Assistant` agent exists but has no UI yet.

## Everyday commands

```sh
docker compose exec app php artisan <command>      # artisan
docker compose exec app php artisan test           # Pest test suite
docker compose exec app ./vendor/bin/pint          # code style
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app composer <command>
docker compose exec vite npm <command>             # npm (never on the host)
docker compose exec app supervisorctl status       # frankenphp / queue / scheduler
docker compose logs -f app                         # web, queue and scheduler output
docker compose exec db psql -U tory -d tory
```

Smoke-test the queue worker: `docker compose exec app php artisan tinker --execute="App\Jobs\PingLog::dispatch();"` and watch for `queue ok` in `storage/logs/laravel.log`.

## Laravel Boost

`.mcp.json` runs the Boost MCP server inside the container (`docker compose exec -T app php artisan boost:mcp`), so Claude Code picks it up from the project root with no host PHP. Guidelines live in `CLAUDE.md`, skills in `.claude/skills`. Refresh them with `docker compose exec app php artisan boost:install --guidelines --skills --mcp` (then `git checkout .mcp.json`, since Boost rewrites it for host PHP).

## Production image

`docker/Dockerfile` has a `prod` target that bakes in Composer dependencies and the built Vite assets and runs `queue:work` instead of `queue:listen`:

```sh
docker build -f docker/Dockerfile --target prod -t tory:prod .
```

Provide a real `.env` (or environment variables) and set `AUTO_MIGRATE=true` if you want migrations to run on start.
