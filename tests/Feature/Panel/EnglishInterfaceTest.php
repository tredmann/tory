<?php

use App\Enums\Language;
use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AiProvider;
use App\Models\BulkImport;
use App\Models\HouseholdSettings;
use App\Models\Import;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * German phrases of lang/de.json that differ from their English key, so umlaut-free German is caught too.
 *
 * @return list<string>
 */
function germanPhrases(): array
{
    $translations = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_unique(array_filter(
        $translations,
        fn (string $german, string $english): bool => $german !== $english && mb_strlen($german) >= 6,
        ARRAY_FILTER_USE_BOTH,
    )));
}

/**
 * The text a visitor reads: tags and script or style contents removed, the values of placeholder, aria-label,
 * title and alt attributes added, entities decoded.
 */
function visibleText(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    preg_match_all('/\s(?:placeholder|aria-label|title|alt)="([^"]*)"/i', $html, $attributes);
    $text = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html).' '.implode(' ', $attributes[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return preg_replace('/\s+/u', ' ', $text);
}

/**
 * Describes the German left in the text, or null when there is none.
 */
function germanLeftover(string $text): ?string
{
    if (preg_match('/.{0,40}[äöüÄÖÜß].{0,40}/u', $text, $match)) {
        return 'umlaut in "'.trim($match[0]).'"';
    }

    foreach (germanPhrases() as $phrase) {
        if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $text)) {
            return 'German text "'.$phrase.'"';
        }
    }

    return null;
}

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
});

test('the English interface contains no German', function (): void {
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();

    $location = Location::factory()->create(['name' => 'Basement shelf']);
    $spot = Spot::factory()->for($location)->create(['name' => 'Top box']);
    $item = Item::factory()->for($spot)->create(['title' => 'Garden hose', 'description' => 'Green hose with a nozzle.']);
    $import = Import::factory()->completed()->for($user)->create(['description' => 'Green board with four USB ports.']);
    $bulkImport = BulkImport::factory()->ready()->for($user)->for($spot)->create();
    AiProvider::factory()->create(['name' => 'Cloud model']);

    $pages = [
        'dashboard' => '/',
        'items' => '/items',
        'item view' => "/items/{$item->getKey()}",
        'item edit' => "/items/{$item->getKey()}/edit",
        'imports' => '/imports',
        'import create' => '/imports/create',
        'import view' => "/imports/{$import->getKey()}",
        'bulk import create' => '/bulk-imports/create',
        'bulk import view' => "/bulk-imports/{$bulkImport->getKey()}",
        'spots' => '/spots',
        'spot create' => '/spots/create',
        'spot view' => "/spots/{$spot->code}",
        'spot edit' => "/spots/{$spot->code}/edit",
        'locations' => '/locations',
        'users' => '/users',
        'ai providers' => '/ai-providers',
        'tory in claude' => '/tory-in-claude',
        'household' => '/household',
        'profile' => '/profile',
    ];

    $leftovers = [];

    foreach ($pages as $name => $url) {
        $response = $this->actingAs($user)->withHeader('Accept-Language', 'en')->get($url);
        $response->assertOk();

        if ($leftover = germanLeftover(visibleText($response->getContent()))) {
            $leftovers[] = "{$name} ({$url}): {$leftover}";
        }
    }

    expect($leftovers)->toBe([]);
});

test('the English login page contains no German', function (): void {
    User::factory()->create();
    HouseholdSettings::current()->changeContentLanguage(Language::English);

    $response = $this->withHeader('Accept-Language', 'en')->get('/login');
    $response->assertOk();

    expect(germanLeftover(visibleText($response->getContent())))->toBeNull();
});

test('the English modal forms contain no German', function (): void {
    Filament::setCurrentPanel('admin');
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);

    $location = Location::factory()->create(['name' => 'Basement shelf']);
    $provider = AiProvider::factory()->create(['name' => 'Cloud model']);

    $mounted = [
        'create location' => Livewire::test(ManageLocations::class)->mountAction('create'),
        'edit location' => Livewire::test(ManageLocations::class)->mountAction(TestAction::make('edit')->table($location)),
        'create user' => Livewire::test(ManageUsers::class)->mountAction('create'),
        'edit user' => Livewire::test(ManageUsers::class)->mountAction(TestAction::make('edit')->table($user)),
        'create provider' => Livewire::test(ManageAiProviders::class)->mountAction('create'),
        'edit provider' => Livewire::test(ManageAiProviders::class)->mountAction(TestAction::make('edit')->table($provider)),
    ];

    $leftovers = [];

    foreach ($mounted as $name => $component) {
        $component->assertHasNoErrors();

        if ($leftover = germanLeftover(visibleText($component->html()))) {
            $leftovers[] = "{$name}: {$leftover}";
        }
    }

    expect($leftovers)->toBe([]);
});
