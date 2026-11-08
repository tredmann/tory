<?php

namespace App\Photos;

use App\Jobs\GenerateThumbnail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class PhotoStore
{
    private const ROUTE_PATTERN = '#^(imports/[^/\\\\\p{C}]+|photos/\d{4}/\d{2}/[^/\\\\\p{C}]+)$#uD';

    public static function directory(): string
    {
        return 'photos/'.now()->format('Y/m');
    }

    /**
     * Stores the photo and queues its thumbnail.
     */
    public static function store(UploadedFile $file): string|false
    {
        $path = $file->store(self::directory(), 'local');

        if ($path !== false) {
            GenerateThumbnail::dispatch($path);
        }

        return $path;
    }

    public static function thumbnailPath(string $path): string
    {
        return 'thumbs/'.pathinfo($path, PATHINFO_DIRNAME).'/'.basename($path).'.webp';
    }

    /**
     * Deletes the originals and their thumbnails; a missing thumbnail is no failure.
     *
     * @param  array<int, string>  $paths
     */
    public static function delete(array $paths): bool
    {
        $files = [];
        foreach ($paths as $path) {
            $files[] = $path;
            $files[] = self::thumbnailPath($path);
        }

        return Storage::disk('local')->delete($files);
    }

    public static function isUploadPath(string $path): bool
    {
        return (bool) preg_match('#^photos/\d{4}/\d{2}/[^/\\\\\p{C}]+$#uD', $path)
            && ! in_array(basename($path), ['.', '..'], true);
    }

    public static function routePath(string $path): string
    {
        return str_starts_with($path, 'photos/') ? substr($path, strlen('photos/')) : $path;
    }

    public static function storagePath(string $routePath): ?string
    {
        $path = str_starts_with($routePath, 'imports/') ? $routePath : 'photos/'.$routePath;

        if (! preg_match(self::ROUTE_PATTERN, $path) || in_array(basename($path), ['.', '..'], true)) {
            return null;
        }

        return $path;
    }
}
