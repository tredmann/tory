<?php

use App\Enums\LabelSize;
use App\Filament\Resources\Spots\Pages\ListSpots;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Filament\Resources\Spots\SpotLabelSheet;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * @param  array<string, string>  $attributes
 */
function labelledSpot(string $code, array $attributes = []): Spot
{
    $spot = Spot::factory()->create($attributes);
    $spot->forceFill(['code' => $code])->save();

    return $spot;
}

/**
 * WeasyPrint keeps its page objects in compressed streams.
 */
function pdfPageCount(string $pdf): int
{
    preg_match_all('#<<[^>]*/ObjStm[^>]*>>\s*stream\r?\n(.*?)\r?\n?endstream#s', $pdf, $streams);
    $objects = implode('', array_map(fn (string $stream): string => (string) @gzuncompress($stream), $streams[1]));

    return preg_match_all('#/Type\s*/Page\b#', $objects);
}

test('the spot page downloads a label pdf in the chosen size', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM', ['name' => 'Elektronik-Kiste']);

    Livewire::test(ViewSpot::class, ['record' => $spot->code])
        ->callAction('labels', ['size' => LabelSize::Small->value])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded('elektronik-kiste-klein.pdf');

    Pdf::assertViewIs('pdf.spot-labels');
    Pdf::assertViewHas('size', LabelSize::Small);
    Pdf::assertSee(['K7XM', 'Elektronik-Kiste']);
});

test('the label dialog requires a size', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM');

    Livewire::test(ViewSpot::class, ['record' => $spot->code])
        ->callAction('labels', ['size' => null])
        ->assertHasFormErrors(['size' => 'required'])
        ->assertNoFileDownloaded();
});

test('the list downloads one label for each ticked spot and none for the others', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $ticked = labelledSpot('AAAA', ['name' => 'Kiste Eins']);
    $alsoTicked = labelledSpot('BBBB', ['name' => 'Kiste Zwei']);
    labelledSpot('CCCC', ['name' => 'Kiste Drei']);

    Livewire::test(ListSpots::class)
        ->selectTableRecords([$ticked->getKey(), $alsoTicked->getKey()])
        ->callAction(TestAction::make('labels')->table()->bulk(), ['size' => LabelSize::Large->value])
        ->assertFileDownloaded();

    Pdf::assertSee(['Kiste Eins', 'Kiste Zwei']);
    Pdf::assertDontSee(['CCCC', 'Kiste Drei']);
});

test('the label leaves out the location', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM', ['location_id' => Location::factory()->create(['name' => 'Ferienwohnung'])->getKey()]);

    Livewire::test(ViewSpot::class, ['record' => $spot->code])
        ->callAction('labels', ['size' => LabelSize::Medium->value]);

    Pdf::assertDontSee('Ferienwohnung');
});

test('a spot name is escaped on the label', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM', ['name' => '<img src="file:///etc/passwd">']);

    Livewire::test(ViewSpot::class, ['record' => $spot->code])
        ->callAction('labels', ['size' => LabelSize::Medium->value]);

    Pdf::assertDontSee('<img src="file:///etc/passwd">');
});

test('the file name of a single label holds the spot name and the size in lowercase', function (string $name, LabelSize $size, string $fileName) {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM', ['name' => $name]);

    Livewire::test(ViewSpot::class, ['record' => $spot->code])
        ->callAction('labels', ['size' => $size->value])
        ->assertFileDownloaded($fileName);
})->with([
    'plain' => ['Elektronik-Kiste', LabelSize::Small, 'elektronik-kiste-klein.pdf'],
    'umlauts and symbols' => ['Gehäuse & Zubehör', LabelSize::Medium, 'gehaeuse-zubehoer-mittel.pdf'],
    'sharp s' => ['Große Kiste', LabelSize::Large, 'grosse-kiste-gross.pdf'],
    'nothing usable falls back to the code' => ['???', LabelSize::Medium, 'k7xm-mittel.pdf'],
    'too long is cut without a loose hyphen' => [str_repeat('a', 99).' bbb', LabelSize::Medium, str_repeat('a', 99).'-mittel.pdf'],
]);

