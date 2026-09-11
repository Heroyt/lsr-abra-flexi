# LSR ABRA Flexi

`lsr/abra-flexi` provides typed invoice and price-list adapters over `spojenet/flexibee`, with guarded HTTP transport, sanitized diagnostics, and optional Nette DI registration.

The package issues invoices, looks them up by an application-supplied external ID, downloads their PDFs, and reads catalogue prices. It does not depend on an application's `App\` namespace or require LSR Core.

## Requirements

- 64-bit PHP `>=8.4`, PHP cURL and JSON extensions, and libcurl `>=7.85`.
- An HTTPS ABRA Flexi endpoint, company identifier, and credentials authorized for the requested operations.
- A PSR-3 logger. Examples use `NullLogger`; production applications should supply their own logger.
- Composer installs the SDK, Nette DI `^3.2`, Nette Schema `^1.3`, and remaining dependencies. Using a Nette container is optional.
- Tests additionally require OpenSSL and zlib, plus PHPUnit's dependencies. Integration tests start a local TLS fixture; they do not require an ABRA account.

**Currency scope:** all monetary integers represent hundredths of the configured company's domestic currency. For example, `12100` means `121.00 CZK` when `currencyCode` is `CZK`. The adapter does not perform foreign-currency conversion or infer currencies' decimal precision. Request and returned invoice currencies must match the configuration. The application owns currency eligibility and tax policy.

## Installation

For local integration before publishing a release, register this checkout as a Composer path repository in the consuming application. Replace the example path with the actual package directory:

```shell
composer config repositories.abra-flexi '{"type":"path","url":"/absolute/path/to/lsr-abra-flexi","options":{"symlink":true}}'
composer require lsr/abra-flexi:dev-main
```

This symlinks the working package into the application. Do not deploy an application with a workstation-specific path repository. Once a release is published, remove the temporary repository and require its released version from the configured Composer repository instead. These instructions do not imply that the package has already been published.

## Nette DI

Register the extension and a logger in application-owned NEON:

```neon
extensions:
    abraFlexi: Lsr\AbraFlexi\Di\AbraFlexiExtension

parameters:
    abraUsername: ''
    abraPassword: ''

services:
    logger: Psr\Log\NullLogger

abraFlexi:
    enabled: false
    apiUrl: 'https://accounting.example.test'
    company: 'company'
    username: %abraUsername%
    password: %abraPassword%
    currencyCode: CZK
    logger: @logger
```

Replace the example connection settings, supply credentials through the application's parameter/secret integration, and explicitly enable access. Keep the service reference `@logger` unquoted. If the application already has a logger, reference that service instead of registering another. Omitting `logger` requires an unambiguous autowired `Psr\Log\LoggerInterface`.

The extension registers `AbraFlexiConfiguration`, `AbraFlexiClientFactory`, `AbraFlexiInvoiceGateway`, and `AbraFlexiPriceListGateway` under the chosen extension prefix. Inject the gateways by type into application services.

See [the complete NEON example](examples/abra-flexi.neon) and [the configuration schema](src/Di/AbraFlexiExtension.php). Defaults are:

| Option | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Explicitly enable provider access. |
| `apiUrl`, `company`, `username`, `password` | Empty strings | Required connection settings. `apiUrl` must use HTTPS. |
| `currencyCode` | `CZK` | Configured domestic currency; three uppercase letters. |
| `timeout` | `30` | Positive request timeout in seconds. |
| `verifyTls` | `true` | Verify the peer certificate and hostname. Keep enabled in production. |
| `pdfReportName` | `null` | Optional provider report name. |
| `pdfLanguage` | `null` | Optional `cs`, `sk`, `en`, or `de`. |
| `traceCurl` | `false` | Opt in to bounded, allowlisted cURL diagnostics. |
| `logger` | `null` | Autowire a PSR-3 logger unless a service is selected explicitly. |

The package does not read environment variables itself. Username and password support dynamic Nette parameters; register them as dynamic with Nette's `ParametersExtension` before compilation if secrets must be supplied at container instantiation rather than embedded in the compiled container.

## Direct construction

Without a Nette container, construct the gateways directly. The environment variable names below are an application convention, not package configuration keys:

