<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

use DateTimeImmutable;

/** Monetary amounts are hundredths of the configured company's domestic currency, not foreign-currency totals. */
final readonly class IssuedInvoice
{
    public function __construct(
        public int $internalId,
        public string $externalId,
        public string $documentNumber,
        public string $documentTypeCode,
        public string $numberSeriesCode,
        public DateTimeImmutable $issuedOn,
        public DateTimeImmutable $taxPointOn,
        public string $currencyCode,
        public int $netAmountMinor,
        public int $vatAmountMinor,
        public int $grossAmountMinor,
    ) {
    }
}
