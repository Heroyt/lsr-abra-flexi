<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use CurlHandle;
use JsonException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * One instance belongs to one cURL handle, including its redirects and subsequent requests.
 *
 * @phpstan-type TraceState array{
 *     headers: array<string, bool|int|string>, headersOpen: bool, headerCount: int,
 *     mediaType: ?string, unsupportedEncoding: bool, contentLength: ?int,
 *     buffer: string, bodyDone: bool, started: bool, depth: int, inString: bool, escaped: bool, complete: bool
 * }
 */
final class AbraFlexiCurlTrace
{
    private const int MAX_BODY_BYTES = 65536;
    private const int MAX_HEADER_BYTES = 8192;
    private const int MAX_HEADERS = 128;
    private const int MAX_JSON_DEPTH = 32;
    private const int MAX_LOG_DEPTH = 16;
    private const int MAX_LOG_ENTRIES = 64;
    private const int MAX_LOG_NODES = 512;
    private const string REDACTED = '[redacted]';

    private const array EMPTY_STATE = [
        'headers' => [],
        'headersOpen' => true,
        'headerCount' => 0,
        'mediaType' => null,
        'unsupportedEncoding' => false,
        'contentLength' => null,
        'buffer' => '',
        'bodyDone' => false,
        'started' => false,
        'depth' => 0,
        'inString' => false,
        'escaped' => false,
        'complete' => false,
    ];

    /** @var array{request: TraceState, response: TraceState} */
    private array $states = ['request' => self::EMPTY_STATE, 'response' => self::EMPTY_STATE];

    public function __construct(private readonly LoggerInterface $logger) {
    }

    public function __invoke(CurlHandle $handle, int $type, string $data): void {
        try {
            switch ($type) {
                case CURLINFO_HEADER_OUT:
                    $this->reset('request');
                    $this->reset('response');
                    $this->headers('request', $data);
                    $this->emitHeaders('request');
                    break;
                case CURLINFO_HEADER_IN:
                    $this->headers('response', $data);
                    break;
                case CURLINFO_DATA_OUT:
                    $this->body('request', $data);
                    break;
                case CURLINFO_DATA_IN:
                    $this->body('response', $data);
                    break;
                    // Internal TEXT and SSL records are never diagnostics-safe.
            }
        } catch (Throwable) {
            // Even a parser failure must not change the outcome of an accounting request.
            foreach ($this->states as &$state) {
                $state['buffer'] = '';
                $state['bodyDone'] = true;
            }
        }
    }

    /** @param 'request'|'response' $direction */
    private function reset(string $direction): void {
        if ($this->states[$direction]['buffer'] !== '' && ! $this->states[$direction]['bodyDone']) {
            $this->omitBody($direction, 'incomplete_json');
        }
        $this->states[$direction] = self::EMPTY_STATE;
    }

