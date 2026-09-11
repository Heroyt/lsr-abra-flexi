<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Enums;

enum InvoiceGatewayFailure: string
{
    case ConfigurationDisabled = 'configuration_disabled';
    case Retryable = 'retryable';
    case AmbiguousWrite = 'ambiguous_write';
    case RejectedRequest = 'rejected_request';
    case IdentityConflict = 'identity_conflict';
    case InvalidResponse = 'invalid_response';
}
