<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use AbraFlexi\FakturaVydana;
use Lsr\AbraFlexi\Dto\InvoicePdf;
use Lsr\AbraFlexi\Dto\IssuedInvoice;
use Lsr\AbraFlexi\Dto\IssueInvoiceLine;
use Lsr\AbraFlexi\Dto\IssueInvoiceRecipient;
use Lsr\AbraFlexi\Dto\IssueInvoiceRequest;
use Lsr\AbraFlexi\Enums\InvoiceFailureStage;
use Lsr\AbraFlexi\Enums\InvoiceGatewayFailure;
use Lsr\AbraFlexi\Exceptions\InvoiceGatewayException;
use Lsr\AbraFlexi\Exceptions\InvoiceResponseMappingException;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Issues and recovers invoices through one fixed company connection.
 *
 * Calls are sequential, not safe for concurrent use of the same instance.
 * Callers own durable external identities and reconciliation: an AmbiguousWrite
 * must be looked up before any new write attempt. No retries, persistence,
 * accounting eligibility, PDF storage, or customer delivery run in this module.
 * Public operations throw sanitized InvoiceGatewayException failures.
 */
final class AbraFlexiInvoiceGateway
{
    private ?FakturaVydana $client;
    private readonly AbraFlexiClientFactory $clientFactory;

    public function __construct(
        private readonly AbraFlexiConfiguration $configuration,
        private readonly LoggerInterface $logger,
        ?FakturaVydana $client = null,
        ?AbraFlexiClientFactory $clientFactory = null,
    ) {
        $this->client = $client;
        $this->clientFactory = $clientFactory ?? new AbraFlexiClientFactory($configuration, $logger);
    }

    public function issue(IssueInvoiceRequest $request): IssuedInvoice {
        $startedAt = hrtime(true);
        $client = null;
        try {
            $this->requireConfigured();
            $this->validateRequest($request);

            $client = $this->client();
            $this->resetResponseDiagnostics($client);
            try {
                $client->dataReset();
                $header = $this->headerData($request);
                $client->takeData($header);
                foreach ($request->lines as $line) {
                    $client->addArrayToBranch($this->lineData($line));
                }
            } catch (Throwable $exception) {
                throw $this->failure(
                    InvoiceGatewayFailure::Retryable,
                    'The ABRA Flexi invoice request could not be prepared.',
                    $exception,
                );
            }

            $this->logSubmission($request, $header);

            try {
                $issued = $client->sync();
            } catch (Throwable $exception) {
                // sync() includes a write and readback; a thrown call cannot prove the write outcome.
                throw $this->failure(
                    InvoiceGatewayFailure::AmbiguousWrite,
                    'The ABRA Flexi invoice write outcome is unknown.',
                    $exception,
                );
            }

            if ( ! $issued) {
                throw $this->writeFailure($client->lastResponseCode ?? 0);
            }

            $this->logInvoiceRead(InvoiceFailureStage::Create, $client, $request->externalId, $request->orderNumber);
            $invoice = $this->mapInvoice($client, $request->externalId);
            $this->assertIssueMatches($invoice, $request);

            return $invoice;
        } catch (InvoiceGatewayException $exception) {
            $this->logFailure(InvoiceFailureStage::Create, $exception, $client, $request->externalId, $startedAt);
            throw $exception;
        }
    }

    public function findByExternalId(string $externalId): ?IssuedInvoice {
        $startedAt = hrtime(true);
        $client = null;
        try {
            $this->requireConfigured();
            $externalId = $this->validateExternalId($externalId);
            $client = $this->client();
            if ( ! $this->load($client, $externalId)) {
                return null;
            }

            $this->logInvoiceRead(InvoiceFailureStage::Lookup, $client, $externalId);
            return $this->mapInvoice($client, $externalId);
        } catch (InvoiceGatewayException $exception) {
            $this->logFailure(InvoiceFailureStage::Lookup, $exception, $client, $externalId, $startedAt);
            throw $exception;
        }
    }

