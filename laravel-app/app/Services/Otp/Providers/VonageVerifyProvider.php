<?php

namespace App\Services\Otp\Providers;

use App\Services\Otp\Contracts\OtpProvider;
use App\Services\Otp\Exceptions\OtpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter Vonage Verify v2.
 * Dokumentasi: https://developer.vonage.com/en/api/verify.v2
 *
 * Fallback WhatsApp -> SMS ditangani Vonage lewat array `workflow`.
 * Tidak ada timer atau state machine yang perlu kita tulis.
 */
class VonageVerifyProvider implements OtpProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'vonage-verify-v2';
    }

    private function http()
    {
        return Http::withBasicAuth(
            $this->config['vonage']['api_key'],
            $this->config['vonage']['api_secret'],
        )
            ->timeout($this->config['vonage']['timeout'])
            ->acceptJson()
            ->asJson();
    }

    /**
     * Vonage memakai problem+json (RFC 7807):
     *   { "type": ".../verify#conflict", "title": "Conflict", "detail": "..." }
     */
    private function slug(array $payload): string
    {
        $fromType = '';
        if (! empty($payload['type'])) {
            $parts    = explode('#', (string) $payload['type']);
            $fromType = end($parts) ?: '';
        }

        $raw = $fromType !== '' ? $fromType : (string) ($payload['title'] ?? '');

        return strtolower(preg_replace('/[\s_]+/', '-', $raw) ?? '');
    }

    public function send(string $msisdn, ?string $locale = null): array
    {
        $workflow = array_map(
            fn (string $channel) => ['channel' => $channel, 'to' => $msisdn],
            $this->config['channels'],
        );

        try {
            $response = $this->http()->post($this->config['vonage']['base_url'], [
                'brand'           => $this->config['brand'],
                'code_length'     => $this->config['code_length'],
                'channel_timeout' => $this->config['channel_timeout'],
                'locale'          => $locale ?? $this->config['locale'],
                'workflow'        => $workflow,
            ]);
        } catch (ConnectionException $e) {
            throw new OtpException(
                OtpException::PROVIDER_UNAVAILABLE,
                504,
                'Gagal menghubungi Vonage: ' . $e->getMessage(),
            );
        }

        if ($response->successful()) {
            return [
                'request_id' => $response->json('request_id'),
                'check_url'  => $response->json('check_url'),
            ];
        }

        $this->throwSendError($response);
    }

    private function throwSendError(Response $response): never
    {
        $payload = $response->json() ?? [];
        $slug    = $this->slug($payload);
        $status  = $response->status();

        if ($status === 409 || $slug === 'conflict') {
            throw new OtpException(
                OtpException::CONCURRENT_REQUEST,
                409,
                'Masih ada permintaan OTP aktif untuk nomor ini',
            );
        }

        if ($status === 429) {
            throw new OtpException(OtpException::RATE_LIMITED, 429, 'Rate limit Vonage tercapai');
        }

        if ($status === 422) {
            throw new OtpException(
                OtpException::PROVIDER_ERROR,
                422,
                $payload['detail'] ?? 'Parameter ditolak Vonage',
                ['slug' => $slug],
            );
        }

        throw new OtpException(
            OtpException::PROVIDER_ERROR,
            502,
            $payload['detail'] ?? "Vonage mengembalikan status {$status}",
            ['slug' => $slug, 'provider_status' => $status],
        );
    }

    public function check(string $requestId, string $code): bool
    {
        $url = $this->config['vonage']['base_url'] . '/' . $requestId;

        try {
            $response = $this->http()->post($url, ['code' => $code]);
        } catch (ConnectionException $e) {
            throw new OtpException(
                OtpException::PROVIDER_UNAVAILABLE,
                504,
                'Gagal menghubungi Vonage: ' . $e->getMessage(),
            );
        }

        if ($response->successful() && $response->json('status') === 'completed') {
            return true;
        }

        $payload = $response->json() ?? [];
        $slug    = $this->slug($payload);
        $status  = $response->status();

        if ($slug === 'invalid-code' || $status === 400) {
            throw new OtpException(OtpException::INVALID_CODE, 400, 'Kode verifikasi tidak cocok');
        }

        if ($slug === 'request-not-found' || $status === 404) {
            throw new OtpException(
                OtpException::REFERENCE_NOT_FOUND,
                404,
                'Permintaan OTP tidak ditemukan atau sudah selesai',
            );
        }

        if ($slug === 'expired' || $status === 410) {
            throw new OtpException(
                OtpException::EXPIRED,
                410,
                'Permintaan OTP sudah kedaluwarsa. Minta kode baru.',
            );
        }

        if ($status === 409) {
            throw new OtpException(OtpException::TOO_MANY_ATTEMPTS, 409, 'Terlalu banyak percobaan salah');
        }

        throw new OtpException(
            OtpException::PROVIDER_ERROR,
            502,
            $payload['detail'] ?? "Vonage mengembalikan status {$status}",
            ['slug' => $slug, 'provider_status' => $status],
        );
    }

    /** Vonage hanya mengizinkan cancel 30 detik setelah request dibuat. */
    public function cancel(string $requestId): bool
    {
        try {
            $response = $this->http()->delete(
                $this->config['vonage']['base_url'] . '/' . $requestId
            );
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }
}
