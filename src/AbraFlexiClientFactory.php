<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use AbraFlexi\Cenik;
use AbraFlexi\FakturaVydana;
use AbraFlexi\RO;
use CurlHandle;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class AbraFlexiClientFactory
{
    public function __construct(
        private AbraFlexiConfiguration $configuration,
        private LoggerInterface $logger,
    ) {
    }

    public function priceList(): Cenik {
        return $this->configure(new Cenik(null, $this->options()));
    }

    public function issuedInvoice(): FakturaVydana {
        return $this->configure(new FakturaVydana(null, $this->options()));
    }

    /** @return array<string, bool|int|string> */
    private function options(): array {
        return [
            'url'            => $this->configuration->apiUrl,
            'company'        => $this->configuration->company,
            'user'           => $this->configuration->username,
            'password'       => $this->configuration->password,
            'timeout'        => $this->configuration->timeout,
            'autoload'       => false,
            'debug'          => false,
            'ignore404'      => true,
            'nativeTypes'    => false,
            'throwException' => true,
        ];
    }

    /** @template T of RO
     * @param T $client
     * @return T
     */
    private function configure(RO $client): RO {
        if ($client->curl === null) {
            throw new RuntimeException('The ABRA Flexi client could not initialize HTTP transport.');
        }

        curl_setopt($client->curl, CURLOPT_SSL_VERIFYPEER, $this->configuration->verifyTls);
        curl_setopt($client->curl, CURLOPT_SSL_VERIFYHOST, $this->configuration->verifyTls ? 2 : 0);
        curl_setopt($client->curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($client->curl, CURLOPT_MAXREDIRS, 3);
        curl_setopt($client->curl, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        curl_setopt($client->curl, CURLOPT_UNRESTRICTED_AUTH, false);
        // The SDK uses CUSTOMREQUEST and POSTFIELDS together; retain both verb and body on canonical redirects.
        curl_setopt($client->curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301 | CURL_REDIR_POST_302);
        $origin = new Uri($this->configuration->apiUrl);
        curl_setopt(
            $client->curl,
            CURLOPT_HEADERFUNCTION,
            static fn (CurlHandle $curl, string $header): int => self::acceptRedirectHeader($curl, $header, $origin),
        );
        if ($this->configuration->traceCurl) {
            curl_setopt($client->curl, CURLOPT_DEBUGFUNCTION, new AbraFlexiCurlTrace($this->logger));
            curl_setopt($client->curl, CURLOPT_VERBOSE, true);
        }

        return $client;
    }

    private static function acceptRedirectHeader(CurlHandle $curl, string $header, UriInterface $origin): int {
        $length = strlen($header);
        if (strncasecmp($header, 'Location:', 9) !== 0) {
            return $length;
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ( ! in_array($status, [301, 302, 303, 307, 308], true)) {
            return $length;
        }
        // A write's 303 means "see other", not permission to replay the invoice or silently turn it into a GET.
        if ($status === 303 && ! in_array(curl_getinfo($curl, CURLINFO_EFFECTIVE_METHOD), ['GET', 'HEAD'], true)) {
            return 0;
        }
        try {
            $location = trim(substr($header, 9));
            if ($location === '') {
                return 0;
            }
            $current = new Uri((string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL));
            $target = UriResolver::resolve($current, new Uri($location));
            if (
                $target->getScheme() !== 'https'
                || $target->getHost() !== $origin->getHost()
                || $target->getPort() !== $origin->getPort()
                || $target->getUserInfo() !== ''
            ) {
                return 0;
            }
        } catch (Throwable) {
            // Returning fewer bytes aborts cURL before it sends credentials or a body to the redirect target.
            return 0;
        }
        return $length;
    }
}
