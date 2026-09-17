<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Exceptions\OtpException;
use App\Models\Application;

/**
 * Menyusun `channel_plan`: urutan channel yang akan dicoba, provider untuk
 * masing-masing, dan berapa lama menunggu sebelum pindah ke langkah berikutnya.
 *
 * Rencana disimpan di baris verifikasi, bukan dihitung ulang saat fallback.
 * Kalau konfigurasi application berubah di tengah jalan, verifikasi yang sedang
 * berjalan tetap memakai rencana yang sama seperti saat dibuat.
 */
final class ChannelRouter
{
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly CircuitBreaker $breaker,
        private readonly PhoneCapabilityCache $capabilities,
    ) {}

    /**
     * @param  list<string>|null  $requested
     * @return list<array{channel: string, provider: string, timeout: int}>
     *
     * @throws OtpException
     */
    public function plan(Application $application, string $msisdn, ?array $requested = null): array
    {
        $channels = $this->desiredChannels($application, $requested);
        $channels = $this->applyCapabilityCache($channels, $msisdn);

        $timeout = (int) $application->setting('channel_timeout');

        $steps = [];
        foreach ($channels as $channel) {
            $provider = $this->providerFor($application, $channel);

            // Provider yang breaker-nya terbuka dilewati saat menyusun rencana,
            // bukan saat pengiriman — supaya user tidak menunggu langkah yang
            // sudah pasti tidak akan dicoba.
            if (! $this->breaker->allows($provider)) {
                continue;
            }

            $steps[] = [
                'channel' => $channel->value,
                'provider' => $provider,
                'timeout' => $timeout,
            ];
        }

        if ($steps === []) {
            throw OtpException::noChannelAvailable();
        }

        // Rencana maksimal tiga langkah: lebih dari itu berarti user menunggu
        // lebih lama daripada kesabaran siapa pun terhadap satu layar OTP.
        return array_slice($steps, 0, 3);
    }

    /**
     * @param  list<string>|null  $requested
     * @return list<Channel>
     */
    private function desiredChannels(Application $application, ?array $requested): array
    {
        $allowOverride = (bool) data_get($application->config, 'allow_override', false);

        $raw = $allowOverride && $requested !== null && $requested !== []
            ? $requested
            : (array) $application->setting('channels');

        $channels = [];
        foreach ($raw as $value) {
            $channel = Channel::tryFrom((string) $value);

            if ($channel !== null && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels === [] ? [Channel::Whatsapp, Channel::Sms] : $channels;
    }

    /**
     * @param  list<Channel>  $channels
     * @return list<Channel>
     */
    private function applyCapabilityCache(array $channels, string $msisdn): array
    {
        if ($this->capabilities->waCapable($msisdn) !== false) {
            return $channels;
        }

        // Diketahui tidak punya WhatsApp: buang langkah WhatsApp seluruhnya.
        $filtered = array_values(array_filter(
            $channels,
            static fn (Channel $c) => $c !== Channel::Whatsapp,
        ));

        return $filtered === [] ? [Channel::Sms] : $filtered;
    }

    private function providerFor(Application $application, Channel $channel): string
    {
        return (string) (data_get($application->config, "providers.{$channel->value}")
            ?? $this->senders->defaultFor($channel));
    }
}
