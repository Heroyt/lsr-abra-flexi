<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class AbraRedirectServer
{
    public static function defaultPdfBytes(): string {
        $pdf = "%PDF-1.7\n";
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 1 1] >>\nendobj\n",
        ];
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }

        return $pdf
            . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF\n";
    }

    public static function run(string $certificate): never {
        $context = stream_context_create(['ssl' => [
            'local_cert' => $certificate,
            'verify_peer' => false,
        ]]);
        $server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            exit(1);
        }
        $address = stream_socket_get_name($server, false);
        if ($address === false) {
            exit(1);
        }
        $port = (int) substr($address, strrpos($address, ':') + 1);
        echo 'https://' . $address . "\n";
        flush();
        $forbiddenHits = 0;
        $invoice = null;
        $invoiceWrites = 0;
        $invoicePdf = self::defaultPdfBytes();

        while (true) {
            // A client rejecting the self-signed certificate is an expected test scenario.
            $connection = @stream_socket_accept($server, 10);
            if ($connection === false) {
                continue;
            }
            stream_set_timeout($connection, 3);
            $requestLine = fgets($connection);
            if ($requestLine === false) {
                fclose($connection);
                continue;
            }
            [$method, $requestTarget] = explode(' ', trim($requestLine), 3);
            $headers = [];
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
            $body = '';
            $length = (int) ($headers['content-length'] ?? 0);
            while (($remaining = $length - strlen($body)) > 0) {
                $part = fread($connection, $remaining);
                if ($part === false || $part === '') {
                    break;
                }
                $body .= $part;
            }
            $path = rawurldecode((string) parse_url($requestTarget, PHP_URL_PATH));
            if ($path === '/forbidden') {
                ++$forbiddenHits;
            }
            $authorized = ($headers['authorization'] ?? '') === 'Basic ' . base64_encode('api-user:api-password');
            $status = 200;
            $location = null;
            $pdf = null;
            $data = ['method' => $method, 'body' => $body, 'authorized' => $authorized, 'forbiddenHits' => $forbiddenHits];
            if ( ! $authorized) {
                $status = 401;
            } elseif ($method === 'PUT' && preg_match('~^/c/test_company/faktura-vydana(?:\.json)?$~', $path) === 1) {
                $submitted = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['winstrom']['faktura-vydana'];
                $invoice = array_replace($submitted, [
                    'id' => 481,
                    'external-ids' => [$submitted['id']],
                    'kod' => '2026-0019',
                    'rada' => 'code:FAKTURA',
                    'datVyst' => '2026-09-05+02:00',
                    'duzpPuv' => '2026-09-05+02:00',
                    'sumZklCelkem' => '100.0',
                    'sumDphCelkem' => '21.0',
                    'sumCelkem' => '121.0',
                    // ABRA may leave payment status empty before payment matching.
                    'stavUhrK' => '',
                ]);
                ++$invoiceWrites;
                $status = 201;
                $data = ['winstrom' => ['success' => 'true', 'results' => [['id' => 481]]]];
            } elseif (preg_match("~^/c/test_company/cenik/\\(kod eq '([^']+)'\\)(?:\\.json)?$~", $path, $matches) === 1) {
                $data = ['winstrom' => ['cenik' => $matches[1] === 'ALIAS' ? [[
                    'id' => 42,
                    'kod' => 'ALIAS',
                    'nazev' => 'Fixture catalogue item',
                    'cenaZaklVcDph' => '121.00',
                    'cenaZaklBezDph' => '100.00',
                    'typCenyDphK' => 'typCeny.sDph',
                    'typSzbDphK' => 'typSzbDph.dphZakl',
                ]] : []]];
            } elseif ($path === '/invoice-state') {
                $data = ['invoice' => $invoice, 'writes' => $invoiceWrites];
            } elseif (preg_match('~^/c/test_company/(faktura-vydana|cenik)/(?:code:ALIAS|ext:Accounting_Bridge:alias\.12:invoice)(?:\.json)?$~', $path) === 1) {
                $status = 301;
                $location = '42.json';
            } elseif (preg_match('~^/c/test_company/faktura-vydana/ext:[A-Za-z0-9_-]{1,32}:[A-Za-z0-9_.:-]+(?:\.(?:json|pdf))?$~', $path) === 1) {
                $selector = preg_replace('/\.(?:json|pdf)$/', '', basename($path));
                if ($invoice === null || $invoice['external-ids'] !== [$selector]) {
                    $status = 404;
                    $data = ['winstrom' => ['success' => 'false']];
                } else {
                    $status = 301;
                    $location = str_ends_with($path, '.pdf') ? '481.pdf' : '481.json';
                }
            } elseif (preg_match('~^/c/test_company/faktura-vydana/481(?:\.json)?$~', $path) === 1) {
                $data = ['winstrom' => ['faktura-vydana' => [$invoice]]];
            } elseif ($path === '/c/test_company/faktura-vydana/481.pdf') {
                $pdf = $invoicePdf;
            } elseif (preg_match('~^/c/test_company/(faktura-vydana|cenik)/42\.json$~', $path, $matches) === 1) {
                $data = ['winstrom' => [$matches[1] => [['id' => 42, 'kod' => 'ALIAS']]]];
            } elseif (preg_match('~^/redirect/(301|302|303|307|308)$~', $path, $matches) === 1) {
                $status = (int) $matches[1];
                $location = '/echo';
            } else {
                $location = match ($path) {
                    '/relative/start' => 'next',
                    '/relative/next' => '../echo',
                    '/cross-host' => 'https://localhost:' . $port . '/forbidden',
                    '/cross-port' => 'https://127.0.0.1:1/forbidden',
                    '/downgrade' => 'http://127.0.0.1:' . $port . '/forbidden',
                    '/credentials' => 'https://alternate:secret@127.0.0.1:' . $port . '/forbidden',
                    '/chain/cross' => '/cross-host',
                    '/loop' => '/loop',
                    default => null,
                };
                if ($location !== null) {
                    $status = 307;
                }
            }
            $responseBody = $pdf ?? ($location === null ? json_encode($data, JSON_THROW_ON_ERROR) : '');
            $mediaType = $pdf === null ? 'application/json' : 'application/pdf';
            $response = 'HTTP/1.1 ' . $status . " Test\r\nContent-Type: " . $mediaType . "\r\nConnection: close\r\n";
            if ($location !== null) {
                $response .= 'Location: ' . $location . "\r\n";
            }
            $response .= 'Content-Length: ' . strlen($responseBody) . "\r\n\r\n" . $responseBody;
            fwrite($connection, $response);
            fclose($connection);
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    AbraRedirectServer::run($argv[1]);
}
