<?php

namespace App\BulkImports;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final readonly class StagedPhoto
{
    public function __construct(
        public UploadedFile $file,
        public string $name,
        public ?CarbonImmutable $takenAt,
    ) {}
}