```php
<?php

require 'vendor/autoload.php';

use Lsr\AbraFlexi\AbraFlexiConfiguration;
use Lsr\AbraFlexi\AbraFlexiInvoiceGateway;
use Lsr\AbraFlexi\AbraFlexiPriceListGateway;
use Psr\Log\NullLogger;

$configuration = new AbraFlexiConfiguration(
    enabled: true,
    apiUrl: (string) getenv('ABRA_API_URL'),
    company: (string) getenv('ABRA_COMPANY'),
    username: (string) getenv('ABRA_USERNAME'),
    password: (string) getenv('ABRA_PASSWORD'),
    currencyCode: 'CZK',
);
$logger = new NullLogger();
$invoices = new AbraFlexiInvoiceGateway($configuration, $logger);
$priceList = new AbraFlexiPriceListGateway($configuration, $logger);
```

Missing credentials or invalid connection settings do not enable access. Gateway instances hold mutable SDK clients for one fixed company: reuse them sequentially, not concurrently, and do not share them across tenants.

## Price-list lookup

Using the `$priceList` gateway above:

```php
$item = $priceList->findByCode('SERVICE-90');
```

The result is a [PriceListItem](src/Dto/PriceListItem.php), or `null` when the lookup yields no record. It exposes the code, name, currency, gross and net price amounts (`priceAmountMinor` and `netPriceAmountMinor`), and provider price/VAT type codes. Lookup trims surrounding whitespace and accepts an optional `code:` prefix. The remaining code must contain 1–64 printable bytes and is sent as an encoded literal selector, not filter syntax.

Invalid lookup input raises `InvalidArgumentException`; unavailable configuration or SDK failures raise `RuntimeException`; incomplete or malformed price data raises `UnexpectedValueException`. Price-list errors do not use the invoice failure enum. SDK exception messages and previous-exception chains are not forwarded.

## Issue an invoice

The following request represents one item with a gross price of `121.00 CZK`, net `100.00 CZK`, and VAT `21.00 CZK`. The document and VAT type codes are illustrative: choose codes and tax treatment valid for the company. The application must durably assign the external ID before submitting a write.

```php
use Lsr\AbraFlexi\Dto\IssueInvoiceLine;
use Lsr\AbraFlexi\Dto\IssueInvoiceRequest;

$request = new IssueInvoiceRequest(
    orderNumber: 'ORDER-0042',
    recipientName: 'Example customer',
    externalId: 'ext:billing:invoice.42',
    paymentMethodCode: null,
    debitAccountCode: null,
    creditAccountCode: null,
    documentTypeCode: 'FAKTURA',
    numberSeriesCode: null,
    issuedOn: null,
    taxPointOn: null,
    dueOn: null,
    currencyCode: 'CZK',
    paymentReference: '0042',
    lines: [new IssueInvoiceLine(
        description: 'Consulting',
        quantity: '1',
        unitCode: null,
        unitPriceMinor: 12100,
        netAmountMinor: 10000,
        vatAmountMinor: 2100,
        grossAmountMinor: 12100,
        priceTypeCode: 'typCeny.sDph',
        vatRateTypeCode: 'typSzbDph.dphZakl',
        vatRateBasisPoints: 2100,
    )],
    recipient: null,
);
```

Null optional codes and dates are omitted so the ABRA document defaults apply. For complete billing details, pass an [IssueInvoiceRecipient](src/Dto/IssueInvoiceRecipient.php); `recipientName` is only an explicit name fallback when no recipient is supplied. The package checks the country-code shape, not customer or country eligibility.

**The next call writes to ABRA.** Run it only from an application-owned operation that has persisted the identity and applies its own concurrency and recovery policy:

```php
$invoice = $invoices->issue($request);
```

The returned [IssuedInvoice](src/Dto/IssuedInvoice.php) includes internal and external IDs, document number, type, series, dates, currency, and net/VAT/gross totals. Issuance verifies the returned identity, currency, totals, and explicitly requested type, series, and relevant dates.

### Request invariants

