<?php

use App\Photos\Thumbnails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('makes a 400 px webp thumbnail and stores it', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(2048, 1536));

    $path = (new Thumbnails(0))->for('photos/2026/11/a.jpg');

    expect($path)->toBe('thumbs/photos/2026/11/a.webp');
    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height, $type])->toBe([400, 300, IMAGETYPE_WEBP]);
});

it('does not upscale a small photo', function () {
    Storage::disk('local')->put('imports/small.png', png(200, 100));

    $path = (new Thumbnails(0))->for('imports/small.png');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([200, 100]);
});

it('keeps the stored thumbnail on the second call', function () {
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    Storage::disk('local')->put('thumbs/imports/a.webp', 'marker');

    $path = (new Thumbnails(0))->for('imports/a.jpg');

    expect($path)->toBe('thumbs/imports/a.webp');
    expect(Storage::disk('local')->get($path))->toBe('marker');
});

it('applies the exif orientation', function () {
    Storage::disk('local')->put('imports/r.jpg', jpegWithOrientationSix(400, 200));

    $path = (new Thumbnails(0))->for('imports/r.jpg');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([200, 400]);
});

it('uses the first frame of a gif', function () {
    Storage::disk('local')->put('imports/g.gif', gif(300, 200));

    $path = (new Thumbnails(0))->for('imports/g.gif');

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height, $type])->toBe([300, 200, IMAGETYPE_WEBP]);
});

it('handles a 24 megapixel photo', function () {
    Storage::disk('local')->put('imports/big.jpg', jpeg(6000, 4000));

    $path = (new Thumbnails(0))->for('imports/big.jpg');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([400, 267]);
});

it('returns null and reports a corrupt photo', function () {
    Exceptions::fake();
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');

    expect((new Thumbnails(0))->for('imports/broken.jpg'))->toBeNull();

    Exceptions::assertReportedCount(1);
    Storage::disk('local')->assertMissing('thumbs/imports/broken.webp');
});

it('returns null without reporting while the lock is held', function () {
    Exceptions::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    $lock = Cache::lock(Thumbnails::LOCK, 60);
    $lock->get();

    expect((new Thumbnails(0))->for('imports/a.jpg'))->toBeNull();

    Exceptions::assertNothingReported();
    $lock->release();
});

it('leaves no temporary file behind', function () {
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    (new Thumbnails(0))->for('imports/a.jpg');

    expect(Storage::disk('local')->allFiles('thumbs'))->toBe(['thumbs/imports/a.webp']);
});
