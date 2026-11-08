# User Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two fixed roles (administrator, user), an admin-only user list with short invite links and locking, and no more self-registration or password reset.

**Architecture:** Role, lock and invite live as columns on `users` and change only through `User` methods. Two Laravel policies (`UserPolicy`, `AiProviderPolicy`) make the user list and "Eigene KI" admin-only; Filament applies them to pages and actions. One web middleware logs out locked users on every session door (panel, Livewire, `/oauth/authorize`, `/docs/api`); the renamed bearer middleware refuses them on the API and `/mcp`. The invite page is a Filament simple page on a guest panel route.

**Tech Stack:** Laravel 13, Filament 5, Livewire, Sanctum, Passport, Pest on Postgres.

**Spec:** `docs/superpowers/specs/2026-11-08-user-management-design.md`

## Global Constraints

- No new dependency; no `spatie/laravel-permission`.
- URL paths English (`/users`, `/invite/{token}`), UI copy German.
- Role values `admin` / `member`, labels "Administrator" / "Nutzer". Status labels "Eingeladen", "Aktiv", "Gesperrt".
- Invite token: `Str::random(32)`, stored only as SHA-256 hex in `users.invite_token`, valid 24 hours, one use, overwritten by a new link, cleared by locking.
- Locked message on API and MCP: `Dieses Konto ist gesperrt.` Invalid link message: `Dieser Link ist abgelaufen oder ungültig. Bitte frag nach einem neuen.`
- An administrator can neither lock nor demote themselves nor issue a link for themselves.
- All commands run in Docker: `docker compose exec app …`. After the migration also run `docker compose exec app php artisan migrate` on the dev database.
- Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`. Before the last commit also `docker compose exec app ./vendor/bin/phpstan analyse` and the full suite `docker compose exec app php artisan test --compact`.
- Commit messages end with `(TOR-18)` and the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A link that expires after the invite page loaded is refused on submit; the password stays unchanged (test in Task 4).
2. The same link opened again after a successful use shows the invalid message (test in Task 4).
3. A locked user with a valid Sanctum token gets 403, and the same token works again after unlocking (test in Task 2).
4. A locked user with an open session who opens `/oauth/authorize` is logged out and redirected to the panel login (test in Task 2).
5. Creating a user with an email that already exists shows a form error and creates nothing (test in Task 5).

---

### Task 1: Role, status, lock and invite on the user

**Files:**
- Create: migration via `php artisan make:migration add_role_lock_and_invite_to_users_table --table=users --no-interaction`
- Create: `app/Enums/UserRole.php`, `app/Enums/UserStatus.php` (`make:enum` writes to `app/`; move the files)
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `CONTEXT.md`
- Test: `tests/Feature/Models/UserTest.php` (create with `php artisan make:test --pest Models/UserTest` or extend if it exists)

**Interfaces:**
- Produces:
  - `enum UserRole: string implements HasLabel, HasColor { Admin = 'admin'; Member = 'member' }` (colors: `primary` / `gray`)
  - `enum UserStatus: string implements HasLabel, HasColor { Invited = 'invited'; Active = 'active'; Locked = 'locked' }` (colors: `warning` / `success` / `danger`)
  - `User::$role` (`UserRole`, cast), `User::$locked_at` (`?Carbon`), `User::$invite_expires_at` (`?Carbon`); `invite_token` hidden. Fillable stays `name`, `email`, `password`.
  - `User::isAdmin(): bool`, `User::isLocked(): bool`, `User::status(): UserStatus`
  - `static User::invite(string $name, string $email, UserRole $role): User` — creates the user with a random unusable password (hashed `Str::random(64)`), unverified
  - `User::assignRole(UserRole $role): void`
  - `User::issueInviteLink(): string` — returns `url('/invite/'.$token)`
  - `static User::findByInviteToken(string $token): ?User` — null when unknown, expired or locked
  - `User::acceptInvite(string $password): void` — sets password, `email_verified_at` if null, clears both invite columns
  - `User::lock(): void` (also clears the invite columns), `User::unlock(): void`
  - Factory states `admin()`, `locked()`

- [ ] **Step 1: Write the failing tests**

```php
test('a new user is a member', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Member);
});

