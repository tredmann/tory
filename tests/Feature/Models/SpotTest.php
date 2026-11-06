<?php

use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a new spot gets a four character code without ambiguous characters', function () {
    $spot = Spot::create(['name' => 'Elektronik-Kiste']);

    expect($spot->code)->toMatch('/^[2-9A-HJ-NP-Z]{4}$/');
});

test('the code cannot be changed', function () {
    $spot = Spot::factory()->create(['code' => '7KQ3']);

    $spot->update(['code' => 'AAAA', 'name' => 'Schrank']);

    expect($spot->fresh())->code->toBe('7KQ3')->name->toBe('Schrank');
});

test('two spots cannot share a code', function () {
    Spot::factory()->create(['code' => '7KQ3']);

    expect(fn () => Spot::factory()->create(['code' => '7KQ3']))->toThrow(UniqueConstraintViolationException::class);
});

test('the label shows code and name', function () {
    $spot = Spot::factory()->create(['name' => 'Elektronik-Kiste']);

    expect($spot->label)->toBe("{$spot->code} · Elektronik-Kiste");
});

test('a spot holding items cannot be deleted', function () {
    $item = Item::factory()->create();

    expect(fn () => $item->spot->delete())->toThrow(QueryException::class);
});

test('deleting a spot clears it from imports and users', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->create(['spot_id' => $spot->id]);
    $user = User::factory()->create();
    $user->forceFill(['last_spot_id' => $spot->id])->save();

    $spot->delete();

    expect($import->fresh()->spot_id)->toBeNull()
        ->and($user->fresh()->last_spot_id)->toBeNull();
});

test('a spot exposes its items and imports', function () {
    $spot = Spot::factory()->create();
    $item = Item::factory()->create(['spot_id' => $spot->id]);
    $import = Import::factory()->create(['spot_id' => $spot->id]);

    expect($spot->items->modelKeys())->toBe([$item->id])
        ->and($spot->imports->modelKeys())->toBe([$import->id]);
});
