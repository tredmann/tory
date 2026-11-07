# MCP Server ("Tory in Claude") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An OAuth-protected MCP server at `/mcp` with four tools (find items, find spots, move item, create spot) and a panel page "Tory in Claude" that explains connecting and lists connections with "Trennen".

**Architecture:** `laravel/mcp` serves `App\Mcp\Servers\ToryServer` from `routes/ai.php`; Passport (`auth:api`) issues the OAuth tokens through `Mcp::oauthRoutes()`, with open dynamic client registration. Tools in `app/Mcp/Tools/` call existing domain code (`ItemSearch`, `Spot`, `Item`). `App\Mcp\ClaudeConnections` lists and revokes a user's connections; the Filament page `App\Filament\Pages\TorySetup` uses it. Sanctum stays the REST API's guard.

**Tech Stack:** Laravel 13, Filament 5, `laravel/mcp`, `laravel/passport` (both approved), Pest.

**Spec:** `docs/superpowers/specs/2026-11-07-mcp-server-design.md`

## Global Constraints

- All commands run in Docker: `docker compose exec app …`. Never host PHP/Composer.
- MCP endpoint: `{APP_URL}/mcp`. Middleware on it: `auth:api` and `App\Http\Middleware\EnsureEmailIsVerified`.
- Only scope: `mcp:use`. Dynamic client registration open to any client and any redirect URI: no allowlist.
- Token lifetimes: access and refresh tokens last until revoked in practice; use `CarbonInterval::years(100)` for both.
- Spots leave the server only as `code`; the bigint `id` never appears in a tool result. Items are identified by UUID. No embedding, no provider key in any result.
- All text Claude or the user sees (server instructions, tool descriptions, results, errors, consent page, help page) is German; the help page uses no "MCP", "OAuth" or "Token" in running text.
- Tools never pick between several matches; "move item" accepts only an exact item id and spot code.
- Sanctum keeps working unchanged: `tests/Feature/Api/` must stay green.
- Tests: tools through `ToryServer::actingAs($user)->tool(Tool::class, [...])`; HTTP/OAuth as feature tests in `tests/Feature/Mcp/`; the page in `tests/Feature/Panel/`. Never assert literal ids. `Embeddings::fake()` is already on in `TestCase`.
- Before each commit: `./vendor/bin/pint --dirty` and `./vendor/bin/phpstan analyse`.
- Commit message ends with `(TOR-14)` and the trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. An unauthenticated `POST /mcp` from an MCP client must answer `401` with a `WWW-Authenticate` header pointing to the protected-resource metadata, never a redirect to the login page (the guest redirect added for `/oauth/authorize` must not catch it). → test in Task 2.
2. An item id that is not a UUID (`"bohrmaschine"`) must give a German tool error, not a Postgres exception. → test in Task 5.
3. A spot code in lower case (`k7xm`) must resolve in "move item"; one with `0`/`O`/`1`/`I` must be a tool error without a query. → test in Task 5.
4. "Trennen" must also kill the refresh token: a refresh after disconnecting fails. → test in Task 7.
5. A user must see and disconnect only their own connections, even when another user connected the same client. → test in Task 7.

---

### Task 1: Install Passport and `laravel/mcp` beside Sanctum

**Files:**
- Modify: `composer.json`, `composer.lock`, `app/Models/User.php`, `config/auth.php`, `app/Providers/AppServiceProvider.php`, `docker/entrypoint.sh` (and the `setup` script in `composer.json`)
- Create: `config/passport.php` (published), Passport migrations (published, then edited), `routes/ai.php` (published)
- Test: `tests/Feature/Mcp/PassportSetupTest.php`

**Interfaces:**
- Produces: guard `api` (driver `passport`). `User` implements `Laravel\Passport\Contracts\OAuthenticatable`, keeps Sanctum's `createToken()`, `tokens()`, `currentAccessToken()` unchanged, and gains `oauthTokens(): HasMany<\Laravel\Passport\Token>` (Passport's `tokens()` under another name). Passport tables reference users by UUID.