test('an invited user is unverified and cannot log in with a guessable password', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    expect($user->status())->toBe(UserStatus::Invited)
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('the invite link is short, path-only and stores only a hash', function () {
    $user = User::factory()->create();

    $link = $user->issueInviteLink();
    $token = Str::afterLast($link, '/invite/');

    expect($link)->toMatch('#/invite/[A-Za-z0-9]{32}$#')
        ->and($user->fresh()->getAttributes()['invite_token'])->toBe(hash('sha256', $token))
        ->and(User::findByInviteToken($token)?->is($user))->toBeTrue();
});

test('an invite link expires after 24 hours', function () {
    $user = User::factory()->create();
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $this->travel(24)->hours();
    $this->travel(1)->second();

    expect(User::findByInviteToken($token))->toBeNull();
});

test('a new link replaces the old one', function () {
    $user = User::factory()->create();
    $old = Str::afterLast($user->issueInviteLink(), '/invite/');
    $user->issueInviteLink();

    expect(User::findByInviteToken($old))->toBeNull();
});

test('accepting an invite sets the password, verifies and uses up the link', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $user->acceptInvite('ein-langes-passwort');

    expect(Hash::check('ein-langes-passwort', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->status())->toBe(UserStatus::Active)
        ->and(User::findByInviteToken($token))->toBeNull();
});

test('locking clears an open link and unlocking restores the status', function () {
    $user = User::factory()->create();
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $user->lock();
    expect($user->status())->toBe(UserStatus::Locked)
        ->and(User::findByInviteToken($token))->toBeNull();

    $user->unlock();
    expect($user->status())->toBe(UserStatus::Active);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/UserTest.php`
Expected: FAIL (`UserRole` not found).

- [ ] **Step 3: Implement migration, enums, model methods and factory states**

Migration columns as in the spec: `role` string default `member` not null, `locked_at` nullable timestamp, `invite_token` nullable string unique, `invite_expires_at` nullable timestamp. The model methods use `forceFill()->save()` for the non-fillable columns. Add `@property` lines for the new columns.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/UserTest.php`
Expected: PASS. Then `docker compose exec app php artisan migrate`.

- [ ] **Step 5: Glossary**

`CONTEXT.md`: add a section "### People" (before "Places" or after "Things", matching the file's format) with **Administrator**, **User** (Nutzer), **Invite link** (Einladungslink) and **Locked** (gesperrt), each with one or two sentences from the spec and an `_Avoid_` line (e.g. User: _Avoid_: Mitglied, Member in the UI; Invite link: _Avoid_: Registrierungslink, Token).

- [ ] **Step 6: Commit** — `Add roles, invite links and locking to users (TOR-18)`

---

### Task 2: Locked users get in through no door

**Files:**
- Create: `app/Http/Middleware/LogOutLockedUser.php`
- Rename: `app/Http/Middleware/EnsureEmailIsVerified.php` → `app/Http/Middleware/EnsureUserIsActive.php` (update `routes/api.php`, `routes/ai.php`, every test that imports it)
- Modify: `bootstrap/app.php` (append `LogOutLockedUser` to the `web` group), `app/Providers/Filament/AdminPanelProvider.php` (add it to `->middleware([...])` right after `AuthenticateSession`), `app/Models/User.php` (`canAccessPanel()` returns `! $this->isLocked()`), `app/Http/Controllers/Api/V1/AuthController.php`, `app/Providers/AppServiceProvider.php` (`viewApiDocs` also requires `! $user->isLocked()`)
- Test: `tests/Feature/Panel/LoginTest.php`, `tests/Feature/Api/AuthTest.php`, `tests/Feature/Api/DocsTest.php`, `tests/Feature/Mcp/OAuthTest.php`

**Interfaces:**
- Consumes: `User::isLocked()`, `User::lock()`, `User::unlock()`, factory state `locked()` (Task 1).
- Produces: `EnsureUserIsActive` (aborts 403 `Dieses Konto ist gesperrt.` for a locked user, then the existing verification message for an unverified one); `LogOutLockedUser` (logs out the `web` guard, invalidates the session and regenerates the CSRF token, then continues as guest).

- [ ] **Step 1: Write the failing tests**

```php
// LoginTest.php
test('a locked user cannot log in', function () {
    $user = User::factory()->locked()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('an open session ends when the user is locked', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk();

    $user->lock();

    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest();
});

// AuthTest.php
test('a locked user gets no token', function () {
    $user = User::factory()->locked()->create();

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Dieses Konto ist gesperrt.');
});

test('a token stops working while its user is locked and works again after unlocking', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    $user->lock();
    $this->withToken($token)->getJson('/api/v1/user')
        ->assertForbidden()
        ->assertJsonPath('message', 'Dieses Konto ist gesperrt.');

    $user->unlock();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertOk();
});

// DocsTest.php
test('a locked user gets 403', function () {
    $this->actingAs(User::factory()->locked()->create());

    $this->get('/docs/api')->assertForbidden();
});

// OAuthTest.php
test('a locked user is sent to the login instead of the consent page', function () {
    $clientId = registerClient('Claude', 'https://claude.ai/api/mcp/auth_callback');

    actingAs(User::factory()->locked()->create())
        ->get('/oauth/authorize?'.http_build_query([/* same query as the existing consent-page test */]))
        ->assertRedirect(route('filament.admin.auth.login'));

    $this->assertGuest();
});

test('mcp refuses a locked user', function () {
    $user = User::factory()->create();
    $tokens = issueMcpTokens($user);
    $user->lock();

    $this->withToken($tokens['access_token'])->postJson('/mcp', [/* the initialize request used in the existing MCP tests */])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/LoginTest.php tests/Feature/Api/AuthTest.php tests/Feature/Api/DocsTest.php tests/Feature/Mcp/OAuthTest.php`
Expected: the new tests FAIL.

- [ ] **Step 3: Implement the middleware, the rename, `canAccessPanel()`, the login check and the gate**

`AuthController::login()` checks the lock after the password and before verification.

- [ ] **Step 4: Run the same tests plus the MCP and API folders to verify they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/LoginTest.php tests/Feature/Api tests/Feature/Mcp`
Expected: PASS.

- [ ] **Step 5: Commit** — `Lock users out of panel, API and Claude (TOR-18)`

---

### Task 3: "Eigene KI" only for administrators

**Files:**
- Create: `app/Policies/AiProviderPolicy.php` (`php artisan make:policy AiProviderPolicy --model=AiProvider --no-interaction`)
- Modify: `app/Filament/Resources/AiProviders/Pages/ManageAiProviders.php`, `app/Filament/Resources/AiProviders/Tables/AiProvidersTable.php`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Schemas/RegenerationEntries.php`, `resources/views/filament/resources/bulk-imports/view.blade.php`
- Test: `tests/Feature/Panel/AiProviderResourceTest.php`, `tests/Feature/Panel/ViewImportTest.php`, `tests/Feature/Panel/ViewBulkImportTest.php`

**Interfaces:**
- Consumes: `User::isAdmin()`, factory state `admin()` (Task 1).
- Produces: `AiProviderPolicy` with `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, each `return $user->isAdmin();`.

- [ ] **Step 1: Write the failing tests**

In `AiProviderResourceTest.php` the `beforeEach` acts as `User::factory()->admin()->create()`. Add:

```php
test('a member can neither see nor open the provider page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/ai-providers')->assertForbidden();
    $this->get('/')->assertDontSee('Eigene KI');
});
```

In `ViewImportTest.php` (use the existing setup for a failed import with `failure_reason`):

```php
test('a member sees the failure without the provider link', function () {
    // failed import with failure_reason, acting as a member
    $this->get(/* view url */)
        ->assertSee($import->failureMessage())
        ->assertDontSee('Provider prüfen')
        ->assertDontSee('/ai-providers');
});

test('an administrator sees the provider link', function () {
    // same import, acting as admin
    $this->get(/* view url */)->assertSee('Provider prüfen');
});
```

The same pair in `ViewBulkImportTest.php` for a bulk import with `failure_reason`. Use one test of the pair for the regeneration failure as well (item with `regeneration_failure_reason`, in `ItemResourceTest.php` or wherever the existing regeneration-failure test lives).

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/AiProviderResourceTest.php tests/Feature/Panel/ViewImportTest.php tests/Feature/Panel/ViewBulkImportTest.php`
Expected: the new tests FAIL.

- [ ] **Step 3: Implement the policy and hide the links**

Every custom action (`export`, `import`, `activate`, `activateForSearch`, `test`) gets `->authorize('update')` (`export`/`import`: `'create'`), so a Livewire call cannot bypass the page check. The links use `AiProviderResource::canAccess()`: `->url(fn (): ?string => AiProviderResource::canAccess() ? AiProviderResource::getUrl('index') : null)` and `' Provider prüfen'` only when it is true; the Blade view wraps the `<a>` in `@if (AiProviderResource::canAccess())`.

- [ ] **Step 4: Run the whole suite and give every other test that opens `/ai-providers` an admin**

Run: `docker compose exec app php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit** — `Restrict providers to administrators (TOR-18)`

---

### Task 4: Invite page

**Files:**
- Create: `app/Filament/Auth/AcceptInvite.php` (outside `app/Filament/Pages/` so it is not discovered as an authenticated page), `resources/views/filament/auth/accept-invite.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`->routes(fn () => Route::get('/invite/{token}', AcceptInvite::class)->middleware('throttle:10,1')->name('invite'))`)
- Test: `tests/Feature/Panel/AcceptInviteTest.php`

**Interfaces:**
- Consumes: `User::findByInviteToken()`, `User::acceptInvite()`, `User::issueInviteLink()` (Task 1).
- Produces: route `filament.admin.invite` at `/invite/{token}`; `AcceptInvite` extends `Filament\Pages\SimplePage`, `mount(string $token)`, `public function accept(): void`.

- [ ] **Step 1: Write the failing tests**

```php
function inviteToken(User $user): string
{
    return Str::afterLast($user->issueInviteLink(), '/invite/');
}

test('the invite page asks for a password', function () {
    $this->get('/invite/'.inviteToken(User::invite('Anna', 'anna@example.com', UserRole::Member)))
        ->assertOk()
        ->assertSee('Passwort');
});

test('accepting the invite logs the user in with the new password', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)])
        ->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'])
        ->call('accept')
        ->assertRedirect(filament()->getUrl());

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('the password must be confirmed', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)])
        ->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'anders'])
        ->call('accept')
        ->assertHasFormErrors(['password']);
});

test('an unknown, used or locked link shows the invalid message', function (Closure $tokenFor) {
    $this->get('/invite/'.$tokenFor())
        ->assertSee('Dieser Link ist abgelaufen oder ungültig. Bitte frag nach einem neuen.')
        ->assertDontSee('passwordConfirmation');
})->with([
    'unknown' => fn () => fn () => Str::random(32),
    'used' => fn () => function () {
        $user = User::factory()->create();
        $token = inviteToken($user);
        $user->acceptInvite('ein-langes-passwort');

        return $token;
    },
    'locked' => fn () => function () {
        $user = User::factory()->create();
        $token = inviteToken($user);
        $user->lock();

        return $token;
    },
]);

test('a link that expires after the page loaded is refused on submit', function () {
    $user = User::factory()->create();
    $before = $user->password;
    $page = Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)]);

    $this->travel(25)->hours();

    $page->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'])
        ->call('accept')
        ->assertSee('Dieser Link ist abgelaufen oder ungültig.');

    expect($user->fresh()->password)->toBe($before);
    $this->assertGuest();
});

test('the invite page is throttled', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get('/invite/'.Str::random(32));
    }

    $this->get('/invite/'.Str::random(32))->assertTooManyRequests();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/AcceptInviteTest.php`
Expected: FAIL (route not defined).

- [ ] **Step 3: Implement `AcceptInvite`**

Model it on Filament's `ResetPassword` page (`vendor/filament/filament/src/Auth/Pages/PasswordReset/ResetPassword.php`): same password and confirmation components and rules, German heading "Passwort festlegen", button "Passwort speichern". `mount()` stores the token and whether it is valid; the view shows the invalid message and no form when it is not. `accept()` validates, looks the user up again (the link may have expired meanwhile), calls `acceptInvite()`, `Filament::auth()->login($user)`, `session()->regenerate()` and redirects to `filament()->getUrl()`. The invalid page answers 200; Livewire full pages cannot set 404 cleanly. *(Deviation from the spec's 404, recorded here.)*

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/AcceptInviteTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** — `Let invited users set their password through a short link (TOR-18)`

