<?php

namespace App\Services\Otp;

use App\Services\Otp\Contracts\OtpProvider;
use App\Services\Otp\Exceptions\OtpException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Layer netral vendor. Controller hanya bicara ke class ini.
 * Pindah ke Twilio / Infobip / Meta Cloud API = tulis satu provider baru,
 * file ini tidak berubah.
 */
class OtpService
{
    public function __construct(
        private readonly OtpProvider $provider,
        private readonly PhoneNormalizer $normalizer,
        private readonly array $config,
    ) {}

    private function refKey(string $reference): string
    {
        return "otp:ref:{$reference}";
    }

    /**
     * Pakai RateLimiter bawaan Laravel: window-nya benar dan atomik,
     * tidak perlu kita hitung TTL manual.
     */
    private function enforceRateLimit(string $scope, string $identifier, array $rule): void
    {
        $key = "otp:rl:{$scope}:" . sha1($identifier);

        if (RateLimiter::tooManyAttempts($key, $rule['max'])) {
            throw new OtpException(
                OtpException::RATE_LIMITED,
                429,
                'Terlalu banyak permintaan. Coba lagi nanti.',
                ['scope' => $scope, 'retry_after' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, $rule['window']);
    }

    /**
     * Minta OTP. Mengembalikan reference acak, bukan request_id Vonage.
     *
     * @return array{reference: string, masked_phone: string, channels: array, channel_timeout: int, expires_in: int}
     */
    public function request(string $phone, ?string $ip = null, ?string $locale = null): array
    {
        $parsed = $this->normalizer->normalize($phone);

        if ($parsed['rejected'] === 'COUNTRY_NOT_ALLOWED') {
            throw new OtpException(
                OtpException::COUNTRY_NOT_ALLOWED,
                403,
                'Nomor di luar wilayah layanan',
            );
        }

        if ($parsed['msisdn'] === null) {
            throw new OtpException(OtpException::INVALID_PHONE, 422, 'Format nomor tidak valid');
        }

        $msisdn = $parsed['msisdn'];

        $this->enforceRateLimit('phone', $msisdn, $this->config['rate_limit']['phone']);
        if ($ip !== null) {
            $this->enforceRateLimit('ip', $ip, $this->config['rate_limit']['ip']);
        }

        $result    = $this->provider->send($msisdn, $locale);
        $reference = Str::random(40);

        Cache::put($this->refKey($reference), [
            'request_id' => $result['request_id'],
            'msisdn'     => $msisdn,
            'provider'   => $this->provider->name(),
            'attempts'   => 0,
        ], $this->config['reference_ttl']);

        return [
            'reference'       => $reference,
            'masked_phone'    => PhoneNormalizer::mask($msisdn),
            'channels'        => $this->config['channels'],
            'channel_timeout' => $this->config['channel_timeout'],
            'expires_in'      => $this->config['reference_ttl'],
        ];
    }

    /**
     * Verifikasi kode.
     *
     * @return array{verified: bool, msisdn: string, masked_phone: string, attempts: int}
     */
    public function verify(string $reference, string $code): array
    {
        $key    = $this->refKey($reference);
        $record = Cache::get($key);

        if ($record === null) {
            throw new OtpException(
                OtpException::REFERENCE_NOT_FOUND,
                404,
                'Sesi verifikasi tidak ditemukan atau sudah kedaluwarsa',
            );
        }

        // Batas percobaan di sisi kita sendiri, tidak hanya mengandalkan provider.
        if ($record['attempts'] >= $this->config['max_verify_attempts']) {
            Cache::forget($key);
            throw new OtpException(
                OtpException::TOO_MANY_ATTEMPTS,
                429,
                'Terlalu banyak percobaan salah. Minta kode baru.',
            );
        }

        $record['attempts']++;
        Cache::put($key, $record, $this->config['reference_ttl']);

        try {
            $this->provider->check($record['request_id'], $code);
        } catch (OtpException $e) {
            if (in_array($e->errorCode, [OtpException::EXPIRED, OtpException::REFERENCE_NOT_FOUND], true)) {
                Cache::forget($key);
            }
            throw $e;
        }

        Cache::forget($key);

        return [
            'verified'     => true,
            'msisdn'       => $record['msisdn'],
            'masked_phone' => PhoneNormalizer::mask($record['msisdn']),
            'attempts'     => $record['attempts'],
        ];
    }
}
