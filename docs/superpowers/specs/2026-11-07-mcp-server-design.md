# MCP server ("Tory in Claude") — design

Date: 2026-11-07. Status: draft. Ticket: TOR-14. Vocabulary: `CONTEXT.md`.

## Intent

Everyone in the household shall be able to ask Claude "Wo ist die Bohrmaschine?" and tell it "Ich habe die Bohrmaschine ins Regal 3 gelegt", wherever they use Claude: claude.ai in the browser, the Claude mobile app, Claude Desktop and Claude Code. Tory gets an MCP server for that. Like the REST API, it is another door to the same rules as the panel, not a second implementation of them.

The household members are not all technical, so connecting Claude to Tory must be as simple as adding an address in Claude and logging in to Tory once, and the panel explains this in plain language.

## Goals

### Server

- `laravel/mcp` (approved), one web server registered in `routes/ai.php` with `Mcp::web('/mcp', ...)`, protected by `auth:api` (Passport) and email verification. Unverified users get no access.
- Server name "Tory", with instructions telling Claude in German what Tory is (items lie on spots, spots belong to locations), that it should ask back when a name fits several spots or none, and that it should ask before creating a spot.
- Tool texts (descriptions, results, errors) are German, since users talk to Claude in German and Claude repeats them.

### Tools

1. **Find items** (read-only)
   - Input: a search phrase.
   - Uses `ItemSearch::apply()` exactly as the item list does (keyword matches first, then semantic; keyword-only if embedding fails).
   - Returns up to 10 items, each with item id, title, a short description, spot name, spot code and location name. Says so when nothing matched.
2. **Find spots** (read-only)
   - Input: a name or part of one, optionally a location name.
   - Matches spot name case-insensitively as a substring, plus an exact code match (any case). Returns every match with code, name, note and location name, so Claude can ask back when there are several. Also returns the list of locations when nothing matched, so Claude can offer where to create a new spot.
3. **Move item**
   - Input: item id (from "find items") and spot code (from "find spots" or "create spot").
   - Sets the item's spot. Same rule as the API: refused with a German message while the item is regenerating. Returns the item with its new spot and location.
   - Does not change `last_spot_id`. That field preselects the spot for the next capture in the panel, and a move through Claude is not a capture. (My call.)
4. **Create spot**
   - Input: spot name, location name, optional note.
   - The location must already exist (exact name, case-insensitive); otherwise the tool refuses and lists the existing locations. Creating locations is not part of this ticket.
   - Same validation as the panel form. The code is generated as everywhere. Returns code, name and location.

Spots are identified to Claude only by their code, never by the internal id (same as the API). Items are identified by their UUID.

Disambiguation is Claude's job, guided by the server instructions and the tool results: the tools never guess between several matches. "Move item" only accepts an exact id and code.

### Authentication: OAuth through Passport

