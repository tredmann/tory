<?php

use App\Models\User;
use App\Photos\PhotoLinks;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
});

it('serves an original with immutable caching', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(20, 10));

    $response = $this->get('/photos/2026/11/a.jpg');

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->headers->hasCacheControlDirective('immutable'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('max-age'))->toBe('31536000')
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue();
});

it('serves an old photo', function () {
    Storage::disk('local')->put('imports/a.jpg', jpeg(20, 10));

    $this->get('/photos/imports/a.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('serves a webp thumbnail', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(800, 600));

    $response = $this->get('/photos/thumb/2026/11/a.jpg');

    $response->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect($response->headers->hasCacheControlDirective('immutable'))->toBeTrue();
    Storage::disk('local')->assertExists('thumbs/photos/2026/11/a.webp');
});

it('falls back to the original without caching', function () {
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');

    $response = $this->get('/photos/thumb/imports/broken.jpg');

    $response->assertOk();
    expect($response->streamedContent())->toBe('not an image')
        ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('immutable'))->toBeFalse();
});

it('answers 404 outside the photo folders', function (string $url) {
    Storage::disk('local')->put('evals/x.json', '{}');
    Storage::disk('local')->put('livewire-tmp/x.jpg', jpeg(20, 10));

    $this->get($url)->assertNotFound();
})->with([
    'other folder' => '/photos/evals/x.json',
    'livewire tmp' => '/photos/livewire-tmp/x.jpg',
    'thumb of other folder' => '/photos/thumb/livewire-tmp/x.jpg',
    'traversal' => '/photos/imports/../evals/x.json',
    'encoded traversal' => '/photos/imports/%2e%2e/evals/x.json',
    'nested in imports' => '/photos/imports/sub/x.jpg',
    'bad month' => '/photos/2026/1/a.jpg',
    'thumbs folder' => '/photos/thumb/thumbs/imports/a.webp',
    'missing original' => '/photos/imports/missing.jpg',
    'missing thumbnail source' => '/photos/thumb/imports/missing.jpg',
    'backslash traversal' => '/photos/imports/..%5Cevals%5Cx.json',
    'backslash traversal thumbnail' => '/photos/thumb/imports/..%5Clivewire-tmp%5Cx.jpg',
    'control character' => '/photos/imports/a%01b.jpg',
    'trailing newline' => '/photos/imports/a.jpg%0A',
]);

it('sends guests to the login', function () {
    auth()->logout();

    $this->get('/photos/imports/a.jpg')->assertRedirect('/login');
});

it('sends unverified users to the verification prompt', function () {
    auth()->logout();
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/photos/imports/a.jpg')->assertRedirect('/email-verification/prompt');
});

it('builds links to both routes', function () {
    Storage::disk('local')->put('imports/b.jpg', jpeg(20, 10));

    expect(PhotoLinks::original('photos/2026/11/a.jpg'))->toEndWith('/photos/2026/11/a.jpg')
        ->and(PhotoLinks::thumbnail('imports/a.jpg'))->toEndWith('/photos/thumb/imports/a.jpg')
        ->and(PhotoLinks::existing(['imports/missing.jpg', 'imports/b.jpg']))->toBe(['imports/b.jpg'])
        ->and(PhotoLinks::thumbnails(['imports/missing.jpg', 'imports/b.jpg']))->toBe([PhotoLinks::thumbnail('imports/b.jpg')]);
});