---

### Task 5: User list

**Files:**
- Create: `app/Policies/UserPolicy.php`, `app/Filament/Resources/Users/UserResource.php`, `app/Filament/Resources/Users/Pages/ManageUsers.php`, `app/Filament/Resources/Users/Schemas/UserForm.php`, `app/Filament/Resources/Users/Tables/UsersTable.php` (follow the `AiProviders` directory)
- Test: `tests/Feature/Panel/UserResourceTest.php`

**Interfaces:**
- Consumes: everything from Task 1.
- Produces: `UserPolicy` with `viewAny(User $user)`, `create(User $user)`, `update(User $user, User $target)`: `isAdmin()`; `changeRole(User $user, User $target)`, `issueInvite(User $user, User $target)`: `isAdmin()` and `! $user->is($target)` (`issueInvite` also `! $target->isLocked()`); `lock(User $user, User $target)`: `isAdmin()`, not self, not locked; `unlock(User $user, User $target)`: `isAdmin()`, locked. No `delete`/`deleteAny` methods (Filament then hides deleting).
- `UserResource`: slug `users`, navigation label "Nutzer", model label "Nutzer", icon `Heroicon::OutlinedUsers`, `navigationSort` 6.

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('a member cannot open the user list', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/users')->assertForbidden();
});

