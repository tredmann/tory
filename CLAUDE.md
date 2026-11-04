# Tory

Laravel 13 + Filament 5 application running on FrankenPHP (PHP 8.5) with Postgres 18, developed entirely in Docker. The Filament panel is the whole app and is served at `/`.

## Running the app

- `docker compose up -d` starts three services: `app` (FrankenPHP + queue + scheduler under supervisord, http://localhost:8000), `db` (Postgres 18, port 5432), `vite` (Vite dev server, http://localhost:5173; idle until a custom Filament theme exists).
- The project directory is bind-mounted into `app` and `vite` at `/app`. Edit files on the host; no rebuild needed.
- The app entrypoint (`docker/entrypoint.sh`) creates `.env`, generates `APP_KEY`, runs `composer install` when `vendor/` is missing, and runs migrations when `AUTO_MIGRATE=true` (set in `compose.yaml`).
- Rebuild the image after changing `docker/`: `docker compose build app`.

## Running commands

Everything PHP runs inside the `app` container; everything npm runs inside the `vite` container. Never use host PHP/Composer/npm.

```sh
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose exec app php artisan test            # Pest
docker compose exec app ./vendor/bin/pint            # formatter
docker compose exec app ./vendor/bin/phpstan analyse # Larastan
docker compose exec app php artisan make:filament-user --name=Admin --email=admin@example.com --password=secret
# CLI-created users are unverified; mark one verified with:
docker compose exec app php artisan tinker --execute='App\Models\User::firstWhere("email", "admin@example.com")->markEmailAsVerified();'
docker compose exec vite npm <command>
docker compose exec db psql -U tory -d tory
```

Supervised processes: `docker compose exec app supervisorctl status` (frankenphp, queue, scheduler). Logs: `docker compose logs -f app`.

## Conventions

- The panel is configured in `app/Providers/Filament/AdminPanelProvider.php` (id `admin`, path `''`). Resources, pages and widgets live under `app/Filament/` and are auto-discovered. `routes/web.php` must never define `/`.
- Auth is Filament's: login, registration, password reset, email verification and profile are enabled on the panel. `App\Models\User` implements `FilamentUser` (every user may access the panel) and `MustVerifyEmail`.
- Filament assets are generated (`public/{css,js,fonts}/filament`, gitignored). `composer dump-autoload` runs `filament:upgrade`; run `php artisan filament:assets` after a manual Filament upgrade.
- Styling: Filament's precompiled theme. `resources/css/app.css` is a plain Tailwind 4 entry that nothing loads yet; add a custom theme with `php artisan make:filament-theme admin` when needed.
- Build UI as Filament pages, resources and widgets. There is no app-level Blade layout: `config/livewire.php` still carries Livewire's default `layouts::`/`pages::` namespaces and `component_layout => 'layouts::app'`, but those directories do not exist, so a `Route::livewire()` full-page component would fail until you create a layout (`php artisan livewire:layout`). Livewire's `make_command.emoji` is off, so generated component files have no ⚡ prefix.
- AI: `laravel/ai` with Anthropic as the default provider (`config/ai.php`). Agents live in `app/Ai/Agents/` (`Assistant`); there is no UI for it yet. Set `ANTHROPIC_API_KEY` in `.env`. In tests use `Assistant::fake()` / `Assistant::assertPrompted()`.
- Inventory extraction: the panel page "Erfassen" (`app/Filament/Pages/ExtractItem.php`, `/erfassen`) sends uploaded photos to the structured-output agent `app/Ai/Agents/InventoryExtractor.php`, which returns a German `title` and `description`. Provider and model come from `INVENTORY_AI_PROVIDER` / `INVENTORY_AI_MODEL` (`config('ai.inventory.*')`); for local Ollama also set `OLLAMA_URL`. Nothing is persisted yet: the result is only shown on the page and the uploads are deleted. The instructions tell the model to answer with a bare JSON object because the local `gemma4:e4b-mlx` ignores Ollama's `format` schema and otherwise prefixes `json`, which fails decoding.
- Mail uses the `log` driver in development; verification and reset links are in `storage/logs/laravel.log`.
- Tests use Pest with an in-memory SQLite database (`phpunit.xml`); `RefreshDatabase` is applied to every Feature test in `tests/Pest.php`. Panel pages are tested with `Livewire::test(PageClass::class)->fillForm([...])->call('action')`.
- Write a failing test before production code. Run Pint and PHPStan before committing.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
