<?php

namespace App\Filament\Forms\Components;

use App\BulkImports\StagedPhoto;
use App\Models\BulkImport;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Filament\Forms\Components\Field;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Collects the photos of a bulk import. State: the files keyed by a client-made key and, under
 * the same key, the original file name and the capture time the browser read before shrinking.
 */
class BulkPhotos extends Field
{
    protected string $view = 'filament.forms.components.bulk-photos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Fotos');
        $this->columnSpanFull();
        $this->default(['uploads' => [], 'meta' => []]);

        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $files = array_map(fn (StagedPhoto $photo): UploadedFile => $photo->file, self::stagedPhotos($value));

            if ($files === []) {
                $fail(__('At least one photo.'));

                return;
            }

            if (count($files) > BulkImport::MAX_PHOTOS) {
                $fail(__('At most :max photos per bulk import.', ['max' => BulkImport::MAX_PHOTOS]));

                return;
            }

            foreach ($files as $file) {
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
     * The uploaded files of a state with their names and capture times. A file counts only while
     * its key is in `meta`, which the browser removes on ✕, so an upload that finished after its
     * cancellation stays unused. Anything in the uploads that is no uploaded file is left out;
     * a missing name falls back to the file's own name.
     *
     * @return list<StagedPhoto>
     */
    public static function stagedPhotos(mixed $state): array
    {
        $uploads = is_array($state) && is_array($state['uploads'] ?? null) ? $state['uploads'] : [];
        $meta = is_array($state) && is_array($state['meta'] ?? null) ? $state['meta'] : [];
        $photos = [];

        foreach ($uploads as $key => $file) {
            if (! $file instanceof UploadedFile || ! is_array($meta[$key] ?? null)) {
                continue;
            }

            $fileMeta = $meta[$key];
            $name = is_string($fileMeta['name'] ?? null) && $fileMeta['name'] !== ''
                ? $fileMeta['name']
                : $file->getClientOriginalName();

            $photos[] = new StagedPhoto($file, Str::substr($name, 0, 255), self::parseTakenAt($fileMeta['taken_at'] ?? null));
        }

        return $photos;
    }

    /**
     * Accepts only the ISO 8601 form of the browser's toISOString(); anything else counts as no time.
     */
    private static function parseTakenAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.vP', $value);

        if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }
}
