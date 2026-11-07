<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use App\Http\Requests\Api\V1\Concerns\ValidatesPhotoUploads;
use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    use ResolvesSpotCode;
    use ValidatesPhotoUploads;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->photoRules(),
            'spot' => $this->spotRules('required'),
        ];
    }
}
