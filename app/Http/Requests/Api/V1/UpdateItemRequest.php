<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    use ResolvesSpotCode;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            /**
             * What the user noted about the item, never written by the AI.
             *
             * @example Ohne Netzteil
             */
            'notes' => ['sometimes', 'nullable', 'string'],
            'search_terms' => ['sometimes', 'array'],
            'search_terms.*' => ['string', 'max:255'],
            'spot' => $this->spotRules('sometimes'),
        ];
    }
}
