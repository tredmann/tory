<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesSpotCode;
use App\Http\Requests\Api\V1\Concerns\ValidatesPhotoUploads;
use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
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
            /**
             * @example Akkuschrauber
             */
            'title' => ['required', 'string', 'max:255'],
            /**
             * @example Grüner Akkuschrauber mit zwei Akkus und Ladegerät.
             */
            'description' => ['nullable', 'string'],
            /**
             * Extra words the keyword and semantic search should find the item by.
             */
            'search_terms' => ['nullable', 'array'],
            'search_terms.*' => ['string', 'max:255'],
            /**
             * Code of the spot the item lies in, in any case.
             *
             * @example K7QX
             */
            'spot' => $this->spotRules('required'),
        ];
    }
}
