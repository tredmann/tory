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
