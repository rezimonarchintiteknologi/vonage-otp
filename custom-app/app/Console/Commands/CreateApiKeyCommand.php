<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Application;
use Illuminate\Console\Command;

final class CreateApiKeyCommand extends Command
{
    protected $signature = 'otp:key:create {--app= : ID application} {--mode=test : live atau test} {--name=default : Label kunci}';

    protected $description = 'Terbitkan API key baru — token hanya ditampilkan sekali';

    public function handle(): int
    {
        $application = Application::find($this->option('app'));

        if ($application === null) {
            $this->error('Application tidak ditemukan.');

            return self::FAILURE;
        }

        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['live', 'test'], true)) {
            $this->error("Mode harus 'live' atau 'test'.");

            return self::FAILURE;
        }

        [$key, $token] = ApiKey::issue($application, $mode, (string) $this->option('name'));

        $this->info('Simpan token ini sekarang. Setelah layar ini tertutup, hanya prefix-nya yang bisa dilihat lagi.');
        $this->newLine();
        $this->line($token);
        $this->newLine();
        $this->table(['id', 'prefix', 'mode'], [[$key->id, $key->key_prefix, $key->mode]]);

        return self::SUCCESS;
    }
}
