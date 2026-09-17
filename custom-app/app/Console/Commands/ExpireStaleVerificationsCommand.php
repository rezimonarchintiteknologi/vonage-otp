<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireStaleVerificationsCommand extends Command
{
    protected $signature = 'otp:expire-stale';

    protected $description = 'Tandai verifikasi pending yang sudah lewat masa berlakunya';

    public function handle(): int
    {
        // Memakai index parsial verifications_expiring: hanya baris pending
        // yang dipindai, berapa pun besarnya tabel.
        $affected = DB::update("
            UPDATE verifications
               SET status = 'expired', updated_at = now()
             WHERE status = 'pending' AND expires_at <= now()
        ");

        $this->info("{$affected} verifikasi ditandai kedaluwarsa.");

        return self::SUCCESS;
    }
}
