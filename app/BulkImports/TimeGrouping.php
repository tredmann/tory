<?php

namespace App\BulkImports;

final class TimeGrouping
{
    public const int GAP_SECONDS = 30;

    /**
     * Orders photos by capture time, then by name; photos without a time come last.
     *
     * @param  list<BulkPhoto>  $photos
     * @return list<BulkPhoto>
     */
    public static function sort(array $photos): array
    {
        usort($photos, function (BulkPhoto $a, BulkPhoto $b): int {
            if ($a->takenAt !== null && $b->takenAt !== null) {
                $byTime = $a->takenAt->getTimestamp() <=> $b->takenAt->getTimestamp();

                if ($byTime !== 0) {
                    return $byTime;
                }
            }

            return [$a->takenAt === null, $a->name] <=> [$b->takenAt === null, $b->name];
        });

        return $photos;
    }

    /**
     * One entry per neighbouring pair: true where a gap longer than GAP_SECONDS
     * separates two timed photos.
     *
     * @param  list<BulkPhoto>  $photos
     * @return list<bool>
     */
    public static function cuts(array $photos): array
    {
        $cuts = [];

        for ($i = 1; $i < count($photos); $i++) {
            $before = $photos[$i - 1]->takenAt;
            $after = $photos[$i]->takenAt;

            $cuts[] = $before !== null && $after !== null
                && $after->getTimestamp() - $before->getTimestamp() > self::GAP_SECONDS;
        }

        return $cuts;
    }
}
