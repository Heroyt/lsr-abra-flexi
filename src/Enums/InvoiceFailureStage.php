<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Enums;

enum InvoiceFailureStage: string
{
    case Lookup = 'lookup';
    case Create = 'create';
    case PdfDownload = 'pdf_download';
}
