<?php

declare(strict_types=1);

namespace Tests;

use AbraFlexi\Cenik;
use AbraFlexi\Exception as AbraFlexiException;
use AbraFlexi\FakturaVydana;
use AbraFlexi\RO;
use CurlHandle;
use Lsr\AbraFlexi\AbraFlexiClientFactory;
use Lsr\AbraFlexi\AbraFlexiConfiguration;
use Lsr\AbraFlexi\AbraFlexiInvoiceGateway;
use Lsr\AbraFlexi\AbraFlexiPriceListGateway;
use Lsr\AbraFlexi\Dto\IssueInvoiceLine;
use Lsr\AbraFlexi\Dto\IssueInvoiceRequest;
use Lsr\AbraFlexi\Enums\InvoiceGatewayFailure;
use Lsr\AbraFlexi\Exceptions\InvoiceGatewayException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class AbraFlexiClientFactoryTest extends TestCase
{
    /** @var resource|null */
    private static mixed $server = null;
    private static string $directory;
    private static string $origin;

    public static function setUpBeforeClass(): void {
        self::$directory = sys_get_temp_dir() . '/abra-redirect-' . bin2hex(random_bytes(8));
        mkdir(self::$directory, 0700);
        $configFile = self::$directory . '/openssl.cnf';
        file_put_contents($configFile, "[req]\ndistinguished_name=dn\n[dn]\n[server_cert]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $configFile]);
        if ($key === false) {
            throw new RuntimeException('Cannot create redirect fixture TLS key.');
        }
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $configFile]);
        $certificate = is_bool($csr) ? false : openssl_csr_sign($csr, null, $key, 1, [
            'config' => $configFile,
            'x509_extensions' => 'server_cert',
        ]);
        if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Cannot create redirect fixture TLS certificate.');
        }
        file_put_contents(self::$directory . '/server.pem', $pem . $privateKey);
        chmod(self::$directory . '/server.pem', 0600);
        // Keep TLS rejection diagnostics off the one-shot stdout readiness pipe.
        $server = proc_open([
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            __DIR__ . '/Fixtures/AbraRedirectServer.php',
            self::$directory . '/server.pem',
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', self::$directory . '/stderr.log', 'w'],
        ], $pipes);
        if ( ! is_resource($server)) {
            throw new RuntimeException('Cannot start redirect fixture.');
        }
        self::$server = $server;
        stream_set_timeout($pipes[1], 5);
        $origin = fgets($pipes[1]);
        fclose($pipes[1]);
        if ($origin === false || preg_match('~^https://127\.0\.0\.1:\d+$~', trim($origin)) !== 1) {
            self::tearDownAfterClass();
            throw new RuntimeException('Redirect fixture did not become ready.');
        }
        self::$origin = trim($origin);
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (['openssl.cnf', 'server.pem', 'stderr.log', 'trace-stderr.log', 'auth-stderr.log'] as $file) {
            if (is_file(self::$directory . '/' . $file)) {
                unlink(self::$directory . '/' . $file);
            }
        }
        if (is_dir(self::$directory)) {
            rmdir(self::$directory);
        }
    }

    #[DataProvider('clientKinds')]
    public function test_sdk_loads_alias_through_authenticated_same_origin_redirect(bool $priceList, string $selector): void {
        $client = $this->client($priceList);
        $client->loadFromAbraFlexi($selector);
        self::assertSame(200, $client->lastResponseCode);
        self::assertSame(42, $client->getDataValue('id'));
        self::assertSame('ALIAS', $client->getDataValue('kod'));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function clientKinds(): iterable {
        yield 'invoice code' => [false, 'code:ALIAS'];
        yield 'invoice external ID' => [false, 'ext:Accounting_Bridge:alias.12:invoice'];
        yield 'price list code' => [true, 'code:ALIAS'];
    }

    #[DataProvider('writeRedirects')]
    public function test_canonical_write_redirect_preserves_method_body_and_authentication(int $status): void {
        $client = $this->client();
        $client->postFields = '{"invoice":"private invoice data"}';
        $client->doCurlRequest(self::$origin . '/redirect/' . $status, 'POST');
        self::assertSame(200, $client->lastResponseCode);
        $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $echo['method']);
        self::assertSame($client->postFields, $echo['body']);
        self::assertTrue($echo['authorized']);
    }

    /** @return iterable<string, array{int}> */
    public static function writeRedirects(): iterable {
        yield 'permanent' => [301];
        yield 'found' => [302];
        yield 'temporary method preserving' => [307];
        yield 'permanent method preserving' => [308];
    }

    public function test_relative_redirect_chain_resolves_against_each_current_path(): void {
        $client = $this->client();
        $client->doCurlRequest(self::$origin . '/relative/start', 'GET');
        self::assertSame(200, $client->lastResponseCode);
        $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GET', $echo['method']);
        self::assertTrue($echo['authorized']);
    }

    public function test_relative_redirect_accepts_inherited_authentication_metadata(): void {
        $client = $this->client();
        // Older libcurl versions add USERPWD credentials to the effective URL after a redirect.
        // Seed that metadata on the initial URL to exercise the same state on newer versions.
        $url = str_replace('https://', 'https://api-user:api-password@', self::$origin) . '/relative/start';
        $client->doCurlRequest($url, 'GET');

        self::assertSame(200, $client->lastResponseCode);
        $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GET', $echo['method']);
        self::assertTrue($echo['authorized']);
    }

    #[DataProvider('unsafeRedirects')]
    public function test_unsafe_redirect_is_blocked_before_forwarding_invoice_data(string $path): void {
        $client = $this->client();
        $client->postFields = '{"invoice":"private invoice data"}';
        try {
            $client->doCurlRequest(self::$origin . $path, 'POST');
            self::fail('Unsafe redirects must be aborted before another request is sent.');
        } catch (AbraFlexiException) {
            self::assertSame(CURLE_WRITE_ERROR, curl_errno($this->curl($client)));
        }
        $client->postFields = null;
        $client->doCurlRequest(self::$origin . '/echo', 'GET');
        $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $echo['forbiddenHits']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeRedirects(): iterable {
        yield 'different host' => ['/cross-host'];
        yield 'different port' => ['/cross-port'];
        yield 'HTTPS downgrade' => ['/downgrade'];
        yield 'embedded credentials' => ['/credentials'];
        yield 'unsafe second hop' => ['/chain/cross'];
        yield 'write see-other must not replay POST' => ['/redirect/303'];
    }

    public function test_read_see_other_redirect_remains_supported(): void {
        $client = $this->client();
        $client->doCurlRequest(self::$origin . '/redirect/303', 'GET');
        self::assertSame(200, $client->lastResponseCode);
        $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GET', $echo['method']);
    }

    public function test_redirect_loop_is_bounded_and_does_not_poison_the_next_call(): void {
        $client = $this->client();
        try {
            $client->doCurlRequest(self::$origin . '/loop', 'GET');
            self::fail('Redirect loops must terminate.');
        } catch (AbraFlexiException) {
            self::assertSame(CURLE_TOO_MANY_REDIRECTS, curl_errno($this->curl($client)));
            self::assertSame(3, curl_getinfo($this->curl($client), CURLINFO_REDIRECT_COUNT));
        }
        $client->loadFromAbraFlexi('code:ALIAS');
        self::assertSame(42, $client->getDataValue('id'));
    }

    public function test_untrusted_tls_certificate_is_still_rejected(): void {
        $client = $this->client(trustFixtureCertificate: false);
        try {
            $client->loadFromAbraFlexi('code:ALIAS');
            self::fail('Redirect support must not disable TLS verification.');
        } catch (AbraFlexiException) {
            self::assertSame(CURLE_SSL_CACERT, curl_errno($this->curl($client)));
        }

        $trustedClient = $this->client();
        $trustedClient->loadFromAbraFlexi('code:ALIAS');
        self::assertSame(42, $trustedClient->getDataValue('id'));
    }

    public function test_real_sdk_price_lookup_maps_amounts_and_clears_a_missing_record(): void {
        $client = $this->client(priceList: true);
        self::assertInstanceOf(Cenik::class, $client);
        $gateway = new AbraFlexiPriceListGateway($this->configuration(), new NullLogger(), $client);
        $item = $gateway->findByCode('code:ALIAS');
        self::assertNotNull($item);
        self::assertSame('ALIAS', $item->code);
        self::assertSame('Fixture catalogue item', $item->name);
        self::assertSame(12100, $item->priceAmountMinor);
        self::assertSame(10000, $item->netPriceAmountMinor);
        self::assertSame('GBP', $item->currencyCode);
        self::assertNull($gateway->findByCode('MISSING'));
        self::assertSame(12100, $gateway->findByCode('ALIAS')?->priceAmountMinor);
    }

    public function test_price_filter_syntax_is_treated_as_a_literal_code(): void {
        $client = $this->client(priceList: true);
        self::assertInstanceOf(Cenik::class, $client);
        $gateway = new AbraFlexiPriceListGateway($this->configuration(), new NullLogger(), $client);

        $item = $gateway->findByCode("service' or kod ne 'other");

        self::assertNotNull($item);
        self::assertSame("service' or kod ne 'other", $item->code);
        self::assertSame(12100, $item->priceAmountMinor);
    }

    public function test_real_sdk_creates_reads_and_downloads_invoice_without_losing_order_number(): void {
        $client = $this->client();
        self::assertInstanceOf(FakturaVydana::class, $client);
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), new NullLogger(), $client);
        $externalId = 'ext:Accounting_Bridge:document.2026-0042:invoice';
        $initialState = $this->invoiceState($client);
        self::assertNull($gateway->findByExternalId($externalId));
        $invoice = $gateway->issue($this->invoiceRequest($externalId));
        self::assertSame('2026-09-05', $invoice->issuedOn->format('Y-m-d'));
        self::assertSame('INV 2026/0042', $client->getDataValue('cisObj'));
        self::assertSame('2026000042', $client->getDataValue('varSym'));
        self::assertSame('Private Recipient Name', $client->getDataValue('nazFirmy'));

        $recovered = $gateway->findByExternalId($externalId);
        self::assertNotNull($recovered);
        self::assertSame($invoice->internalId, $recovered->internalId);
        self::assertSame(12100, $recovered->grossAmountMinor);
        self::assertSame('GBP', $recovered->currencyCode);
        self::assertStringStartsWith('%PDF-', $gateway->downloadPdf($externalId)->bytes);

        $client->doCurlRequest(self::$origin . '/invoice-state', 'GET');
        $state = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($initialState['writes'] + 1, $state['writes']);
        self::assertSame('INV 2026/0042', $state['invoice']['cisObj']);
    }

    public function test_persisted_invoice_with_failed_readback_requires_reconciliation(): void {
        $client = $this->client();
        self::assertInstanceOf(FakturaVydana::class, $client);
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), new NullLogger(), $client);
        $request = $this->invoiceRequest('ext:fault:readback.404');
        $before = $this->invoiceState($client);
        $client->defaultHttpHeaders['X-Fixture-Fault'] = 'readback404';

        try {
            $gateway->issue($request);
            self::fail('A failed readback must not imply a rejected or successful write.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::AmbiguousWrite, $exception->failure);
            self::assertNull($exception->getPrevious());
        }
        unset($client->defaultHttpHeaders['X-Fixture-Fault']);

        $recovered = $gateway->findByExternalId($request->externalId);
        self::assertSame($request->externalId, $recovered?->externalId);
        self::assertSame(12100, $recovered->grossAmountMinor);
        self::assertSame($before['writes'] + 1, $this->invoiceState($client)['writes']);
    }

    #[DataProvider('rejectedWrites')]
    public function test_actual_rejected_write_is_not_reported_as_persisted(int $status, InvoiceGatewayFailure $failure): void {
        $client = $this->client();
        self::assertInstanceOf(FakturaVydana::class, $client);
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), new NullLogger(), $client);
        $before = $this->invoiceState($client);
        $client->defaultHttpHeaders['X-Fixture-Fault'] = 'write' . $status;

        try {
            $gateway->issue($this->invoiceRequest('ext:fault:rejected.' . $status));
            self::fail('A provider rejection must remain a typed failure.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame($failure, $exception->failure);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('api-password', $exception->getMessage());
            self::assertStringNotContainsString('private@example.test', $exception->getMessage());
        }
        self::assertSame($before['writes'], $this->invoiceState($client)['writes']);
    }

    /** @return iterable<string, array{int, InvoiceGatewayFailure}> */
    public static function rejectedWrites(): iterable {
        yield 'invalid document' => [400, InvoiceGatewayFailure::RejectedRequest];
        yield 'conflicting identity' => [409, InvoiceGatewayFailure::IdentityConflict];
    }

    public function test_failed_pdf_transport_preserves_subsequent_lookup_and_issue(): void {
        $client = $this->client();
        self::assertInstanceOf(FakturaVydana::class, $client);
        $gateway = new AbraFlexiInvoiceGateway($this->configuration(), new NullLogger(), $client);
        $request = $this->invoiceRequest('ext:fault:pdf.first');
        $before = $this->invoiceState($client);
        $gateway->issue($request);
        $client->defaultHttpHeaders['X-Fixture-Fault'] = 'pdfdrop';

        try {
            $gateway->downloadPdf($request->externalId);
            self::fail('The dropped PDF connection must propagate a transport failure.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::Retryable, $exception->failure);
            self::assertSame(AbraFlexiException::class, $exception->causeType);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('api-password', $exception->getMessage());
            self::assertStringNotContainsString(self::$origin, $exception->getMessage());
        }
        unset($client->defaultHttpHeaders['X-Fixture-Fault']);
        $afterFailure = $this->invoiceState($client);

        self::assertSame($request->externalId, $gateway->findByExternalId($request->externalId)?->externalId);
        $next = $this->invoiceRequest('ext:fault:pdf.second');
        self::assertSame($next->externalId, $gateway->issue($next)->externalId);
        $afterRecovery = $this->invoiceState($client);
        self::assertSame($before['writes'] + 2, $afterRecovery['writes']);
        foreach (array_slice($afterRecovery['requests'], count($afterFailure['requests'])) as $requestHeaders) {
            self::assertSame('application/json', $requestHeaders['accept']);
            self::assertSame('application/json', $requestHeaders['contentType']);
        }
        self::assertStringStartsWith('%PDF-', $gateway->downloadPdf($next->externalId)->bytes);
    }

    #[DataProvider('emptyPriceFailures')]
    public function test_empty_http_price_failure_is_not_an_absent_item(int $status): void {
        $client = $this->client(priceList: true);
        self::assertInstanceOf(Cenik::class, $client);
        $gateway = new AbraFlexiPriceListGateway($this->configuration(), new NullLogger(), $client);
        self::assertSame(12100, $gateway->findByCode('ALIAS')?->priceAmountMinor);
        $client->defaultHttpHeaders['X-Fixture-Fault'] = 'price' . $status;
        $failure = null;

        try {
            $gateway->findByCode('ALIAS');
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'A failed price lookup must not become item-not-found.');
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString('api-password', $failure->getMessage());
        self::assertStringNotContainsString(self::$origin, $failure->getMessage());
        unset($client->defaultHttpHeaders['X-Fixture-Fault']);
        self::assertSame(12100, $gateway->findByCode('ALIAS')?->priceAmountMinor);
    }

    /** @return iterable<string, array{int}> */
    public static function emptyPriceFailures(): iterable {
        yield 'authentication failure' => [401];
        yield 'permission failure' => [403];
        yield 'provider failure' => [500];
        yield 'pending response' => [202];
    }

    #[DataProvider('emptyPriceResults')]
    public function test_completed_empty_price_response_remains_absent(int $status): void {
        $client = $this->client(priceList: true);
        self::assertInstanceOf(Cenik::class, $client);
        $gateway = new AbraFlexiPriceListGateway($this->configuration(), new NullLogger(), $client);
        $client->defaultHttpHeaders['X-Fixture-Fault'] = 'price' . $status;
        self::assertNull($gateway->findByCode('MISSING'));
        unset($client->defaultHttpHeaders['X-Fixture-Fault']);
        self::assertSame(12100, $gateway->findByCode('ALIAS')?->priceAmountMinor);
        self::assertNull($gateway->findByCode('EMPTY'));
    }

    /** @return iterable<string, array{int}> */
    public static function emptyPriceResults(): iterable {
        yield 'successful empty body' => [200];
        yield 'no content' => [204];
        yield 'not found' => [404];
    }

    #[DataProvider('ambientSessionSources')]
    public function test_configured_basic_authentication_ignores_ambient_sdk_sessions(bool $priceList, string $source): void {
        $script = <<<'PHP'
            if ($argv[1] === 'constant') {
                putenv('ABRAFLEXI_AUTHSESSID');
                define('ABRAFLEXI_AUTHSESSID', 'unrelated-session');
            } else {
                putenv('ABRAFLEXI_AUTHSESSID=unrelated-session');
            }
            require 'vendor/autoload.php';
            $ambientEnvironment = getenv('ABRAFLEXI_AUTHSESSID');
            $ambientConstant = defined('ABRAFLEXI_AUTHSESSID') ? constant('ABRAFLEXI_AUTHSESSID') : null;
            $configuration = new Lsr\AbraFlexi\AbraFlexiConfiguration(
                enabled: true,
                apiUrl: $argv[3],
                company: 'test_company',
                username: 'api-user',
                password: 'api-password',
                currencyCode: 'GBP',
                timeout: 3,
            );
            $factory = new Lsr\AbraFlexi\AbraFlexiClientFactory($configuration, new Psr\Log\NullLogger());
            $client = $argv[2] === 'price-list' ? $factory->priceList() : $factory->issuedInvoice();
            curl_setopt($client->curl, CURLOPT_NOPROXY, '*');
            curl_setopt($client->curl, CURLOPT_CAINFO, $argv[4]);
            curl_setopt($client->curl, CURLINFO_HEADER_OUT, true);
            $client->doCurlRequest($argv[3] . '/echo', 'GET');
            $echo = json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
            $headers = curl_getinfo($client->curl, CURLINFO_HEADER_OUT);
            echo json_encode([
                'status' => $client->lastResponseCode,
                'authorized' => $echo['authorized'],
                'requestCaptured' => is_string($headers) && $headers !== '',
                'sessionHeaderPresent' => is_string($headers) && stripos($headers, 'X-authSessionId:') !== false,
                'ambientUnchanged' => getenv('ABRAFLEXI_AUTHSESSID') === $ambientEnvironment
                    && (defined('ABRAFLEXI_AUTHSESSID') ? constant('ABRAFLEXI_AUTHSESSID') : null) === $ambientConstant,
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([
            PHP_BINARY, '-r', $script,
            $source, $priceList ? 'price-list' : 'invoice',
            self::$origin, self::$directory . '/server.pem',
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::$directory . '/auth-stderr.log', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $errors = file_get_contents(self::$directory . '/auth-stderr.log');
        self::assertSame(0, $status, 'The isolated SDK authentication request must succeed.');
        self::assertTrue($errors === '', 'The SDK authentication request must not emit raw diagnostics.');
        $result = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $result['status']);
        self::assertTrue($result['authorized']);
        self::assertTrue($result['requestCaptured']);
        self::assertFalse($result['sessionHeaderPresent']);
        self::assertTrue($result['ambientUnchanged']);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function ambientSessionSources(): iterable {
        yield 'invoice environment' => [false, 'environment'];
        yield 'invoice constant' => [false, 'constant'];
        yield 'price list environment' => [true, 'environment'];
        yield 'price list constant' => [true, 'constant'];
    }

    #[DataProvider('traceModes')]
    public function test_curl_trace_is_controlled_by_its_di_flag_only(?bool $production, bool $enabled, bool $expected): void {
        $script = <<<'PHP'
            if ($argv[1] !== 'undefined') {
                define('PRODUCTION', $argv[1] === 'production');
            }
            require 'vendor/autoload.php';
            $logger = new class extends Psr\Log\AbstractLogger {
                public array $events = [];
                public function log($level, string|Stringable $message, array $context = []): void {
                    $this->events[] = ['level' => $level, 'event' => (string) $message, 'context' => $context];
                }
            };
            $configuration = new Lsr\AbraFlexi\AbraFlexiConfiguration(
                enabled: true,
                apiUrl: $argv[3],
                company: 'test_company',
                username: 'api-user',
                password: 'api-password',
                currencyCode: 'GBP',
                timeout: 3,
                traceCurl: $argv[2] === 'enabled',
            );
            $client = (new Lsr\AbraFlexi\AbraFlexiClientFactory($configuration, $logger))->issuedInvoice();
            curl_setopt($client->curl, CURLOPT_NOPROXY, '*');
            curl_setopt($client->curl, CURLOPT_CAINFO, $argv[4]);
            $client->loadFromAbraFlexi('code:ALIAS');
            echo json_encode(['id' => $client->getDataValue('id'), 'events' => $logger->events], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([
            PHP_BINARY, '-r', $script,
            $production === null ? 'undefined' : ($production ? 'production' : 'development'),
            $enabled ? 'enabled' : 'disabled',
            self::$origin, self::$directory . '/server.pem',
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::$directory . '/trace-stderr.log', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $errors = file_get_contents(self::$directory . '/trace-stderr.log');
        self::assertSame(0, $status, (string) $errors);
        self::assertSame('', $errors, 'Raw cURL debug output must not reach stderr.');
        $result = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $result['id']);
        if ($expected) {
            self::assertContains('commerce.abra.curl_trace', array_column($result['events'], 'event'));
            $directions = array_column(array_column($result['events'], 'context'), 'direction');
            self::assertContains('request', $directions);
            self::assertContains('response', $directions);
        } else {
            self::assertSame([], $result['events']);
        }
        self::assertStringNotContainsString('api-password', (string) $output);
        self::assertStringNotContainsString(base64_encode('api-user:api-password'), (string) $output);
    }

    /** @return iterable<string, array{?bool, bool, bool}> */
    public static function traceModes(): iterable {
        yield 'development opt-in' => [false, true, true];
        yield 'development default off' => [false, false, false];
        yield 'production opt-in' => [true, true, true];
        yield 'undefined environment opt-in' => [null, true, true];
        yield 'production default off' => [true, false, false];
    }

    private function invoiceRequest(string $externalId): IssueInvoiceRequest {
        return new IssueInvoiceRequest(
            orderNumber: 'INV 2026/0042',
            recipientName: 'Private Recipient Name',
            externalId: $externalId,
            paymentMethodCode: null,
            debitAccountCode: null,
            creditAccountCode: null,
            documentTypeCode: 'FAKTURA',
            numberSeriesCode: null,
            issuedOn: null,
            taxPointOn: null,
            dueOn: null,
            currencyCode: 'GBP',
            paymentReference: '2026000042',
            lines: [new IssueInvoiceLine(
                description: 'Invoice transport regression',
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
    }

    /** @return array{invoice: array<string, mixed>|null, writes: int, requests: list<array{method: string, accept: string, contentType: string}>} */
    private function invoiceState(RO $client): array {
        $client->doCurlRequest(self::$origin . '/invoice-state', 'GET');
        return json_decode($client->lastCurlResponse, true, flags: JSON_THROW_ON_ERROR);
    }

    private function client(bool $priceList = false, bool $trustFixtureCertificate = true): RO {
        $factory = new AbraFlexiClientFactory($this->configuration(), new NullLogger());
        $client = $priceList ? $factory->priceList() : $factory->issuedInvoice();
        curl_setopt($this->curl($client), CURLOPT_NOPROXY, '*');
        if ($trustFixtureCertificate) {
            curl_setopt($this->curl($client), CURLOPT_CAINFO, self::$directory . '/server.pem');
        }
        return $client;
    }

    private function curl(RO $client): CurlHandle {
        return $client->curl ?? throw new RuntimeException('The SDK did not initialize cURL.');
    }

    private function configuration(): AbraFlexiConfiguration {
        return new AbraFlexiConfiguration(
            enabled: true,
            apiUrl: self::$origin,
            company: 'test_company',
            username: 'api-user',
            password: 'api-password',
            currencyCode: 'GBP',
            timeout: 3,
        );
    }
}
