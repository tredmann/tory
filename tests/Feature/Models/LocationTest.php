<?php

use App\Models\Spot;
use Illuminate\Database\QueryException;

test('a location with spots cannot be deleted', function () {
    $spot = Spot::factory()->create();

    expect(fn () => $spot->location->delete())->toThrow(QueryException::class);
});
