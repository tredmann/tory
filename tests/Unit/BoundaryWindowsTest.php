<?php

use App\BulkImports\BoundaryWindow;
use App\BulkImports\BoundaryWindows;

test('fewer than two photos need no window', function (int $count) {
    expect(BoundaryWindows::for($count))->toBe([]);
})->with([0, 1]);

test('up to eight photos fit one window deciding every pair', function () {
    expect(BoundaryWindows::for(8))->toEqual([new BoundaryWindow(0, 7, 0, 6)]);
});

test('every pair is decided by exactly one window', function () {
    foreach (range(2, 100) as $count) {
        $decided = [];

        foreach (BoundaryWindows::for($count) as $window) {
            expect($window->firstPair)->toBeGreaterThanOrEqual($window->firstPhoto)
                ->and($window->lastPair)->toBeLessThan($window->lastPhoto);

            array_push($decided, ...range($window->firstPair, $window->lastPair));
        }

        expect($decided)->toBe(range(0, $count - 2));
    }
});

test('no window holds more than eight photos', function () {
    foreach (range(2, 100) as $count) {
        foreach (BoundaryWindows::for($count) as $window) {
            expect($window->lastPhoto - $window->firstPhoto + 1)->toBeLessThanOrEqual(BoundaryWindows::SIZE);
        }
    }
});

test('a hundred photos need at most twenty windows', function () {
    expect(count(BoundaryWindows::for(100)))->toBeLessThanOrEqual(20);
});
