<?php

declare(strict_types=1);

namespace App\Domain\Otp\Enums;

enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';

    public static function fromList(array $values): array
    {
        return array_map(static fn (string $v) => self::from($v), $values);
    }
}
