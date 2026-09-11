<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Exceptions;

use UnexpectedValueException;

final class InvoiceResponseMappingException extends UnexpectedValueException
{
    public readonly string $valueType;
    public readonly ?int $valueLength;

    public function __construct(
        public readonly string $field,
        public readonly string $expected,
        mixed $value,
    ) {
        $this->valueType = gettype($value);
        $this->valueLength = is_string($value) ? strlen($value) : null;
        parent::__construct(sprintf('Invalid invoice response field "%s"; expected %s.', $field, $expected));
    }
}
