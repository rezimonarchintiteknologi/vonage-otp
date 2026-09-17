<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ingatan tentang nomor mana yang punya WhatsApp.
 *
 * Penghematan biaya terbesar yang bisa didapat tanpa menyentuh harga vendor:
 * nomor yang sudah terbukti tidak punya WhatsApp langsung dikirimi SMS, tanpa
 * membakar satu percobaan WhatsApp dan tanpa membuat user menunggu timer.
 */
final class PhoneCapabilityCache
{
    public function __construct(private readonly int $ttlDays = 30) {}

    /** null berarti belum diketahui — bukan "tidak punya WhatsApp". */
    public function waCapable(string $msisdn): ?bool
    {
        $row = DB::table('phone_capabilities')
            ->where('msisdn_hash', PhoneNormalizer::hash($msisdn))
            ->where('expires_at', '>', now())
            ->first();

        return $row === null ? null : (bool) $row->wa_capable;
    }

    public function remember(string $msisdn, bool $waCapable): void
    {
        DB::table('phone_capabilities')->upsert(
            [[
                'msisdn_hash' => PhoneNormalizer::hash($msisdn),
                'wa_capable' => $waCapable,
                'last_checked' => now(),
                'expires_at' => now()->addDays($this->ttlDays),
            ]],
            ['msisdn_hash'],
            ['wa_capable', 'last_checked', 'expires_at'],
        );
    }

    public function forget(string $msisdn): void
    {
        DB::table('phone_capabilities')
            ->where('msisdn_hash', PhoneNormalizer::hash($msisdn))
            ->delete();
    }
}
