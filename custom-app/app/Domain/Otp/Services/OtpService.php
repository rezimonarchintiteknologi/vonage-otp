<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Enums\VerificationStatus;
use App\Domain\Otp\Exceptions\OtpException;
use App\Domain\Otp\Jobs\DispatchChannelStep;
use App\Models\Application;
use App\Models\Verification;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrator verifikasi: membuat kode, menyerahkan pengirimannya ke rencana
 * channel, dan memeriksa jawaban user.
 *
 * Kode OTP milik sistem ini, bukan milik provider. Itu yang membuat kode yang
 * sama bisa dipakai lintas channel dan membuat pindah vendor tidak menyentuh
 * satu baris pun logika di kelas ini.
 */
final class OtpService
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly PhoneNormalizer $phones,
        private readonly ChannelRouter $router,
        private readonly EloquentVerificationRepository $repository,
        private readonly Metrics $metrics,
    ) {}

    /**
     * @param  array{purpose?: string, channels?: list<string>|null, mode?: string, ip?: string|null, metadata?: array<string, mixed>}  $options
     *
     * @throws OtpException
     */
    public function request(Application $application, string $rawPhone, array $options = []): Verification
    {
        $mode = $options['mode'] ?? 'live';
        $msisdn = $this->phones->normalize($rawPhone, $application->allowedPrefixes());

        $plan = $this->router->plan($application, $msisdn, $options['channels'] ?? null);

        $length = (int) $application->setting('code_length');
        $code = $this->codes->generate($length);

        $verification = $this->repository->create([
            'tenant_id' => $application->tenant_id,
            'application_id' => $application->id,
            'msisdn' => $msisdn,
            'msisdn_hash' => PhoneNormalizer::hash($msisdn),
            'code_hash' => $this->codes->hash($code, $msisdn),
            'status' => VerificationStatus::Pending->value,
            'purpose' => $options['purpose'] ?? 'login',
            'mode' => $mode,
            'channel_plan' => $plan,
            'current_step' => 0,
            'attempts' => 0,
            'max_attempts' => (int) $application->setting('max_attempts'),
            'expires_at' => now()->addSeconds((int) $application->setting('ttl')),
            'client_ip' => $options['ip'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ]);

        $this->metrics->increment('verification_created_total', [
            'tenant' => $application->tenant_id,
            'application' => $application->id,
            'purpose' => $verification->purpose,
            'mode' => $mode,
        ]);

        // Kode hanya hidup di memori proses ini dan di payload job terenkripsi.
        DispatchChannelStep::dispatch($verification->id, 0, encrypt($code));

        Log::info('otp.verification.created', [
            'verification_id' => $verification->id,
            'tenant_id' => $application->tenant_id,
            'masked_phone' => PhoneNormalizer::mask($msisdn),
            'channels' => array_column($plan, 'channel'),
            'mode' => $mode,
        ]);

        return $verification->fresh();
    }

    /**
     * @throws OtpException
     */
    public function check(Verification $verification, string $code): Verification
    {
        if ($verification->status === VerificationStatus::Approved) {
            throw OtpException::alreadyConsumed();
        }

        if ($verification->status->isTerminal()) {
            throw $verification->status === VerificationStatus::Expired
                ? OtpException::expired()
                : OtpException::notFound();
        }

        if ($verification->expires_at->isPast()) {
            $this->repository->markExpired($verification->id);

            throw OtpException::expired();
        }

        if ($verification->attempts >= $verification->max_attempts) {
            throw OtpException::tooManyAttempts();
        }

        $hash = $this->codes->hash($code, $verification->msisdn);

        if (! $this->repository->consume($verification->id, $hash)) {
            $attempts = $this->repository->recordFailedAttempt($verification->id);

            $this->metrics->increment('verification_rejected_total', [
                'tenant' => $verification->tenant_id,
                'reason' => 'invalid_code',
            ]);

            if ($attempts >= $verification->max_attempts) {
                throw OtpException::tooManyAttempts();
            }

            throw OtpException::invalidCode(max(0, $verification->max_attempts - $attempts));
        }

        $verification->refresh();

        $this->metrics->increment('verification_approved_total', [
            'tenant' => $verification->tenant_id,
            'channel_used' => $verification->channel_used ?? $this->lastChannel($verification),
        ]);

        Log::info('otp.verification.approved', [
            'verification_id' => $verification->id,
            'tenant_id' => $verification->tenant_id,
            'masked_phone' => $verification->maskedMsisdn(),
        ]);

        return $verification;
    }

    public function cancel(Verification $verification): bool
    {
        return $this->repository->cancel($verification->id);
    }

    private function lastChannel(Verification $verification): ?string
    {
        return $verification->deliveries()
            ->orderByDesc('created_at')
            ->value('channel');
    }
}
