<?php

namespace App\Ai;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image;
use RuntimeException;

class PhotoAttachments
{
    /**
     * @param  list<string>  $paths  paths on the private `local` disk
     * @return list<Image>
     *
     * @throws RuntimeException when a file is missing
     */
    public static function fromStorage(array $paths): array
    {
        return array_map(function (string $path): Image {
            if (! Storage::disk('local')->exists($path)) {
                throw new RuntimeException("Foto {$path} wurde nicht gefunden.");
            }

            return Image::fromStorage($path, 'local');
        }, $paths);
    }
}
