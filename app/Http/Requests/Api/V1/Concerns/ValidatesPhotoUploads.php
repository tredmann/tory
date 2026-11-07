<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Import;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * For Form Requests that take the photos of an import or item as `photos[]`.
 */
trait ValidatesPhotoUploads
{
    public const int MAX_TOTAL_KILOBYTES = 71680;

    /**
     * @return array<string, mixed>
     */
    protected function photoRules(): array
    {
        return [
            /**
             * 1 to 20 photos (JPEG, PNG, WebP or GIF), at most 12 MB each and 70 MB together. The first one becomes the overview photo.
             */
            'photos' => ['required', 'array', 'min:1', 'max:'.Import::MAX_PHOTOS, $this->totalSizeRule()],
            'photos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:12288'],
        ];
    }

    private function totalSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $bytes = collect(is_array($value) ? $value : [])
                ->sum(fn (mixed $file): int => $file instanceof UploadedFile ? (int) $file->getSize() : 0);

            if ($bytes > self::MAX_TOTAL_KILOBYTES * 1024) {
                $fail('Alle Fotos zusammen dürfen höchstens 70 MB groß sein.');
            }
        };
    }
}
