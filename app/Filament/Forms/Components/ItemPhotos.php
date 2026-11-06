<?php

namespace App\Filament\Forms\Components;

use App\Models\Item;
use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Edits the photos of an item. State: the overview photo (a path or an upload key),
 * the marked photos (paths and upload keys) and the new files keyed by a client-made key.
 */
class ItemPhotos extends Field
{
    protected string $view = 'filament.forms.components.item-photos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Fotos');
        $this->columnSpanFull();

        $this->formatStateUsing(function (?Item $record): array {
            /** @var list<string> $photos */
            $photos = $record->photos ?? [];

            return ['overview' => $photos[0] ?? null, 'removed' => [], 'uploads' => []];
        });

        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            foreach (self::keptUploads($value) as $file) {
                $valid = Validator::make(
                    ['file' => $file],
                    ['file' => ['image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:12288']],
                )->passes();

                if (! $valid) {
                    $fail('Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.');

                    return;
                }
            }
        });
    }

    /**
     * The new files of a state that are not marked for removal. The server checks and stores exactly these.
     * Keys are compared as strings, because PHP turns a numeric array key into an integer.
     *
     * @return array<string, UploadedFile>
     */
    public static function keptUploads(mixed $state): array
    {
        $uploads = is_array($state) && is_array($state['uploads'] ?? null) ? $state['uploads'] : [];
        $removed = is_array($state) && is_array($state['removed'] ?? null) ? $state['removed'] : [];
        $removedKeys = array_map(strval(...), array_filter($removed, is_scalar(...)));

        return array_filter(
            $uploads,
            fn (mixed $file, int|string $key): bool => $file instanceof UploadedFile && ! in_array((string) $key, $removedKeys, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The photos stored on the item, with links that stay the same within the hour.
     *
     * @return list<array{path: string, url: string}>
     */
    public function getPhotos(): array
    {
        $record = $this->getRecord();
        $photos = $record instanceof Item ? $record->photos : [];

        return array_map(fn (string $path): array => [
            'path' => $path,
            'url' => Storage::disk('local')->temporaryUrl($path, now()->addMinutes(30)->endOfHour()),
        ], array_values($photos));
    }
}
