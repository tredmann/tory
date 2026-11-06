# Tory

Laravel 13 · Filament 5 · Livewire 4 · Laravel AI SDK · FrankenPHP (PHP 8.5) · Postgres 18

The Filament panel is the whole app and answers at `/`.

Development happens entirely in Docker. You only need Docker Desktop (or compatible) on the host.

## Where things stand

Tory is going to be a household inventory. Built so far (2026-11-04 to 2026-11-06):

- **Stack and tooling.** Dockerised FrankenPHP app with queue worker and scheduler under supervisord, Postgres, Vite service, Pest/Pint/Larastan, Laravel Boost with the Filament skill, GitHub Actions test workflow.
- **Panel.** Filament 5 is the whole app at `/`, with login, registration, password reset, email verification and profile. Every verified user sees everything; there is no policy layer yet.
- **Photo imports.** "Importe" → "Erfassen" uploads 1–6 photos, stores them on the private disk and queues `ExtractImport`. The AI extractor (Anthropic in production, Ollama + gemma4 locally) returns a German title and a search-friendly description that only describes what is visible. The import page polls until the job is done, shows the result or the error, and offers Retry. Verified live against gemma4.

Not built yet, in the intended order:

1. Edit the extracted title and description on the import page.
2. Turn an import into an inventory **item** (the `Item` model does not exist); photos move with it.
3. Browse and search items; delete imports/items including their photos.

Design documents and plans for each feature are in `docs/superpowers/specs/` and `docs/superpowers/plans/`. Known small gaps are listed at the end of each plan's execution recap (Retry race under concurrent clicks, list table polling while idle, a handful of test-coverage gaps).

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

Photos are imported at `/importe/create` ("Erfassen"): the upload is queued and `App\Ai\Agents\InventoryExtractor` turns the photos into a German title and description, which appear on the import's page once the job has finished, so the queue worker must be running. Its provider and model are set separately; locally it runs on Ollama with `gemma4:e4b-mlx`, reached from the container via `host.docker.internal`:

```dotenv
INVENTORY_AI_PROVIDER=ollama
INVENTORY_AI_MODEL=gemma4:e4b-mlx
OLLAMA_URL=http://host.docker.internal:11434
```

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

Provide a real `.env` (or environment variables) and set `AUTO_MIGRATE=true` if you want migrations to run on start. Keep `DB_QUEUE_RETRY_AFTER` above the import job timeout (200 seconds; the default is 300), otherwise a second worker can re-run a running extraction.