- `externalId` is an exact `ext:<namespace>:<opaque-id>` selector, at most 120 bytes. The namespace accepts 1–32 ASCII letters, digits, underscores, or hyphens; the nonempty opaque ID additionally accepts dots and colons. It is not trimmed, normalized, or generated by the package.
- `orderNumber` is an independent printable reference of 1–64 bytes. `paymentReference` is an independent variable symbol of 1–10 decimal digits, preserving leading zeroes.
- Document type, number series, payment method, account, and unit codes are supplied without a `code:` prefix. Provider enum codes such as `typCeny.sDph` retain their provider notation.
- At least one line is required. Quantities are positive decimal strings with at most six fractional digits; quantities below one must end in a nonzero fractional digit (for example, `0.5`, not `0.50`). Monetary integers must be nonnegative, and each line's net amount plus VAT must equal its gross amount. VAT basis points range from `0` to `10000`; `2100` represents `21%`.
- The caller supplies tax rates and coherent accounting amounts. This is not a tax engine or an application invoice lifecycle.

## Lookup, PDFs, and recovery

Use the same durable external ID for subsequent operations:

```php
$existing = $invoices->findByExternalId($request->externalId);
$pdf = $invoices->downloadPdf($request->externalId);
```

Lookup returns `IssuedInvoice|null`. PDF download returns [InvoicePdf](src/Dto/InvoicePdf.php) with `bytes` and `mediaType`; it verifies the invoice identity and a `%PDF-` prefix. It does not perform full PDF parsing, write a file, calculate a storage hash, or deliver the document. Storage, access control, and delivery remain application responsibilities.

Invoice operations throw [InvoiceGatewayException](src/Exceptions/InvoiceGatewayException.php). Inspect its `failure` enum rather than matching message text:

| Failure | Application response |
| --- | --- |
| `ConfigurationDisabled` | Enable access or correct connection/PDF configuration. |
| `Retryable` | Schedule another attempt under application policy; the package performs no retries. |
| `AmbiguousWrite` | A write may have succeeded. Reconcile using the same external ID before any further write. |
| `RejectedRequest` | Correct invalid request data or a provider rejection. |
| `IdentityConflict` | Investigate an identity, requested-data mismatch, or missing invoice during PDF retrieval. Do not overwrite blindly. |
| `InvalidResponse` | Investigate malformed/incompatible provider data. After issuance, reconcile rather than assuming no write occurred. |

`issue()` does not perform a lookup-first deduplication check, and the package does not guarantee exactly-once execution. Persist request intent and identity, serialize competing operations in the application, and compare recovered invoices against the expected accounting snapshot. A failed or temporarily empty lookup after an ambiguous write is not proof that creating another invoice is safe. Do not generate a new identity merely because a request timed out. PDF failures likewise do not justify reissuing an invoice.

## Transport and logging

- Factory-created SDK clients verify TLS by default. Redirects are bounded to three hops and restricted to HTTPS on the same host and port, without embedded credentials. Write method/body semantics are preserved; a write redirect that would switch to GET is rejected.
- Diagnostics use sanitized metadata, bounded allowlists, and hashed document references. `traceCurl` is an explicit opt-in independent of application environment mode; it is not a raw request/response dump.
- Never log or dump configuration, credentials, SDK client objects, invoice requests, or PDF bytes. Application logging outside these adapters needs its own redaction policy.
- Directly injected SDK clients are an advanced integration seam; configure their transport protections yourself. Normal DI and direct gateway construction use [AbraFlexiClientFactory](src/AbraFlexiClientFactory.php).

Purchase identities and roles, customer/country eligibility, order-number derivation, accounting snapshots, lifecycle transitions, retry scheduling, persistence, PDF storage, and customer delivery belong to the consuming application. In an extraction from an existing application, retain its durable identity scheme instead of adopting the example ID above.

## Development

From a package checkout:

```shell
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

Use `composer cs:fix` to apply formatting. [GitHub Actions CI](.github/workflows/ci.yml) runs the checks on PHP 8.4 and 8.5 on Ubuntu 24.04 for pushes, pull requests, and manual dispatches. The test suite includes local TLS/redirect, real SDK request, sanitized diagnostic, and Nette DI checks; it does not contact a production ABRA service.

## License

Licensed under the [MIT License](LICENSE).
