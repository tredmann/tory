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
        $links = PhotoLinks::forRecord($paths);

        $clickedIndex = array_search($clickedUrl, $links['thumbnails'], strict: true);

        if ($clickedIndex === false) {
            return [];
        }

        return [
            'data-slideshow' => e(json_encode($links['originals'], JSON_THROW_ON_ERROR)),
            'data-slideshow-index' => $clickedIndex,
        ];
    }
}
