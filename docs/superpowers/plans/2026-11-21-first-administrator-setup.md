# First Administrator Setup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On an installation without any user, the first visitor creates the first administrator at `/setup` in the browser.

**Architecture:** A model method creates the first administrator under a Postgres advisory lock. A `SimplePage` at `/setup`, modelled on `AcceptInvite`, calls it and logs the user in. Panel middleware redirects every panel request to `/setup` while the `users` table is empty.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest on Postgres.

**Spec:** `docs/superpowers/specs/2026-11-21-first-administrator-setup-design.md`

## Global Constraints

- The page is open exactly while `users` is empty; any user, verified or not, admin or not, closes it.
- Route `GET /setup`, name `setup` (full name `filament.admin.setup`), `throttle:10,1`, registered in the panel's `->routes()` next to `invite`.
- Class names: `App\Filament\Auth\SetUpFirstAdministrator`, `App\Http\Middleware\RedirectToFirstAdministratorSetup`. Never `TorySetup`.
- German copy: title and heading „Willkommen bei Tory“, subheading „Lege den ersten Administrator an.“, fields „Name“, „E-Mail“, „Passwort“, „Passwort bestätigen“, button „Administrator anlegen“.
- Password rule `Password::default()` (15 characters plus Pwned Passwords in production), same as `AcceptInvite`.
- REST API, `/mcp` and the OAuth routes are not touched.
- All commands run in Docker: `docker compose exec app …`.
- Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`. Before the last commit also `docker compose exec app ./vendor/bin/phpstan analyse`.
- Commit messages end with `(TOR-39)` and the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A user created from the CLI who is neither verified nor admin closes the page (test in Task 1 and Task 3).
2. Submitting after someone else finished setup creates no second user and lands on the login (test in Task 2).
3. Opening `/setup` once a user exists answers 404 rather than showing the form (test in Task 2).
4. An invite link opened on an empty database redirects to `/setup` and does not error (test in Task 3).
5. Two truly simultaneous submits: no single-connection test can race them, so the reviewer checks that the existence check and the insert sit inside the same transaction, after `pg_advisory_xact_lock`.

---

### Task 1: `User::createFirstAdministrator()`

**Files:**
- Modify: `app/Models/User.php` (next to `invite()`)
- Test: `tests/Feature/Models/UserTest.php`

**Interfaces:**
- Produces: `User::createFirstAdministrator(string $name, string $email, #[SensitiveParameter] string $password): ?User`. Returns the new user (role `UserRole::Admin`, `email_verified_at` set), or `null` when any user already exists.

- [ ] **Step 1: Write the failing tests**

```php
test('the first administrator is created verified and as administrator', function () {
    $user = User::createFirstAdministrator('Anna', 'anna@example.com', 'ein-langes-passwort');

    expect($user->isAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('ein-langes-passwort', $user->password))->toBeTrue();
});

test('no first administrator is created once any user exists', function () {
    User::factory()->unverified()->create();

    expect(User::createFirstAdministrator('Anna', 'anna@example.com', 'ein-langes-passwort'))->toBeNull()
        ->and(User::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/UserTest.php --filter="first administrator"`
Expected: FAIL, method `createFirstAdministrator` does not exist.

- [ ] **Step 3: Implement `createFirstAdministrator()`**

`DB::transaction()`: first `DB::select('select pg_advisory_xact_lock(?)', [<fixed integer key as a class constant>])`, then return `null` if `self::query()->exists()`, otherwise create the user and set `role` and `email_verified_at` with `forceFill` (both are not fillable). PHPDoc one line on why the lock is there.

- [ ] **Step 4: Run the tests to verify they pass**

Run: same as Step 2. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Models/UserTest.php
git commit -m "Create the first administrator under a lock (TOR-39)"
```

### Task 2: Setup page at `/setup`

**Files:**
- Create: `app/Filament/Auth/SetUpFirstAdministrator.php`
- Create: `resources/views/filament/auth/set-up-first-administrator.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (the `->routes()` closure)
- Test: `tests/Feature/Panel/SetUpFirstAdministratorTest.php`

**Interfaces:**
- Consumes: `User::createFirstAdministrator()` from Task 1.
- Produces: route `filament.admin.setup`; Livewire method `create(): void` on the page.

- [ ] **Step 1: Write the failing tests**

```php
test('the setup page asks for the first administrator', function () {
    $this->get('/setup')->assertOk()->assertSeeText('Willkommen bei Tory')->assertSeeText('Administrator anlegen');
});

test('setting up logs the new administrator in', function () {
    Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'])
        ->call('create')
        ->assertRedirect(filament()->getUrl());

    $user = User::firstWhere('email', 'anna@example.com');
    $this->assertAuthenticatedAs($user);
    expect($user->isAdmin())->toBeTrue()->and($user->hasVerifiedEmail())->toBeTrue();
});

test('the setup page is gone once a user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertNotFound();
});

test('a submit after someone else set up creates nobody and goes to the login', function () {
    $page = Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort']);
    User::factory()->create();

    $page->call('create')->assertRedirect(filament()->getLoginUrl());

    expect(User::query()->count())->toBe(1);
    $this->assertGuest();
});

test('the setup needs a confirmed password and a valid email', function () {
    Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm(['name' => 'Anna', 'email' => 'keine-adresse', 'password' => 'ein-langes-passwort', 'passwordConfirmation' => 'anders'])
        ->call('create')
        ->assertHasFormErrors(['email', 'password']);
});
```

Add the production password test by copying „in production the invite refuses a password shorter than 15 characters“ from `tests/Feature/Panel/AcceptInviteTest.php`, with this page and `create`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/SetUpFirstAdministratorTest.php`
Expected: FAIL, class `SetUpFirstAdministrator` not found / route `/setup` 404.

- [ ] **Step 3: Implement the page**

Mirror `AcceptInvite` (`SimplePage`, `content()` with `Form`, `#[Exposed]`/`#[Unexposed]` attributes, same password components). `mount()` aborts 404 when a user exists. `create()` calls `User::createFirstAdministrator()`; on `null` redirect to `filament()->getLoginUrl()`, otherwise `Filament::auth()->login($user)`, `session()->regenerate()`, redirect to `filament()->getUrl()`. `getSubheading()` returns the subheading copy. Fields: `name` and `email` `TextInput`s, required, `maxLength(255)`, email with `->email()`. View: `<x-filament-panels::page.simple>{{ $this->content }}</x-filament-panels::page.simple>`. Route: `Route::get('/setup', SetUpFirstAdministrator::class)->middleware('throttle:10,1')->name('setup')` inside the existing `->routes()` closure.

- [ ] **Step 4: Run the tests to verify they pass**

Run: same as Step 2. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Auth/SetUpFirstAdministrator.php resources/views/filament/auth/set-up-first-administrator.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Panel/SetUpFirstAdministratorTest.php
git commit -m "Set up the first administrator in the browser (TOR-39)"
```

### Task 3: Redirect to `/setup` while no user exists

**Files:**
- Create: `app/Http/Middleware/RedirectToFirstAdministratorSetup.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`->middleware([...])`, directly after `StartSession::class`)
- Modify: tests that request guest panel pages on an empty database (create a user first): `tests/Feature/Panel/RoutesTest.php`, `BrandTest.php`, `ThemeModeTest.php`, `PageHeadingsTest.php`, `tests/Feature/ProductionSettingsTest.php`, `tests/Feature/Mcp/OAuthTest.php`; find the rest by running the full suite.
- Test: `tests/Feature/Panel/SetUpFirstAdministratorTest.php`