- [ ] **Step 1:** `composer require laravel/mcp laravel/passport`, `php artisan vendor:publish --tag=ai-routes`, `php artisan vendor:publish --tag=passport-config`, `php artisan vendor:publish --tag=passport-migrations`. In the published migrations change every user reference to UUID (`user_id` → `foreignUuid(...)->nullable()->index()`, `nullableMorphs('owner')` → `nullableUuidMorphs('owner')`). Add the `api` guard to `config/auth.php`.
- [ ] **Step 2: Write the failing tests** in `PassportSetupTest.php`:
  - `passport tokens can belong to a uuid user`: create a Passport authorization-code client with `app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'])`, insert a `Laravel\Passport\Token` for the user; `expect($user->oauthTokens()->sole()->client_id)->toBe($client->id)`.
  - `sanctum tokens still work beside passport`: `$user->createToken('iPhone')`, then `withToken($plain)->getJson('/api/v1/user')` → `200`.
  - `tokens last a hundred years`: `expect(Passport::tokensExpireIn()->totalYears)->toBe(100.0)` and the same for `refreshTokensExpireIn()`.
- [ ] **Step 3:** Run `php artisan test --compact tests/Feature/Mcp/PassportSetupTest.php`: FAIL.
- [ ] **Step 4: Implement.** On `User`, combine Sanctum's and Passport's `HasApiTokens` with `insteadof` so Sanctum wins every shared method, alias Passport's `tokens` as `oauthTokens`, implement `OAuthenticatable`. In `AppServiceProvider::boot()` add `configurePassport()`: `Passport::tokensExpireIn(CarbonInterval::years(100))`, `Passport::refreshTokensExpireIn(CarbonInterval::years(100))`. Run `php artisan passport:keys` locally; add `php artisan passport:keys --no-interaction` to the `setup` composer script after the migration (CI uses it) and to `docker/entrypoint.sh` in the non-production branch when `storage/oauth-private.key` is missing. In the production branch of the entrypoint, refuse to start when `PASSPORT_PRIVATE_KEY` or `PASSPORT_PUBLIC_KEY` is empty, with the same wording style as the `APP_KEY` check.
- [ ] **Step 5:** Run the new file and `tests/Feature/Api/`: PASS. Run `php artisan migrate` on the dev database.
- [ ] **Step 6: Commit** `Install Passport and laravel/mcp beside Sanctum (TOR-14)`.

---

### Task 2: Server, OAuth routes, login redirect and consent page

**Files:**
- Create: `app/Mcp/Servers/ToryServer.php`, `resources/views/mcp/authorize.blade.php` (published with `--tag=mcp-views`, then rewritten)
- Modify: `routes/ai.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Mcp/OAuthTest.php`, plus a helper `issueMcpTokens(User $user, string $clientName = 'Claude', string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array{access_token: string, refresh_token: string, client_id: string}` in `tests/Pest.php`

**Interfaces:**
- Produces: `ToryServer` (tools registered in Tasks 3 to 6), `routes/ai.php` with `Mcp::oauthRoutes()` and `Mcp::web('/mcp', ToryServer::class)->middleware(['auth:api', EnsureEmailIsVerified::class])`. The test helper runs the real flow: register client via the dynamic registration route, `actingAs($user)->get('/oauth/authorize?...')` with PKCE S256, approve, exchange the code at `/oauth/token`.

- [ ] **Step 1: Write the failing tests** in `OAuthTest.php`:
  - `the protected resource and authorization server are discoverable`: `GET /.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` → `200`; the authorization server metadata lists `S256` and a registration endpoint.
  - `any client may register`: POST to the registration endpoint with `client_name` "Irgendwas" and redirect `https://example.org/cb` → success; a Passport client named "Irgendwas" exists.
  - `an unauthenticated mcp request is told how to log in`: `postJson('/mcp', <initialize payload>)` → `401`, header `WWW-Authenticate` contains `oauth-protected-resource`; not a redirect.
  - `a guest is sent to the tory login before authorizing`: `get('/oauth/authorize?...')` → redirect to `route('filament.admin.auth.login')`.
  - `the consent page names the requesting program`: client "Claude" with redirect `https://claude.ai/api/mcp/auth_callback`, authorize as a verified user → `assertSee('Claude')`, `assertSee('claude.ai')`, `assertSee('Erlauben')`, `assertSee('Ablehnen')`.
  - `a connected client can list the tools`: `issueMcpTokens($user)`, `withToken($access)->postJson('/mcp', <tools/list payload>)` → `200`.
  - `an unverified user cannot use the server`: same with `User::factory()->unverified()` → `403`.
