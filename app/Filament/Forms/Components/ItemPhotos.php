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
 * the removed paths and the new files keyed by a client-made key.
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
            $uploads = is_array($value) && is_array($value['uploads'] ?? null) ? $value['uploads'] : [];

            foreach ($uploads as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

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
