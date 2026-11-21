<?php

namespace App\Photos;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class PhotoResponder
{
    private const string IMMUTABLE = 'private, max-age=31536000, immutable';

    public function __construct(private Thumbnails $thumbnails) {}

    public function original(string $path): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => self::IMMUTABLE]);
    }

    /**
     * The stored thumbnail, or the placeholder while it is missing; never the original.
     */
    public function thumbnail(string $path): Response
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        $thumbnail = $this->thumbnails->for($path);

        if ($thumbnail === null) {
            return response()->file(resource_path('images/thumbnail-placeholder.png'), ['Cache-Control' => 'private, no-cache']);
        }

        return Storage::disk('local')->response($thumbnail, null, ['Cache-Control' => self::IMMUTABLE]);
    }
}
