<?php

use App\Jobs\GenerateThumbnail;
use App\Photos\Thumbnails;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('makes the thumbnail', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(800, 600));

    GenerateThumbnail::dispatch('photos/2026/11/a.jpg');

    Storage::disk('local')->assertExists('thumbs/photos/2026/11/a.jpg.webp');
});

it('marks the path as failed when the job fails', function () {
    Queue::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    new GenerateThumbnail('imports/a.jpg')->failed(new RuntimeException('killed'));

    expect((new Thumbnails)->for('imports/a.jpg'))->toBeNull();
    Queue::assertNothingPushed();
});

it('runs once, for 60 seconds at most, on the thumbnails queue', function () {
    $job = new GenerateThumbnail('imports/a.jpg');

    expect([$job->tries, $job->timeout, $job->queue, $job->uniqueId()])->toBe([1, 60, 'thumbnails', 'imports/a.jpg']);
});
