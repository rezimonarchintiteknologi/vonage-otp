<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'brand', 'status', 'config'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Konfigurasi efektif: nilai application menimpa default global.
     */
    public function setting(string $key): mixed
    {
        return data_get($this->config, $key, config("otp.defaults.{$key}"));
    }

    /** @return list<string> */
    public function allowedPrefixes(): array
    {
        $configured = data_get($this->config, 'allowed_prefixes');

        return is_array($configured) && $configured !== []
            ? array_values(array_map('strval', $configured))
            : config('otp.phone.allowed_prefixes');
    }
}
