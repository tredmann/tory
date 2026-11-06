<?php

namespace App\Filament\Resources\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a record key that is no positive bigint resolve to no record, so the
 * page answers 404 instead of Postgres failing on the cast.
 */
trait ResolvesNumericRecordKeys
{
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $key = (string) $key;

        $isBigint = preg_match('/^[1-9][0-9]*$/', $key) === 1
            && (strlen($key) < 19 || (strlen($key) === 19 && $key <= '9223372036854775807'));

        if (! $isBigint) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }
}
