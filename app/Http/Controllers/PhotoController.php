<?php

namespace App\Http\Controllers;

use App\Photos\PhotoResponder;
use App\Photos\PhotoStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function __construct(private PhotoResponder $photos) {}

    public function original(string $path): StreamedResponse
    {
        return $this->photos->original($this->storagePath($path));
    }

    public function thumbnail(string $path): Response
    {
        return $this->photos->thumbnail($this->storagePath($path));
    }

    private function storagePath(string $path): string
    {
        return PhotoStore::storagePath($path) ?? abort(404);
    }
}