- [ ] **Step 2:** Run the file: FAIL.
- [ ] **Step 3: Implement.** `ToryServer` with `#[Name('Tory')]`, `#[Version('1.0.0')]` and German `#[Instructions(...)]` saying: Tory is the household inventory; items lie on spots (Lagerplätze), spots belong to locations (Lagerorte); search before acting; if several items or spots fit, or none, ask the user instead of choosing; ask before creating a spot. Route file as in Interfaces. In `bootstrap/app.php`, `redirectGuestsTo(fn () => route('filament.admin.auth.login'))`, which must leave the `401` for `/mcp` intact (Review Focus 1). `Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters))` in `configurePassport()`. The view: plain German page in the panel's look (load the panel's CSS via `@vite` like the `HEAD_END` hook does, or plain inline CSS), heading "Zugriff erlauben?", sentence „{client name}" ({redirect host}) möchte auf dein Tory zugreifen. Es kann Gegenstände finden, umlagern und Lagerplätze anlegen.", buttons "Erlauben" / "Ablehnen" posting the forms the published view already has.
- [ ] **Step 4:** Run the file: PASS.
- [ ] **Step 5: Commit** `Serve an OAuth-protected MCP server at /mcp (TOR-14)`.

---

### Task 3: Tool "Gegenstände finden"

**Files:**
- Create: `app/Mcp/Tools/FindItemsTool.php`, `app/Mcp/SpotSummary.php`
- Modify: `app/Mcp/Servers/ToryServer.php`
- Test: `tests/Feature/Mcp/FindItemsToolTest.php`

**Interfaces:**
- Produces: `SpotSummary::of(Spot $spot): array{code: string, name: string, note: ?string, location: string}`, used by every tool that returns a spot (Tasks 4 to 6).
- Input: `query` (string, required). `#[IsReadOnly]`.
- Result: text plus structured `{items: list<{id, title, description, spot: SpotSummary}>}`, at most 10, `description` cut to 200 characters.

- [ ] **Step 1: Write the failing tests:**
  - `it finds items with their spot and location`: item "Bohrmaschine" on spot "Regal 3" in location "Keller" → `assertOk()`, `assertSee(['Bohrmaschine', 'Regal 3', 'Keller', $spot->code])`.
  - `it returns at most ten items`: 12 matching items → structured `items` has 10 entries.
  - `it says when nothing matched` → `assertSee('Nichts gefunden')`.
  - `it never shows the internal spot id or the embedding`: result JSON has no key `spot_id`, `id` of the spot, or `embedding`.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement** `handle(Request $request, ItemSearch $search): Response`: `Item::query()->with('spot.location')`, `$search->apply($query, $term)`, then `->latest()->latest('id')->limit(10)`, which is the same order as `ItemController::index`. Register on `ToryServer`.
- [ ] **Step 4:** Run: PASS.
- [ ] **Step 5: Commit** `Add the find items tool (TOR-14)`.

---

### Task 4: Tool "Lagerplätze finden"

**Files:**
- Create: `app/Mcp/Tools/FindSpotsTool.php`
- Modify: `app/Mcp/Servers/ToryServer.php`
- Test: `tests/Feature/Mcp/FindSpotsToolTest.php`

**Interfaces:**
- Input: `name` (string, required), `location` (string, optional). `#[IsReadOnly]`.
- Result: structured `{spots: list<SpotSummary>, locations: list<string>}`; `locations` (all location names, by name) only when `spots` is empty.

- [ ] **Step 1: Write the failing tests:**
  - `it matches part of a spot name in any case`: spot "Regal 3" → name `regal` finds it.
  - `it matches an exact code in any case`: name = lower-case code finds the spot.
  - `it lists every match so claude can ask back`: two spots named "Kiste" in different locations → both returned.
  - `it narrows by location name`: `location: 'keller'` returns only the Keller spot.
  - `it offers the locations when nothing matched`: no match → `locations` lists all location names.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement:** `name ilike %term%` (escape `\ % _` as `ItemSearch` does) `or code = Spot::normalizeCode($term)` when that is not null; location filter `whereHas('location', fn ($q) => $q->whereRaw('lower(name) = lower(?)', [...]))`; order by location name, spot name, code.
