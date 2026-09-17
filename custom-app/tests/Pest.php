<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Tenancy');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Tenant + application + kunci API siap pakai.
 *
 * @param  array<string, mixed>  $config
 * @return array{tenant: Tenant, application: Application, key: ApiKey, token: string}
 */
function createTenantWithKey(string $mode = 'live', array $config = []): array
{
    $tenant = Tenant::factory()->create();
    $application = Application::factory()->for($tenant)->create(
        $config === [] ? [] : ['config' => array_merge([
            'channels' => ['whatsapp', 'sms'],
            'channel_timeout' => 30,
            'ttl' => 300,
            'code_length' => 6,
            'max_attempts' => 5,
            'allow_override' => false,
        ], $config)]
    );

    [$key, $token] = ApiKey::issue($application, $mode, 'test key');

    return ['tenant' => $tenant, 'application' => $application, 'key' => $key, 'token' => $token];
}

/**
 * Ambil kode OTP dari payload permintaan yang ditangkap Http::fake().
 * Dipakai supaya test tidak perlu membaca kode dari database — memang tidak
 * tersimpan di sana.
 */
function codeFromMetaRequest(Illuminate\Http\Client\Request $request): string
{
    return data_get($request->data(), 'template.components.0.parameters.0.text');
}

/**
 * Jalankan job yang sudah waktunya dieksekusi pada koneksi antrean database.
 *
 * Dipakai alih-alih driver `sync` karena `sync` mengabaikan delay — dan justru
 * delay itulah mekanisme fallback yang perlu diuji.
 */
function workQueue(int $max = 25): int
{
    $queue = app('queue')->connection('database');
    $processed = 0;

    while ($processed < $max && ($job = $queue->pop()) !== null) {
        $job->fire();
        $processed++;
    }

    return $processed;
}
