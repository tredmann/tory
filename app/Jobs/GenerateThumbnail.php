<?php

namespace App\Jobs;

use App\Photos\Thumbnails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes the thumbnail of one photo, queued once per path on the thumbnails queue.
 */
class GenerateThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $path)
    {
        $this->onQueue('thumbnails');
    }

    public function uniqueId(): string
    {
        return $this->path;
    }

    public function handle(Thumbnails $thumbnails): void
    {
        $thumbnails->generate($this->path);
    }

    /**
     * A job killed by its timeout or the memory limit never reaches the catch in generate().
     */
    public function failed(?Throwable $exception): void
    {
        Thumbnails::markFailed($this->path);
    }
}
