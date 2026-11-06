<?php

use App\Models\Item;
use App\Models\Spot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

test('an item cannot exist without a spot', function () {
    expect(fn () => Item::factory()->create(['spot_id' => null]))->toThrow(QueryException::class);
});

test('an item belongs to a spot and casts its photos', function () {
    $item = Item::factory()->create();

    expect($item->fresh()->spot)->toBeInstanceOf(Spot::class)
        ->and($item->fresh()->photos)->toBe(['imports/item.jpg']);
});

test('the embedding round-trips as 1024 floats and stays out of the array form', function () {
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->save();

    expect($item->fresh()->embedding)->toHaveCount(1024)
        ->and($item->fresh()->toArray())->not->toHaveKey('embedding');
});

test('deleting an item deletes its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/x.jpg']]);

    $item->delete();

    Storage::disk('local')->assertMissing('imports/x.jpg');
});

test('deleting an item quietly keeps its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    Item::factory()->create(['photos' => ['imports/x.jpg']])->deleteQuietly();

    Storage::disk('local')->assertExists('imports/x.jpg');
});