    public function downloadPdf(string $externalId): InvoicePdf {
        $startedAt = hrtime(true);
        $client = null;
        try {
            $this->requireConfigured();
            $externalId = $this->validateExternalId($externalId);
            $client = $this->client();
            if ( ! $this->load($client, $externalId)) {
                throw $this->failure(
                    InvoiceGatewayFailure::IdentityConflict,
                    'The ABRA Flexi invoice no longer exists.',
                );
            }
            $this->assertExternalId($client, $externalId);

            try {
                $this->resetResponseDiagnostics($client);
                $bytes = $client->getInFormat(
                    'pdf',
                    $this->configuration->pdfReportName,
                    $this->configuration->pdfLanguage,
                );
            } catch (Throwable $exception) {
                throw $this->failure(
                    InvoiceGatewayFailure::Retryable,
                    'The ABRA Flexi invoice PDF could not be downloaded.',
                    $exception,
                );
            }
            if ( ! is_string($bytes) || ! str_starts_with($bytes, '%PDF-')) {
                throw $this->failure(
                    InvoiceGatewayFailure::InvalidResponse,
                    'ABRA Flexi returned an invalid invoice PDF.',
                );
            }

            return new InvoicePdf($bytes, 'application/pdf');
        } catch (InvoiceGatewayException $exception) {
            $this->logFailure(InvoiceFailureStage::PdfDownload, $exception, $client, $externalId, $startedAt);
            throw $exception;
        }
    }

    private function client(): FakturaVydana {
        if ($this->client === null) {
            try {
                $this->client = $this->clientFactory->issuedInvoice();
            } catch (Throwable $exception) {
                throw $this->failure(
                    InvoiceGatewayFailure::Retryable,
                    'The ABRA Flexi invoice client is unavailable.',
                    $exception,
                );
            }

        }
        return $this->client;
    }

    private function requireConfigured(): void {
        if ( ! $this->configuration->isConfiguredForInvoices()) {
            throw $this->failure(
                InvoiceGatewayFailure::ConfigurationDisabled,
                'ABRA Flexi invoice access is not configured.',
            );
        }
    }

    private function validateRequest(IssueInvoiceRequest $request): void {
        $this->validateExternalId($request->externalId);
        $this->validateCode($request->documentTypeCode, 'document type');
        $this->validateOptionalCode($request->numberSeriesCode, 'number series');
        $this->validateOptionalCode($request->paymentMethodCode, 'payment method');
        $this->validateOptionalCode($request->debitAccountCode, 'debit account');
        $this->validateOptionalCode($request->creditAccountCode, 'credit account');
        if ($request->currencyCode !== $this->configuration->currencyCode) {
            throw $this->rejected('The invoice currency does not match configuration.');
        }
        $this->validatePrintable($request->orderNumber, 64, 'order number');
        if (preg_match('/^[0-9]{1,10}$/D', $request->paymentReference) !== 1) {
            throw $this->rejected('The invoice variable symbol is invalid.');
        }
        if ($request->lines === []
            || ($request->dueOn !== null && $request->issuedOn !== null && $request->dueOn < $request->issuedOn)
        ) {
            throw $this->rejected('The invoice request is incomplete.');
        }

        foreach ($request->lines as $line) {
            $this->validateLine($line);
        }
        $this->totals($request);
        if ($request->recipient !== null) {
            $this->validateRecipient($request->recipient);
        } elseif ($request->recipientName !== null) {
            $this->validatePrintable($request->recipientName, 255, 'recipient name');
        }
    }

    private function validateLine(IssueInvoiceLine $line): void {
        $this->validatePrintable($line->description, 255, 'line description');
        $this->validateOptionalCode($line->unitCode, 'unit');
        $this->validatePrintable($line->priceTypeCode, 50, 'price type');
        $this->validatePrintable($line->vatRateTypeCode, 50, 'VAT rate type');
        if (preg_match('/^(?:[1-9]\d*(?:\.\d{1,6})?|0\.\d{0,5}[1-9])$/D', $line->quantity) !== 1) {
            throw $this->rejected('The invoice line quantity is invalid.');
        }
        if (
            $line->unitPriceMinor < 0
            || $line->netAmountMinor < 0
            || $line->vatAmountMinor < 0
            || $line->grossAmountMinor < 0
            || $line->vatRateBasisPoints < 0
            || $line->vatRateBasisPoints > 10000
        ) {
            throw $this->rejected('The invoice line amounts are invalid.');
        }
        if (
            $line->netAmountMinor > PHP_INT_MAX - $line->vatAmountMinor
            || $line->netAmountMinor + $line->vatAmountMinor !== $line->grossAmountMinor
        ) {
            throw $this->rejected('The invoice line totals are inconsistent.');
        }
    }

