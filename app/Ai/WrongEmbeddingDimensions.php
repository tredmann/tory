<?php

namespace App\Ai;

use RuntimeException;

/**
 * The provider returned a vector that does not fit the 1024-dimension column.
 */
final class WrongEmbeddingDimensions extends RuntimeException
{
    public function __construct(public readonly int $actual)
    {
        parent::__construct('Das Embedding hat '.$actual.' statt '.ItemEmbedder::DIMENSIONS.' Dimensionen.');
    }
}
