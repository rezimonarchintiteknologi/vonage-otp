<?php

declare(strict_types=1);

namespace App\Domain\Otp\Jobs;

use App\Domain\Otp\Services\ChannelDispatcher;
use App\Models\Verification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mengirim satu langkah channel_plan.
 *
 * Kode dibawa terenkripsi di payload job, bukan disimpan di database: kode
 * yang sama harus dipakai di semua channel, dan satu-satunya bentuk yang
 * tersimpan permanen adalah hash-nya.
 */
final class DispatchChannelStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $verificationId,
        public readonly int $step,
        public readonly string $encryptedCode,
        public readonly int $retry = 0,
    ) {}

    public function handle(ChannelDispatcher $dispatcher): void
    {
        $verification = Verification::with('application')->find($this->verificationId);

        if ($verification === null || $verification->status->isTerminal()) {
            return;
        }

        $dispatcher->dispatchStep(
            $verification,
            $this->step,
            decrypt($this->encryptedCode),
            $this->retry,
        );
    }
}
