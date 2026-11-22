<?php

use App\Enums\Language;
use App\Enums\UserRole;
use App\Filament\Pages\HouseholdSettingsPage;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Spots\SpotResource;
use App\Filament\Widgets\OnboardingWidget;
use App\Models\HouseholdSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->withoutVite();
});

test('an administrator of a fresh household sees Tory einrichten above the search', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('Tory einrichten')
        ->assertSee('0 von 5 erledigt')
        ->assertSeeInOrder(['Tory einrichten', 'placeholder="Wo ist …?"'], false);
});

test('a plain user does not see the onboarding', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Tory einrichten');
});

test('only the open step shows its explanation and button', function (): void {
    HouseholdSettings::current()->confirmContentLanguage();
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')
        ->assertSee('Diese KI schreibt Titel, Beschreibung und Suchbegriffe aus deinen Fotos.')
        ->assertSee('Eigene KI öffnen')
        ->assertDontSee('Ein Lagerort ist ein Bereich wie')
        ->assertDontSee('Passt so');
});

test('every step title links to its page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->get('/');

    foreach ([HouseholdSettingsPage::getUrl(), AiProviderResource::getUrl(), SpotResource::getUrl('create'), ImportResource::getUrl('create')] as $url) {
        $response->assertSee('href="'.e($url).'"', false);
    }
});

test('Passt so confirms the content language', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(OnboardingWidget::class)
        ->assertSee('0 von 5 erledigt')
        ->callAction('confirmContentLanguage')
        ->assertSee('1 von 5 erledigt');

    expect(HouseholdSettings::current()->content_language_confirmed_at)->not->toBeNull();
});

test('Ausblenden asks first and hides the onboarding for every administrator', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(OnboardingWidget::class)
        ->mountAction('dismiss')
        ->assertActionMounted('dismiss')
        ->assertMountedActionModalSee('Einrichtung ausblenden?')
        ->callMountedAction();

    expect(HouseholdSettings::current()->onboarding_finished_at)->not->toBeNull();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('Tory einrichten');
});

test('a plain user cannot run the onboarding actions', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(OnboardingWidget::class)->assertForbidden();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $dismissing = Livewire::test(OnboardingWidget::class);
    $confirming = Livewire::test(OnboardingWidget::class);
    $admin->forceFill(['role' => UserRole::Member])->save();

    $dismissing->call('mountAction', 'dismiss')->assertForbidden();
    $confirming->call('mountAction', 'confirmContentLanguage')->assertForbidden();

    expect(HouseholdSettings::current()->content_language_confirmed_at)->toBeNull()
        ->and(HouseholdSettings::current()->onboarding_finished_at)->toBeNull();
});

test('an English administrator sees the onboarding in English', function (): void {
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->get('/')
        ->assertOk()
        ->assertSee('Set up Tory')
        ->assertSee('0 of 5 done')
        ->assertSee('Check the content language')
        ->assertDontSee('Inhaltssprache prüfen')
        ->assertDontSee('Tory einrichten');
});
