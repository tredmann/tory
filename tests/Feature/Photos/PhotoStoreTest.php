<?php

use App\Photos\PhotoStore;

it('stores into the month folder', function () {
    $this->travelTo('2026-11-07 12:00');
    expect(PhotoStore::directory())->toBe('photos/2026/11');
});

it('names the thumbnail after the original', function () {
    expect(PhotoStore::thumbnailPath('photos/2026/11/abc.jpg'))->toBe('thumbs/photos/2026/11/abc.jpg.webp')
        ->and(PhotoStore::thumbnailPath('imports/abc.png'))->toBe('thumbs/imports/abc.png.webp');
});

it('keeps the original extension so two files of one name stay apart', function () {
    expect(PhotoStore::thumbnailPath('imports/a.jpg'))->not->toBe(PhotoStore::thumbnailPath('imports/a.png'));
});

it('maps storage paths to route paths and back', function (string $storage, string $route) {
    expect(PhotoStore::routePath($storage))->toBe($route)
        ->and(PhotoStore::storagePath($route))->toBe($storage);
})->with([
    ['photos/2026/11/abc.jpg', '2026/11/abc.jpg'],
    ['imports/abc.jpg', 'imports/abc.jpg'],
]);

it('refuses route paths outside the photo folders', function (string $route) {
    expect(PhotoStore::storagePath($route))->toBeNull();
})->with(['../.env', 'imports/..', 'imports/.', 'imports/a/b.jpg', 'evals/x.json', 'livewire-tmp/x.jpg', 'thumb/imports/a.jpg', '2026/1/a.jpg', '', 'imports/..\\evals\\x.json', "imports/a\x01b.jpg", "imports/a.jpg\n", "imports/\xff.jpg"]);
