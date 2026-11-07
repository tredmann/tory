<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    use ResolvesSpotCode;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:12288'],
            'spot' => $this->spotRules('required'),
        ];
    }
}
