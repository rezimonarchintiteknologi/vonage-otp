<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Contracts\VerificationRepository;
use App\Domain\Otp\Enums\VerificationStatus;
use App\Domain\Otp\Exceptions\OtpException;
use App\Models\Verification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentVerificationRepository implements VerificationRepository
{
    public function create(array $attributes): Verification
    {
        try {
            // Dibungkus savepoint: pada PostgreSQL, pelanggaran constraint
            // membatalkan seluruh transaksi berjalan. Tanpa savepoint, satu
            // permintaan duplikat akan ikut menggagalkan transaksi pemanggil.
            return DB::transaction(fn (): Verification => Verification::create($attributes));
        } catch (UniqueConstraintViolationException) {
            // Partial unique index `verifications_active_unique` yang menolak.
            // Dua request paralel untuk nomor yang sama: satu menang, satu ini.
            throw OtpException::verificationExists();
        }
    }

    public function find(string $tenantId, string $id): ?Verification
    {
        return Verification::query()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }

    /**
     * Satu query, tanpa jendela race condition: pemeriksaan status, masa
     * berlaku, dan kecocokan kode terjadi di dalam WHERE yang sama dengan
     * penulisannya.
     */
    public function consume(string $id, string $codeHash): bool
    {
        $affected = DB::update("
            UPDATE verifications
               SET status = 'approved',
                   consumed_at = now(),
                   attempts = attempts + 1,
                   updated_at = now()
             WHERE id = ?
               AND status = 'pending'
               AND expires_at > now()
               AND code_hash = ?
        ", [$id, $codeHash]);

        return $affected === 1;
    }

    public function recordFailedAttempt(string $id): int
    {
        DB::update("
            UPDATE verifications
               SET attempts = attempts + 1, updated_at = now()
             WHERE id = ? AND status = 'pending'
        ", [$id]);

        return (int) DB::table('verifications')->where('id', $id)->value('attempts');
    }

    public function markExpired(string $id): void
    {
        DB::update("
            UPDATE verifications
               SET status = 'expired', updated_at = now()
             WHERE id = ? AND status = 'pending'
        ", [$id]);
    }

    public function markFailed(string $id): void
    {
        DB::update("
            UPDATE verifications
               SET status = 'failed', updated_at = now()
             WHERE id = ? AND status = 'pending'
        ", [$id]);
    }

    public function cancel(string $id): bool
    {
        return DB::update("
            UPDATE verifications
               SET status = 'cancelled', updated_at = now()
             WHERE id = ? AND status = 'pending'
        ", [$id]) === 1;
    }

    public function isPending(string $id): bool
    {
        return DB::table('verifications')
            ->where('id', $id)
            ->where('status', VerificationStatus::Pending->value)
            ->exists();
    }
}