- `laravel/passport` (approved). `Mcp::oauthRoutes()` registers discovery and dynamic client registration; the `mcp:use` scope is the only scope.
- Registration stays open to every client and every redirect address (the user's decision): no allowlist of Claude's callback hosts.
- Flow for a user: add the connector address in Claude → Claude opens Tory's authorization page → not logged in: Filament login, then back → MCP's published authorization view (`vendor:publish --tag=mcp-views`), restyled in German and simple. It names the requesting program by the client name it registered and the host of its redirect address, never a fixed "Claude", e.g. "„Claude" (claude.ai) möchte auf dein Tory zugreifen. [Erlauben] [Ablehnen]". (My call: with open registration this is how a user tells Claude from anything else.)
- A connection lasts until it is disconnected (the user's decision): access and refresh tokens get lifetimes long enough that nobody is asked to log in again on a timer.
- Sanctum stays the guard for the REST API, unchanged. Both packages ship a `HasApiTokens` trait for `User`; the builder resolves the clash so both keep working (the API's token tests must stay green).
- The Passport tables reference users by UUID.
- Guests hitting the authorization route are redirected to the Filament login (`filament.admin.auth.login`), since no route named `login` exists on the web side, and return to the authorization afterwards.

### Help page "Tory in Claude"

A Filament page with its own navigation entry (after "Eigene KI"), German, written for non-technical household members. No "MCP", "OAuth" or "Token" in the running text.

- One sentence on what it does: ask Claude where something is, tell Claude when you put something elsewhere.
- **The address** to copy, with a copy button: `{APP_URL}/mcp`, built from `APP_URL`.
- **Step by step**, one short section per place:
  1. claude.ai in the browser: Settings → Connectors → add custom connector → paste address → log in to Tory and click "Erlauben".
  2. Mobile app: nothing to do; a connector added on claude.ai shows up there.
  3. Claude Desktop: same as the browser.
  4. Claude Code: one command to copy (`claude mcp add --transport http --scope user tory {APP_URL}/mcp`, user scope so it works in every folder), then start Claude Code, type `/mcp`, pick "tory" and log in.
- **Try it:** example sentences: "Wo ist die Bohrmaschine?", "Ich habe den Akkuschrauber in die Kiste Werkzeug gelegt.", "Leg im Keller einen neuen Lagerplatz ‚Regal 4' an."
- **What Claude cannot do:** take photos or add new things; that stays "Erfassen".
- **Verbundene Claude-Zugänge:** one row per connected program, i.e. per OAuth client for which the logged-in user holds a non-revoked token (refreshes add tokens but no rows). Each row shows the client's registered name, the host of its redirect address and when it was first connected. A "Trennen" button per row (with confirmation) revokes all of that user's access **and** refresh tokens for that client, so the program cannot quietly get a new token; it has to go through "Erlauben" again. A test proves that a refresh after "Trennen" fails and a tool call answers `401`. Only one's own connections are shown and can be disconnected.

The exact menu path in claude.ai may change over time; the page describes it in words, without screenshots. (My call.)

### Production

- Passport needs its key pair. Production uses `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` environment variables, so the keys do not depend on the storage volume. Locally `php artisan passport:keys` writes them to `storage/` (gitignored); the dev entrypoint creates them when missing.
- The `prod` image refuses to start without the Passport keys, as it does without `APP_KEY`.
- MCP must work behind the TLS-terminating proxy; `TRUSTED_PROXIES` already makes URLs come out as `https`, which the OAuth discovery documents depend on.
- `CLAUDE.md` (architecture map, production) and `CONTEXT.md` (term for a connected Claude access) are updated.

## Non-goals

- Sending photos through Claude, creating items
- Changing an item other than its spot, deleting items, spots or locations
- Creating or renaming locations
- A history of moves
- A server-side confirmation step before moving (Claude's apps already ask the user to approve tool calls)
- A local stdio MCP server
- Changes to the REST API or its Sanctum tokens
- Scopes other than `mcp:use`; per-user permissions (one household, every verified user sees everything)

## Anti-goals

These would be failures even if it seemed to work:

- An unverified or unauthenticated client can call any tool.
- A rule the panel enforces (spot code immutable and generated, no edit during regeneration, spot validation) can be bypassed through MCP.
- A tool guesses between several matching items or spots and moves something silently.
- The internal spot id, an embedding or an AI provider key appears in any tool result.
- The help page needs technical knowledge to follow.
- Adding Passport breaks the REST API's token login.

## Constraints

- Laravel 13, Filament 5, `laravel/mcp`, `laravel/passport`; both new dependencies are approved.
- Panel stays at `/`; `routes/web.php` still defines no `/`.
- No policies; every verified user sees every record.
- Tests: tools through `laravel/mcp`'s `Server::actingAs($user)->tool(...)` tests; OAuth discovery and the unauthenticated `401` as feature tests; the help page and "Trennen" as Filament/Livewire tests. `Embeddings::fake()` as everywhere.

## Left to the builder

- Class and tool names, the exact server instructions and tool descriptions (German, short).
- Result format of the tools (structured content and/or text), as long as it carries the fields above.
- How the `HasApiTokens` clash is resolved.
- Layout of the help page within Filament's components.
