<?php

namespace App\Photos;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhotoResponder
{
    private const IMMUTABLE = 'private, max-age=31536000, immutable';

    public function __construct(private Thumbnails $thumbnails) {}

    public function original(string $path): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => self::IMMUTABLE]);
    }

    public function thumbnail(string $path): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        $thumbnail = $this->thumbnails->for($path);

        if ($thumbnail === null) {
            return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-cache']);
        }

        return Storage::disk('local')->response($thumbnail, null, ['Cache-Control' => self::IMMUTABLE]);
    }
}
