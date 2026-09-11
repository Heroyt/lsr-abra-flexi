<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

final readonly class InvoicePdf
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }
}