    private function validateRecipient(IssueInvoiceRecipient $recipient): void {
        $this->validatePrintable($recipient->legalName, 200, 'recipient legal name');
        $this->validatePrintable($recipient->street, 200, 'recipient street');
        $this->validatePrintable($recipient->city, 120, 'recipient city');
        $this->validatePrintable($recipient->postalCode, 32, 'recipient postal code');
        if (preg_match('/^[A-Z]{2}$/D', $recipient->countryCode) !== 1) {
            throw $this->rejected('The invoice recipient country is invalid.');
        }
        if ($recipient->companyRegistrationNumber !== null) {
            $this->validatePrintable($recipient->companyRegistrationNumber, 64, 'recipient registration number');
        }
        if ($recipient->vatId !== null) {
            $this->validatePrintable($recipient->vatId, 64, 'recipient VAT ID');
        }
    }

    /** @return array<string, string> */
    private function headerData(IssueInvoiceRequest $request): array {
        $data = [
            'id'              => $request->externalId,
            'typDokl'         => $this->code($request->documentTypeCode),
            'mena'            => $this->code($request->currencyCode),
            'varSym'          => $request->paymentReference,
            'cisObj'          => $request->orderNumber,
        ];
        foreach ([
            'rada' => $request->numberSeriesCode,
            'formaUhradyCis' => $request->paymentMethodCode,
            'primUcet' => $request->debitAccountCode,
            'protiUcet' => $request->creditAccountCode,
        ] as $field => $value) {
            if ($value !== null) {
                $data[$field] = $this->code($value);
            }
        }
        foreach ([
            'datVyst' => $request->issuedOn,
            'duzpPuv' => $request->taxPointOn,
            'datSplat' => $request->dueOn,
        ] as $field => $value) {
            if ($value !== null) {
                $data[$field] = $value->format('Y-m-d');
            }
        }
        if ($request->recipient !== null) {
            $data += [
                'nazFirmy' => $request->recipient->legalName,
                'ulice'    => $request->recipient->street,
                'mesto'    => $request->recipient->city,
                'psc'      => $request->recipient->postalCode,
                'stat'     => $this->code($request->recipient->countryCode),
            ];
            if ($request->recipient->companyRegistrationNumber !== null) {
                $data['ic'] = $request->recipient->companyRegistrationNumber;
            }
            if ($request->recipient->vatId !== null) {
                $data['dic'] = $request->recipient->vatId;
            }
        } elseif ($request->recipientName !== null) {
            $data['nazFirmy'] = $request->recipientName;
        }

        return $data;
    }

    /** @return array<string, string> */
    private function lineData(IssueInvoiceLine $line): array {
        $data = [
            'nazev'       => $line->description,
            'mnozMj'      => $line->quantity,
            'cenaMj'      => AbraFlexiValueMapper::minorToDecimal($line->unitPriceMinor),
            'typCenyDphK' => $line->priceTypeCode,
            'typSzbDphK'  => $line->vatRateTypeCode,
            'szbDph'      => AbraFlexiValueMapper::basisPointsToPercent($line->vatRateBasisPoints),
        ];
        if ($line->unitCode !== null) {
            $data['mj'] = $this->code($line->unitCode);
        }

        return $data;
    }

    private function load(FakturaVydana $client, string $externalId): bool {
        try {
            $this->resetResponseDiagnostics($client);
            $client->dataReset();
            $loaded = $client->loadFromAbraFlexi($externalId);
        } catch (Throwable $exception) {
            throw $this->failure(
                InvoiceGatewayFailure::Retryable,
                'The ABRA Flexi invoice lookup failed.',
                $exception,
            );
        }
        if ($loaded > 0) {
            return true;
        }
        if ($client->lastResponseCode === 404) {
            return false;
        }

        throw $this->readFailure($client->lastResponseCode ?? 0, 'lookup');
    }

