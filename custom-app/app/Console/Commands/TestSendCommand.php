<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Otp\Exceptions\OtpException;
use App\Domain\Otp\Services\OtpService;
use App\Models\Application;
use Illuminate\Console\Command;

/**
 * Perintah yang paling sering dipakai saat development: kirim satu OTP nyata
 * tanpa perlu menyiapkan klien HTTP.
 */
final class TestSendCommand extends Command
{
    protected $signature = 'otp:test-send
        {--phone= : Nomor tujuan}
        {--app= : ID application, default application pertama}
        {--channel= : Paksa satu channel saja, misal whatsapp atau sms}
        {--mode=live : live atau test}';

    protected $description = 'Kirim satu verifikasi untuk pengujian manual';

    public function handle(OtpService $otp): int
    {
        $application = $this->option('app')
            ? Application::find($this->option('app'))
            : Application::query()->oldest()->first();

        if ($application === null) {
            $this->error('Application tidak ditemukan. Jalankan otp:app:create dulu.');

            return self::FAILURE;
        }

        $phone = (string) $this->option('phone');

        if ($phone === '') {
            $this->error('--phone wajib diisi.');

            return self::FAILURE;
        }

        $channels = $this->option('channel') ? [(string) $this->option('channel')] : null;

        if ($channels !== null && ! data_get($application->config, 'allow_override')) {
            $this->warn('Application ini tidak mengizinkan override channel — rencana bawaannya yang dipakai.');
        }

        try {
            $verification = $otp->request($application, $phone, [
                'purpose' => 'other',
                'channels' => $channels,
                'mode' => (string) $this->option('mode'),
            ]);
        } catch (OtpException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(
            ['id', 'nomor', 'rencana channel', 'kedaluwarsa'],
            [[
                $verification->id,
                $verification->maskedMsisdn(),
                implode(' → ', array_column($verification->plan(), 'channel')),
                $verification->expires_at->toDateTimeString(),
            ]],
        );

        $this->info('Pengiriman berjalan di antrean. Pastikan worker atau Horizon menyala.');

        return self::SUCCESS;
    }
}
