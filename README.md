# Tory

Laravel 13 · Livewire 4 · Tailwind 4 + daisyUI 5 · Laravel AI SDK · Laravel Fortify · FrankenPHP (PHP 8.5) · Postgres 18

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

## AI assistant

Set `ANTHROPIC_API_KEY` in `.env` (Anthropic is the default provider in `config/ai.php`; `OPENAI_API_KEY` works too if you switch the default). The dashboard has an assistant backed by `app/Ai/Agents/Assistant.php`.

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

`.mcp.json` runs the Boost MCP server inside the container (`docker compose exec -T app php artisan boost:mcp`), so Claude Code picks it up from the project root with no host PHP. Guidelines live in `CLAUDE.md`, skills in `.claude/skills`. Refresh them with `docker compose exec app php artisan boost:update`.

## Production image

`docker/Dockerfile` has a `prod` target that bakes in Composer dependencies and the built Vite assets and runs `queue:work` instead of `queue:listen`:

```sh
docker build -f docker/Dockerfile --target prod -t tory:prod .
```

Provide a real `.env` (or environment variables) and set `AUTO_MIGRATE=true` if you want migrations to run on start.
