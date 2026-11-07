<?php

namespace App\Jobs;

use App\Models\BulkImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GroupBulkImport implements ShouldQueue
{
    use Queueable;

    public function __construct(public BulkImport $bulkImport, public int $nextPair = 0) {}

    public function handle(): void
    {
        //
    }
}
