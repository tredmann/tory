<?php

namespace App\Photos;

use App\Jobs\GenerateThumbnail;
use GdImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class Thumbnails
{
    public const int MAX_PIXELS = 60_000_000;

    private const int SIZE = 400;

    private const string FAILED = 'thumbnail-failed:';

    /**
     * The storage path of the thumbnail when it exists; otherwise queues it and returns null.
     */
    public function for(string $path): ?string
    {
        $thumbnail = PhotoStore::thumbnailPath($path);
        if ($this->exists($thumbnail)) {
            return $thumbnail;
        }

        if (! Cache::has(self::FAILED.$path)) {
            GenerateThumbnail::dispatch($path);
        }

        return null;
    }

    /**
     * Makes the thumbnail unless the original is gone or the thumbnail exists; a failure is reported and remembered.
     */
    public function generate(string $path): void
    {
        $thumbnail = PhotoStore::thumbnailPath($path);
        if (! Storage::disk('local')->exists($path) || $this->exists($thumbnail)) {
            return;
        }

        try {
            $this->make($path, $thumbnail);
        } catch (Throwable $e) {
            report($e);
            self::markFailed($path);
        }
    }

    /**
     * Keeps the path from being queued again, so a photo that cannot be thumbnailed shows the placeholder for good.
     */
    public static function markFailed(string $path): void
    {
        Cache::forever(self::FAILED.$path, true);
    }

    private function exists(string $thumbnail): bool
    {
        return Storage::disk('local')->exists($thumbnail);
    }

    private function make(string $path, string $thumbnail): void
    {
        $disk = Storage::disk('local');

        $dimensions = @getimagesize($disk->path($path));

        if ($dimensions === false || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            throw new RuntimeException("Photo {$path} is unreadable or too large to thumbnail.");
        }

        $contents = $disk->get($path);

        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new RuntimeException("Cannot decode photo {$path}.");
        }

        imagepalettetotruecolor($image);
        $image = $this->orient($this->scale($image), $disk->path($path), $contents);

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $encoded = imagewebp($image, null, 80);
        $webp = (string) ob_get_clean();

        if (! $encoded || $webp === '') {
            throw new RuntimeException("Cannot encode thumbnail for {$path}.");
        }

        $temporary = $thumbnail.'.'.Str::random(8).'.tmp';
        $disk->put($temporary, $webp);
        $disk->move($temporary, $thumbnail);
    }

    private function scale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= self::SIZE) {
            return $image;
        }

        $ratio = self::SIZE / max($width, $height);
        $scaled = imagescale(
            $image,
            (int) max(1, round($width * $ratio)),
            (int) max(1, round($height * $ratio)),
            IMG_TRIANGLE,
        );

        if ($scaled === false) {
            throw new RuntimeException('Cannot scale photo.');
        }

        return $scaled;
    }

    private function orient(GdImage $image, string $file, string $contents): GdImage
    {
        if (! str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $degrees = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            throw new RuntimeException('Cannot rotate photo.');
        }

        return $rotated;
    }
}
