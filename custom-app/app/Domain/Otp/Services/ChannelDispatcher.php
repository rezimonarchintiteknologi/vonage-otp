<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Enums\DeliveryStatus;
use App\Domain\Otp\Enums\FailureType;
use App\Domain\Otp\Exceptions\ProviderSendException;
use App\Domain\Otp\Jobs\AdvanceChannelPlan;
use App\Domain\Otp\Jobs\DispatchChannelStep;
use App\Models\Delivery;
use App\Models\Verification;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;

/**
 * Menjalankan satu langkah dari `channel_plan` dan menentukan apa yang terjadi
 * sesudahnya: menunggu timer, mengulang channel yang sama, atau melompat ke
 * channel berikutnya sekarang juga.
 *
 * Perbedaan antara implementasi yang baik dan yang asal jalan ada di sini:
 * menunggu 30 detik untuk error yang sudah pasti permanen adalah 30 detik
 * yang dibuang dari hidup user, dan mereka sudah terlanjur menekan "kirim ulang".
 */
final class ChannelDispatcher
{
    private const MAX_RETRY_SAME_CHANNEL = 2;

    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly CircuitBreaker $breaker,
        private readonly PhoneCapabilityCache $capabilities,
        private readonly EloquentVerificationRepository $repository,
        private readonly Metrics $metrics,
    ) {}

    public function dispatchStep(Verification $verification, int $step, string $code, int $retry = 0): void
    {
        $plan = $verification->stepAt($step);

        if ($plan === null) {
            $this->exhaust($verification);

            return;
        }

        $channel = Channel::from($plan['channel']);
        $provider = $plan['provider'];
        $operator = PhoneNormalizer::operator($verification->msisdn);

        if (! $this->breaker->allows($provider)) {
            // Provider jatuh setelah rencana disusun. Jangan buang waktu user.
            $this->advance($verification, $step, $code);

            return;
        }

        $sender = $this->senders->resolve($provider, $channel, $verification->mode);

        $delivery = Delivery::create([
            'verification_id' => $verification->id,
            'tenant_id' => $verification->tenant_id,
            'step' => $step,
            'channel' => $channel->value,
            'provider' => $provider,
            'status' => DeliveryStatus::Queued->value,
            'operator' => $operator,
            'attempted_at' => now(),
        ]);

        $verification->forceFill(['current_step' => $step])->save();

        $startedAt = microtime(true);

        try {
            $result = $sender->send($verification->msisdn, $code, [
                'brand' => $verification->application->brand,
                'locale' => data_get($verification->application->config, 'locale'),
                'template' => data_get($verification->application->config, 'template'),
            ]);
        } catch (ProviderSendException $e) {
            $this->recordFailure($verification, $delivery, $e, $operator, $startedAt);
            $this->reactToFailure($verification, $step, $code, $e->failureType, $retry);

            return;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $delivery->forceFill([
            'status' => DeliveryStatus::Sent->value,
            'provider_msg_id' => $result['provider_msg_id'],
            'latency_ms' => $latencyMs,
        ])->save();

        $this->breaker->recordSuccess($provider);

        $this->metrics->increment('delivery_attempt_total', [
            'tenant' => $verification->tenant_id,
            'channel' => $channel->value,
            'provider' => $provider,
            'status' => 'sent',
            'operator' => $operator,
        ]);
        $this->metrics->observe('delivery_latency_seconds', $latencyMs / 1000, [
            'channel' => $channel->value,
            'provider' => $provider,
            'operator' => $operator,
        ]);

        $this->scheduleAdvance($verification, $step, $code, $plan['timeout']);
    }

    /**
     * Pindah ke langkah berikutnya. Kalau rencana habis, verifikasi ditandai
     * gagal — user tidak akan pernah menerima kode ini.
     */
    public function advance(Verification $verification, int $fromStep, string $code): void
    {
        $verification->refresh();

        if ($verification->status->isTerminal()) {
            return;
        }

        if ($this->hasSettledDelivery($verification)) {
            return;
        }

        $next = $fromStep + 1;

        if ($verification->stepAt($next) === null) {
            $this->exhaust($verification);

            return;
        }

        $this->dispatchStep($verification, $next, $code);
    }

    private function reactToFailure(
        Verification $verification,
        int $step,
        string $code,
        FailureType $type,
        int $retry,
    ): void {
        match ($type) {
            // Tidak akan pernah berhasil di channel ini: lompat sekarang juga.
            FailureType::Permanent => $this->advance($verification, $step, $code),

            // Mungkin sesaat: ulangi channel yang sama dengan backoff, lalu menyerah.
            FailureType::Temporary => $retry < self::MAX_RETRY_SAME_CHANNEL
                ? DispatchChannelStep::dispatch($verification->id, $step, $this->encrypt($code), $retry + 1)
                    ->delay(now()->addSeconds(2 ** ($retry + 1)))
                : $this->advance($verification, $step, $code),

            // Provider tidak memberi kepastian: tunggu timer seperti biasa.
            FailureType::Unknown => $this->scheduleAdvance(
                $verification,
                $step,
                $code,
                (int) ($verification->stepAt($step)['timeout'] ?? 30),
            ),
        };
    }

    private function recordFailure(
        Verification $verification,
        Delivery $delivery,
        ProviderSendException $e,
        string $operator,
        float $startedAt,
    ): void {
        $delivery->forceFill([
            'status' => DeliveryStatus::Failed->value,
            'error_code' => $e->providerCode,
            'failure_type' => $e->failureType->value,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'raw' => $e->raw,
        ])->save();

        $this->breaker->recordFailure($e->provider);

        $this->metrics->increment('delivery_attempt_total', [
            'tenant' => $verification->tenant_id,
            'channel' => $delivery->channel,
            'provider' => $e->provider,
            'status' => 'failed',
            'error_code' => $e->providerCode,
            'operator' => $operator,
        ]);

        // Nomor terbukti tidak punya WhatsApp: ingat, supaya permintaan
        // berikutnya langsung memakai SMS.
        if ($delivery->channel === Channel::Whatsapp->value && $e->providerCode === '131026') {
            $this->capabilities->remember($verification->msisdn, false);
        }

        // Kode tidak pernah masuk log. Yang dicatat hanya identitas dan sebabnya.
        Log::warning('otp.delivery.failed', [
            'verification_id' => $verification->id,
            'tenant_id' => $verification->tenant_id,
            'channel' => $delivery->channel,
            'provider' => $e->provider,
            'error_code' => $e->providerCode,
            'failure_type' => $e->failureType->value,
        ]);
    }

    private function scheduleAdvance(Verification $verification, int $step, string $code, int $timeout): void
    {
        if ($verification->stepAt($step + 1) === null) {
            return;   // langkah terakhir: tidak ada yang perlu dijadwalkan
        }

        $job = AdvanceChannelPlan::dispatch($verification->id, $step, $this->encrypt($code))
            ->delay(now()->addSeconds($timeout));

        // Job yang tetap menyala akan memeriksa status terkini dan berhenti
        // sendiri — pembatalan lewat id ini adalah optimasi, bukan syarat benar.
        $verification->forceFill(['pending_job_id' => method_exists($job, 'getJobId') ? $job->getJobId() : null])->save();
    }

    private function hasSettledDelivery(Verification $verification): bool
    {
        return $verification->deliveries()
            ->whereIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Read->value])
            ->exists();
    }

    private function exhaust(Verification $verification): void
    {
        $this->repository->markFailed($verification->id);

        $this->metrics->increment('verification_exhausted_total', [
            'tenant' => $verification->tenant_id,
        ]);
    }

    private function encrypt(string $code): string
    {
        return encrypt($code);
    }
}