    /** @param 'request'|'response' $direction */
    private function headers(string $direction, string $data): void {
        if (strlen($data) > self::MAX_BODY_BYTES) {
            if ($direction === 'response' && str_starts_with($data, 'HTTP/')) {
                $this->reset('response');
            }
            $this->states[$direction]['headers']['[truncated]'] = true;
            $this->states[$direction]['unsupportedEncoding'] = true;
            return;
        }

        $lines = explode("\n", $data);
        if (str_ends_with($data, "\n")) {
            // A line terminator is not the empty line that terminates the header block.
            array_pop($lines);
        }
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($direction === 'response' && str_starts_with($line, 'HTTP/')) {
                $this->reset('response');
            }
            $state = &$this->states[$direction];
            if ( ! $state['headersOpen']) {
                continue;
            }
            if ($line === '') {
                $this->emitHeaders($direction);
                continue;
            }
            if ($state['headerCount'] >= self::MAX_HEADERS || strlen($line) > self::MAX_HEADER_BYTES) {
                $state['headers']['[truncated]'] = true;
                $state['unsupportedEncoding'] = true;
                continue;
            }
            ++$state['headerCount'];

            if (str_starts_with($line, 'HTTP/')) {
                if (preg_match('~^(HTTP/(?:1\.[01]|[23](?:\.0)?)) ([1-5][0-9]{2})(?:[ \t].*)?$~D', $line, $matches) === 1) {
                    $state['headers']['version'] = $matches[1];
                    $state['headers']['status'] = (int) $matches[2];
                } else {
                    $state['headers'][self::REDACTED] = true;
                    $state['unsupportedEncoding'] = true;
                }
                continue;
            }
            if ($direction === 'request' && $state['headerCount'] === 1) {
                if (preg_match('~^([A-Z]+) [^\r\n]+ (HTTP/(?:1\.[01]|[23](?:\.0)?))$~D', $line, $matches) === 1) {
                    $state['headers']['method'] = in_array($matches[1], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'], true)
                        ? $matches[1] : self::REDACTED;
                    $state['headers']['version'] = $matches[2];
                } else {
                    $state['headers'][self::REDACTED] = true;
                    $state['unsupportedEncoding'] = true;
                }
                $state['headers']['target'] = self::REDACTED;
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                // Do not reinterpret folded or malformed metadata as an unencoded JSON entity.
                $state['headers'][self::REDACTED] = true;
                $state['unsupportedEncoding'] = true;
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                $state['headers'][self::REDACTED] = true;
                $state['unsupportedEncoding'] = true;
                continue;
            }
            $name = strtolower(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            switch ($name) {
                case 'content-type':
                    if (isset($state['headers'][$name])) {
                        $state['unsupportedEncoding'] = true;
                    }
                    $mediaType = strtolower(trim(explode(';', $value, 2)[0]));
                    $state['mediaType'] = in_array($mediaType, ['application/json', 'application/problem+json', 'text/json'], true)
                        ? $mediaType : null;
                    $state['headers'][$name] = in_array($mediaType, [
                        'application/json', 'application/problem+json', 'text/json', 'application/pdf',
                        'application/octet-stream', 'application/xml', 'text/xml', 'text/plain', 'text/html',
                    ], true) ? $mediaType : self::REDACTED;
                    break;
                case 'content-length':
                    if (isset($state['headers'][$name]) || preg_match('/^[0-9]{1,18}$/D', $value) !== 1) {
                        $state['unsupportedEncoding'] = true;
                        $state['headers'][$name] = self::REDACTED;
                    } else {
                        $state['contentLength'] = (int) $value;
                        $state['headers'][$name] = $state['contentLength'];
                    }
                    break;
                case 'content-encoding':
                case 'transfer-encoding':
                    $encoding = strtolower($value);
                    $state['headers'][$name] = in_array($encoding, ['identity', 'gzip', 'deflate', 'br', 'zstd', 'compress', 'chunked'], true)
                        ? $encoding : self::REDACTED;
                    // DEBUGFUNCTION observes wire bytes, before content/transfer decoding.
                    $state['unsupportedEncoding'] = $state['unsupportedEncoding'] || $encoding !== 'identity';
                    break;
                case 'authorization':
                case 'proxy-authorization':
                case 'cookie':
                case 'set-cookie':
                    $state['headers'][$name] = self::REDACTED;
                    break;
                default:
                    // Untrusted names can themselves contain credentials or personal data.
                    $state['headers'][self::REDACTED] = true;
            }
        }
    }

    /** @param 'request'|'response' $direction */
    private function emitHeaders(string $direction): void {
        $state = &$this->states[$direction];
        if ( ! $state['headersOpen']) {
            return;
        }
        $state['headersOpen'] = false;
        if ($state['headers'] !== []) {
            $this->log(['direction' => $direction, 'kind' => 'headers', 'headers' => $state['headers']]);
        }
    }

    /** @param 'request'|'response' $direction */
    private function body(string $direction, string $data): void {
        $state = &$this->states[$direction];
        if ($data === '' || $state['bodyDone']) {
            return;
        }
        $this->emitHeaders($direction);
        if ($state['unsupportedEncoding']) {
            $this->omitBody($direction, 'unsupported_encoding');
            return;
        }
        if ($state['mediaType'] === null) {
            $this->omitBody($direction, 'unsupported_media_type');
            return;
        }
        $length = strlen($data);
        $bufferLength = strlen($state['buffer']);
        if ($length > self::MAX_BODY_BYTES - $bufferLength || $state['contentLength'] > self::MAX_BODY_BYTES) {
            $this->omitBody($direction, 'body_limit', true);
            return;
        }
        $state['buffer'] .= $data;
        for ($offset = 0; $offset < $length && ! $state['complete']; ++$offset) {
            $character = $data[$offset];
            if ( ! $state['started']) {
                if (str_contains(" \t\r\n", $character)) {
                    continue;
                }
                if ($character !== '{' && $character !== '[') {
                    $this->omitBody($direction, 'invalid_json');
                    return;
                }
                $state['started'] = true;
                $state['depth'] = 1;
                continue;
            }
            if ($state['inString']) {
                if ($state['escaped']) {
                    $state['escaped'] = false;
                } elseif ($character === '\\') {
                    $state['escaped'] = true;
                } elseif ($character === '"') {
                    $state['inString'] = false;
                }
                continue;
            }
            if ($character === '"') {
                $state['inString'] = true;
            } elseif ($character === '{' || $character === '[') {
                if (++$state['depth'] > self::MAX_JSON_DEPTH) {
                    $this->omitBody($direction, 'nesting_limit', true);
                    return;
                }
            } elseif ($character === '}' || $character === ']') {
                $state['complete'] = --$state['depth'] === 0;
            }
        }
        $bufferLength += $length;
        if ($state['contentLength'] !== null && $bufferLength > $state['contentLength']) {
            $this->omitBody($direction, 'invalid_json');
            return;
        }
        if ($state['contentLength'] !== null && $bufferLength < $state['contentLength']) {
            return;
        }
        if ( ! $state['complete'] && $state['contentLength'] === null) {
            return;
        }
        try {
            $decoded = json_decode($state['buffer'], false, self::MAX_JSON_DEPTH + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $remaining = self::MAX_LOG_NODES;
            $sanitized = json_encode($this->sanitize($decoded, 0, $remaining), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $this->omitBody($direction, 'invalid_json');
            return;
        }
        $state['buffer'] = '';
        $state['bodyDone'] = true;
        $this->log(['direction' => $direction, 'kind' => 'body', 'body' => $sanitized]);
    }

    private function sanitize(mixed $value, int $depth, int &$remaining): mixed {
        if ($depth >= self::MAX_LOG_DEPTH || --$remaining < 0) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $item) {
                if (count($sanitized) >= self::MAX_LOG_ENTRIES || $remaining <= 0) {
                    $sanitized[] = '[truncated]';
                    break;
                }
                $sanitized[] = $this->sanitize($item, $depth + 1, $remaining);
            }
            return $sanitized;
        }
        if ( ! $value instanceof stdClass) {
            return self::REDACTED;
        }
        $sanitized = new stdClass();
        $entries = 0;
        foreach (get_object_vars($value) as $key => $item) {
            if (++$entries > self::MAX_LOG_ENTRIES || $remaining-- <= 0) {
                $sanitized->{'[truncated]'} = true;
                break;
            }
            $kind = is_string($key) ? $this->fieldKind($key) : null;
            if ($kind === null) {
                $sanitized->{self::REDACTED} = true;
                continue;
            }
            $sanitized->{$key} = $kind === 'container'
                ? $this->sanitize($item, $depth + 1, $remaining)
                : $this->scalar($kind, $item);
        }
        return $sanitized;
    }

    private function fieldKind(string $field): ?string {
        return match ($field) {
            'winstrom', 'results', 'content', 'faktura-vydana', 'cenik', 'polozkyFaktury', 'errors' => 'container',
            'datVyst', 'duzpPuv', 'datSplat' => 'date',
            'id', 'varSym', 'created', 'updated', 'deleted', 'failed' => 'integer',
            'sumZklCelkem', 'sumDphCelkem', 'sumCelkem', 'sumOsv', 'sumZklSniz', 'sumZklSniz2',
            'sumZklZakl', 'sumDphSniz', 'sumDphSniz2', 'sumDphZakl', 'sumCelkSniz', 'sumCelkSniz2',
            'sumCelkZakl', 'sumOsvMen', 'sumZklSnizMen', 'sumZklSniz2Men', 'sumZklZaklMen',
            'sumZklCelkemMen', 'sumDphSnizMen', 'sumDphSniz2Men', 'sumDphZaklMen', 'sumDphCelkemMen',
            'sumCelkSnizMen', 'sumCelkSniz2Men', 'sumCelkZaklMen', 'sumCelkemMen', 'sumZalohy',
            'sumZalohyMen', 'sumCelkemBezZaloh', 'sumCelkemBezZalohMen', 'sumPrepl', 'sumPreplMen',
            'cenaMj', 'mnozMj', 'szbDph', 'cenaZaklVcDph', 'cenaZaklBezDph', 'kurz', 'kurzMnozstvi' => 'decimal',
            'mena' => 'currency',
            'stavUhrK' => 'payment_state',
            'success' => 'success',
            default => null,
        };
    }

    private function scalar(string $kind, mixed $value): mixed {
        if ($value === null) {
            return null;
        }
        if ($kind === 'integer') {
            return (is_int($value) && $value >= 0)
                || (is_string($value) && preg_match('/^[0-9]{1,20}$/D', $value) === 1)
                ? $value : self::REDACTED;
        }
        if ($kind === 'decimal') {
            return is_int($value) || (is_float($value) && is_finite($value))
                || (is_string($value) && preg_match('/^-?[0-9]{1,20}(?:\.[0-9]{1,10})?$/D', $value) === 1)
                ? $value : self::REDACTED;
        }
        if ($kind === 'success') {
            return is_bool($value) || in_array($value, ['true', 'false', 'ok'], true) ? $value : self::REDACTED;
        }
        if ( ! is_string($value)) {
            return self::REDACTED;
        }
        return match ($kind) {
            'date' => preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:Z|[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00))?$/D', $value, $date) === 1
                && checkdate((int) $date[2], (int) $date[3], (int) $date[1]) ? $value : self::REDACTED,
            'currency' => preg_match('/\A(?:code:)?[A-Z]{3}\z/', $value) === 1
                ? $value : self::REDACTED,
            // These are the values published in the installed SDK's faktura-vydana metadata.
            'payment_state' => in_array($value, ['', 'stavUhr.castUhr', 'stavUhr.uhrazeno', 'stavUhr.uhrazenoRucne'], true)
                ? $value : self::REDACTED,
            default => self::REDACTED,
        };
    }

    /** @param 'request'|'response' $direction */
    private function omitBody(string $direction, string $reason, bool $truncated = false): void {
        $this->states[$direction]['buffer'] = '';
        $this->states[$direction]['bodyDone'] = true;
        $this->log([
            'direction' => $direction,
            'kind' => 'body',
            'body' => '[omitted]',
            'omitted' => true,
            'truncated' => $truncated,
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function log(array $context): void {
        try {
            $this->logger->debug('commerce.abra.curl_trace', $context);
        } catch (Throwable) {
            // Debug logging is never a reason to fail or retry an invoice request.
        }
    }
}
