<?php

use App\Jobs\GenerateThumbnail;
use App\Photos\Thumbnails;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('makes a 400 px webp thumbnail and stores it', function (): void {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(2048, 1536));

    (new Thumbnails)->generate('photos/2026/11/a.jpg');

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get('thumbs/photos/2026/11/a.jpg.webp'));
    expect([$width, $height, $type])->toBe([400, 300, IMAGETYPE_WEBP]);
});

it('does not upscale a small photo', function (): void {
    Storage::disk('local')->put('imports/small.png', png(200, 100));

    (new Thumbnails)->generate('imports/small.png');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get('thumbs/imports/small.png.webp'));
    expect([$width, $height])->toBe([200, 100]);
});

it('keeps a stored thumbnail', function (): void {
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    Storage::disk('local')->put('thumbs/imports/a.jpg.webp', 'marker');

    (new Thumbnails)->generate('imports/a.jpg');

    expect(Storage::disk('local')->get('thumbs/imports/a.jpg.webp'))->toBe('marker');
});

it('applies the exif orientation', function (): void {
    Storage::disk('local')->put('imports/r.jpg', jpegWithOrientationSix(400, 200));

    (new Thumbnails)->generate('imports/r.jpg');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get('thumbs/imports/r.jpg.webp'));
    expect([$width, $height])->toBe([200, 400]);
});

it('uses the first frame of a gif', function (): void {
    Storage::disk('local')->put('imports/g.gif', gif(300, 200));

    (new Thumbnails)->generate('imports/g.gif');

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get('thumbs/imports/g.gif.webp'));
    expect([$width, $height, $type])->toBe([300, 200, IMAGETYPE_WEBP]);
});

it('handles a 24 megapixel photo', function (): void {
    Storage::disk('local')->put('imports/big.jpg', jpeg(6000, 4000));

    (new Thumbnails)->generate('imports/big.jpg');

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get('thumbs/imports/big.jpg.webp'));
    expect([$width, $height])->toBe([400, 267]);
});

it('reports a corrupt photo once and marks it as failed', function (): void {
    Exceptions::fake();
    Queue::fake();
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');

    (new Thumbnails)->generate('imports/broken.jpg');
    expect((new Thumbnails)->for('imports/broken.jpg'))->toBeNull();

    Exceptions::assertReportedCount(1);
    Storage::disk('local')->assertMissing('thumbs/imports/broken.jpg.webp');
    Queue::assertNotPushed(GenerateThumbnail::class);
});

it('leaves no temporary file behind', function (): void {
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    (new Thumbnails)->generate('imports/a.jpg');

    expect(Storage::disk('local')->allFiles('thumbs'))->toBe(['thumbs/imports/a.jpg.webp']);
});

it('refuses a photo above the pixel limit without decoding it', function (): void {
    Exceptions::fake();
    $image = imagecreate(8000, 8000);
    imagecolorallocate($image, 255, 255, 255);
    ob_start();
    imagepng($image, null, 9);
    $png = (string) ob_get_clean();
    Storage::disk('local')->put('imports/huge.png', $png);

    (new Thumbnails)->generate('imports/huge.png');

    Exceptions::assertReportedCount(1);
    Storage::disk('local')->assertMissing('thumbs/imports/huge.png.webp');
});

it('does nothing when the original is gone', function (): void {
    Exceptions::fake();

    (new Thumbnails)->generate('imports/gone.jpg');

    Exceptions::assertNothingReported();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('returns an existing thumbnail without queuing', function (): void {
    Queue::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    Storage::disk('local')->put('thumbs/imports/a.jpg.webp', 'marker');

    expect((new Thumbnails)->for('imports/a.jpg'))->toBe('thumbs/imports/a.jpg.webp');
    Queue::assertNothingPushed();
});

it('queues a missing thumbnail once without generating it', function (): void {
    Queue::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    expect((new Thumbnails)->for('imports/a.jpg'))->toBeNull()
        ->and((new Thumbnails)->for('imports/a.jpg'))->toBeNull();

    Queue::assertPushedOn('thumbnails', GenerateThumbnail::class, fn (GenerateThumbnail $job): bool => $job->path === 'imports/a.jpg');
    Queue::assertPushedTimes(GenerateThumbnail::class, 1);
    Storage::disk('local')->assertMissing('thumbs/imports/a.jpg.webp');
});
