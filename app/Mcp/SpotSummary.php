<?php

namespace App\Mcp;

use App\Models\Spot;

final class SpotSummary
{
    /**
     * @return array{code: string, name: string, note: ?string, location: string}
     */
    public static function of(Spot $spot): array
    {
        return [
            'code' => $spot->code,
            'name' => $spot->name,
            'note' => $spot->note,
            'location' => $spot->location->name,
        ];
    }
}
