<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use App\Models\Import;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreImportRequest extends FormRequest
{
    use ResolvesSpotCode;

    public const int MAX_TOTAL_KILOBYTES = 71680;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.Import::MAX_PHOTOS, $this->totalSizeRule()],
            'photos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:12288'],
            'spot' => $this->spotRules('required'),
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
