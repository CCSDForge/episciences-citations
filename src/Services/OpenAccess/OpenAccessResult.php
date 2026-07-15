<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

final readonly class OpenAccessResult
{
    public function __construct(
        public string $url,
        public string $sourceTitle = '',
    ) {
    }
}
