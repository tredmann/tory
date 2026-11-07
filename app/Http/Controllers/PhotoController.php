<?php

namespace App\Http\Controllers;

use App\Photos\PhotoResponder;
use App\Photos\PhotoStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController
{
    public function original(string $path, PhotoResponder $responder): StreamedResponse
    {
        return $responder->original($this->storagePath($path));
    }

    public function thumbnail(string $path, PhotoResponder $responder): StreamedResponse
    {
        return $responder->thumbnail($this->storagePath($path));
    }

    private function storagePath(string $path): string
    {
        return PhotoStore::storagePath($path) ?? abort(404);
    }
}
