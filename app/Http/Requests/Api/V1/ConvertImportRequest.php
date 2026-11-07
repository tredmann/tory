<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use Illuminate\Foundation\Http\FormRequest;

class ConvertImportRequest extends FormRequest
{
    use ResolvesSpotCode;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'search_terms' => ['nullable', 'array'],
            'search_terms.*' => ['string', 'max:255'],
            'spot' => $this->spotRules('required'),
        ];
    }
}
