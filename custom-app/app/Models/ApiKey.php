<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'application_id', 'name', 'mode',
        'key_prefix', 'key_hash', 'last_used_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isTest(): bool
    {
        return $this->mode === 'test';
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Buat kunci baru. Token mentah hanya dikembalikan di sini — setelah ini
     * hanya hash-nya yang tersimpan, dan tidak ada cara memulihkannya.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Application $application, string $mode, string $name): array
    {
        $token = sprintf('otp_%s_%s', $mode, Str::random(40));

        $key = self::create([
            'tenant_id' => $application->tenant_id,
            'application_id' => $application->id,
            'name' => $name,
            'mode' => $mode,
            'key_prefix' => substr($token, 0, 16),
            'key_hash' => self::hashToken($token),
        ]);

        return [$key, $token];
    }
}