    private function mapInvoice(FakturaVydana $client, string $expectedExternalId): IssuedInvoice {
        try {
            $this->assertExternalId($client, $expectedExternalId);

            $currencyCode = AbraFlexiValueMapper::relationCode($client->getDataValue('mena'), 'mena');
            if ($currencyCode !== $this->configuration->currencyCode) {
                throw new InvoiceResponseMappingException('mena', 'configured domestic currency', $currencyCode);
            }

            return new IssuedInvoice(
                internalId: AbraFlexiValueMapper::requiredPositiveInt($client->getDataValue('id'), 'id'),
                externalId: $expectedExternalId,
                documentNumber: AbraFlexiValueMapper::requiredString($client->getDataValue('kod'), 'kod'),
                documentTypeCode: AbraFlexiValueMapper::relationCode($client->getDataValue('typDokl'), 'typDokl'),
                numberSeriesCode: AbraFlexiValueMapper::relationCode($client->getDataValue('rada'), 'rada'),
                issuedOn: AbraFlexiValueMapper::requiredDate($client->getDataValue('datVyst'), 'datVyst'),
                taxPointOn: AbraFlexiValueMapper::requiredDate($client->getDataValue('duzpPuv'), 'duzpPuv'),
                currencyCode: $currencyCode,
                netAmountMinor: AbraFlexiValueMapper::decimalToMinor($client->getDataValue('sumZklCelkem'), 'sumZklCelkem'),
                vatAmountMinor: AbraFlexiValueMapper::decimalToMinor($client->getDataValue('sumDphCelkem'), 'sumDphCelkem'),
                grossAmountMinor: AbraFlexiValueMapper::decimalToMinor($client->getDataValue('sumCelkem'), 'sumCelkem'),
            );
        } catch (InvoiceGatewayException $exception) {
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            throw new InvoiceGatewayException(
                InvoiceGatewayFailure::InvalidResponse,
                'ABRA Flexi returned invalid invoice data.',
                $exception instanceof InvoiceResponseMappingException ? $exception : null,
            );
        }
    }
    private function assertExternalId(FakturaVydana $client, string $expectedExternalId): void {
        $externalIds = $client->getDataValue('external-ids');
        if (is_string($externalIds)) {
            $externalIds = [$externalIds];
        }
        if ( ! is_array($externalIds) || ! in_array($expectedExternalId, $externalIds, true)) {
            throw $this->failure(
                InvoiceGatewayFailure::IdentityConflict,
                'ABRA Flexi returned a different invoice identity.',
            );
        }
    }


    private function assertIssueMatches(IssuedInvoice $invoice, IssueInvoiceRequest $request): void {
        [$net, $vat, $gross] = $this->totals($request);
        if (
            $invoice->externalId !== $request->externalId
            || $invoice->currencyCode !== $request->currencyCode
            || $invoice->documentTypeCode !== $request->documentTypeCode
            || ($request->numberSeriesCode !== null && $invoice->numberSeriesCode !== $request->numberSeriesCode)
            || ($request->issuedOn !== null && $invoice->issuedOn->format('Y-m-d') !== $request->issuedOn->format('Y-m-d'))
            || ($request->taxPointOn !== null && $invoice->taxPointOn->format('Y-m-d') !== $request->taxPointOn->format('Y-m-d'))
            || $invoice->netAmountMinor !== $net
            || $invoice->vatAmountMinor !== $vat
            || $invoice->grossAmountMinor !== $gross
        ) {
            throw $this->failure(
                InvoiceGatewayFailure::IdentityConflict,
                'ABRA Flexi returned invoice data that does not match the request.',
            );
        }
    }

    /** @return array{int, int, int} */
    private function totals(IssueInvoiceRequest $request): array {
        $net = 0;
        $vat = 0;
        $gross = 0;
        foreach ($request->lines as $line) {
            if (
                $line->netAmountMinor > PHP_INT_MAX - $net
                || $line->vatAmountMinor > PHP_INT_MAX - $vat
                || $line->grossAmountMinor > PHP_INT_MAX - $gross
            ) {
                throw $this->rejected('The invoice totals are excessive.');
            }
            $net += $line->netAmountMinor;
            $vat += $line->vatAmountMinor;
            $gross += $line->grossAmountMinor;
        }

        return [$net, $vat, $gross];
    }

