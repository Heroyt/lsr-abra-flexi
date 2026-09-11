<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Exceptions;

use Lsr\AbraFlexi\Enums\InvoiceGatewayFailure;
use RuntimeException;

final class InvoiceGatewayException extends RuntimeException
{
    public function __construct(
        public readonly InvoiceGatewayFailure $failure,
        string $message,
        public readonly ?InvoiceResponseMappingException $responseProblem = null,
        public readonly ?string $causeType = null,
    ) {
        parent::__construct($message);
    }
}
