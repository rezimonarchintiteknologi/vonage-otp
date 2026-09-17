<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

final class RevokeApiKeyCommand extends Command
{
    protected $signature = 'otp:key:revoke {--id= : ID kunci yang dicabut}';

    protected $description = 'Cabut API key';

    public function handle(): int
    {
        $key = ApiKey::find($this->option('id'));

        if ($key === null) {
            $this->error('Kunci tidak ditemukan.');

            return self::FAILURE;
        }

        if ($key->revoked_at !== null) {
            $this->warn('Kunci ini sudah dicabut sebelumnya.');

            return self::SUCCESS;
        }

        $key->forceFill(['revoked_at' => now()])->save();
        $this->info("Kunci {$key->key_prefix}… dicabut.");

        return self::SUCCESS;
    }
}