test('one ticked spot in the list is named after the spot', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spot = labelledSpot('K7XM', ['name' => 'Elektronik-Kiste']);

    Livewire::test(ListSpots::class)
        ->selectTableRecords([$spot->getKey()])
        ->callAction(TestAction::make('labels')->table()->bulk(), ['size' => LabelSize::Medium->value])
        ->assertFileDownloaded('elektronik-kiste-mittel.pdf');
});

test('several spots of one location are named after the location', function (string $location, string $fileName) {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $locationId = Location::factory()->create(['name' => $location])->getKey();
    $spots = [labelledSpot('AAAA', ['location_id' => $locationId]), labelledSpot('BBBB', ['location_id' => $locationId])];

    Livewire::test(ListSpots::class)
        ->selectTableRecords(array_map(fn (Spot $spot) => $spot->getKey(), $spots))
        ->callAction(TestAction::make('labels')->table()->bulk(), ['size' => LabelSize::Small->value])
        ->assertFileDownloaded($fileName);
})->with([
    'plain' => ['Keller', 'keller-klein.pdf'],
    'nothing usable falls back to the general name' => ['???', 'etiketten-klein.pdf'],
]);

test('spots from several locations get the general name', function () {
    Pdf::fake();
    $this->actingAs(User::factory()->create());
    $spots = [
        labelledSpot('AAAA', ['location_id' => Location::factory()->create()->getKey()]),
        labelledSpot('BBBB', ['location_id' => Location::factory()->create()->getKey()]),
    ];

    Livewire::test(ListSpots::class)
        ->selectTableRecords(array_map(fn (Spot $spot) => $spot->getKey(), $spots))
        ->callAction(TestAction::make('labels')->table()->bulk(), ['size' => LabelSize::Large->value])
        ->assertFileDownloaded('etiketten-gross.pdf');
});

test('the label prints the address of its qr code without the scheme', function () {
    config(['app.url' => 'https://tory.example']);

    [$label] = $labels = app(SpotLabelSheet::class)->labels(new Collection([labelledSpot('K7XM')]));
    $html = strip_tags(view('pdf.spot-labels', ['rows' => [$labels], 'size' => LabelSize::Medium])->render());

    expect((string) (new QRCode)->readFromBlob(base64_decode(explode(',', $label->qrCode, 2)[1])))->toBe('https://'.$label->address)
        ->and($label->address)->toBe('tory.example/spots/K7XM')
        ->and($html)->toContain('tory.example/spots/K7XM')
        ->not->toContain('https://');
});

test('the labels are sorted by code', function () {
    $labels = app(SpotLabelSheet::class)->labels(new Collection([labelledSpot('ZZZZ'), labelledSpot('AAAA')]));

    expect(array_map(fn ($label) => $label->code, $labels))->toBe(['AAAA', 'ZZZZ']);
});

test('each qr code holds only the address of its own spot on the installation', function () {
    config(['app.url' => 'https://tory.example']);

    $labels = app(SpotLabelSheet::class)->labels(new Collection([labelledSpot('AAAA'), labelledSpot('K7XM')]));
    $addresses = array_map(
        fn ($label): string => (string) (new QRCode)->readFromBlob(base64_decode(explode(',', $label->qrCode, 2)[1])),
        $labels,
    );

    expect($addresses)->toBe(['https://tory.example/spots/AAAA', 'https://tory.example/spots/K7XM']);
});

test('labels continue on the next page once a sheet is full', function (LabelSize $size, int $perSheet) {
    $this->actingAs(User::factory()->create());
    $spots = Spot::factory()->count($perSheet + 1)->create();
    $pages = fn (Collection $spots): int => pdfPageCount(app(SpotLabelSheet::class)->pdf($spots, $size));

    expect($pages($spots->take($perSheet)))->toBe(1)
        ->and($pages($spots))->toBe(2);
})->with([
    'small' => [LabelSize::Small, 36],
    'medium' => [LabelSize::Medium, 14],
    'large' => [LabelSize::Large, 8],
]);

test('a scanned label leads through the login back to the spot', function () {
    $spot = labelledSpot('K7XM');
    $user = User::factory()->create();

    $this->get('/spots/K7XM')->assertRedirect('/login');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect('/spots/'.$spot->code);
});
