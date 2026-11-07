<?php

namespace App\Photos;

use Illuminate\Support\Facades\Storage;

final class PhotoLinks
{
    public static function original(string $path): string
    {
        return route('filament.admin.photos.original', ['path' => PhotoStore::routePath($path)]);
    }

    public static function thumbnail(string $path): string
    {
        return route('filament.admin.photos.thumbnail', ['path' => PhotoStore::routePath($path)]);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public static function existing(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => Storage::disk('local')->exists($path)));
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public static function thumbnails(array $paths): array
    {
        return self::forRecord($paths)['thumbnails'];
    }

    /**
     * The existing photos of one record with their URLs, checked and built once per request.
     *
     * @param  array<int, string>  $paths
     * @return array{thumbnails: array<int, string>, originals: array<int, string>}
     */
    public static function forRecord(array $paths): array
    {
        return once(function () use ($paths): array {
            $existing = self::existing($paths);

            return [
                'thumbnails' => array_map(self::thumbnail(...), $existing),
                'originals' => array_map(self::original(...), $existing),
            ];
        });
    }
}
