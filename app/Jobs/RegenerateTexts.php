<?php

namespace App\Jobs;

use App\Ai\Agents\InventoryExtractor;
use App\Ai\PhotoAttachments;
use App\Ai\ProviderFailure;
use App\Enums\ProviderFailureReason;
use App\Models\AiProvider;
use App\Models\HouseholdSettings;
use App\Models\Import;
use App\Models\Item;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replaces title, description and search terms of an item or completed import
 * with fresh suggestions for its stored photos. A failure keeps the old texts.
 */
class RegenerateTexts implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Import|Item $record) {}

    public function handle(): void
    {
        $aiProvider = null;

        try {
            $aiProvider = AiProvider::active() ?? throw ProviderFailure::noRecognitionProvider();

            /** @var list<string> $photos */
            $photos = $this->record->photos ?? [];

            $attachments = PhotoAttachments::fromStorage($photos);

            $result = InventoryExtractor::make(
                aiProvider: $aiProvider,
                language: HouseholdSettings::current()->content_language,
            )->extract($attachments);
        } catch (Throwable $e) {
            if ($aiProvider !== null) {
                $e = ProviderFailure::from($e, $aiProvider);
            }

            if (! $e instanceof ProviderFailure || $e->reason !== ProviderFailureReason::NoProvider) {
                report($e);
            }

            throw $e;
        }

        DB::transaction(function () use ($result): void {
            $record = $this->record::query()->lockForUpdate()->whereKey($this->record->getKey())->first();

            if ($record === null) {
                return;
            }

            $record->update([
                'title' => $result->title,
                'description' => $result->description,
                'search_terms' => $result->searchTerms,
            ]);

            $record->forceFill([
                'regenerating_at' => null,
                'regeneration_error' => null,
                'regeneration_failure_reason' => null,
                'regeneration_failure_provider' => null,
            ])->saveQuietly();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->record::query()->whereKey($this->record->getKey())->update([
            'regenerating_at' => null,
            'regeneration_error' => $exception?->getMessage() ?: 'Der Auftrag wurde abgebrochen.',
            'regeneration_failure_reason' => $exception instanceof ProviderFailure ? $exception->reason?->value : null,
            'regeneration_failure_provider' => $exception instanceof ProviderFailure && $exception->reason !== null && $exception->reason !== ProviderFailureReason::NoProvider ? $exception->providerName : null,
        ]);
    }
}
