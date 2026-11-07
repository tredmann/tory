<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Spot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Spot
 */
class SpotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'note' => $this->note,
            'label' => $this->label,
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
