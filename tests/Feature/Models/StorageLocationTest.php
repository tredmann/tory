<?php

use App\Models\Import;
use App\Models\Item;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Database\QueryException;

test('the label shows number and name', function () {
    $location = StorageLocation::factory()->create(['name' => 'Elektronik-Kiste']);

    expect($location->label)->toBe("Nr. {$location->id} · Elektronik-Kiste");
});

test('a location holding items cannot be deleted', function () {
    $item = Item::factory()->create();

    expect(fn () => $item->storageLocation->delete())->toThrow(QueryException::class);
});

test('deleting a location clears it from imports and users', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->create(['storage_location_id' => $location->id]);
    $user = User::factory()->create();
    $user->forceFill(['last_storage_location_id' => $location->id])->save();

    $location->delete();

    expect($import->fresh()->storage_location_id)->toBeNull()
        ->and($user->fresh()->last_storage_location_id)->toBeNull();
});

test('a location exposes its items and imports', function () {
    $location = StorageLocation::factory()->create();
    $item = Item::factory()->create(['storage_location_id' => $location->id]);
    $import = Import::factory()->create(['storage_location_id' => $location->id]);

    expect($location->items->modelKeys())->toBe([$item->id])
        ->and($location->imports->modelKeys())->toBe([$import->id]);
});
