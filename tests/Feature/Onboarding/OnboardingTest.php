<?php

use App\Models\AiProvider;
use App\Models\HouseholdSettings;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use App\Onboarding\Onboarding;
use App\Onboarding\OnboardingStep;
use Filament\Facades\Filament;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

/**
 * @return array<string, bool>
 */
function onboardingDone(): array
{
    return collect(resolve(Onboarding::class)->steps())->mapWithKeys(fn ($step): array => [$step->key => $step->done])->all();
}

test('a fresh household has all five steps open, the content language first', function (): void {
    $onboarding = resolve(Onboarding::class);

    expect(array_map(fn (OnboardingStep $step): string => $step->key, $onboarding->steps()))
        ->toBe(['content-language', 'recognition', 'search', 'spot', 'item'])
        ->and(onboardingDone())->each->toBeFalse()
        ->and($onboarding->openStep()->key)->toBe('content-language')
        ->and($onboarding->doneCount())->toBe(0);
});

test('each step is done by its own condition', function (): void {
    HouseholdSettings::current()->confirmContentLanguage();
    expect(onboardingDone())->toMatchArray(['content-language' => true, 'recognition' => false, 'search' => false, 'spot' => false, 'item' => false]);

    $recognition = AiProvider::factory()->active()->create();
    expect(onboardingDone())->toMatchArray(['recognition' => true, 'search' => false]);
    expect(resolve(Onboarding::class)->steps()[1]->result)->toBe($recognition->name);

    $search = AiProvider::factory()->embedding()->activeForSearch()->create();
    expect(onboardingDone())->toMatchArray(['search' => true, 'spot' => false]);
    expect(resolve(Onboarding::class)->steps()[2]->result)->toBe($search->name);

    Spot::factory()->create();
    expect(onboardingDone())->toMatchArray(['spot' => true, 'item' => false]);

    Item::factory()->create();
    expect(onboardingDone())->each->toBeTrue()
        ->and(resolve(Onboarding::class)->doneCount())->toBe(5);
});

test('an open import does not count as the first item', function (): void {
    Import::factory()->create();

    expect(onboardingDone()['item'])->toBeFalse();
});

test('a step done out of order is ticked and the open step is the first undone one', function (): void {
    Spot::factory()->create();

    expect(onboardingDone()['spot'])->toBeTrue()
        ->and(resolve(Onboarding::class)->openStep()->key)->toBe('content-language')
        ->and(resolve(Onboarding::class)->doneCount())->toBe(1);
});

test('the content language step names the language', function (): void {
    expect(resolve(Onboarding::class)->steps()[0]->result)->toBe('Deutsch');
});

test('only administrators see the onboarding', function (): void {
    $onboarding = resolve(Onboarding::class);

    expect($onboarding->isShown(User::factory()->create()))->toBeFalse()
        ->and($onboarding->isShown(User::factory()->admin()->create()))->toBeTrue()
        ->and($onboarding->isShown(null))->toBeFalse();
});

test('once every step is done the onboarding is finished for good', function (): void {
    HouseholdSettings::current()->confirmContentLanguage();
    AiProvider::factory()->active()->create();
    AiProvider::factory()->embedding()->activeForSearch()->create();
    Spot::factory()->create();
    Item::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(resolve(Onboarding::class)->isShown($admin))->toBeFalse()
        ->and(HouseholdSettings::current()->onboarding_finished_at)->not->toBeNull();

    Item::query()->delete();

    expect(resolve(Onboarding::class)->isShown($admin))->toBeFalse();
});

test('a finished onboarding is not shown', function (): void {
    HouseholdSettings::current()->finishOnboarding();

    expect(resolve(Onboarding::class)->isShown(User::factory()->admin()->create()))->toBeFalse();
});