- [ ] **Step 4:** Run: PASS.
- [ ] **Step 5: Commit** `Add the find spots tool (TOR-14)`.

---

### Task 5: Tool "Gegenstand umlagern"

**Files:**
- Create: `app/Mcp/Tools/MoveItemTool.php`
- Modify: `app/Mcp/Servers/ToryServer.php`
- Test: `tests/Feature/Mcp/MoveItemToolTest.php`

**Interfaces:**
- Input: `item` (string, UUID, required), `spot` (string, code, required). `#[IsIdempotent]`, not read-only.
- Result: structured `{id, title, spot: SpotSummary}`.

- [ ] **Step 1: Write the failing tests:**
  - `it moves the item to the spot`: `item->fresh()->spot_id` is the new spot; result shows new spot and location.
  - `it accepts a lower-case code`.
  - `it refuses a value that cannot be a code` (`'K0OI'`) → `assertHasErrors(['Diesen Lagerplatz gibt es nicht.'])`.
  - `it refuses an id that is no uuid` (`'bohrmaschine'`) → `assertHasErrors(['Diesen Gegenstand gibt es nicht.'])`.
  - `it refuses while the item is regenerating` (`regenerating_at` set) → `assertHasErrors(['Der Gegenstand wird gerade neu generiert. Bitte versuch es gleich noch einmal.'])`, spot unchanged.
  - `it leaves the last used spot alone`: user's `last_spot_id` unchanged.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement:** `Str::isUuid()` before querying the item; `Spot::normalizeCode()` before querying the spot; the regenerating check on a fresh query as `ItemController::update` does; `$item->update(['spot_id' => $spot->id])`.
- [ ] **Step 4:** Run: PASS.
- [ ] **Step 5: Commit** `Add the move item tool (TOR-14)`.

---

### Task 6: Tool "Lagerplatz anlegen"

**Files:**
- Create: `app/Mcp/Tools/CreateSpotTool.php`
- Modify: `app/Mcp/Servers/ToryServer.php`
- Test: `tests/Feature/Mcp/CreateSpotToolTest.php`

**Interfaces:**
- Input: `name` (string, required, max 255), `location` (string, required: an existing location name, any case), `note` (string, optional). Not read-only, not idempotent.
- Result: structured `SpotSummary` of the new spot.

- [ ] **Step 1: Write the failing tests:**
  - `it creates a spot in the named location`: `location: 'keller'` with location "Keller" → spot exists there with a generated 4-character code; result shows the code.
  - `it refuses an unknown location and lists the existing ones` → error contains "Diesen Lagerort gibt es nicht" and every location name; no spot created.
  - `it validates the name like the panel`: empty name or 256 characters → error, no spot.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement** with `$request->validate([...])` using the rules of `StoreSpotRequest` for `name` and `note`, then the location lookup, then `Spot::create([...])`.
- [ ] **Step 4:** Run: PASS.
- [ ] **Step 5: Commit** `Add the create spot tool (TOR-14)`.

---

### Task 7: Connections and "Trennen"

**Files:**
- Create: `app/Mcp/ClaudeConnections.php`, `app/Mcp/ClaudeConnection.php`
- Test: `tests/Feature/Mcp/ClaudeConnectionsTest.php`

**Interfaces:**
- Produces: `final readonly class ClaudeConnection(string $clientId, string $name, ?string $host, CarbonImmutable $connectedAt)`; `ClaudeConnections::for(User $user): Collection<int, ClaudeConnection>` (one per client with a non-revoked access token of that user, `connectedAt` = that user's oldest such token, newest connection first); `ClaudeConnections::disconnect(User $user, string $clientId): void` (revokes all of the user's access tokens for that client and their refresh tokens).

