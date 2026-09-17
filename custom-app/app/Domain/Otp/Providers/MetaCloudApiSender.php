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
 * WhatsApp lewat Meta Cloud API, tanpa perantara.
 *
 * Template yang dipakai berkategori `authentication`: kodenya masuk sebagai
 * parameter body dan sekaligus parameter tombol salin-kode, sehingga WhatsApp
 * bisa menawarkan one-tap autofill.
 */
final class MetaCloudApiSender implements MessageSender
{
    /**
     * Kode error Meta yang sudah pasti tidak akan berhasil di channel ini.
     * Menunggu 30 detik untuk kasus ini membuang waktu user.
     *
     * @var array<string, string>
     */
    private const PERMANENT = [
        '131026' => 'Nomor tidak bisa menerima pesan WhatsApp',
        '131047' => 'Di luar jendela 24 jam dan template tidak berlaku',
        '131051' => 'Tipe pesan tidak didukung',
        '132000' => 'Jumlah parameter template tidak cocok',
        '132001' => 'Template tidak ditemukan',
        '132005' => 'Teks template melebihi batas',
        '132007' => 'Format template ditolak',
        '133010' => 'Nomor pengirim belum terdaftar',
    ];

    /**
     * Kode yang layak diulang: masalah sesaat di sisi Meta atau rate limit.
     *
     * @var array<string, string>
     */
    private const TEMPORARY = [
        '130429' => 'Rate limit provider tercapai',
        '131000' => 'Kesalahan internal Meta',
        '131048' => 'Batas kualitas pengirim tercapai',
        '131056' => 'Terlalu banyak pesan ke nomor yang sama',
        '133016' => 'Nomor pengirim sedang dipulihkan',
        '80007' => 'Rate limit aplikasi tercapai',
        '4' => 'Batas panggilan API tercapai',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'meta';
    }

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function send(string $msisdn, string $code, array $options = []): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->config['graph_version'],
            $this->config['phone_number_id'],
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $msisdn,
            'type' => 'template',
            'template' => [
                'name' => $options['template'] ?? $this->config['template_name'],
                'language' => ['code' => $options['locale'] ?? $this->config['template_lang']],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->config['access_token'])
                ->timeout($this->config['timeout'])
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new ProviderSendException(
                $this->name(),
                'connection_error',
                FailureType::Unknown,
                'Tidak bisa menghubungi Meta Cloud API',
            );
        }

        if ($response->failed()) {
            $error = (array) $response->json('error', []);
            $providerCode = (string) ($error['code'] ?? $response->status());

            throw new ProviderSendException(
                $this->name(),
                $providerCode,
                $this->classifyError($providerCode),
                (string) ($error['message'] ?? 'Pengiriman WhatsApp gagal'),
                $this->scrub($error),
            );
        }

        return [
            'provider_msg_id' => $response->json('messages.0.id'),
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

    /**
     * Payload error Meta memuat gema permintaan. Buang apa pun yang bisa
     * memuat kode sebelum disimpan ke kolom `raw`.
     *
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>
     */
    private function scrub(array $error): array
    {
        return [
            'code' => $error['code'] ?? null,
            'type' => $error['type'] ?? null,
            'message' => $error['message'] ?? null,
            'details' => data_get($error, 'error_data.details'),
        ];
    }
}
