<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use RuntimeException;

/**
 * Pembuat dan pembanding kode OTP.
 *
 * Tiga hal yang tidak boleh dikompromikan di sini:
 *  - random_int(), bukan rand()/mt_rand() — harus CSPRNG
 *  - pepper dibaca dari config (env atau KMS), tidak pernah dari database
 *  - hash_equals() untuk perbandingan constant-time
 */
final class CodeGenerator
{
    public function __construct(private readonly string $pepper)
    {
        if ($pepper === '') {
            throw new RuntimeException('OTP_PEPPER belum diisi. Kode tidak bisa di-hash tanpa pepper.');
        }
    }

    public function generate(int $length = 6): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /** Hash diikat ke nomor: kode yang sama untuk nomor lain menghasilkan hash berbeda. */
    public function hash(string $code, string $msisdn): string
    {
        return hash_hmac('sha256', $code.':'.$msisdn, $this->pepper);
    }

    public function verify(string $code, string $msisdn, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($code, $msisdn));
    }
}
