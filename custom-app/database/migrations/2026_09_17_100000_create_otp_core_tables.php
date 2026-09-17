<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');   // active | suspended
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
        });

        Schema::create('applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('brand');
            $table->string('status')->default('active');   // active | disabled
            $table->jsonb('config')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('mode', 8);                     // live | test
            $table->string('key_prefix', 24);
            $table->char('key_hash', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['application_id', 'mode']);
        });

        Schema::create('verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();

            $table->string('msisdn', 20);
            $table->char('msisdn_hash', 64);
            $table->char('code_hash', 64);

            $table->string('status', 16)->default('pending');
            $table->string('purpose', 32)->default('login');
            $table->string('mode', 8)->default('live');

            $table->jsonb('channel_plan');                 // [{channel, provider, timeout}]
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('channel_used', 16)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('pending_job_id')->nullable();

            $table->string('client_ip', 45)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['msisdn_hash', 'created_at']);
        });

        // Satu OTP aktif per nomor per application — ditegakkan database, bukan aplikasi.
        DB::statement("
            CREATE UNIQUE INDEX verifications_active_unique
              ON verifications (application_id, msisdn)
              WHERE status = 'pending'
        ");

        // Pembersihan efisien: hanya baris pending yang pernah perlu dipindai.
        DB::statement("
            CREATE INDEX verifications_expiring
              ON verifications (expires_at)
              WHERE status = 'pending'
        ");

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('verification_id')->constrained('verifications')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->unsignedSmallInteger('step');
            $table->string('channel', 16);
            $table->string('provider', 32);
            $table->string('provider_msg_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 64)->nullable();
            $table->string('failure_type', 16)->nullable();
            $table->string('operator', 32)->nullable();

            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->jsonb('raw')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('provider_msg_id');
            $table->index(['tenant_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('tenants');
    }
};
