<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'notes' => $this->notes,
            'search_terms' => $this->search_terms ?? [],
            'spot' => new SpotResource($this->spot),
            'photos' => PhotoUrls::for('api.v1.items.photos.show', $this->resource),
            'thumbnails' => PhotoUrls::for('api.v1.items.photos.thumbnail', $this->resource),
            'regenerating' => $this->isRegenerating(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
