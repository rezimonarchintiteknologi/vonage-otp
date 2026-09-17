<?php

declare(strict_types=1);

namespace App\Domain\Otp\Enums;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /** Status yang membuktikan pesan sampai ke perangkat. */
    public function isSettled(): bool
    {
        return $this === self::Delivered || $this === self::Read;
    }
}
