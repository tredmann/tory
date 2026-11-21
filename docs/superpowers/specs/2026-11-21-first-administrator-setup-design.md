# First administrator setup — design

Date: 2026-11-21. Status: approved (PRD). Ticket: TOR-39. PRD (German): https://kb.balt.io/doc/ersteinrichtung-erster-administrator-im-browser-f3r7lhBxsM

## Intent

Tory should be usable by as many households as possible, including people who never touch a command line. Today a fresh installation needs `make:filament-user`, a tinker call to mark the user verified (tinker does not even run in the prod container) and `users:make-admin` before anyone can log in. From now on the first person who opens a fresh installation creates the first administrator in the browser.

Two roles are kept apart. The **operator** installs Tory (Docker, compose file, `APP_KEY`, Passport keys, database, URL); nothing changes for them. The **administrator** is the existing in-app role; this feature is for them.

## Goals

- On an installation without any user, the first administrator can be created without a single command.
- Afterwards they are logged in and can invite users and set up "Eigene KI" straight away.

## Non-goals

- Onboarding beyond the account (guided "Eigene KI", a "no AI set up yet" hint on the overview). A later feature.
- A one-time setup link written to the log for the operator to pass on. A possible later step.
- Generating `APP_KEY` or the Passport keys in the image.
- Registration for further users; they still join only through invite links.

## Anti-goals

- The setup page is reachable once any user exists. It would be a back door to the registration TOR-18 removed.
- Two simultaneous submits produce two administrators.
- An existing installation ever sees the page.

## Accepted risk

The page is open: whoever reaches a fresh installation first becomes administrator. The operator docs say so plainly and recommend keeping Tory unreachable from the internet until setup is done. An operator who cannot guarantee that keeps creating the first user from the CLI, which closes the page from the start.

## Approaches

1. **A setup page of its own at `/setup` plus panel middleware (chosen).** A `SimplePage` modelled on `AcceptInvite`; an existing pattern, cleanly separate from login. Costs one cheap `exists` query per panel request.
2. **Filament's registration, enabled only while the database is empty.** Rejected: TOR-18 just removed it, and it would need switching on database contents at panel boot, bypassing the verification mail and setting the role. More bending than reuse.
3. **The login page shows the setup form while the database is empty.** Rejected: mixes two jobs on one page, and `/` would still need a redirect.

## Decisions

### When the page is open

- Open exactly while the `users` table is empty. Any user, including an unpromoted or unverified one created from the CLI, closes it for good. Since users cannot be deleted (TOR-18 non-goal), it never reopens.
- While no user exists, panel middleware redirects every panel request except `/setup` itself to `/setup`; that includes `/`, `/login` and `/invite/{token}`. Once a user exists the middleware does nothing.
- `/setup` answers 404 once a user exists, also on submit.
- The REST API, `/mcp` and the OAuth routes are untouched. `/oauth/authorize` already sends guests to the panel login, which then redirects to `/setup`.

### The page

- `App\Filament\Auth\SetUpFirstAdministrator` (a `SimplePage` with a Blade view like `filament.auth.accept-invite`), route `GET /setup`, name `setup`, registered in the panel's `->routes()` next to `invite` with the same `throttle:10,1`. *(My call; not `TorySetup`, which is the "Tory in Claude" page.)*
- Title and heading „Willkommen bei Tory“, subheading „Lege den ersten Administrator an.“ Fields „Name“ (required, max 255), „E-Mail“ (required, email, max 255), „Passwort“ (required, `Password::default()`, same as `AcceptInvite`: 15 characters plus Pwned Passwords in production), „Passwort bestätigen“. Button „Administrator anlegen“.
- On success the new user has role `admin`, `email_verified_at` now, is logged in (`Filament::auth()->login()`, session regenerated) and is redirected to the panel's home.

### Creating the user

- `User::createFirstAdministrator(string $name, string $email, string $password): ?self` runs in one transaction that first takes a Postgres transaction-level advisory lock (`pg_advisory_xact_lock` on a fixed key), then checks `User::query()->exists()`. If a user exists it returns `null`; otherwise it creates the user with role and verification set and returns it. *(My call: a model method like `invite()`; it needs no service.)*
- When it returns `null` (someone else was faster), the page redirects to the panel login, which shows the normal login form because a user now exists.

### Middleware

- `App\Http\Middleware\RedirectToFirstAdministratorSetup`, added to the panel's `->middleware([...])` after `StartSession`. It checks `User::query()->exists()` and redirects to `route('filament.admin.setup')` when false, unless the request is for that route. No caching of the check. *(My call: one indexed `exists` per panel request is cheap, and FrankenPHP runs in classic mode, so a process-level memo buys nothing.)*

### Kept

- `make:filament-user`, the verification hint and `users:make-admin` stay as the CLI path, described as the safe way for servers that are already public.

### Documentation

- `README.md` „Create a user“: the browser is the normal way; the CLI path follows as the alternative, with the accepted risk.
- `docs/dockerhub.md` „First user“: the same, for the image.
- `docs/production.md`: the fresh-install sentence points to the setup page and the risk.
- `CLAUDE.md`: the production paragraph and the Users line in the architecture map name the setup page and the middleware.
- `CONTEXT.md`: term **First administrator setup** (Ersteinrichtung). The operator does not become a term; code and docs keep saying "operator" in passing only.

## Left to the builder

- Test layout, following `tests/Feature/Panel/AcceptInviteTest.php`. Existing panel tests that request `/login` or other guest pages with an empty database must create a user first, since those requests now redirect to `/setup`.
- The advisory lock key.
- Exact German wording beyond the texts above, and the 404 handling inside the Livewire submit.