**Interfaces:**
- Consumes: route `filament.admin.setup` from Task 2.

- [ ] **Step 1: Write the failing tests**

```php
test('an empty installation sends every panel page to the setup', function (string $path) {
    $this->get($path)->assertRedirect(route('filament.admin.setup'));
})->with(['/', '/login', '/items', '/invite/irgendwas']);

test('a user created from the command line closes the setup', function () {
    User::factory()->unverified()->create();

    $this->get('/login')->assertOk();
    $this->get('/setup')->assertNotFound();
});

test('the api is not redirected on an empty installation', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/SetUpFirstAdministratorTest.php`
Expected: FAIL, `/` redirects to `/login`.

- [ ] **Step 3: Implement the middleware**

`handle(Request $request, Closure $next): Response`: when `! User::query()->exists()` and `! $request->routeIs('filament.admin.setup')`, return `redirect()->route('filament.admin.setup')`; otherwise `$next($request)`. Class PHPDoc in one line, like `LogOutLockedUser`. Register it in the panel middleware list after `StartSession::class`.

- [ ] **Step 4: Run the new tests, then the whole suite**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/SetUpFirstAdministratorTest.php` — expected PASS.
Run: `docker compose exec app php artisan test --compact` — fix every test that now gets a redirect to `/setup` by creating a user (`User::factory()->create()`) before the request. Never weaken an assertion. Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/RedirectToFirstAdministratorSetup.php app/Providers/Filament/AdminPanelProvider.php tests
git commit -m "Send an empty installation to the setup (TOR-39)"
```

### Task 4: Documentation

**Files:**
- Modify: `README.md` („Create a user“)
- Modify: `docs/dockerhub.md` („First user“)
- Modify: `docs/production.md` (the fresh-installation sentence, line 23)
- Modify: `CLAUDE.md` (production paragraph; architecture map, Users line)
- Modify: `CONTEXT.md` (section People)

- [ ] **Step 1: Update the docs**

- README and `docs/dockerhub.md`: on a fresh installation open Tory in the browser and create the first administrator at `/setup`. Whoever opens it first becomes administrator, so keep Tory unreachable from the internet until then. If that cannot be guaranteed, create the first user from the CLI as before (keep the existing commands), which closes the page.
- `docs/production.md`: the fresh-installation sentence points to the setup page and the same warning.
- `CLAUDE.md`: production paragraph says a fresh install opens `/setup` (`SetUpFirstAdministrator`) while `users` is empty, the CLI path stays; Users line names the page and `RedirectToFirstAdministratorSetup`.
- `CONTEXT.md` under People, after **Invite link**:

```markdown
**First administrator setup** (Ersteinrichtung):
The page a fresh installation shows while it has no user at all, on which the first visitor creates the first administrator. Once any user exists it is gone for good.
_Avoid_: Registrierung, Installation
```

- [ ] **Step 2: Run PHPStan and the suite**

Run: `docker compose exec app ./vendor/bin/phpstan analyse` and `docker compose exec app php artisan test --compact`
Expected: no errors, all green.

- [ ] **Step 3: Commit**

```bash
git add README.md docs/dockerhub.md docs/production.md CLAUDE.md CONTEXT.md
git commit -m "Describe the first administrator setup (TOR-39)"
```
