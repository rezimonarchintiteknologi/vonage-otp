<?php

declare(strict_types=1);

namespace App\Domain\Otp\Providers;

use App\Domain\Otp\Contracts\MessageSender;
use App\Domain\Otp\Enums\Channel;
use App\Domain\Otp\Enums\FailureType;
use App\Domain\Otp\Exceptions\ProviderSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS lewat aggregator.
 *
 * PERHATIAN: kontrak HTTP di bawah ini mengikuti bentuk umum aggregator
 * Indonesia (POST JSON, balasan berisi message id dan status code). Sebelum
 * dipakai di produksi, cocokkan `endpoint`, nama field, dan tabel kode error
 * dengan dokumentasi resmi dari aggregator yang akhirnya dipilih — itu bagian
 * dari tugas 0.8 pada rencana. Yang tidak perlu berubah: interface ini dan
 * pemetaan ke FailureType.
 */
final class JatisSmsSender implements MessageSender
{
    /** @var array<string, string> */
    private const PERMANENT = [
        '400' => 'Permintaan tidak valid',
        '403' => 'Sender ID tidak diizinkan',
        '404' => 'Nomor tujuan tidak dikenal',
        'INVALID_DESTINATION' => 'Nomor tujuan tidak valid',
        'BLACKLISTED' => 'Nomor masuk daftar blokir',
        'SENDER_REJECTED' => 'Sender ID ditolak operator',
    ];

    /** @var array<string, string> */
    private const TEMPORARY = [
        '429' => 'Rate limit aggregator tercapai',
        '500' => 'Kesalahan internal aggregator',
        '502' => 'Gateway aggregator bermasalah',
        '503' => 'Layanan aggregator tidak tersedia',
        'ROUTE_BUSY' => 'Rute operator sedang penuh',
        'INSUFFICIENT_BALANCE' => 'Saldo aggregator habis',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'jatis';
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function send(string $msisdn, string $code, array $options = []): array
    {
        $brand = $options['brand'] ?? config('app.name');

        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->config['api_key']])
                ->timeout($this->config['timeout'])
                ->asJson()
                ->post(rtrim((string) $this->config['base_url'], '/').'/v1/messages', [
                    'sender' => $this->config['sender_id'],
                    'msisdn' => $msisdn,
                    'text' => "{$code} adalah kode verifikasi {$brand}. Jangan bagikan kode ini kepada siapa pun.",
                    'type' => 'transactional',
                ]);
        } catch (ConnectionException) {
            throw new ProviderSendException(
                $this->name(),
                'connection_error',
                FailureType::Unknown,
                'Tidak bisa menghubungi aggregator SMS',
            );
        }

        if ($response->failed()) {
            $providerCode = (string) ($response->json('code') ?? $response->status());

            throw new ProviderSendException(
                $this->name(),
                $providerCode,
                $this->classifyError($providerCode),
                (string) ($response->json('message') ?? 'Pengiriman SMS gagal'),
                ['code' => $providerCode],
            );
        }

        return [
            'provider_msg_id' => $response->json('message_id') ?? $response->json('id'),
        ];
    }

    public function classifyError(string $providerCode): FailureType
    {
        return match (true) {
            isset(self::PERMANENT[$providerCode]) => FailureType::Permanent,
            isset(self::TEMPORARY[$providerCode]) => FailureType::Temporary,
            default => FailureType::Unknown,
        };
    }
}
