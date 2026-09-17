<?php

declare(strict_types=1);

namespace App\Domain\Otp\Contracts;

use App\Models\Verification;

interface VerificationRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws \App\Domain\Otp\Exceptions\OtpException  kalau nomor masih punya verifikasi aktif
     */
    public function create(array $attributes): Verification;

    public function find(string $tenantId, string $id): ?Verification;

    /**
     * Konsumsi kode dalam satu query. Mengembalikan true kalau baris benar-benar
     * berpindah ke `approved` — tidak ada jendela race di antara cek dan tulis.
     */
    public function consume(string $id, string $codeHash): bool;

    /** Naikkan penghitung percobaan pada verifikasi yang masih pending. */
    public function recordFailedAttempt(string $id): int;

    public function markExpired(string $id): void;

    public function cancel(string $id): bool;
}