test('the list shows role and status', function () {
    $invited = User::invite('Anna', 'anna@example.com', UserRole::Member);
    $locked = User::factory()->locked()->create();

    Livewire::test(ManageUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $invited, $locked])
        ->assertSee(['Administrator', 'Eingeladen', 'Gesperrt']);
});

test('creating a user shows the invite link once', function () {
    Livewire::test(ManageUsers::class)
        ->callAction('create', ['name' => 'Anna', 'email' => 'anna@example.com', 'role' => UserRole::Member->value])
        ->assertActionMounted('showInviteLink')
        ->assertSee('/invite/');

    $anna = User::firstWhere('email', 'anna@example.com');
    expect($anna->status())->toBe(UserStatus::Invited)
        ->and($anna->role)->toBe(UserRole::Member);
});

test('an existing email is refused', function () {
    User::factory()->create(['email' => 'anna@example.com']);

    Livewire::test(ManageUsers::class)
        ->callAction('create', ['name' => 'Anna', 'email' => 'anna@example.com', 'role' => UserRole::Member->value])
        ->assertHasFormErrors(['email' => 'unique']);

    expect(User::where('email', 'anna@example.com')->count())->toBe(1);
});

test('a new link invalidates the old one', function () {
    $anna = User::factory()->create();
    $old = Str::afterLast($anna->issueInviteLink(), '/invite/');

    Livewire::test(ManageUsers::class)
        ->callTableAction('issueInvite', $anna)
        ->assertActionMounted('showInviteLink');

    expect(User::findByInviteToken($old))->toBeNull();
});

