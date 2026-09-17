<?php

declare(strict_types=1);

namespace App\Domain\Otp\Exceptions;

use App\Domain\Otp\Enums\FailureType;
use RuntimeException;

/**
 * Kegagalan pengiriman di sisi provider. Membawa kode mentah provider dan
 * klasifikasinya, karena itulah yang menentukan apakah channel berikutnya
 * dipakai seketika atau setelah menunggu timer.
 */
final class ProviderSendException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerCode,
        public readonly FailureType $failureType,
        string $message,
        public readonly array $raw = [],
    ) {
        parent::__construct($message);
    }
}
