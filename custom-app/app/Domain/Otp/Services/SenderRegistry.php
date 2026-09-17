<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Contracts\MessageSender;
use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Providers\JatisSmsSender;
use App\Domain\Otp\Providers\MetaCloudApiSender;
use App\Domain\Otp\Providers\SandboxSender;
use InvalidArgumentException;

/**
 * Pemetaan nama provider ke sender-nya. Satu-satunya tempat yang tahu kelas
 * mana yang mengirim lewat pipa mana — menambah vendor berarti menambah satu
 * baris di sini dan satu file provider.
 */
final class SenderRegistry
{
    /** @var array<string, MessageSender> */
    private array $resolved = [];

    public function resolve(string $provider, Channel $channel, string $mode = 'live'): MessageSender
    {
        // Kunci test tidak pernah memicu panggilan provider nyata.
        if ($mode === 'test') {
            return $this->resolved["sandbox:{$channel->value}"] ??= new SandboxSender(
                $channel,
                config('otp.magic_numbers'),
            );
        }

        return $this->resolved["{$provider}:{$channel->value}"] ??= match ($provider) {
            'meta' => new MetaCloudApiSender(config('otp.meta')),
            'jatis' => new JatisSmsSender(config('otp.sms')),
            'sandbox' => new SandboxSender($channel, config('otp.magic_numbers')),
            default => throw new InvalidArgumentException("Provider tidak dikenal: {$provider}"),
        };
    }

    public function defaultFor(Channel $channel): string
    {
        return config("otp.providers.{$channel->value}")
            ?? throw new InvalidArgumentException("Tidak ada provider default untuk channel {$channel->value}");
    }

    public function forget(): void
    {
        $this->resolved = [];
    }
}
