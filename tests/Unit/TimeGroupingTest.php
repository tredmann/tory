<?php

use App\BulkImports\BulkPhoto;
use App\BulkImports\TimeGrouping;
use Carbon\CarbonImmutable;

function bulkPhoto(string $name, ?string $time): BulkPhoto
{
    return new BulkPhoto("imports/{$name}", $time === null ? null : new CarbonImmutable($time), $name);
}

test('photos sort by capture time, then by name', function () {
    $sorted = TimeGrouping::sort([
        bulkPhoto('c.jpg', '2026-01-01 10:00:05'),
        bulkPhoto('b.jpg', '2026-01-01 10:00:00'),
        bulkPhoto('a.jpg', '2026-01-01 10:00:00'),
    ]);

    expect(array_map(fn (BulkPhoto $p) => $p->name, $sorted))->toBe(['a.jpg', 'b.jpg', 'c.jpg']);
});

test('photos without a time come after timed photos, sorted by name', function () {
    $sorted = TimeGrouping::sort([
        bulkPhoto('z.jpg', null),
        bulkPhoto('m.jpg', '2026-01-01 10:00:00'),
        bulkPhoto('a.jpg', null),
    ]);

    expect(array_map(fn (BulkPhoto $p) => $p->name, $sorted))->toBe(['m.jpg', 'a.jpg', 'z.jpg']);
});

test('a gap of more than 30 seconds cuts, exactly 30 does not', function () {
    $cuts = TimeGrouping::cuts([
        bulkPhoto('a.jpg', '2026-01-01 10:00:00'),
        bulkPhoto('b.jpg', '2026-01-01 10:00:30'),
        bulkPhoto('c.jpg', '2026-01-01 10:01:01'),
    ]);

    expect($cuts)->toBe([false, true]);
});

test('no cut is made next to a photo without a time', function () {
    expect(TimeGrouping::cuts([
        bulkPhoto('a.jpg', '2026-01-01 10:00:00'),
        bulkPhoto('b.jpg', null),
    ]))->toBe([false]);
});

test('a single photo has no cuts', function () {
    expect(TimeGrouping::cuts([bulkPhoto('a.jpg', null)]))->toBe([]);
});
