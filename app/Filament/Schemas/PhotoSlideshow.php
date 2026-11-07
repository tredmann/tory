<?php

namespace App\Filament\Schemas;

use App\Photos\PhotoLinks;

class PhotoSlideshow
{
    /**
     * The attributes that let a click on a thumbnail open all photos of its
     * record. Filament merges them unescaped, so the URLs are escaped here.
     *
     * @param  array<string>  $paths
     * @return array<string, string|int>
     */
    public static function imgAttributes(array $paths, string $clickedUrl): array
    {
        $existing = PhotoLinks::existing($paths);

        $clickedIndex = array_search($clickedUrl, array_map(PhotoLinks::thumbnail(...), $existing), strict: true);

        if ($clickedIndex === false) {
            return [];
        }

        return [
            'data-slideshow' => e(json_encode(array_map(PhotoLinks::original(...), $existing), JSON_THROW_ON_ERROR)),
            'data-slideshow-index' => $clickedIndex,
        ];
    }
}
