<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** A 1024-dimension unit vector pointing along the given axis. */
function axis(int $index): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}
