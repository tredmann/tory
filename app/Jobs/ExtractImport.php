<?php

namespace App\Jobs;

use App\Ai\Agents\InventoryExtractor;
use App\Enums\ImportStatus;
use App\Models\AiProvider;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image;
use RuntimeException;
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
        try {
            $this->import->update(['status' => ImportStatus::Running]);

            /** @var list<string> $photos */
            $photos = $this->import->photos;

            $attachments = array_map(function (string $path): Image {
                if (! Storage::disk('local')->exists($path)) {
                    throw new RuntimeException("Foto {$path} wurde nicht gefunden.");
                }

                return Image::fromStorage($path, 'local');
            }, $photos);

            $result = InventoryExtractor::make(aiProvider: AiProvider::active())->extract($attachments);

            $this->import->update([
                'title' => $result['title'],
                'description' => $result['description'],
                'error' => null,
                'status' => ImportStatus::Completed,
            ]);
        } catch (Throwable $e) {
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
            'error' => $exception?->getMessage() ?: 'Der Auftrag wurde abgebrochen.',
        ]);
    }
}
