<?php

declare(strict_types=1);

namespace App\Domain\Otp\Providers;

use App\Domain\Otp\Contracts\MessageSender;
use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Enums\FailureType;
use App\Domain\Otp\Exceptions\ProviderSendException;
use Illuminate\Support\Str;

/**
 * Sender untuk mode test. Tidak pernah memanggil provider mana pun.
 *
 * Perilakunya ditentukan nomor ajaib, sehingga tenant bisa menguji jalur
 * gagal tanpa perlu nomor nyata yang kebetulan bermasalah.
 */
final class SandboxSender implements MessageSender
{
    public function __construct(
        private readonly Channel $channel,
        /** @var array<string, string> */
        private readonly array $magicNumbers = [],
    ) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function send(string $msisdn, string $code, array $options = []): array
    {
        $behaviour = $this->magicNumbers[$msisdn] ?? 'success';

        $fail = fn (string $providerCode, FailureType $type, string $message) => throw new ProviderSendException(
            $this->name(),
            $providerCode,
            $type,
            $message,
            ['simulated' => $behaviour],
        );

        match (true) {
            $behaviour === 'all_fail' => $fail('sandbox_all_fail', FailureType::Temporary, 'Simulasi: semua channel gagal'),
            $behaviour === 'undeliverable' => $fail('sandbox_undeliverable', FailureType::Permanent, 'Simulasi: nomor tidak bisa menerima pesan'),
            $behaviour === 'whatsapp_fail_sms_ok' && $this->channel === Channel::Whatsapp => $fail(
                'sandbox_wa_fail',
                FailureType::Permanent,
                'Simulasi: WhatsApp gagal, SMS akan berhasil',
            ),
            default => null,
        };

        // `timeout_all` berhasil dikirim tapi tidak pernah dapat konfirmasi
        // delivered — persis seperti pesan yang hilang di jaringan operator.
        return ['provider_msg_id' => 'sandbox_'.Str::random(24)];
    }

    public function classifyError(string $providerCode): FailureType
    {
        return match ($providerCode) {
            'sandbox_undeliverable', 'sandbox_wa_fail' => FailureType::Permanent,
            'sandbox_all_fail' => FailureType::Temporary,
            default => FailureType::Unknown,
        };
    }

    public function behaviourFor(string $msisdn): string
    {
        return $this->magicNumbers[$msisdn] ?? 'success';
    }
}
