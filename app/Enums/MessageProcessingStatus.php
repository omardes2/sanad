<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageProcessingStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
