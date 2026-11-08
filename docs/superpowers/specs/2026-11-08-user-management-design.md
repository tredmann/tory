# User management — design

Date: 2026-11-08. Status: draft for approval. Ticket: TOR-18.

## Intent

Today every verified user may do everything, and wherever self-service is on, anyone who registers can download every provider key in plain text. Tory gets two fixed roles and a user list in which an administrator creates, invites and locks users. Nobody can sign up on their own any more. The household stays one household: an ordinary user still sees and changes every item, spot and location.

## Goals

- Two fixed roles: **administrator** and **user**. Only administrators see and use the user list and "Eigene KI" (providers, including export and import of keys). Users may do everything else: imports, bulk imports, items, locations, spots, labels, REST API, Claude.
- An administrator creates a user with name, email and role and gets a short invite link to pass on themselves (messenger, chat). Whoever opens it sets a password and is thereby verified.
- An administrator can create a new link at any time, for an expired invitation or a forgotten password.
- An administrator can lock and unlock a user at any time. A locked user gets in through no door: panel, REST API, MCP.

## Non-goals

- Deleting users.
- Configurable roles or single permissions.
- Sending the invitation by email.
- Exposing the role or the lock in the REST API (`GET /api/v1/user` stays as it is).

## Anti-goals

- An invite link that breaks when pasted into a messenger: no query string, no `?`, `&`, `%`, `=`, no long signed URL.
- Leaving the household without an administrator through the user list.
- Storing the invite token in plain text.
- A new dependency (`spatie/laravel-permission` is not needed for two fixed roles).

## Decisions

### Data

- Migration on `users`:
  - `role` string, not null, default `member`. All existing users become `member`.
  - `locked_at` nullable timestamp.
  - `invite_token` nullable string, unique: the SHA-256 hex hash of the token.
  - `invite_expires_at` nullable timestamp.
- Enum `App\Enums\UserRole` with `Admin` (`admin`, label "Administrator") and `Member` (`member`, label "Nutzer"), implementing `HasLabel` and `HasColor` like the other status enums. Cast on `User`. `User::isAdmin()` and `User::isLocked()`.
- `role`, `locked_at` and the invite columns are not fillable; they change only through model methods (below). `invite_token` is hidden.
- The status shown in the list is derived, not stored: **Gesperrt** if `locked_at` is set, otherwise **Eingeladen** if `email_verified_at` is null, otherwise **Aktiv**. Existing unverified users therefore show as "Eingeladen", which is right: they need a link.
- A new user gets a random unusable password (hashed `Str::random`), because `password` stays not null. No schema change for it.

### Invite link

