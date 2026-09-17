<?php

namespace App\Services\Otp\Exceptions;

use Exception;

class OtpException extends Exception
{
    public const INVALID_PHONE        = 'INVALID_PHONE';
    public const COUNTRY_NOT_ALLOWED  = 'COUNTRY_NOT_ALLOWED';
    public const RATE_LIMITED         = 'RATE_LIMITED';
    public const CONCURRENT_REQUEST   = 'CONCURRENT_REQUEST';
    public const PROVIDER_ERROR       = 'PROVIDER_ERROR';
    public const PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    public const REFERENCE_NOT_FOUND  = 'REFERENCE_NOT_FOUND';
    public const INVALID_CODE         = 'INVALID_CODE';
    public const EXPIRED              = 'EXPIRED';
    public const TOO_MANY_ATTEMPTS    = 'TOO_MANY_ATTEMPTS';

    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        string $message = '',
        public readonly array $meta = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function toArray(): array
    {
        return array_merge(
            ['code' => $this->errorCode, 'message' => $this->getMessage()],
            $this->meta
        );
    }
}
