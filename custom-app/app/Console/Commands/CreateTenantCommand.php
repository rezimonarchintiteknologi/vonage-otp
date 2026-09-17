<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateTenantCommand extends Command
{
    protected $signature = 'otp:tenant:create {name : Nama tenant} {--slug= : Slug unik, dibuat otomatis kalau kosong}';

    protected $description = 'Buat tenant baru';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $slug = (string) ($this->option('slug') ?: Str::slug($name));

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Slug '{$slug}' sudah dipakai.");

            return self::FAILURE;
        }

        $tenant = Tenant::create(['name' => $name, 'slug' => $slug, 'status' => 'active']);

        $this->table(['id', 'name', 'slug'], [[$tenant->id, $tenant->name, $tenant->slug]]);

        return self::SUCCESS;
    }
}
