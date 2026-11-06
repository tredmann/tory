<?php

use App\Models\AiProvider;
use App\Models\Import;
use App\Models\Item;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('new records get a uuid as primary key', function (string $model) {
    $record = $model::factory()->create();

    expect(Str::isUuid($record->getKey()))->toBeTrue()
        ->and($record->fresh()->getKey())->toBe($record->getKey());
})->with([
    Item::class,
    Import::class,
    AiProvider::class,
    User::class,
]);

test('storage locations keep their internal numeric key', function () {
    expect(StorageLocation::factory()->create()->getKey())->toBeInt();
});

test('sessions can reference a user by uuid', function () {
    expect(Schema::getColumnType('sessions', 'user_id'))->toBe('uuid');
});
