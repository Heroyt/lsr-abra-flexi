<?php

declare(strict_types=1);

namespace Tests;

use AbraFlexi\FakturaVydana;
use DateTimeImmutable;
use LogicException;
use Lsr\AbraFlexi\AbraFlexiConfiguration;
use Lsr\AbraFlexi\AbraFlexiInvoiceGateway;
use Lsr\AbraFlexi\Dto\IssueInvoiceLine;
use Lsr\AbraFlexi\Dto\IssueInvoiceRecipient;
use Lsr\AbraFlexi\Dto\IssueInvoiceRequest;
use Lsr\AbraFlexi\Enums\InvoiceGatewayFailure;
use Lsr\AbraFlexi\Exceptions\InvoiceGatewayException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

final class AbraFlexiInvoiceGatewayTest extends TestCase
{
    public function test_issue_preserves_explicit_provider_overrides_and_hundredths_amounts(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('dataReset');
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data): bool {
            self::assertSame('ext:Accounting_Bridge:document.2026-0042:invoice', $data['id']);
            self::assertSame('code:FAKTURA', $data['typDokl']);
            self::assertSame('code:INVOICE-SERIES', $data['rada']);
            self::assertSame('2026-09-04', $data['datVyst']);
            self::assertSame('2026-09-04', $data['duzpPuv']);
            self::assertSame('2026-09-04', $data['datSplat']);
            self::assertArrayNotHasKey('bezPolozek', $data);
            self::assertSame('code:CZK', $data['mena']);
            self::assertSame('code:GOPAY', $data['formaUhradyCis']);
            self::assertSame('code:311000', $data['primUcet']);
            self::assertSame('code:602000', $data['protiUcet']);
            self::assertSame('Billing Customer', $data['nazFirmy']);
            self::assertSame('CZ12345678', $data['dic']);
            return true;
        }))->willReturn(12);
        $client->expects(self::once())->method('addArrayToBranch')->with(self::callback(static function (array $line): bool {
            self::assertArrayNotHasKey('cenik', $line);
            self::assertSame('1', $line['mnozMj']);
            self::assertSame('990.00', $line['cenaMj']);
            self::assertSame('code:KS', $line['mj']);
            foreach (['sumZkl', 'sumDph', 'sumCelkem', 'sumZklMen', 'sumDphMen', 'sumCelkemMen'] as $field) {
                self::assertArrayNotHasKey($field, $line);
            }
            self::assertSame('21', $line['szbDph']);
            return true;
        }))->willReturn(true);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $this->invoiceData($client);

        $invoice = (new AbraFlexiInvoiceGateway(
            $this->configuration(),
            new NullLogger(),
            $client,
        ))->issue($this->request());

        self::assertSame(481, $invoice->internalId);
        self::assertSame('2026-0019', $invoice->documentNumber);
        self::assertSame('CZK', $invoice->currencyCode);
        self::assertSame(81818, $invoice->netAmountMinor);
        self::assertSame(17182, $invoice->vatAmountMinor);
        self::assertSame(99000, $invoice->grossAmountMinor);
    }

    public function test_document_type_defaults_are_omitted_from_payload_and_provider_values_are_accepted(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data): bool {
            self::assertSame('ext:Accounting_Bridge:document.2026-0042:invoice', $data['id']);
            self::assertSame('code:FAKTURA', $data['typDokl']);
            self::assertSame('code:CZK', $data['mena']);
            self::assertSame('0042', $data['varSym']);
            self::assertSame('INV 2026/0042', $data['cisObj']);
            self::assertSame('customer@example.test', $data['nazFirmy']);
            foreach ([
                'rada', 'datVyst', 'duzpPuv', 'datSplat', 'formaUhradyCis', 'primUcet', 'protiUcet',
                'bezPolozek', 'ulice', 'mesto', 'psc', 'stat', 'ic', 'dic',
            ] as $field) {
                self::assertArrayNotHasKey($field, $data);
            }
            return true;
        }))->willReturn(12);
        $client->expects(self::once())->method('addArrayToBranch')->with(self::callback(static function (array $line): bool {
            self::assertSame('Consulting 90 minutes', $line['nazev']);
            self::assertSame('1', $line['mnozMj']);
            self::assertSame('990.00', $line['cenaMj']);
            self::assertSame('typCeny.sDph', $line['typCenyDphK']);
            self::assertSame('typSzbDph.dphZakl', $line['typSzbDphK']);
            self::assertSame('21', $line['szbDph']);
            foreach (['mj', 'cenik', 'sumZkl', 'sumDph', 'sumCelkem', 'sumZklMen', 'sumDphMen', 'sumCelkemMen'] as $field) {
                self::assertArrayNotHasKey($field, $line);
            }
            return true;
        }))->willReturn(true);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $this->invoiceData($client, overrides: [
            'rada' => 'code:PROVIDER-DEFAULT',
            'datVyst' => '2026-09-05',
            'duzpPuv' => '2026-09-03',
        ]);

        $invoice = (new AbraFlexiInvoiceGateway(
            $this->configuration(),
            new NullLogger(),
            $client,
        ))->issue($this->request(recipient: null, inheritDefaults: true));

        self::assertSame('PROVIDER-DEFAULT', $invoice->numberSeriesCode);
        self::assertSame('2026-09-05', $invoice->issuedOn->format('Y-m-d'));
        self::assertSame('2026-09-03', $invoice->taxPointOn->format('Y-m-d'));
        self::assertSame(81818, $invoice->netAmountMinor);
        self::assertSame(17182, $invoice->vatAmountMinor);
        self::assertSame(99000, $invoice->grossAmountMinor);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('conflictingInvoiceData')]
    public function test_issue_rejects_conflicting_remote_evidence(array $overrides, bool $inheritDefaults): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client, overrides: $overrides);

        try {
            $this->gateway($client)->issue($this->request(inheritDefaults: $inheritDefaults));
            self::fail('Conflicting remote evidence must not be published locally.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::IdentityConflict, $exception->failure);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function conflictingInvoiceData(): iterable {
        yield 'explicit series' => [['rada' => 'code:OTHER'], false];
        yield 'explicit issue date' => [['datVyst' => '2026-09-05'], false];
        yield 'explicit tax point date' => [['duzpPuv' => '2026-09-05'], false];
        yield 'minimal external identity' => [['external-ids' => ['ext:Other_Bridge:document.2026-0042:invoice']], true];
        yield 'external identity case is significant' => [['external-ids' => ['ext:accounting_bridge:document.2026-0042:invoice']], true];
        yield 'external identity is not trimmed' => [['external-ids' => ['ext:Accounting_Bridge:document.2026-0042:invoice ']], true];
        yield 'minimal document type' => [['typDokl' => 'code:ZALOHA'], true];
        yield 'minimal net amount' => [['sumZklCelkem' => '818.17'], true];
        yield 'minimal VAT amount' => [['sumDphCelkem' => '171.81'], true];
        yield 'minimal gross amount' => [['sumCelkem' => '989.99'], true];
    }

    public function test_issue_rejects_remote_currency_even_when_the_totals_match(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client, currencyCode: 'EUR');

        try {
            $this->gateway($client)->issue($this->request(inheritDefaults: true));
            self::fail('A different remote currency must not be published locally.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
        }
    }

    public function test_issue_uses_hundredths_of_a_non_czk_domestic_currency(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data): bool {
            self::assertSame('code:EUR', $data['mena']);
            self::assertSame('INV 2026/0042', $data['cisObj']);
            self::assertSame('008172', $data['varSym']);
            return true;
        }))->willReturn(12);
        $client->expects(self::once())->method('addArrayToBranch')->with(self::callback(static function (array $line): bool {
            self::assertSame('990.00', $line['cenaMj']);
            return true;
        }))->willReturn(true);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $this->invoiceData($client, currencyCode: 'EUR');

        $invoice = (new AbraFlexiInvoiceGateway(
            $this->configuration(currencyCode: 'EUR'),
            new NullLogger(),
            $client,
        ))->issue($this->request(currencyCode: 'EUR', paymentReference: '008172'));

        self::assertSame('EUR', $invoice->currencyCode);
        self::assertSame(81818, $invoice->netAmountMinor);
        self::assertSame(17182, $invoice->vatAmountMinor);
        self::assertSame(99000, $invoice->grossAmountMinor);
    }

    public function test_issue_rejects_a_currency_different_from_the_configured_domestic_currency_before_writing(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('sync');

        try {
            (new AbraFlexiInvoiceGateway($this->configuration(currencyCode: 'EUR'), new NullLogger(), $client))
                ->issue($this->request());
            self::fail('Amounts in another currency must not be sent as domestic currency.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
        }
    }

    public function test_lookup_requires_the_configured_domestic_currency(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('loadFromAbraFlexi')->willReturn(1);
        $this->invoiceData($client, currencyCode: 'EUR');
        $externalId = 'ext:Accounting_Bridge:document.2026-0042:invoice';

        $invoice = (new AbraFlexiInvoiceGateway($this->configuration(currencyCode: 'EUR'), new NullLogger(), $client))
            ->findByExternalId($externalId);
        self::assertNotNull($invoice);
        self::assertSame('EUR', $invoice->currencyCode);
        self::assertSame(99000, $invoice->grossAmountMinor);

        try {
            $this->gateway($client)->findByExternalId($externalId);
            self::fail('The same domestic totals must not be mislabeled with another currency.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
            self::assertSame('mena', $exception->responseProblem?->field);
        }
    }

    #[DataProvider('fallbackRecipientNames')]
    public function test_optional_recipient_name_does_not_manufacture_billing_details(?string $name): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data) use ($name): bool {
            self::assertSame('INV 2026/0042', $data['cisObj']);
            self::assertSame('0042', $data['varSym']);
            if ($name === null) {
                self::assertArrayNotHasKey('nazFirmy', $data);
            } else {
                self::assertSame($name, $data['nazFirmy']);
            }
            foreach (['ulice', 'mesto', 'psc', 'stat', 'ic', 'dic'] as $field) {
                self::assertArrayNotHasKey($field, $data);
            }
            return true;
        }))->willReturn(12);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client);

        $invoice = $this->gateway($client)->issue($this->request(recipient: null, recipientName: $name));

        self::assertSame(481, $invoice->internalId);
        self::assertSame('ext:Accounting_Bridge:document.2026-0042:invoice', $invoice->externalId);
    }

    /** @return iterable<string, array{?string}> */
    public static function fallbackRecipientNames(): iterable {
        yield 'explicit display name' => ['Walk-in customer'];
        yield 'caller-selected email name' => ['customer@example.test'];
        yield 'provider default' => [null];
    }

    public function test_malformed_country_is_rejected_before_sending_to_abra(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('sync');

        try {
            $this->gateway($client)->issue($this->request(recipient: new IssueInvoiceRecipient(
                legalName: 'Customer',
                street: 'Test 42',
                city: 'Prague',
                postalCode: '11000',
                countryCode: 'cz',
                companyRegistrationNumber: null,
                vatId: null,
            )));
            self::fail('A malformed country code must not reach ABRA.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
        }
    }

    public function test_country_eligibility_is_left_to_the_application_and_provider(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data): bool {
            self::assertSame('code:ZZ', $data['stat']);
            self::assertSame('Customer', $data['nazFirmy']);
            return true;
        }))->willReturn(12);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $this->invoiceData($client);

        $invoice = $this->gateway($client)->issue($this->request(recipient: new IssueInvoiceRecipient(
            legalName: 'Customer',
            street: 'Test 42',
            city: 'Prague',
            postalCode: '11000',
            countryCode: 'ZZ',
            companyRegistrationNumber: null,
            vatId: null,
        )));

        self::assertSame(481, $invoice->internalId);
    }

    public function test_quantity_with_a_trailing_newline_is_rejected_before_writing(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('sync');

        try {
            $this->gateway($client)->issue($this->request(quantity: "1\n"));
            self::fail('A malformed quantity must not reach ABRA.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
        }
    }

    #[DataProvider('validExternalIds')]
    public function test_external_identity_is_opaque_and_preserved_exactly(string $externalId): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('takeData')->with(self::callback(static function (array $data) use ($externalId): bool {
            self::assertSame($externalId, $data['id']);
            return true;
        }))->willReturn(12);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $client->expects(self::once())->method('loadFromAbraFlexi')->with($externalId)->willReturn(1);
        $this->invoiceData($client, overrides: ['external-ids' => $externalId]);
        $gateway = $this->gateway($client);

        self::assertSame($externalId, $gateway->issue($this->request(externalId: $externalId))->externalId);
        self::assertSame($externalId, $gateway->findByExternalId($externalId)?->externalId);
    }

    /** @return iterable<string, array{string}> */
    public static function validExternalIds(): iterable {
        yield 'minimal identity' => ['ext:a:b'];
        yield 'opaque case and separators' => ['ext:Billing_Team-2:Jane.Doe_ref-0042:invoice'];
        yield 'namespace and total length boundaries' => ['ext:' . str_repeat('n', 32) . ':' . str_repeat('i', 83)];
    }

    #[DataProvider('invalidExternalIds')]
    public function test_unsafe_external_identity_is_rejected_before_any_provider_access(string $externalId): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('dataReset');
        $client->expects(self::never())->method('sync');
        $client->expects(self::never())->method('loadFromAbraFlexi');
        $client->expects(self::never())->method('getInFormat');
        $gateway = $this->gateway($client);

        foreach (['issue', 'findByExternalId', 'downloadPdf'] as $operation) {
            try {
                if ($operation === 'issue') {
                    $gateway->issue($this->request(externalId: $externalId));
                } else {
                    $gateway->$operation($externalId);
                }
                self::fail('An unsafe identity must not reach the provider.');
            } catch (InvoiceGatewayException $exception) {
                self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExternalIds(): iterable {
        yield 'provider internal identity' => ['42'];
        yield 'missing namespace' => ['ext::document'];
        yield 'missing opaque identity' => ['ext:billing:'];
        yield 'namespace exceeds limit' => ['ext:' . str_repeat('n', 33) . ':document'];
        yield 'total identity exceeds limit' => ['ext:n:' . str_repeat('i', 115)];
        yield 'leading whitespace is not normalized' => [' ext:billing:document'];
        yield 'trailing newline is not normalized' => ["ext:billing:document\n"];
        yield 'path separator' => ['ext:billing:document/../other'];
        yield 'encoded path separator' => ['ext:billing:document%2Fother'];
        yield 'query delimiter' => ['ext:billing:document?detail=full'];
        yield 'filter quote' => ["ext:billing:document'"];
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_provider_references_are_rejected_before_writing(string $orderNumber, string $paymentReference): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('sync');

        try {
            $this->gateway($client)->issue($this->request(orderNumber: $orderNumber, paymentReference: $paymentReference));
            self::fail('Invalid provider references must not reach an invoice write.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReferences(): iterable {
        yield 'missing order reference' => ['', '42'];
        yield 'oversized order reference' => [str_repeat('n', 65), '42'];
        yield 'control character in order reference' => ["INV\n42", '42'];
        yield 'missing variable symbol' => ['INV/42', ''];
        yield 'oversized variable symbol' => ['INV/42', '12345678901'];
        yield 'non-decimal variable symbol' => ['INV/42', '12.34'];
        yield 'signed variable symbol' => ['INV/42', '+42'];
        yield 'newline in variable symbol' => ['INV/42', "42\n"];
    }

    public function test_issue_exception_is_classified_as_an_ambiguous_sanitized_write(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->lastResponseCode = 409; // A prior call may leave a definitive-looking but stale status.
        $client->method('sync')->willThrowException(new LogicException('password=secret'));

        try {
            $this->gateway($client)->issue($this->request());
            self::fail('The unknown write outcome should cross the gateway as a typed failure.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::AmbiguousWrite, $exception->failure);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_missing_sdk_http_status_keeps_a_failed_write_ambiguous(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturnCallback(static function () use ($client): bool {
            $client->lastResponseCode = null;
            return false;
        });
        try {
            $this->gateway($client)->issue($this->request());
            self::fail('No HTTP status cannot prove the write outcome.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::AmbiguousWrite, $exception->failure);
        }
    }
    public function test_issue_preparation_exception_is_retryable_without_an_unknown_write(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('dataReset')->willThrowException(new LogicException('password=secret'));

        try {
            $this->gateway($client)->issue($this->request());
            self::fail('A local preparation failure must not be classified as an unknown write.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::Retryable, $exception->failure);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }


    #[DataProvider('providerDates')]
    public function test_lookup_preserves_provider_calendar_dates_and_offsets(string $value, string $expected): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('loadFromAbraFlexi')->willReturn(1);
        $this->invoiceData($client, overrides: ['datVyst' => $value, 'duzpPuv' => $value]);

        $invoice = $this->gateway($client)->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice');

        self::assertNotNull($invoice);
        self::assertSame($expected, $invoice->issuedOn->format('Y-m-dP'));
        self::assertSame($expected, $invoice->taxPointOn->format('Y-m-dP'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function providerDates(): iterable {
        yield 'ABRA summer offset' => ['2026-09-05+02:00', '2026-09-05+02:00'];
        yield 'negative offset retains calendar date' => ['2026-09-05-05:30', '2026-09-05-05:30'];
        yield 'UTC leap day' => ['2024-02-29Z', '2024-02-29+00:00'];
    }

    #[DataProvider('invalidProviderDates')]
    public function test_lookup_rejects_invalid_calendar_dates_and_timezone_offsets(string $value): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('loadFromAbraFlexi')->willReturn(1);
        $this->invoiceData($client, overrides: ['datVyst' => $value]);
        try {
            $this->gateway($client)->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice');
            self::fail('Malformed provider dates must not roll into a different calendar day.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
            self::assertSame('datVyst', $exception->responseProblem?->field);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProviderDates(): iterable {
        yield 'non-leap February' => ['2026-02-29+02:00'];
        yield 'offset exceeds XML date limit' => ['2026-09-05+14:01'];
        yield 'invalid offset minutes' => ['2026-09-05-05:60'];
        yield 'trailing data' => ["2026-09-05+02:00\n"];
    }

    public function test_find_distinguishes_not_found_from_invalid_remote_identity(): void {
        $missing = $this->createStub(FakturaVydana::class);
        $missing->method('loadFromAbraFlexi')->willReturnCallback(static function () use ($missing): int {
            $missing->lastResponseCode = 404;
            return 0;
        });
        self::assertNull($this->gateway($missing)->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice'));

        $mismatched = $this->createStub(FakturaVydana::class);
        $mismatched->method('loadFromAbraFlexi')->willReturn(1);
        $mismatched->method('getDataValue')->willReturnMap([
            ['external-ids', ['ext:Other_Bridge:document.2026-0042:invoice']],
        ]);

        try {
            $this->gateway($mismatched)->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice');
            self::fail('A mismatched remote identity must not be accepted.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::IdentityConflict, $exception->failure);
        }
    }

    public function test_pdf_download_uses_configured_report_and_rejects_non_pdf_bytes(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::exactly(2))->method('loadFromAbraFlexi')->willReturn(1);
        $client->expects(self::exactly(2))->method('getDataValue')
            ->with('external-ids')
            ->willReturn(['ext:Accounting_Bridge:document.2026-0042:invoice']);
        $client->expects(self::exactly(2))->method('getInFormat')
            ->with('pdf', 'invoice', 'cs')
            ->willReturnOnConsecutiveCalls("%PDF-1.7\n%%EOF", '<html>login</html>');
        $gateway = $this->gateway($client);

        $pdf = $gateway->downloadPdf('ext:Accounting_Bridge:document.2026-0042:invoice');
        self::assertSame('application/pdf', $pdf->mediaType);
        self::assertSame("%PDF-1.7\n%%EOF", $pdf->bytes);

        try {
            $gateway->downloadPdf('ext:Accounting_Bridge:document.2026-0042:invoice');
            self::fail('A non-PDF upstream response must be rejected.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
        }
    }

    public function test_issue_rejects_an_out_of_range_remote_id(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client, id: (string) PHP_INT_MAX . '0');

        try {
            $this->gateway($client)->issue($this->request());
            self::fail('An out-of-range remote ID must not be truncated.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
        }
    }

    public function test_disabled_configuration_fails_before_the_vendor_client_is_used(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('dataReset');
        $configuration = $this->configuration(enabled: false);

        try {
            (new AbraFlexiInvoiceGateway($configuration, new NullLogger(), $client))->issue($this->request());
            self::fail('Disabled invoice access must fail closed.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::ConfigurationDisabled, $exception->failure);
        }
    }

    public function test_invalid_response_logs_the_mapping_field_without_sensitive_values(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturnCallback(static function () use ($client): bool {
            $client->lastResponseCode = 200;
            $client->curlInfo = [
                'http_method' => 'GET',
                'content_type' => 'application/json; charset=utf-8',
                'url' => 'https://api-user:api-password@abra.example.test/private',
            ];
            $client->lastCurlResponse = '{"nazFirmy":"Sensitive Customer","email":"private@example.test"}';
            return true;
        });
        $this->invoiceData($client, overrides: ['datVyst' => '2026-99-05+02:00']);
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->expects(self::once())->method('log')->willReturnCallback(
            static function ($level, $message, array $context) use (&$logged): void {
                $logged = ['level' => $level, 'message' => $message] + $context;
            },
        );

        try {
            (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))->issue($this->request());
            self::fail('Invalid dates must still fail invoice mapping.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
        }
        self::assertSame('error', $logged['level']);
        self::assertSame('create', $logged['operation']);
        self::assertSame('invalid_response', $logged['failureCode']);
        self::assertSame(200, $logged['httpStatus']);
        self::assertSame('GET', $logged['httpMethod']);
        self::assertSame('application/json', $logged['responseContentType']);
        self::assertSame('datVyst', $logged['responseField']);
        self::assertSame('string', $logged['responseValueType']);
        self::assertSame(16, $logged['responseValueLength']);
        $encoded = json_encode($logged, JSON_THROW_ON_ERROR);
        foreach ([
            'api-password', 'Sensitive Customer', 'private@example.test', 'Billing Customer', 'CZ12345678',
            'ext:Accounting_Bridge:document.2026-0042:invoice', 'INV 2026/0042',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_lookup_logs_transport_failure_without_reusing_previous_response(): void {
        $client = $this->createStub(FakturaVydana::class);
        $calls = 0;
        $client->method('loadFromAbraFlexi')->willReturnCallback(static function () use ($client, &$calls): never {
            if (++$calls === 1) {
                $client->lastResponseCode = 503;
                $client->curlInfo = ['http_method' => 'GET', 'content_type' => 'text/html'];
                $client->lastCurlResponse = '<html>private@example.test</html>';
            }
            throw new LogicException('https://api-user:api-password@abra.example.test/private');
        });
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('log')->willReturnCallback(
            static function ($level, $message, array $context) use (&$logged): void {
                $logged[] = ['level' => $level] + $context;
            },
        );
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $gateway->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice');
                self::fail('The lookup must still fail as retryable.');
            } catch (InvoiceGatewayException $exception) {
                self::assertSame(InvoiceGatewayFailure::Retryable, $exception->failure);
                self::assertNull($exception->getPrevious());
            }
        }
        self::assertSame('lookup', $logged[0]['operation']);
        self::assertSame('warning', $logged[0]['level']);
        self::assertSame(503, $logged[0]['httpStatus']);
        self::assertSame('text/html', $logged[0]['responseContentType']);
        self::assertSame(LogicException::class, $logged[0]['causeType']);
        self::assertNull($logged[1]['httpStatus']);
        self::assertNull($logged[1]['httpMethod']);
        self::assertNull($logged[1]['responseContentType']);
        self::assertSame(0, $logged[1]['responseBytes']);
        self::assertSame([], $logged[1]['providerErrorFields']);
        $encoded = json_encode($logged, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('api-password', $encoded);
        self::assertStringNotContainsString('private@example.test', $encoded);
    }

    public function test_provider_rejection_logs_only_allowlisted_error_fields(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturnCallback(static function () use ($client): bool {
            $client->lastResponseCode = 400;
            $client->curlInfo = ['http_method' => 'PUT'];
            $client->lastCurlResponse = json_encode(['winstrom' => [
                'errors' => [
                    ['for' => 'primUcet', 'message' => 'private@example.test'],
                    ['for' => 'api-password', 'message' => 'private@example.test'],
                ],
                'results' => [['errors' => [
                    ['path' => '/winstrom/faktura-vydana[1]/typDokl', 'message' => 'api-password'],
                ]]],
            ]], JSON_THROW_ON_ERROR);
            return false;
        });
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->willReturnCallback(
            static function ($level, $message, array $context) use (&$logged): void {
                $logged = $context;
            },
        );
        try {
            (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))->issue($this->request());
            self::fail('The rejected request must remain rejected.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::RejectedRequest, $exception->failure);
        }
        self::assertSame(400, $logged['httpStatus']);
        self::assertSame(['primUcet', 'typDokl'], $logged['providerErrorFields']);
        $encoded = json_encode($logged, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('api-password', $encoded);
        self::assertStringNotContainsString('private@example.test', $encoded);
    }

    public function test_pdf_transport_failure_does_not_report_the_successful_lookup_status(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('loadFromAbraFlexi')->willReturnCallback(static function () use ($client): int {
            $client->lastResponseCode = 200;
            $client->curlInfo = ['http_method' => 'GET', 'content_type' => 'application/json'];
            return 1;
        });
        $client->method('getDataValue')->willReturn(['ext:Accounting_Bridge:document.2026-0042:invoice']);
        $client->method('getInFormat')->willReturnCallback(static function () use ($client): never {
            $client->format = 'pdf';
            throw new LogicException('private PDF details');
        });
        $client->method('setFormat')->willThrowException(new UnexpectedValueException('private cleanup details'));
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->willReturnCallback(
            static function ($level, $message, array $context) use (&$logged): void {
                $logged = $context;
            },
        );
        try {
            (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))
                ->downloadPdf('ext:Accounting_Bridge:document.2026-0042:invoice');
            self::fail('The failed PDF download must remain retryable.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::Retryable, $exception->failure);
        }
        self::assertSame('pdf_download', $logged['operation']);
        self::assertSame(LogicException::class, $logged['causeType']);
        self::assertNull($logged['httpStatus']);
        self::assertNull($logged['responseContentType']);
        self::assertStringNotContainsString('private PDF details', json_encode($logged, JSON_THROW_ON_ERROR));
    }

    public function test_logging_failure_does_not_replace_the_invoice_failure(): void {
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client, overrides: ['datVyst' => null]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->willThrowException(new LogicException('Log storage is unwritable.'));
        try {
            (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))->issue($this->request());
            self::fail('Missing response dates must not be accepted.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::InvalidResponse, $exception->failure);
            self::assertSame('datVyst', $exception->responseProblem?->field);
        }
    }

    #[DataProvider('observedOrderNumbers')]
    public function test_lookup_reports_order_presence_without_logging_or_rewriting_it(?string $orderNumber, bool $present): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::never())->method('sync');
        $client->method('loadFromAbraFlexi')->willReturn(1);
        $this->invoiceData($client, overrides: ['cisObj' => $orderNumber, 'nazFirmy' => 'private@example.test']);
        $logger = $this->createMock(LoggerInterface::class);
        $logged = [];
        $logger->expects(self::once())->method('info')->with(
            'commerce.abra.invoice_read',
            self::callback(static function (array $context) use (&$logged): bool {
                $logged = $context;
                return true;
            }),
        );
        $invoice = (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))
            ->findByExternalId('ext:Accounting_Bridge:document.2026-0042:invoice');
        self::assertNotNull($invoice);
        self::assertSame('lookup', $logged['operation']);
        self::assertNull($logged['expectedOrderNumberHash']);
        self::assertSame($present, $logged['orderNumberPresent']);
        self::assertNull($logged['orderNumberMatchesRequest']);
        self::assertTrue($logged['buyerNamePresent']);
        self::assertStringNotContainsString('private@example.test', json_encode($logged, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function observedOrderNumbers(): iterable {
        yield 'old empty order number' => ['', false];
        yield 'missing field' => [null, false];
        yield 'untrusted order number' => ['private@example.test', true];
        yield 'provider order number' => ['INV 2026/0042', true];
    }

    public function test_submission_diagnostic_failure_does_not_prevent_or_repeat_creation(): void {
        $client = $this->createMock(FakturaVydana::class);
        $client->expects(self::once())->method('sync')->willReturn(true);
        $this->invoiceData($client);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info')
            ->willThrowException(new LogicException('Log storage is unwritable.'));
        $invoice = (new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client))->issue($this->request());
        self::assertSame(481, $invoice->internalId);
    }

    public function test_success_and_expected_lookup_miss_do_not_emit_failure_logs(): void {
        $externalId = 'ext:billing:Jane.Doe:document-42';
        $orderNumber = 'private@example.test / appointment 42';
        $client = $this->createStub(FakturaVydana::class);
        $client->method('sync')->willReturn(true);
        $this->invoiceData($client, overrides: ['external-ids' => [$externalId], 'cisObj' => $orderNumber]);
        $client->method('loadFromAbraFlexi')->willReturnCallback(static function () use ($client): int {
            $client->lastResponseCode = 404;
            return 0;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $events = [];
        $logger->expects(self::exactly(2))->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$events): void {
                $events[$message] = $context;
            },
        );
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), $logger, $client);
        self::assertSame(481, $gateway->issue($this->request(
            recipient: null,
            externalId: $externalId,
            orderNumber: $orderNumber,
        ))->internalId);
        self::assertNull($gateway->findByExternalId($externalId));
        self::assertSame(hash('sha256', $orderNumber), $events['commerce.abra.invoice_submit']['orderNumberHash']);
        self::assertSame(hash('sha256', $externalId), $events['commerce.abra.invoice_submit']['externalIdHash']);
        self::assertContains('cisObj', $events['commerce.abra.invoice_submit']['requestFields']);
        self::assertTrue($events['commerce.abra.invoice_submit']['usesRecipientName']);
        self::assertFalse($events['commerce.abra.invoice_submit']['hasBillingRecipient']);
        self::assertSame('create', $events['commerce.abra.invoice_read']['operation']);
        self::assertStringNotContainsString('customer@example.test', json_encode($events, JSON_THROW_ON_ERROR));
        $encoded = json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($orderNumber, $encoded);
        self::assertStringNotContainsString($externalId, $encoded);
        self::assertStringNotContainsString('Jane.Doe', $encoded);
        self::assertStringNotContainsString('private@example.test', $encoded);
    }

    private function gateway(FakturaVydana $client): AbraFlexiInvoiceGateway {
        return new AbraFlexiInvoiceGateway($this->configuration(), new NullLogger(), $client);
    }

    private function configuration(bool $enabled = true, string $currencyCode = 'CZK'): AbraFlexiConfiguration {
        return new AbraFlexiConfiguration(
            enabled: $enabled,
            apiUrl: 'https://abra.example.test',
            company: 'test_company',
            username: 'api-user',
            password: 'api-password',
            currencyCode: $currencyCode,
            pdfReportName: 'invoice',
            pdfLanguage: 'cs',
        );
    }

    private function request(
        ?IssueInvoiceRecipient $recipient = new IssueInvoiceRecipient(
            legalName: 'Billing Customer',
            street: 'Test 42',
            city: 'Prague',
            postalCode: '11000',
            countryCode: 'CZ',
            companyRegistrationNumber: '12345678',
            vatId: 'CZ12345678',
        ),
        bool $inheritDefaults = false,
        ?string $recipientName = 'customer@example.test',
        string $currencyCode = 'CZK',
        string $externalId = 'ext:Accounting_Bridge:document.2026-0042:invoice',
        string $orderNumber = 'INV 2026/0042',
        string $paymentReference = '0042',
        string $quantity = '1',
    ): IssueInvoiceRequest {
        return new IssueInvoiceRequest(
            orderNumber: $orderNumber,
            recipientName: $recipientName,
            externalId: $externalId,
            paymentMethodCode: $inheritDefaults ? null : 'GOPAY',
            debitAccountCode: $inheritDefaults ? null : '311000',
            creditAccountCode: $inheritDefaults ? null : '602000',
            documentTypeCode: 'FAKTURA',
            numberSeriesCode: $inheritDefaults ? null : 'INVOICE-SERIES',
            issuedOn: $inheritDefaults ? null : new DateTimeImmutable('2026-09-04'),
            taxPointOn: $inheritDefaults ? null : new DateTimeImmutable('2026-09-04'),
            dueOn: $inheritDefaults ? null : new DateTimeImmutable('2026-09-04'),
            currencyCode: $currencyCode,
            paymentReference: $paymentReference,
            lines: [new IssueInvoiceLine(
                description: 'Consulting 90 minutes',
                quantity: $quantity,
                unitCode: $inheritDefaults ? null : 'KS',
                unitPriceMinor: 99000,
                netAmountMinor: 81818,
                vatAmountMinor: 17182,
                grossAmountMinor: 99000,
                priceTypeCode: 'typCeny.sDph',
                vatRateTypeCode: 'typSzbDph.dphZakl',
                vatRateBasisPoints: 2100,
            )],
            recipient: $recipient,
        );
    }

    /**
     * @param FakturaVydana&Stub $client
     * @param array<string, mixed> $overrides
     */
    private function invoiceData(
        FakturaVydana $client,
        string $grossAmount = '990.00',
        string $id = '481',
        string $currencyCode = 'CZK',
        array $overrides = [],
    ): void {
        $data = array_replace([
            'external-ids' => ['ext:Accounting_Bridge:document.2026-0042:invoice'],
            'id' => $id,
            'kod' => '2026-0019',
            'typDokl' => 'code:FAKTURA',
            'rada' => 'code:INVOICE-SERIES',
            'datVyst' => '2026-09-04+02:00',
            'duzpPuv' => '2026-09-04+02:00',
            'mena' => 'code:' . $currencyCode,
            'sumZklCelkem' => '818.18',
            'sumDphCelkem' => '171.82',
            'sumCelkem' => $grossAmount,
            'stavUhrK' => '',
        ], $overrides);
        $map = [];
        foreach ($data as $field => $value) {
            $map[] = [$field, $value];
        }
        $client->method('getDataValue')->willReturnMap($map);
    }
}
