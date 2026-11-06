# Tory

Laravel 13 · Filament 5 · Livewire 4 · Laravel AI SDK · FrankenPHP (PHP 8.5) · Postgres 18

The Filament panel is the whole app and answers at `/`.

Development happens entirely in Docker. You only need Docker Desktop (or compatible) on the host.

## Where things stand

Tory is a household inventory. Built so far (2026-11-04 to 2026-11-06):

- **Stack and tooling.** Dockerised FrankenPHP app with queue worker and scheduler under supervisord, Postgres 18 with pgvector, Vite service, Pest/Pint/Larastan, Laravel Boost with the Filament skill, GitHub Actions test workflow.
- **Panel.** Filament 5 is the whole app at `/`, with login, registration, password reset, email verification and profile. Every verified user sees everything; there is no policy layer yet.
- **Photo imports.** "Importe" → "Erfassen" uploads 1–6 photos, stores them on the private disk and queues `ExtractImport`. The AI extractor (runs on the provider active on "Eigene KI", with `.env` as fallback) returns a German title and a search-friendly description. The import page polls until the job is done, shows the result or the error, and offers Retry and "Löschen".
- **Spots.** "Lagerplätze": every spot has a permanent random four-character code (shown as `4WWH · Name`) and a page at `/spots/{code}` that lists the items inside; that address is the future QR target.
- **Locations.** "Lagerorte" (`/locations`) group spots (e.g. Keller, Garage); every spot belongs to one, is shown as `Keller → 4WWH · Regal`, and the item list can be filtered by location.
- **Items.** "Gegenstände": "Übernehmen" on a completed or failed import turns it into an item in a chosen spot (the photo files stay where they are; ownership moves to the item). Items can be edited and deleted (photos included).
- **Eigene KI.** The panel entry "Eigene KI" (`/ai-providers`) lists AI providers as cards (Ollama, Claude, OpenAI, Mistral). Create several, pick the model from a list fetched from the vendor or type it, test one with a bundled photo, and activate one with a click; the next import uses it. Nothing switches providers on its own. "Exportieren" downloads all providers as a JSON file **with the API keys in plain text**, and "Importieren" reads such a file on another installation: providers whose UUID already exists are skipped, new ones arrive inactive. Every verified user can export the keys, so decide about self-registration before making an installation public.
- **Search.** The item list searches by keyword (title and description) and by meaning via embeddings (`bge-m3` on Ollama, 1024 dimensions, stored in pgvector). Run `ollama pull bge-m3` once to enable the semantic part; without it the search still works by keyword. `items:embed` catches up missing embeddings hourly.

Next: print labels with QR codes for spots.

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
| `db` | `localhost:5432` (`tory` / `tory` / `secret`) | Postgres 18 with pgvector |

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

Photos are imported at `/imports/create` ("Erfassen"): the upload is queued and `App\Ai\Agents\InventoryExtractor` turns the photos into a German title and description, which appear on the import's page once the job has finished, so the queue worker must be running. Which AI does the work is set on the panel page **Eigene KI** (`/ai-providers`): "Provider anlegen" with name, vendor (Ollama, Claude, OpenAI, Mistral), API key or Ollama address, and model; "Testen" sends a small test photo; "Aktivieren" makes it the one used from the next extraction on. API keys are stored encrypted with `APP_KEY` and never shown again (only the last four characters), so changing `APP_KEY` makes stored keys unreadable and they must be entered again. The active provider cannot be deleted. Note that registration is open: on a publicly reachable install anyone who registers could use the stored keys, so turn registration off before going public.

Only while no provider is active does extraction fall back to `.env`; locally it then runs on Ollama with `gemma4:e4b-mlx`, reached from the container via `host.docker.internal`:

```dotenv
INVENTORY_AI_PROVIDER=ollama
INVENTORY_AI_MODEL=gemma4:e4b-mlx
OLLAMA_URL=http://host.docker.internal:11434
```

Semantic search embeds items and queries with a separate provider and model (defaults `ollama` and `bge-m3`, 1024 dimensions; run `ollama pull bge-m3` once). The minimum cosine similarity for a semantic match is tunable:

```dotenv
INVENTORY_EMBEDDING_PROVIDER=ollama
INVENTORY_EMBEDDING_MODEL=bge-m3
INVENTORY_SEARCH_MIN_SIMILARITY=0.5
```

## Everyday commands

```sh
docker compose exec app php artisan <command>      # artisan
docker compose exec app php artisan test           # Pest test suite (database tory_testing)
docker compose exec app ./vendor/bin/pint          # code style
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app composer <command>
docker compose exec vite npm <command>             # npm (never on the host)
docker compose exec app supervisorctl status       # frankenphp / queue / scheduler
docker compose logs -f app                         # web, queue and scheduler output
docker compose exec db psql -U tory -d tory
```

The tests run on the Postgres database `tory_testing`, which a fresh volume creates on first start. For a volume that already existed, create it once with `docker compose exec db createdb -U tory tory_testing`.

Smoke-test the queue worker: `docker compose exec app php artisan tinker --execute="App\Jobs\PingLog::dispatch();"` and watch for `queue ok` in `storage/logs/laravel.log`.

## Laravel Boost

`.mcp.json` runs the Boost MCP server inside the container (`docker compose exec -T app php artisan boost:mcp`), so Claude Code picks it up from the project root with no host PHP. Guidelines live in `CLAUDE.md`, skills in `.claude/skills`. Refresh them with `docker compose exec app php artisan boost:install --guidelines --skills --mcp` (then `git checkout .mcp.json`, since Boost rewrites it for host PHP).

## Production image

`docker/Dockerfile` has a `prod` target that bakes in Composer dependencies and the built Vite assets and runs `queue:work` instead of `queue:listen`:

```sh
docker build -f docker/Dockerfile --target prod -t tory:prod .
```

Provide a real `.env` (or environment variables) and set `AUTO_MIGRATE=true` if you want migrations to run on start. The database needs the pgvector extension (the dev `db` image is `pgvector/pgvector:pg18-trixie`; the items migration creates the extension, which needs a role allowed to do so), and an embedding provider must be reachable for semantic search; without one, search falls back to keywords and `items:embed` reports failures. Keep `DB_QUEUE_RETRY_AFTER` above the import job timeout (200 seconds; the default is 300), otherwise a second worker can re-run a running extraction.
