<?php

declare(strict_types=1);

namespace App\Domain\Otp\Enums;

/**
 * Klasifikasi kegagalan provider. Menentukan apakah channel berikutnya
 * dipakai seketika atau setelah menunggu channel_timeout.
 */
enum FailureType: string
{
    /** Tidak akan pernah berhasil di channel ini. Lompat sekarang juga. */
    case Permanent = 'permanent';

    /** Mungkin berhasil kalau diulang. Retry channel yang sama dulu. */
    case Temporary = 'temporary';

    /** Provider tidak memberi kepastian. Tunggu timer, lalu lompat. */
    case Unknown = 'unknown';
}
