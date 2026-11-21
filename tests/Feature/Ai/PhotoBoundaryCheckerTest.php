<?php

use App\Ai\Agents\PhotoBoundaryChecker;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;

function boundaryImages(int $count): array
{
    return array_map(fn (int $i): Base64Image => Image::fromBase64(base64_encode("p{$i}"), 'image/png'), range(1, $count));
}

test('returns the boundaries the model answered', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [false, true]]]);

    expect((new PhotoBoundaryChecker)->check(boundaryImages(3), 60))->toBe([false, true]);

    PhotoBoundaryChecker::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 3);
});

test('an answer of the wrong length is incomplete', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [true]]]);

    (new PhotoBoundaryChecker)->check(boundaryImages(3), 60);
})->throws(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');

test('non-boolean entries are incomplete', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [true, 'ja']]]);

    (new PhotoBoundaryChecker)->check(boundaryImages(3), 60);
})->throws(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');
