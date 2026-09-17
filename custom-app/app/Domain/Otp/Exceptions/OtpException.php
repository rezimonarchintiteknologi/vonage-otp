<?php

declare(strict_types=1);

namespace App\Domain\Otp\Exceptions;

use RuntimeException;

final class OtpException extends RuntimeException
{
    public const INVALID_PHONE = 'invalid_phone';
    public const COUNTRY_NOT_ALLOWED = 'country_not_allowed';
    public const RATE_LIMITED = 'rate_limited';
    public const VERIFICATION_EXISTS = 'verification_exists';
    public const NOT_FOUND = 'not_found';
    public const INVALID_CODE = 'invalid_code';
    public const EXPIRED = 'expired';
    public const TOO_MANY_ATTEMPTS = 'too_many_attempts';
    public const ALREADY_CONSUMED = 'already_consumed';
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const UNAUTHORIZED = 'unauthorized';
    public const IDEMPOTENCY_KEY_REUSE = 'idempotency_key_reuse';
    public const IDEMPOTENCY_KEY_REQUIRED = 'idempotency_key_required';
    public const NO_CHANNEL_AVAILABLE = 'no_channel_available';
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    /** @var array<string, mixed> */
    public array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        array $context = [],
    ) {
        parent::__construct($message);
        $this->context = $context;
    }

    public static function invalidPhone(string $message = 'Format nomor tidak valid'): self
    {
        return new self(self::INVALID_PHONE, 422, $message);
    }

    public static function countryNotAllowed(string $prefix): self
    {
        return new self(
            self::COUNTRY_NOT_ALLOWED,
            403,
            'Prefix negara tidak diizinkan untuk application ini',
            ['prefix' => $prefix],
        );
    }

    public static function verificationExists(): self
    {
        return new self(
            self::VERIFICATION_EXISTS,
            409,
            'Masih ada verifikasi aktif untuk nomor ini',
        );
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, 'Verifikasi tidak ditemukan');
    }

    public static function invalidCode(int $remaining): self
    {
        return new self(
            self::INVALID_CODE,
            400,
            'Kode verifikasi tidak cocok',
            ['attempts_remaining' => $remaining],
        );
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 410, 'Verifikasi sudah kedaluwarsa');
    }

    public static function tooManyAttempts(): self
    {
        return new self(self::TOO_MANY_ATTEMPTS, 429, 'Percobaan verifikasi melebihi batas');
    }

    public static function alreadyConsumed(): self
    {
        return new self(self::ALREADY_CONSUMED, 409, 'Verifikasi sudah dipakai');
    }

    public static function providerUnavailable(string $provider): self
    {
        return new self(
            self::PROVIDER_UNAVAILABLE,
            502,
            'Provider tidak bisa dihubungi',
            ['provider' => $provider],
        );
    }

    public static function noChannelAvailable(): self
    {
        return new self(
            self::NO_CHANNEL_AVAILABLE,
            503,
            'Tidak ada channel yang tersedia saat ini',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->context ?: null,
            ],
        ]);
    }
}