- [ ] **Step 1: Write the failing tests** (tokens via `issueMcpTokens()`):
  - `one connection per client however often it refreshed`: issue, then refresh twice at `/oauth/token` → `for($user)` has one entry with the client's name and host `claude.ai`.
  - `disconnecting stops tool calls and refreshes`: `disconnect()`, then `withToken($access)->postJson('/mcp', ...)` → `401`, and refresh with the old refresh token → `400`/`401` (`invalid_grant`).
  - `a user sees and disconnects only their own connections`: two users connect the same client; `for($a)` lists it once; `disconnect($a, ...)` leaves `$b`'s token working.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement** on `$user->oauthTokens()` with the client loaded; revoke with `$token->revoke()` and `$token->refreshToken?->revoke()` inside a transaction.
- [ ] **Step 4:** Run: PASS.
- [ ] **Step 5: Commit** `List and disconnect Claude connections (TOR-14)`.

---

### Task 8: Panel page "Tory in Claude"

**Files:**
- Create: `app/Filament/Pages/TorySetup.php`, `resources/views/filament/pages/tory-setup.blade.php`
- Test: `tests/Feature/Panel/TorySetupTest.php`

**Interfaces:**
- Consumes: `ClaudeConnections` (Task 7), by typed parameters on the page's closures (constructor injection is not available on Livewire pages).
- Page: title and navigation label "Tory in Claude", `$navigationSort = 6`, slug `tory-in-claude`. Address `url('/mcp')`.

- [ ] **Step 1: Write the failing tests:**
  - `the page explains how to connect`: `get('/tory-in-claude')` as a verified user → `assertSee(url('/mcp'))`, `assertSee('claude mcp add --transport http tory '.url('/mcp'))`, `assertSee('Wo ist die Bohrmaschine?')`, and `assertDontSee(['MCP', 'OAuth', 'Token'])` in the explanatory text (scope the check to the page body, the Claude Code command excepted).
  - `it lists the user's connections`: after `issueMcpTokens($user)` → `assertSee('Claude')`, `assertSee('claude.ai')`.
  - `trennen disconnects`: Livewire `callAction` / `callTableAction('trennen', ...)` → `ClaudeConnections::for($user)` is empty.
- [ ] **Step 2:** Run: FAIL.
- [ ] **Step 3: Implement** with Filament's own components (sections, a copyable text entry for the address and the command, a table or repeatable list for connections, an action "Trennen" with `requiresConfirmation()` and German modal copy "Claude trennen?" / "Claude kann danach nicht mehr auf Tory zugreifen, bis du es neu verbindest."). Copy, all short and plain:
  - Intro: "Du kannst Claude fragen, wo etwas liegt, und ihm sagen, wenn du etwas woanders hingelegt hast."
  - claude.ai: Einstellungen → Connectors → eigenen Connector hinzufügen → Adresse einfügen → bei Tory anmelden → „Erlauben".
  - Handy-App: nichts zu tun, der Connector aus claude.ai ist dort auch da.
  - Claude Desktop: wie im Browser.
  - Claude Code: Befehl kopieren, dann der Anmeldung folgen.
  - Beispiele: „Wo ist die Bohrmaschine?", „Ich habe den Akkuschrauber in die Kiste Werkzeug gelegt.", „Leg im Keller einen neuen Lagerplatz ‚Regal 4' an."
  - Grenzen: "Fotos und neue Gegenstände gehen über „Erfassen"."
  - Empty connections: "Noch kein Claude verbunden."
- [ ] **Step 4:** Run: PASS. Look at the page in the browser (playwright-cli, light and dark).
- [ ] **Step 5: Commit** `Add the Tory in Claude page (TOR-14)`.

---

### Task 9: Docs and full check

**Files:**
- Modify: `CLAUDE.md`, `CONTEXT.md`

- [ ] **Step 1:** `CLAUDE.md`: architecture map line for MCP (`routes/ai.php`, `app/Mcp/`, `TorySetup`, spec name); production: `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` required; gotcha: two `HasApiTokens` on `User` (Sanctum wins, Passport's `tokens` is `oauthTokens`); invariant: registration is open on purpose, consent page names the client. `CONTEXT.md`: term **Claude connection** (Claude-Zugang), one connected program, ends only by "Trennen".
- [ ] **Step 2:** Run the whole suite, Pint, PHPStan: all green.
- [ ] **Step 3:** Manual check with `php artisan mcp:inspector mcp` locally: connect, log in, call each tool.
- [ ] **Step 4: Commit** `Document the MCP server (TOR-14)`.
