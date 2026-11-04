<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Smoke-test job: dispatch it and look for "queue ok" in the log to confirm
 * the supervised queue worker is processing jobs.
 */
class PingLog implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info(sprintf('queue ok (host %s, pid %d)', gethostname(), getmypid()));
    }
}
