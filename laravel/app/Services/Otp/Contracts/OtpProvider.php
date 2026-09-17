<?php

namespace App\Services\Otp\Contracts;

/**
 * Kontrak yang harus dipenuhi setiap provider OTP.
 * Tambah vendor baru = tulis satu class yang mengimplementasikan ini.
 */
interface OtpProvider
{
    public function name(): string;

    /**
     * Kirim OTP. Provider yang mengatur urutan channel dan fallback.
     *
     * @return array{request_id: string, check_url: ?string}
     */
    public function send(string $msisdn, ?string $locale = null): array;

    /** Cek kode. Lempar OtpException kalau gagal. */
    public function check(string $requestId, string $code): bool;

    public function cancel(string $requestId): bool;
}
