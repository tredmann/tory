<?php

namespace App\BulkImports;

final class BoundaryWindows
{
    public const int SIZE = 8;

    /**
     * Splits the neighbouring pairs of a photo sequence into windows so that every pair is decided once,
     * with two photos of context on each side where the sequence allows.
     *
     * @return list<BoundaryWindow>
     */
    public static function for(int $photoCount): array
    {
        $pairs = $photoCount - 1;
        $windows = [];
        $next = 0;

        while ($next < $pairs) {
            $first = max(0, $next - 1);
            $last = min($first + self::SIZE - 1, $photoCount - 1);
            $lastPair = $last === $photoCount - 1 ? $pairs - 1 : $last - 2;
            $windows[] = new BoundaryWindow($first, $last, $next, $lastPair);
            $next = $lastPair + 1;
        }

        return $windows;
    }
}
