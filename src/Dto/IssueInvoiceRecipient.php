<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

final readonly class IssueInvoiceRecipient
{
    public function __construct(
        public string $legalName,
        public string $street,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public ?string $companyRegistrationNumber,
        public ?string $vatId,
    ) {
    }
}
