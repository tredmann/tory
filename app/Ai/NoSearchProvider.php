<?php

namespace App\Ai;

use RuntimeException;

/**
 * No AI provider is active for search, so nothing can be embedded.
 */
final class NoSearchProvider extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No AI provider is active for search.');
    }
}
