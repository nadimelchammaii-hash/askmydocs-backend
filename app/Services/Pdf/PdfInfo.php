<?php

namespace App\Services\Pdf;

final readonly class PdfInfo
{
    public function __construct(
        public int $pages,
    ) {}
}
