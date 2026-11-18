<?php

declare(strict_types=1);

namespace App\Services\Document;

final readonly class GeneratedDocument
{
    public function __construct(
        public string $fileName,
        public string $content,
        public string $contentType = 'application/pdf',
    ) {
    }
}
