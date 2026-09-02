<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
