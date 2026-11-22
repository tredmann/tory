<?php

namespace App\Jobs;

use App\Ai\Agents\PhotoBoundaryChecker;
use App\Ai\PhotoAttachments;
use App\Ai\ProviderFailure;
use App\BulkImports\BoundaryWindows;
use App\Enums\BulkImportStatus;
use App\Models\AiProvider;
use App\Models\BulkImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Lets the AI decide, window by window, where one thing ends and the next begins, and stores
 * the groups that follow. When its time budget runs out it queues itself to continue at the
 * next undecided pair. 150 s plus one 45 s client call stays under the timeout.
 */
class GroupBulkImport implements ShouldQueue
{
    use Queueable;

    public const int BUDGET_SECONDS = 150;

    public const int CLIENT_TIMEOUT = 45;

    public int $tries = 1;

    public int $timeout = 200;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public BulkImport $bulkImport, public int $nextPair = 0) {}

    public function handle(): void
    {
        if (! $this->stillGrouping()) {
            return;
        }

        $aiProvider = AiProvider::active();

        if ($aiProvider === null) {
            $this->bulkImport->finishGrouping(true, ProviderFailure::noRecognitionProvider());

            return;
        }

        $startedAt = now();
        $paths = array_column($this->bulkImport->photos, 'path');

        try {
            foreach (BoundaryWindows::for(count($paths)) as $window) {
                if ($window->firstPair < $this->nextPair) {
                    continue;
                }

                if (! $this->stillGrouping()) {
                    return;
                }

                if ($startedAt->diffInSeconds(now()) >= self::BUDGET_SECONDS) {
                    self::dispatch($this->bulkImport, $window->firstPair);

                    return;
                }

                $attachments = PhotoAttachments::fromStorage(
                    array_slice($paths, $window->firstPhoto, $window->lastPhoto - $window->firstPhoto + 1),
                );

                $answer = new PhotoBoundaryChecker($aiProvider)->check($attachments, self::CLIENT_TIMEOUT);

                $this->bulkImport->storeCuts($window->firstPair, array_slice(
                    $answer,
                    $window->firstPair - $window->firstPhoto,
                    $window->lastPair - $window->firstPair + 1,
                ));
            }
        } catch (Throwable $e) {
            if (! $this->stillGrouping()) {
                return;
            }

            $failure = ProviderFailure::from($e, $aiProvider);

            report($failure);

            $this->bulkImport->finishGrouping(true, $failure);

            return;
        }

        $this->bulkImport->finishGrouping(false);
    }

    /**
     * Keeps the bulk import reviewable with the cuts decided so far, e.g. when the worker killed the job.
     */
    public function failed(?Throwable $exception): void
    {
        $this->bulkImport->finishGrouping(true);
    }

    /**
     * @phpstan-impure
     */
    private function stillGrouping(): bool
    {
        return BulkImport::query()
            ->whereKey($this->bulkImport->getKey())
            ->where('status', BulkImportStatus::Grouping)
            ->exists();
    }
}