- `User::issueInviteLink(): string` creates a token with `Str::random(32)` (letters and digits only), stores its SHA-256 hash and `invite_expires_at = now() + 24 h`, overwrites any previous token and returns the full URL `{APP_URL}/invite/{token}`. The plain token exists only in this return value.
- Route `GET|POST /invite/{token}` (English path), a guest page outside the panel's auth middleware, throttled to 10 per minute. It looks like Filament's login page (Filament's simple layout) and asks for password and confirmation, with the same password rules as the profile page.
- Looking up the hash finds the user. A link is invalid when no user has the hash, `invite_expires_at` has passed or the user is locked. An invalid link shows the page with the message "Dieser Link ist abgelaufen oder ungültig. Bitte frag nach einem neuen." and no form, HTTP status 200 (Livewire full pages cannot set 404 cleanly).
- Submitting sets the password, sets `email_verified_at` if it was null, clears `invite_token` and `invite_expires_at`, logs the user in (session regenerated, replacing anyone already logged in in that browser) and redirects to the dashboard. The link is then used up.
- A new link for an active user does not touch the current password: it keeps working until the link is used.
- Locking clears an open link.

### Lock

- `User::lock()` sets `locked_at` and clears the invite token; `User::unlock()` clears `locked_at`. API tokens (Sanctum) and Claude connections (Passport) are kept, so after unlocking everything works as before.
- **Panel:** `canAccessPanel()` returns false for a locked user, so Filament's login refuses them with its usual failure message (no hint that the account exists and is locked). An open session of a locked user is logged out on the next panel request and lands on the login page; this covers the photo and theme routes, which run inside the panel's authenticated routes. *(How — a middleware in `authMiddleware` that logs out before Filament's `Authenticate` answers 403 — is the builder's choice.)*
- **REST API:** `POST /api/v1/login` answers 403 "Dieses Konto ist gesperrt." for a locked user with a correct password. Every `auth:sanctum` route answers the same 403.
- **MCP:** `/mcp` answers the same 403. `/oauth/authorize` treats a locked user like a guest, logs them out and sends them to the panel login.
- The existing `App\Http\Middleware\EnsureEmailIsVerified` (used on `auth:sanctum` and `/mcp`) also checks the lock; renaming it to fit is the builder's call.

### Who may do what

- Laravel policies `UserPolicy` and `AiProviderPolicy`: every ability is allowed only for administrators. Filament picks them up, which guards pages, URLs and actions (create, edit, activate, export, import). Everything else keeps having no policy and stays open to every verified, unlocked user.
- The parts of the import and item pages that only *show* a provider's name (`ImportInfolist`, `RegenerationEntries`) stay visible to users. Their links to the provider list and the "Provider prüfen" hint (also in `resources/views/filament/resources/bulk-imports/view.blade.php`) appear only for administrators; users see the failure text without them. *(My call.)*
- `Gate viewApiDocs` additionally refuses locked users; otherwise every verified user may read `/docs/api`. The docs run on the web session outside the panel, so without this a locked user's open session could keep reading them. *(My call.)*

### User list ("Nutzer", `/users`, administrators only)

- Filament resource `UserResource` with one manage page (modals, like `ManageAiProviders`). Navigation label "Nutzer".
- Columns: name, email, role (badge), status (badge). Sorted by name. No bulk actions, no delete.
- **Anlegen:** name, email (unique), role (default "Nutzer"). After saving, a modal shows the invite link with a copy button and the note that it is valid for 24 hours and shown only now.
- **Bearbeiten:** name, email (unique), role. On the administrator's own row the role is disabled.
- **Neuer Link:** confirmation, then the same modal with the new link. Hidden for locked users and on the administrator's own row (they change their own password in the profile).
- **Sperren / Entsperren:** with confirmation. Hidden on the administrator's own row.
- Because an administrator can neither lock nor demote themselves, the household always keeps at least one administrator.

### Removed

- Self-registration, "Passwort vergessen" and the setting `auth.self_service` / `AUTH_SELF_SERVICE` everywhere, including `ENV AUTH_SELF_SERVICE=false` in `docker/Dockerfile`. The `password_reset_tokens` table stays (framework table).
- Tests that cover the removed features go: `tests/Feature/Panel/RegistrationTest.php` and the two self-service tests in `tests/Feature/ProductionSettingsTest.php`, replaced by a test that the panel has neither registration nor password reset. Approving this design approves deleting them.

### Kept

- The profile page: every user changes their own name, email and password there.
- `->emailVerification()` on the panel stays, so an unverified user still sees the verification prompt instead of the app.

### First administrator

- Command `php artisan users:make-admin {email}` sets the role to administrator and prints a German confirmation, or fails with a message if no user has that email. After deploying, it is run once for the owner's account; on a fresh install after `make:filament-user`.

### Documentation

- `CONTEXT.md`: terms **Administrator**, **User** (Nutzer, as a role), **Invite link** (Einladungslink) and **Locked** (gesperrt).
- `CLAUDE.md`: the panel line ("No policies") becomes the two admin-only policies; the production paragraph and the self-service gotcha lose `AUTH_SELF_SERVICE`; the command list gets `users:make-admin`; the CLI-created-user hint stays.

## Left to the builder

- How the invite page is built (a Livewire component on Filament's simple page, or a controller with a Blade view using the panel's assets), as long as it looks like the login page and is German.
- How the lock is enforced in the panel and on `/oauth/authorize` in detail.
- Test layout, following the existing tests in `tests/Feature/Panel/`, `tests/Feature/Api/` and `tests/Feature/Mcp/`; factory states `admin()` and `locked()` on `UserFactory`.
