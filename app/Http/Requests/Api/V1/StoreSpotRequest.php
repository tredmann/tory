<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpotRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'location' => ['required', 'uuid', 'exists:locations,id'],
        ];
    }
}
