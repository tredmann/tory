<?php

namespace App\Filament\Schemas;

use App\Photos\PhotoLinks;

class PhotoSlideshow
{
    /**
     * The attributes that let a click on a thumbnail open all photos of its
     * record. Filament merges them unescaped, so the URLs are escaped here.
     * A focusable thumbnail also opens it by keyboard; a list leaves that to
     * its row link, which already leads to the detail page.
     *
     * @param  array<string>  $paths
     * @return array<string, string|int>
     */
    public static function imgAttributes(array $paths, string $clickedUrl, bool $focusable = false): array
    {
        $links = PhotoLinks::forRecord($paths);

        $clickedIndex = array_search($clickedUrl, $links['thumbnails'], strict: true);

        if ($clickedIndex === false) {
            return [];
        }

        $attributes = [
            'data-slideshow' => e(json_encode($links['originals'], JSON_THROW_ON_ERROR)),
            'data-slideshow-index' => $clickedIndex,
        ];

        if (! $focusable) {
            return $attributes;
        }

        return [
            ...$attributes,
            'role' => 'button',
            'tabindex' => 0,
            'aria-label' => e(__('Show photo :number of :total large', ['number' => $clickedIndex + 1, 'total' => count($links['originals'])])),
        ];
    }
}
