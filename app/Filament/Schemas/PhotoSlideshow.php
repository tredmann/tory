<?php

namespace App\Filament\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Tables\Columns\ImageColumn;

class PhotoSlideshow
{
    /**
     * The attributes that let a click on a thumbnail open all photos of its
     * record. Filament merges them unescaped, so the URLs are escaped here.
     *
     * @param  array<string>  $paths
     * @return array<string, string|int>
     */
    public static function imgAttributes(ImageColumn|ImageEntry $component, array $paths, string $clickedPath): array
    {
        $urls = array_filter(array_combine($paths, array_map(fn (string $path): ?string => $component->getImageUrl($path), $paths)));

        $clickedIndex = array_search($clickedPath, array_keys($urls), strict: true);

        if ($clickedIndex === false) {
            return [];
        }

        return [
            'data-slideshow' => e(json_encode(array_values($urls), JSON_THROW_ON_ERROR)),
            'data-slideshow-index' => $clickedIndex,
        ];
    }
}