test('an administrator locks and unlocks a user', function () {
    $anna = User::factory()->create();

    Livewire::test(ManageUsers::class)->callTableAction('lock', $anna);
    expect($anna->fresh()->isLocked())->toBeTrue();

    Livewire::test(ManageUsers::class)->callTableAction('unlock', $anna);
    expect($anna->fresh()->isLocked())->toBeFalse();
});

test('editing changes name, email and role', function () {
    $anna = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $anna, ['name' => 'Anna B.', 'email' => 'anna.b@example.com', 'role' => UserRole::Admin->value]);

    expect($anna->fresh())
        ->name->toBe('Anna B.')
        ->email->toBe('anna.b@example.com')
        ->role->toBe(UserRole::Admin);
});

test('an administrator cannot lock, demote or re-invite themselves', function () {
    Livewire::test(ManageUsers::class)
        ->assertTableActionHidden('lock', $this->admin)
        ->assertTableActionHidden('issueInvite', $this->admin)
        ->mountTableAction('edit', $this->admin)
        ->assertFormFieldIsDisabled('role');
});

test('there is no delete', function () {
    Livewire::test(ManageUsers::class)
        ->assertTableActionDoesNotExist('delete');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/UserResourceTest.php`
Expected: FAIL (`ManageUsers` not found).

- [ ] **Step 3: Implement policy, resource, form, table and page**

- Table columns: name, email, `role` badge, status badge (`->state(fn (User $record): UserStatus => $record->status())`), default sort `name`. Table actions: `edit` (`EditAction` with `->using()` that updates name/email and calls `assignRole()` only when `changeRole` is authorized), `issueInvite` (label "Neuer Link", confirmation), `lock` ("Sperren", confirmation, danger), `unlock` ("Entsperren", confirmation), each with `->authorize(...)` on its policy ability.
- Form: `name` required, `email` required email `unique(ignoreRecord: true)`, `role` `Select` from `UserRole`, default `Member`, `->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)`.
- `ManageUsers` header action `CreateAction` "Nutzer anlegen" with `->using(fn (array $data): User => User::invite(...))` and `->createAnother(false)`; after create and after `issueInvite`, `$this->replaceMountedAction('showInviteLink', ['url' => $link])`.
- `showInviteLink` action on `ManageUsers`: heading "Einladungslink", a copyable `TextEntry` with the URL, description "Gilt 24 Stunden und nur einmal. Der Link wird nur jetzt angezeigt.", no submit button, close label "Schließen".

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/UserResourceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** — `Add the user list for administrators (TOR-18)`

---

### Task 6: Remove self-service, add `users:make-admin`, update docs

**Files:**
- Create: `app/Console/Commands/MakeUserAdmin.php` (`php artisan make:command MakeUserAdmin --no-interaction`)
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (drop `registration()` and `passwordReset()` with their imports and `$selfService`), `config/auth.php` (drop `self_service`), `docker/Dockerfile` (drop `ENV AUTH_SELF_SERVICE=false`), `tests/Feature/ProductionSettingsTest.php`, `tests/Feature/Panel/RoutesTest.php` (the `/register` assertion), `CLAUDE.md`
- Delete: `tests/Feature/Panel/RegistrationTest.php`, `tests/Feature/Panel/PasswordResetTest.php`
- Test: `tests/Feature/Console/MakeUserAdminTest.php`, `tests/Feature/ProductionSettingsTest.php`

**Interfaces:**
- Consumes: `User::assignRole()` (Task 1).
- Produces: `php artisan users:make-admin {email}`.

- [ ] **Step 1: Write the failing tests**

```php
// MakeUserAdminTest.php
test('it makes a user an administrator', function () {
    $user = User::factory()->create(['name' => 'Tobias', 'email' => 'owner@example.com']);

    $this->artisan('users:make-admin', ['email' => 'owner@example.com'])
        ->expectsOutputToContain('Tobias ist jetzt Administrator.')
        ->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue();
});

test('it fails for an unknown email', function () {
    $this->artisan('users:make-admin', ['email' => 'niemand@example.com'])
        ->expectsOutputToContain('Kein Nutzer mit der E-Mail niemand@example.com.')
        ->assertFailed();
});

// ProductionSettingsTest.php: replaces the two self-service tests
test('the panel has neither registration nor password reset', function () {
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->hasRegistration())->toBeFalse()
        ->and($panel->hasPasswordReset())->toBeFalse()
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->hasEmailVerification())->toBeTrue();
});
```

In `RoutesTest.php`, `/register` now answers 404.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Console/MakeUserAdminTest.php tests/Feature/ProductionSettingsTest.php tests/Feature/Panel/RoutesTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the command and remove self-service; delete the two test files**

- [ ] **Step 4: Update `CLAUDE.md`**

- Panel line: replace "No policies: every verified user sees every record (one household)." with: two roles (`UserRole`); `UserPolicy` and `AiProviderPolicy` make the user list and "Eigene KI" admin-only; everything else has no policy and is open to every verified, unlocked user (one household).
- Architecture map: a bullet **Users** (spec user-management): `UserResource` on `/users`, invite page `App\Filament\Auth\AcceptInvite` on `/invite/{token}`, `LogOutLockedUser` (web and panel) and `EnsureUserIsActive` (API, `/mcp`).
- Production paragraph: drop the `AUTH_SELF_SERVICE` sentence; after the first deploy run `users:make-admin`.
- Running commands: add `docker compose exec app php artisan users:make-admin admin@example.com` after `make:filament-user`.
- Gotchas: drop the `AUTH_SELF_SERVICE` gotcha. Add: the invite token is shown only once; a lost link needs "Neuer Link".

- [ ] **Step 5: Run Pint, PHPStan and the full suite**

Run: `docker compose exec app ./vendor/bin/pint --dirty --format agent && docker compose exec app ./vendor/bin/phpstan analyse && docker compose exec app php artisan test --compact`
Expected: all clean, all PASS.

- [ ] **Step 6: Commit** — `Remove self-service sign-up and add users:make-admin (TOR-18)`
