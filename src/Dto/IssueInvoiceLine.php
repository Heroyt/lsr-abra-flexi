<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

/** Monetary amounts are hundredths of the configured company's domestic currency. */
final readonly class IssueInvoiceLine
{
    public function __construct(
        public string $description,
        public string $quantity,
        public ?string $unitCode,
        public int $unitPriceMinor,
        public int $netAmountMinor,
        public int $vatAmountMinor,
        public int $grossAmountMinor,
        public string $priceTypeCode,
        public string $vatRateTypeCode,
        public int $vatRateBasisPoints,
    ) {
    }
}
