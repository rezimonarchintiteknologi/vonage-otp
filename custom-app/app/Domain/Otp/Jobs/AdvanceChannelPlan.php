<?php

declare(strict_types=1);

namespace App\Domain\Otp\Jobs;

use App\Domain\Otp\Services\ChannelDispatcher;
use App\Models\Verification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delayed job pemicu fallback.
 *
 * Dijadwalkan setelah `channel_timeout` sejak langkah terakhir dikirim. Kalau
 * pesan ternyata sudah sampai, job ini berhenti sendiri — itu sebabnya
 * pembatalan job saat webhook `delivered` masuk hanya optimasi.
 */
final class AdvanceChannelPlan implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $verificationId,
        public readonly int $fromStep,
        public readonly string $encryptedCode,
    ) {}

    public function handle(ChannelDispatcher $dispatcher): void
    {
        $verification = Verification::with('application')->find($this->verificationId);

        if ($verification === null || $verification->status->isTerminal()) {
            return;
        }

        $dispatcher->advance($verification, $this->fromStep, decrypt($this->encryptedCode));
    }
}
