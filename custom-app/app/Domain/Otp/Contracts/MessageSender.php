<?php

declare(strict_types=1);

namespace App\Domain\Otp\Contracts;

use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Enums\FailureType;
use App\Domain\Otp\Exceptions\ProviderSendException;

/**
 * Provider adalah pipa kirim, bukan pemilik logika verifikasi. Kode OTP
 * dibuat dan diperiksa oleh sistem ini; sender hanya mengantarkannya.
 */
interface MessageSender
{
    public function name(): string;

    public function channel(): Channel;

    /**
     * @param  array<string, mixed>  $options  brand, template, locale, dan sejenisnya
     * @return array{provider_msg_id: ?string}
     *
     * @throws ProviderSendException
     */
    public function send(string $msisdn, string $code, array $options = []): array;

    /** Petakan kode error provider ke klasifikasi kita. */
    public function classifyError(string $providerCode): FailureType;
}
