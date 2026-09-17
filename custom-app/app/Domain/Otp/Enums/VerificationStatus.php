<?php

declare(strict_types=1);

namespace App\Domain\Otp\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
