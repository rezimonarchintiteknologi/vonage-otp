<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menyimpan hash, bukan nomor: tabel ini lintas tenant, dan tidak ada
        // alasan satu tenant bisa membuktikan nomor tertentu pernah dipakai.
        Schema::create('phone_capabilities', function (Blueprint $table): void {
            $table->char('msisdn_hash', 64)->primary();
            $table->boolean('wa_capable')->nullable();
            $table->timestampTz('last_checked')->useCurrent();
            $table->timestampTz('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_capabilities');
    }
};
