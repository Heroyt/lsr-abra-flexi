<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use DateTimeImmutable;
use DateTimeInterface;
use Lsr\AbraFlexi\Exceptions\InvoiceResponseMappingException;
use UnexpectedValueException;

final class AbraFlexiValueMapper
{
    private function __construct() {
    }

    public static function requiredString(mixed $value, string $field): string {
        if ( ! is_string($value) || trim($value) === '') {
            throw new InvoiceResponseMappingException($field, 'non-empty string', $value);
        }

        return trim($value);
    }

    public static function requiredPositiveInt(mixed $value, string $field): int {
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            $max = (string) PHP_INT_MAX;
            if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
                throw new InvoiceResponseMappingException($field, 'positive integer within supported range', $value);
            }
            $value = (int) $value;
        }
        if ( ! is_int($value) || $value < 1) {
            throw new InvoiceResponseMappingException($field, 'positive integer', $value);
        }

        return $value;
    }

    public static function requiredDate(mixed $value, string $field): DateTimeImmutable {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (
            ! is_string($value)
            || preg_match('/^(\d{4}-\d{2}-\d{2})(Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))?$/D', $value, $matches) !== 1
        ) {
            throw new InvoiceResponseMappingException($field, 'YYYY-MM-DD date with optional timezone', $value);
        }

        // ABRA dates are calendar dates, not instants to convert into the application timezone.
        $date = DateTimeImmutable::createFromFormat(isset($matches[2]) ? '!Y-m-dP' : '!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $matches[1]) {
            throw new InvoiceResponseMappingException($field, 'valid calendar date', $value);
        }

        return $date;
    }

    public static function relationCode(mixed $value, string $field): string {
        $value = self::requiredString($value, $field);
        if (str_starts_with($value, 'code:')) {
            $value = substr($value, 5);
        }
        if ($value === '') {
            throw new InvoiceResponseMappingException($field, 'non-empty relation code', $value);
        }

        return $value;
    }

    public static function decimalToMinor(mixed $value, string $field): int {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value) && $value >= 0) {
            $value = sprintf('%.2F', round($value, 2, PHP_ROUND_HALF_UP));
        }
        if ( ! is_string($value) || preg_match('/^(\d+)(?:\.(\d+))?$/', trim($value), $matches) !== 1) {
            throw new InvoiceResponseMappingException($field, 'non-negative decimal amount', $value);
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 15) {
            throw new InvoiceResponseMappingException($field, 'amount within supported range', $value);
        }
        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $minor = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$minor;
        }

        return $minor;
    }

    public static function minorToDecimal(int $minor): string {
        if ($minor < 0) {
            throw new UnexpectedValueException('Invoice amounts cannot be negative.');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    public static function basisPointsToPercent(int $basisPoints): string {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new UnexpectedValueException('The invoice VAT rate is invalid.');
        }

        return rtrim(rtrim(sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100), '0'), '.');
    }
}
