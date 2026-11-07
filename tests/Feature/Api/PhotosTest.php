<?php

use App\Http\Resources\Api\V1\PhotoUrls;
use App\Models\Import;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
});

it('streams an item photo with its content type', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.jpg', 'jpeg-bytes');
    $item = Item::factory()->create(['photos' => ['imports/abc.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/abc.jpg")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('streams an import photo', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.jpg', 'jpeg-bytes');
    $import = Import::factory()->create(['photos' => ['imports/abc.jpg']]);

    $this->get("/api/v1/imports/{$import->id}/photos/abc.jpg")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('answers 404 for a file of another record', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.jpg', 'jpeg-bytes');
    Item::factory()->create(['photos' => ['imports/abc.jpg']]);
    $other = Item::factory()->create(['photos' => ['imports/other.jpg']]);

    $this->get("/api/v1/items/{$other->id}/photos/abc.jpg")->assertNotFound();
});

it('answers 404 for a path-like name', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.jpg', 'jpeg-bytes');
    $item = Item::factory()->create(['photos' => ['imports/abc.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/..%2Fabc.jpg")->assertNotFound();
});

it('requires a token', function () {
    $item = Item::factory()->create();

    $this->getJson("/api/v1/items/{$item->id}/photos/item.jpg")->assertUnauthorized();
});

it('lists photo urls in photo order', function () {
    $item = Item::factory()->create(['photos' => ['imports/b.jpg', 'imports/a.png']]);

    expect(PhotoUrls::for('api.v1.items.photos.show', $item))->toBe([
        route('api.v1.items.photos.show', [$item, 'b.jpg']),
        route('api.v1.items.photos.show', [$item, 'a.png']),
    ]);
});

it('answers 401 to an image request without a token', function () {
    $item = Item::factory()->create(['photos' => ['imports/abc.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/abc.jpg", ['Accept' => 'image/*'])
        ->assertUnauthorized();
});

it('answers 404 when the file is missing on disk', function () {
    Sanctum::actingAs(User::factory()->create());
    $item = Item::factory()->create(['photos' => ['imports/gone.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/gone.jpg")->assertNotFound();
});

it('streams an item thumbnail as webp', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('photos/2026/11/abc.jpg', jpeg(800, 600));
    $item = Item::factory()->create(['photos' => ['photos/2026/11/abc.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/abc.jpg/thumb")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
    Storage::disk('local')->assertExists('thumbs/photos/2026/11/abc.jpg.webp');
});

it('streams an import thumbnail as webp', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.png', png(800, 600));
    $import = Import::factory()->create(['photos' => ['imports/abc.png']]);

    $this->get("/api/v1/imports/{$import->id}/photos/abc.png/thumb")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

it('finds a photo in a month folder', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('photos/2026/11/abc.jpg', jpeg(10, 10));
    $item = Item::factory()->create(['photos' => ['photos/2026/11/abc.jpg']]);

    $this->get("/api/v1/items/{$item->id}/photos/abc.jpg")->assertOk();
});

it('answers 404 for a thumbnail of another record', function () {
    Sanctum::actingAs(User::factory()->create());
    Storage::disk('local')->put('imports/abc.jpg', jpeg(10, 10));
    Item::factory()->create(['photos' => ['imports/abc.jpg']]);
    $other = Item::factory()->create(['photos' => ['imports/other.jpg']]);

    $this->get("/api/v1/items/{$other->id}/photos/abc.jpg/thumb")->assertNotFound();
});

it('requires a token for thumbnails', function () {
    $item = Item::factory()->create();

    $this->getJson("/api/v1/items/{$item->id}/photos/item.jpg/thumb")->assertUnauthorized();
});
