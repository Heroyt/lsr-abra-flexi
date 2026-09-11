<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Dto;

use DateTimeImmutable;

/**
 * Provider request, not a purchase or accounting-policy snapshot.
 *
 * externalId is an exact ext:<namespace>:<opaque-id> selector, at most 120 bytes.
 * Namespace accepts ASCII letters, digits, underscores and hyphens (1–32);
 * the non-empty opaque ID additionally accepts dots and colons.
 * orderNumber is a printable customer order reference (1–64 bytes);
 * paymentReference is an independent variable symbol (1–10 decimal digits).
 * Codes are unprefixed. Null optional settings are omitted for ABRA defaults.
 * recipientName supplies nazFirmy only when recipient is absent.
 * Monetary integers are hundredths of the configured company domestic currency.
 */
final readonly class IssueInvoiceRequest
{
    /**
     * @param string $orderNumber Provider order reference, independent of the external identity and variable symbol.
     * @param ?string $recipientName Explicit nazFirmy fallback when no billing recipient is provided; not an email policy.
     * @param string $externalId Exact ext:<namespace>:<opaque-id> provider identity.
     * @param list<IssueInvoiceLine> $lines
     */
    public function __construct(
        public string $orderNumber,
        public ?string $recipientName,
        public string $externalId,
        public ?string $paymentMethodCode,
        public ?string $debitAccountCode,
        public ?string $creditAccountCode,
        public string $documentTypeCode,
        public ?string $numberSeriesCode,
        public ?DateTimeImmutable $issuedOn,
        public ?DateTimeImmutable $taxPointOn,
        public ?DateTimeImmutable $dueOn,
        public string $currencyCode,
        public string $paymentReference,
        public array $lines,
        public ?IssueInvoiceRecipient $recipient,
    ) {
    }
}
