<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Spot;
use Illuminate\Validation\Rule;

/**
 * For Form Requests that take a spot by its code, accepted in any case.
 */
trait ResolvesSpotCode
{
    protected function prepareForValidation(): void
    {
        $spot = $this->input('spot');

        if (is_string($spot) && ($code = Spot::normalizeCode($spot)) !== null) {
            $this->merge(['spot' => $code]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function spotRules(string $presence): array
    {
        return [$presence, 'string', Rule::exists('spots', 'code')];
    }

    public function spotId(): int
    {
        return (int) Spot::query()->where('code', $this->validated('spot'))->value('id');
    }
}
