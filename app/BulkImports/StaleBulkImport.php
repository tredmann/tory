<?php

namespace App\BulkImports;

use RuntimeException;

/**
 * An edit named a state the bulk import no longer has, e.g. from a tab that was open too long.
 */
class StaleBulkImport extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Gruppen wurden inzwischen geändert.');
    }
}
