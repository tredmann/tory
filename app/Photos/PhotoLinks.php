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
}
