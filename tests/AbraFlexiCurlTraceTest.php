<?php

declare(strict_types=1);

namespace Tests;

use CurlHandle;
use LogicException;
use Lsr\AbraFlexi\AbraFlexiCurlTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AbraFlexiCurlTraceTest extends TestCase
{
    private CurlHandle $handle;
    private AbraFlexiCurlTrace $trace;
    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $records = [];
    private bool $loggerFailing = false;

    protected function setUp(): void {
        $handle = curl_init();
        self::assertInstanceOf(CurlHandle::class, $handle);
        $this->handle = $handle;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function (string $message, array $context): void {
            $this->records[] = ['message' => $message, 'context' => $context];
        });
        $this->trace = new AbraFlexiCurlTrace($logger);
    }

    public function test_fragmented_json_is_logged_only_when_complete_and_directions_remain_independent(): void {
        $request = '{"winstrom":{"faktura-vydana":[{"datVyst":"2026-09-05+02:00","stavUhrK":"","password":"api-password"}]}}';
        $this->requestHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, substr($request, 0, 43));
        self::assertSame([], $this->events('request', 'body'));

        ($this->trace)($this->handle, CURLINFO_HEADER_IN, "HTTP/1.1 100 Continue\r\n");
        ($this->trace)($this->handle, CURLINFO_HEADER_IN, "\r\n");
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, substr($request, 43, -1));
        self::assertSame([], $this->events('request', 'body'));
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, substr($request, -1));

        $invoice = $this->body('request')['winstrom']['faktura-vydana'][0];
        self::assertSame('2026-09-05+02:00', $invoice['datVyst']);
        self::assertSame('', $invoice['stavUhrK']);
        $this->assertSecretsAbsent(['api-password']);

        $this->responseHeaders();
        $response = '{"winstrom":{"faktura-vydana":[{"id":42,"datVyst":"2026-09-05+02:00","stavUhrK":"","sumCelkem":"990.00","mena":"code:CZK","nazFirmy":"private@example.test"}]}}';
        ($this->trace)($this->handle, CURLINFO_DATA_IN, substr($response, 0, 67));
        self::assertSame([], $this->events('response', 'body'));
        ($this->trace)($this->handle, CURLINFO_DATA_IN, substr($response, 67, -2));
        self::assertSame([], $this->events('response', 'body'));
        ($this->trace)($this->handle, CURLINFO_DATA_IN, substr($response, -2));

        $invoice = $this->body('response')['winstrom']['faktura-vydana'][0];
        self::assertSame(42, $invoice['id']);
        self::assertSame('2026-09-05+02:00', $invoice['datVyst']);
        self::assertSame('', $invoice['stavUhrK']);
        self::assertSame('990.00', $invoice['sumCelkem']);
        self::assertSame('code:CZK', $invoice['mena']);
        self::assertCount(1, $this->events('request', 'body'));
        $this->assertSecretsAbsent(['api-password', 'private@example.test']);
    }

    public function test_nested_json_preserves_diagnostics_but_redacts_credentials_errors_and_unknown_keys(): void {
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, json_encode(['winstrom' => [
            'results' => [[
                'id' => 42,
                'content' => [
                    'faktura-vydana' => [[
                        'datVyst' => '2026-09-05+02:00',
                        'stavUhrK' => 'stavUhr.uhrazenoRucne',
                        'sumCelkem' => '990.00',
                        'nazFirmy' => 'Sensitive Customer',
                        'ulice' => 'Private Street 17',
                        'dic' => 'CZ12345678',
                        'polozkyFaktury' => [[
                            'id' => 51,
                            'cenaMj' => '818.18',
                            'nazev' => 'Private invoice description',
                            'password' => ['content' => ['id' => 987654321]],
                        ]],
                    ]],
                    'cenik' => [['id' => 71, 'nazev' => 'Private catalog title']],
                ],
                'errors' => [[
                    'for' => 'nazFirmy',
                    'message' => 'private@example.test rejected with api-password',
                    'path' => '/c/private-company/faktura-vydana/private-invoice',
                    'access_token' => 'private-access-token',
                    'content' => [['message' => 'Nested private error']],
                ]],
            ]],
            'password' => 'root-password',
            'private-key@example.test' => ['id' => 876543219],
            'providerExtension' => ['content' => ['datVyst' => '2026-01-02']],
        ]], JSON_THROW_ON_ERROR));

        $result = $this->body('response')['winstrom']['results'][0];
        self::assertSame(42, $result['id']);
        $invoice = $result['content']['faktura-vydana'][0];
        self::assertSame('2026-09-05+02:00', $invoice['datVyst']);
        self::assertSame('stavUhr.uhrazenoRucne', $invoice['stavUhrK']);
        self::assertSame('990.00', $invoice['sumCelkem']);
        self::assertSame(51, $invoice['polozkyFaktury'][0]['id']);
        self::assertSame('818.18', $invoice['polozkyFaktury'][0]['cenaMj']);
        self::assertSame(71, $result['content']['cenik'][0]['id']);
        self::assertIsArray($result['errors']);
        $this->assertSecretsAbsent([
            'Sensitive Customer', 'Private Street 17', 'CZ12345678', 'Private invoice description',
            '987654321', 'Private catalog title', 'private@example.test', 'api-password',
            'private-company', 'private-invoice', 'private-access-token', 'Nested private error',
            'root-password', 'private-key@example.test', '876543219', '2026-01-02',
        ]);
    }

    public function test_allowlisted_fields_do_not_admit_arbitrary_strings_or_scalar_containers(): void {
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, json_encode(['winstrom' => [
            'faktura-vydana' => [[
                'id' => 'private-external-id',
                'datVyst' => 'private-date-value',
                'stavUhrK' => 'stavUhr.private-status',
                'mena' => 'code:private-currency',
                'sumCelkem' => 'private-amount',
                'polozkyFaktury' => 'private-line-items',
            ]],
            'results' => ['private-result'],
            'errors' => 'private-error',
        ]], JSON_THROW_ON_ERROR));

        self::assertIsArray($this->body('response')['winstrom']['faktura-vydana']);
        $this->assertSecretsAbsent([
            'private-external-id', 'private-date-value', 'private-status', 'private-currency',
            'private-amount', 'private-line-items', 'private-result', 'private-error',
        ]);
    }

    public function test_generic_external_ids_and_order_references_are_never_logged(): void {
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, json_encode(['winstrom' => [
            'faktura-vydana' => [[
                'id' => 'ext:Accounting_Bridge:private.customer-42:invoice',
                'external-ids' => ['ext:Sales:private.customer-42'],
                'cisObj' => 'Private Customer / order 42',
                'mena' => 'code:GBP',
            ], [
                'cisObj' => 'TH-2026000042',
                'mena' => 'USD',
            ], [
                'cisObj' => '9876543210',
                'mena' => 'gbp',
            ]],
        ]], JSON_THROW_ON_ERROR));

        $invoices = $this->body('response')['winstrom']['faktura-vydana'];
        self::assertSame('code:GBP', $invoices[0]['mena']);
        self::assertSame('USD', $invoices[1]['mena']);
        $this->assertSecretsAbsent([
            'Accounting_Bridge', 'private.customer-42', 'Private Customer / order 42',
            'TH-2026000042', '9876543210', 'gbp',
        ]);
    }

    public function test_headers_keep_transport_diagnostics_without_targets_credentials_or_untrusted_names(): void {
        ($this->trace)(
            $this->handle,
            CURLINFO_HEADER_OUT,
            "POST https://proxy-user:proxy-password@private-origin.test/private-company/faktura-vydana.json?token=query-secret HTTP/1.1\r\n"
            . "Host: private-origin.test\r\n"
            . "aUtHoRiZaTiOn: Bearer private-bearer-token\r\n"
            . "Proxy-Authorization: Basic private-proxy-authorization\r\n"
            . "Cookie: session=private-cookie\r\n"
            . "Content-Type: application/json; boundary=private-boundary\r\n"
            . "Content-Length: 128\r\n"
            . "X-private-header-name: private-header-value\r\n\r\n",
        );
        foreach ([
            "HTTP/1.1 200 private-status-reason\r\n",
            "Content-Type: application/json; charset=utf-8\r\n",
            "Content-Length: 128\r\n",
            "Content-Encoding: gzip\r\n",
            "Set-Cookie: session=private-response-cookie; HttpOnly\r\n",
            "Location: https://private-redirect.test/path?token=private-location-token\r\n",
            "X-Provider-Error: private-provider-message\r\n",
            "\r\n",
        ] as $line) {
            ($this->trace)($this->handle, CURLINFO_HEADER_IN, $line);
        }

        $request = json_encode($this->events('request', 'headers'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = json_encode($this->events('response', 'headers'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['POST', '1.1', 'application/json', '128'] as $diagnostic) {
            self::assertStringContainsString($diagnostic, $request);
        }
        foreach (['200', '1.1', 'application/json', '128', 'gzip'] as $diagnostic) {
            self::assertStringContainsString($diagnostic, $response);
        }
        $this->assertSecretsAbsent([
            'proxy-user', 'proxy-password', 'private-origin.test', 'private-company', 'faktura-vydana.json',
            'query-secret', 'private-bearer-token', 'private-proxy-authorization', 'private-cookie',
            'private-boundary', 'private-header-name', 'private-header-value', 'private-status-reason',
            'private-response-cookie', 'private-redirect.test', 'private-location-token', 'private-provider-message',
        ]);
    }

    #[DataProvider('unsupportedBodies')]
    public function test_unsupported_bodies_are_omitted_without_raw_payloads(string $contentType, ?string $encoding, string $payload): void {
        $this->responseHeaders($contentType, $encoding);
        ($this->trace)($this->handle, CURLINFO_DATA_IN, $payload);

        $events = $this->events('response', 'body');
        self::assertNotEmpty($events, 'Unsupported payloads need a diagnostic omission event.');
        foreach ($events as $event) {
            self::assertTrue($event['omitted'] ?? false);
        }
        self::assertStringNotContainsString($payload, serialize($this->records));
        $this->assertSecretsAbsent(['private-payload', '%PDF-1.7']);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function unsupportedBodies(): iterable {
        yield 'PDF' => ['application/pdf', null, "%PDF-1.7\nprivate-payload\x00\xff"];
        yield 'compressed JSON' => [
            'application/json',
            'gzip',
            gzencode('{"winstrom":{"message":"private-payload"}}') ?: throw new LogicException('Cannot encode fixture.'),
        ];
        yield 'HTML' => ['text/html', null, '<html>private-payload</html>'];
        yield 'invalid JSON' => ['application/json', null, '{"winstrom":!"private-payload"}'];
        yield 'invalid UTF-8 JSON' => ['application/json', null, "{\"winstrom\":{\"message\":\"private-payload\xff\"}}"];
    }

    public function test_oversize_fragmented_json_is_truncated_and_does_not_poison_the_next_exchange(): void {
        $this->requestHeaders();
        $prefix = '{"winstrom":{"faktura-vydana":[{"id":42,"nazFirmy":"';
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, str_pad($prefix, 65536, 'x'));
        self::assertSame([], $this->events('request', 'body'));
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, 'private-oversize-tail"}]}}');

        $events = $this->events('request', 'body');
        self::assertCount(1, $events);
        self::assertTrue($events[0]['omitted'] ?? false);
        self::assertTrue($events[0]['truncated'] ?? false);
        $this->assertSecretsAbsent(['private-oversize-tail', str_repeat('x', 128)]);

        $this->records = [];
        $this->requestHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, '{"winstrom":{"faktura-vydana":[{"id":73}]}}');
        self::assertSame(73, $this->body('request')['winstrom']['faktura-vydana'][0]['id']);
    }

    public function test_new_request_headers_reset_both_directions_including_previous_content_encoding(): void {
        $this->requestHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, '{"winstrom":{"faktura-vydana":[{"datVyst":"2026-');
        $this->responseHeaders('application/json', 'gzip');
        ($this->trace)($this->handle, CURLINFO_DATA_IN, 'private-compressed-fragment');

        $this->records = [];
        $this->requestHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, '{"winstrom":{"faktura-vydana":[{"id":81}]}}');
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, '{"winstrom":{"faktura-vydana":[{"id":82}]}}');

        self::assertSame(81, $this->body('request')['winstrom']['faktura-vydana'][0]['id']);
        self::assertSame(82, $this->body('response')['winstrom']['faktura-vydana'][0]['id']);
        $this->assertSecretsAbsent(['private-compressed-fragment', '2026-']);
    }

    public function test_incoming_status_line_discards_an_incomplete_previous_response(): void {
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, '{"winstrom":{"faktura-vydana":[{"nazFirmy":"abandoned-private-value');
        self::assertSame([], $this->events('response', 'body'));

        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, '{"winstrom":{"faktura-vydana":[{"id":91,"stavUhrK":""}]}}');

        $bodies = array_values(array_filter(
            $this->events('response', 'body'),
            static fn (array $event): bool => ! ($event['omitted'] ?? false),
        ));
        self::assertCount(1, $bodies);
        $body = json_decode($bodies[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(91, $body['winstrom']['faktura-vydana'][0]['id']);
        self::assertSame('', $body['winstrom']['faktura-vydana'][0]['stavUhrK']);
        $this->assertSecretsAbsent(['abandoned-private-value']);
    }

    public function test_internal_text_and_ssl_records_are_never_logged(): void {
        foreach ([CURLINFO_TEXT, CURLINFO_SSL_DATA_IN, CURLINFO_SSL_DATA_OUT] as $type) {
            ($this->trace)($this->handle, $type, "private-api-password private-cookie private@example.test\x00\xff");
        }

        self::assertSame([], $this->records);
    }

    public function test_logger_failures_do_not_escape_or_prevent_later_sanitized_delivery(): void {
        $this->loggerFailing = true;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function (string $message, array $context): void {
            if ($this->loggerFailing) {
                throw new LogicException('Log storage is unwritable.');
            }
            $this->records[] = ['message' => $message, 'context' => $context];
        });
        $this->trace = new AbraFlexiCurlTrace($logger);

        $this->requestHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_OUT, '{"winstrom":{"faktura-vydana":[{"id":42,"password":"private-password"}]}}');
        $this->loggerFailing = false;
        $this->responseHeaders();
        ($this->trace)($this->handle, CURLINFO_DATA_IN, '{"winstrom":{"faktura-vydana":[{"id":43,"password":"private-password"}]}}');

        self::assertSame(43, $this->body('response')['winstrom']['faktura-vydana'][0]['id']);
        $this->assertSecretsAbsent(['private-password']);
    }

    private function requestHeaders(): void {
        ($this->trace)(
            $this->handle,
            CURLINFO_HEADER_OUT,
            "POST /c/private-company/faktura-vydana.json HTTP/1.1\r\nContent-Type: application/json\r\n\r\n",
        );
    }

    private function responseHeaders(string $contentType = 'application/json', ?string $encoding = null): void {
        ($this->trace)($this->handle, CURLINFO_HEADER_IN, "HTTP/1.1 200 OK\r\n");
        ($this->trace)($this->handle, CURLINFO_HEADER_IN, 'Content-Type: ' . $contentType . "\r\n");
        if ($encoding !== null) {
            ($this->trace)($this->handle, CURLINFO_HEADER_IN, 'Content-Encoding: ' . $encoding . "\r\n");
        }
        ($this->trace)($this->handle, CURLINFO_HEADER_IN, "\r\n");
    }

    /** @return list<array<string, mixed>> */
    private function events(string $direction, string $kind): array {
        $events = [];
        foreach ($this->records as $record) {
            if (($record['context']['direction'] ?? null) === $direction && ($record['context']['kind'] ?? null) === $kind) {
                $events[] = $record['context'];
            }
        }
        return $events;
    }

    /** @return array<string, mixed> */
    private function body(string $direction): array {
        $events = array_values(array_filter(
            $this->events($direction, 'body'),
            static fn (array $event): bool => ! ($event['omitted'] ?? false),
        ));
        self::assertCount(1, $events);
        self::assertIsString($events[0]['body']);
        $body = json_decode($events[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        return $body;
    }

    /** @param list<string> $secrets */
    private function assertSecretsAbsent(array $secrets): void {
        $encoded = json_encode($this->records, JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }
}
