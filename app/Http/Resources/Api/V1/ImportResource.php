<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->status->getLabel(),
            'title' => $this->title,
            'description' => $this->description,
            'search_terms' => $this->search_terms ?? [],
            'spot' => $this->spot_id === null ? null : new SpotResource($this->spot),
            'photos' => PhotoUrls::for('api.v1.imports.photos.show', $this->resource),
            'failure_message' => $this->failureMessage()
                ?? ($this->status === ImportStatus::Failed ? 'Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.' : null),
            'error' => config('app.debug') ? $this->error : null,
            'regenerating' => $this->isRegenerating(),
            'created_at' => $this->created_at,
        ];
    }
}