    private function validateExternalId(string $externalId): string {
        if (
            strlen($externalId) > 120
            || preg_match('/^ext:[A-Za-z0-9_-]{1,32}:[A-Za-z0-9_.:-]+$/D', $externalId) !== 1
        ) {
            throw $this->rejected('The invoice external ID is invalid.');
        }

        return $externalId;
    }


    private function validateOptionalCode(?string $code, string $label): void {
        if ($code !== null) {
            $this->validateCode($code, $label);
        }
    }

    private function validateCode(string $code, string $label): void {
        $this->validatePrintable($code, 64, $label);
        if (str_starts_with($code, 'code:')) {
            throw $this->rejected(sprintf('The invoice %s code must not include an ABRA prefix.', $label));
        }
    }

    private function validatePrintable(string $value, int $maxLength, string $label): void {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw $this->rejected(sprintf('The invoice %s is invalid.', $label));
        }
    }

    private function code(string $value): string {
        return 'code:' . $value;
    }

    private function rejected(string $message): InvoiceGatewayException {
        return $this->failure(InvoiceGatewayFailure::RejectedRequest, $message);
    }

    private function writeFailure(int $responseCode): InvoiceGatewayException {
        if ($responseCode === 409) {
            return $this->failure(
                InvoiceGatewayFailure::IdentityConflict,
                'ABRA Flexi rejected the invoice identity.',
            );
        }
        if ($responseCode >= 400 && $responseCode < 500 && ! in_array($responseCode, [408, 429], true)) {
            return $this->rejected('ABRA Flexi rejected the invoice request.');
        }

        return $this->failure(
            InvoiceGatewayFailure::AmbiguousWrite,
            'The ABRA Flexi invoice write outcome is unknown.',
        );
    }

    private function readFailure(int $responseCode, string $operation): InvoiceGatewayException {
        if ($responseCode >= 400 && $responseCode < 500 && ! in_array($responseCode, [408, 429], true)) {
            return $this->rejected(sprintf('ABRA Flexi rejected the invoice %s.', $operation));
        }

        return $this->failure(
            InvoiceGatewayFailure::Retryable,
            sprintf('The ABRA Flexi invoice %s failed.', $operation),
        );
    }

    private function resetResponseDiagnostics(FakturaVydana $client): void {
        // The SDK's dataReset() only clears record data, not the previous HTTP exchange.
        $client->lastResponseCode = 0;
        $client->curlInfo = null;
        $client->lastCurlResponse = '';
    }

    /** @param array<string, string> $header */
    private function logSubmission(IssueInvoiceRequest $request, array $header): void {
        try {
            $this->logger->info('commerce.abra.invoice_submit', [
                'externalIdHash' => hash('sha256', $request->externalId),
                'orderNumberHash' => hash('sha256', $request->orderNumber),
                'requestFields' => array_keys($header),
                'hasBillingRecipient' => $request->recipient !== null,
                'usesRecipientName' => $request->recipient === null && $request->recipientName !== null,
            ]);
        } catch (Throwable) {
            // Diagnostics must never prevent or repeat an invoice write.
        }
    }

    private function logInvoiceRead(
        InvoiceFailureStage $operation,
        FakturaVydana $client,
        string $externalId,
        ?string $expectedOrderNumber = null,
    ): void {
        try {
            $orderNumber = $client->getDataValue('cisObj');
            $buyerName = $client->getDataValue('nazFirmy');
            $this->logger->info('commerce.abra.invoice_read', [
                'operation' => $operation->value,
                'externalIdHash' => hash('sha256', $externalId),
                'expectedOrderNumberHash' => $expectedOrderNumber === null ? null : hash('sha256', $expectedOrderNumber),
                'orderNumberPresent' => is_int($orderNumber) || (is_string($orderNumber) && trim($orderNumber) !== ''),
                'orderNumberMatchesRequest' => $expectedOrderNumber === null ? null
                    : is_string($orderNumber) && $orderNumber === $expectedOrderNumber,
                'orderNumberValueType' => gettype($orderNumber),
                'orderNumberValueLength' => is_string($orderNumber) ? strlen($orderNumber) : null,
                'buyerNamePresent' => is_string($buyerName) && trim($buyerName) !== '',
            ]);
        } catch (Throwable) {
            // A readback diagnostic must not change identity validation or recovery.
        }
    }

    private function logFailure(
        InvoiceFailureStage $operation,
        InvoiceGatewayException $exception,
        ?FakturaVydana $client,
        string $externalId,
        int $startedAt,
    ): void {
        try {
            $status = $client?->lastResponseCode;
            $method = $client?->curlInfo['http_method'] ?? null;
            $contentType = $client?->curlInfo['content_type'] ?? null;
            $contentType = is_string($contentType) ? strtolower(trim(explode(';', $contentType, 2)[0])) : null;
            $body = $client->lastCurlResponse ?? '';
            $problem = $exception->responseProblem;
            $retryable = in_array($exception->failure, [
                InvoiceGatewayFailure::ConfigurationDisabled,
                InvoiceGatewayFailure::Retryable,
                InvoiceGatewayFailure::AmbiguousWrite,
            ], true);
            $this->logger->log($retryable ? 'warning' : 'error', 'commerce.abra.request_failed', [
                'operation' => $operation->value,
                'evidence' => 'faktura-vydana',
                'externalIdHash' => strlen($externalId) <= 120 ? hash('sha256', $externalId) : null,
                'failureCode' => $exception->failure->value,
                'reason' => $exception->getMessage(),
                'causeType' => $exception->causeType,
                'curlErrorCode' => $client?->curlInfo !== null && isset($client->curl) ? curl_errno($client->curl) : null,
                'retryable' => $retryable,
                'durationMs' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'httpStatus' => $status !== null && $status >= 100 && $status <= 599 ? $status : null,
                'httpMethod' => in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'], true) ? $method : null,
                'responseContentType' => in_array($contentType, [
                    'application/json', 'application/xml', 'text/xml', 'text/html', 'text/plain', 'application/pdf',
                ], true) ? $contentType : null,
                'responseBytes' => strlen($body),
                'providerErrorFields' => $this->providerErrorFields($body),
                'responseField' => $problem?->field,
                'responseExpected' => $problem?->expected,
                'responseValueType' => $problem?->valueType,
                'responseValueLength' => $problem?->valueLength,
            ]);
        } catch (Throwable) {
            // Diagnostic storage failure must not change recovery or hide the original gateway failure.
        }
    }

    /** @return list<string> */
    private function providerErrorFields(string $body): array {
        if ($body === '' || strlen($body) > 65536) {
            return [];
        }
        $decoded = json_decode($body, true, 16);
        $response = is_array($decoded) ? ($decoded['winstrom'] ?? null) : null;
        if ( ! is_array($response)) {
            return [];
        }
        $groups = [is_array($response['errors'] ?? null) ? $response['errors'] : []];
        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        foreach (array_slice($results, 0, 10) as $result) {
            if (is_array($result) && is_array($result['errors'] ?? null)) {
                $groups[] = $result['errors'];
            }
        }
        $fields = [];
        foreach ($groups as $errors) {
            foreach (array_slice($errors, 0, 10) as $error) {
                if ( ! is_array($error)) {
                    continue;
                }
                // Provider messages can echo credentials/PII. Only known schema property names leave this boundary.
                $path = $error['path'] ?? null;
                $pathField = is_string($path) ? basename($path) : null;
                foreach ([$error['for'] ?? null, $pathField] as $field) {
                    if (is_string($field) && in_array($field, [
                        'id', 'kod', 'typDokl', 'rada', 'mena', 'datVyst', 'duzpPuv', 'datSplat',
                        'formaUhradyCis', 'primUcet', 'protiUcet', 'typUcOp', 'clenDph', 'varSym', 'cisObj',
                        'nazFirmy', 'ulice', 'mesto', 'psc', 'stat', 'ic', 'dic', 'firma', 'bankovniUcet',
                        'polozkyFaktury', 'nazev', 'mnozMj', 'mj', 'cenaMj', 'typCenyDphK', 'typSzbDphK', 'szbDph',
                    ], true)) {
                        $fields[$field] = true;
                    }
                }
            }
        }
        return array_keys($fields);
    }

    private function failure(
        InvoiceGatewayFailure $failure,
        string $message,
        ?Throwable $cause = null,
    ): InvoiceGatewayException {
        return new InvoiceGatewayException(
            $failure,
            $message,
            causeType: $cause === null ? null
                : (str_contains($cause::class, '@anonymous') ? 'anonymous throwable' : $cause::class),
        );
    }
}
