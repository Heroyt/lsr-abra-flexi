<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use SensitiveParameter;

/**
 * One company connection, reused only within that company's worker lifetime.
 *
 * Amounts use hundredths of the configured company domestic currency. This
 * adapter does not convert foreign currencies or infer currency precision.
 */
final readonly class AbraFlexiConfiguration
{
    public function __construct(
        public bool $enabled = false,
        public string $apiUrl = '',
        public string $company = '',
        public string $username = '',
        #[SensitiveParameter]
        public string $password = '',
        public string $currencyCode = 'CZK',
        public int $timeout = 30,
        public bool $verifyTls = true,
        public ?string $pdfReportName = null,
        public ?string $pdfLanguage = null,
        public bool $traceCurl = false,
    ) {
    }

    public function isConfiguredForPriceList(): bool {
        return $this->enabled
            && filter_var($this->apiUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($this->apiUrl, PHP_URL_SCHEME) === 'https'
            && trim($this->company) !== ''
            && trim($this->username) !== ''
            && trim($this->password) !== ''
            && preg_match('/^[A-Z]{3}$/D', $this->currencyCode) === 1
            && $this->timeout > 0;
    }

    public function isConfiguredForInvoices(): bool {
        return $this->isConfiguredForPriceList()
            && ($this->pdfReportName === null || $this->isPrintable($this->pdfReportName, 64))
            && ($this->pdfLanguage === null || in_array($this->pdfLanguage, ['cs', 'sk', 'en', 'de'], true));
    }

    private function isPrintable(string $value, int $maxLength): bool {
        return trim($value) !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
