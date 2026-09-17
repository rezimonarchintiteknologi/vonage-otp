<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class CreateApplicationCommand extends Command
{
    protected $signature = 'otp:app:create
        {name : Nama application}
        {--tenant= : ID atau slug tenant}
        {--brand= : Nama merek yang muncul di pesan}
        {--channels=whatsapp,sms : Urutan fallback}
        {--timeout=30 : Detik sebelum pindah channel}
        {--prefixes=62 : Prefix negara yang diizinkan}';

    protected $description = 'Buat application di bawah satu tenant';

    public function handle(): int
    {
        $tenant = Tenant::where('id', $this->option('tenant'))
            ->orWhere('slug', $this->option('tenant'))
            ->first();

        if ($tenant === null) {
            $this->error('Tenant tidak ditemukan.');

            return self::FAILURE;
        }

        $application = Application::create([
            'tenant_id' => $tenant->id,
            'name' => (string) $this->argument('name'),
            'brand' => (string) ($this->option('brand') ?: $this->argument('name')),
            'status' => 'active',
            'config' => [
                'channels' => explode(',', (string) $this->option('channels')),
                'channel_timeout' => (int) $this->option('timeout'),
                'ttl' => config('otp.defaults.ttl'),
                'code_length' => config('otp.defaults.code_length'),
                'max_attempts' => config('otp.defaults.max_attempts'),
                'allowed_prefixes' => explode(',', (string) $this->option('prefixes')),
                'allow_override' => false,
            ],
        ]);

        $this->table(['id', 'name', 'brand'], [[$application->id, $application->name, $application->brand]]);

        return self::SUCCESS;
    }
}
