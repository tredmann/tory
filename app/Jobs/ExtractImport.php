<?php

namespace App\Jobs;

use App\Ai\Agents\InventoryExtractor;
use App\Ai\PhotoAttachments;
use App\Ai\ProviderFailure;
use App\Enums\ImportStatus;
use App\Models\AiProvider;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the inventory extractor on an import's photos and stores the result.
 */
class ExtractImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public Import $import) {}

    public function handle(): void
    {
        $aiProvider = null;

        try {
            $this->import->update(['status' => ImportStatus::Running]);

            /** @var list<string> $photos */
            $photos = $this->import->photos;

            $attachments = PhotoAttachments::fromStorage($photos);

            $aiProvider = AiProvider::active();

            $result = InventoryExtractor::make(aiProvider: $aiProvider)->extract($attachments);

            $this->import->update([
                'title' => $result->title,
                'description' => $result->description,
                'search_terms' => $result->searchTerms,
                'error' => null,
                'failure_reason' => null,
                'failure_provider' => null,
                'status' => ImportStatus::Completed,
            ]);
        } catch (Throwable $e) {
            if ($aiProvider !== null) {
                $e = ProviderFailure::from($e, $aiProvider);
            }

            report($e);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update([
            'status' => ImportStatus::Failed,
            'title' => null,
            'description' => null,
            'search_terms' => [],
            'error' => $exception?->getMessage() ?: 'Der Auftrag wurde abgebrochen.',
            'failure_reason' => $exception instanceof ProviderFailure ? $exception->reason : null,
            'failure_provider' => $exception instanceof ProviderFailure && $exception->reason !== null ? $exception->providerName : null,
        ]);
    }
}
