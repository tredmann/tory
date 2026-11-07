<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Item;
use App\Photos\PhotoResponder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function __construct(private PhotoResponder $photos) {}

    public function import(Import $import, string $photo): StreamedResponse
    {
        return $this->photos->original($this->pathOf($import, $photo));
    }

    public function item(Item $item, string $photo): StreamedResponse
    {
        return $this->photos->original($this->pathOf($item, $photo));
    }

    public function importThumbnail(Import $import, string $photo): StreamedResponse
    {
        return $this->photos->thumbnail($this->pathOf($import, $photo));
    }

    public function itemThumbnail(Item $item, string $photo): StreamedResponse
    {
        return $this->photos->thumbnail($this->pathOf($item, $photo));
    }

    private function pathOf(Model $record, string $photo): string
    {
        foreach ($record->photos ?? [] as $path) {
            if (basename($path) === $photo) {
                return $path;
            }
        }

        abort(404);
    }
}
