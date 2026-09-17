<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneVerificationsCommand extends Command
{
    protected $signature = 'otp:prune {--days= : Umur data yang masih disimpan}';

    protected $description = 'Hapus verifikasi lama beserta riwayat pengirimannya';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('otp.prune_after_days'));
        $cutoff = now()->subDays($days);

        // deliveries ikut terhapus lewat foreign key cascade.
        $deleted = DB::table('verifications')->where('created_at', '<', $cutoff)->delete();

        DB::table('phone_capabilities')->where('expires_at', '<', now())->delete();

        $this->info("{$deleted} verifikasi lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
