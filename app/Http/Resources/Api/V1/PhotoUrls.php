<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Database\Eloquent\Model;

final class PhotoUrls
{
    /**
     * @return list<string>
     */
    public static function for(string $routeName, Model $record): array
    {
        return array_values(array_map(
            fn (string $path): string => route($routeName, [$record, basename($path)]),
            $record->photos ?? [],
        ));
    }
}
