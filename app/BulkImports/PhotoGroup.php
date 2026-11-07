<?php

namespace App\BulkImports;

use App\Enums\GroupMark;

final readonly class PhotoGroup
{
    /**
     * @param  list<string>  $photos
     */
    public function __construct(
        public string $id,
        public array $photos,
        public ?GroupMark $mark,
    ) {}
}
