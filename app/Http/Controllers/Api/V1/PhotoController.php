<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function import(Import $import, string $photo): StreamedResponse
    {
        return $this->stream($import, $photo);
    }

    public function item(Item $item, string $photo): StreamedResponse
    {
        return $this->stream($item, $photo);
    }

    private function stream(Model $record, string $photo): StreamedResponse
    {
        foreach ($record->photos ?? [] as $path) {
            if (basename($path) === $photo) {
                return Storage::disk('local')->response($path);
            }
        }

        abort(404);
    }
}
