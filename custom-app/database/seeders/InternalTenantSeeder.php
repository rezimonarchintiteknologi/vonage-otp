<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Tenant internal pertama plus sepasang kunci live/test, supaya lingkungan
 * baru langsung bisa dipakai tanpa merangkai perintah satu per satu.
 */
final class InternalTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'internal'],
            ['name' => 'PT Monarch Inti Teknologi', 'status' => 'active', 'settings' => []],
        );

        $application = Application::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'internal-app'],
            [
                'brand' => 'Monarch',
                'status' => 'active',
                'config' => [
                    'channels' => ['whatsapp', 'sms'],
                    'channel_timeout' => 30,
                    'ttl' => 300,
                    'code_length' => 6,
                    'max_attempts' => 5,
                    'allowed_prefixes' => ['62'],
                    'allow_override' => true,
                ],
            ],
        );

        if ($application->apiKeys()->count() > 0) {
            $this->command?->warn('Application internal sudah punya kunci. Tidak ada kunci baru yang dibuat.');

            return;
        }

        foreach (['test', 'live'] as $mode) {
            [$key, $token] = ApiKey::issue($application, $mode, "seeder {$mode}");

            $this->command?->line("Kunci {$mode} ({$key->key_prefix}…): {$token}");
        }

        $this->command?->warn('Simpan token di atas sekarang — tidak bisa ditampilkan ulang.');
    }
}
