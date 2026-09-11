<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

/** Monetary amounts are hundredths of the configured company's domestic currency; no currency conversion is performed. */
readonly class PriceListItem
{
    public function __construct(
        public string $code,
        public string $name,
        public string $currencyCode,
        public int $priceAmountMinor,
        public int $netPriceAmountMinor,
        public string $priceTypeCode,
        public string $vatRateTypeCode,
    ) {
    }
}
