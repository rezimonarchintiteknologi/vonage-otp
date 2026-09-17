<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Otp\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Verification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'application_id', 'msisdn', 'msisdn_hash', 'code_hash',
        'status', 'purpose', 'mode', 'channel_plan', 'current_step', 'channel_used',
        'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'pending_job_id',
        'client_ip', 'metadata',
    ];

    /** Kode dan hash tidak pernah ikut serialisasi. */
    protected $hidden = ['code_hash', 'msisdn_hash'];

    protected function casts(): array
    {
        return [
            'channel_plan' => 'array',
            'metadata' => 'array',
            'status' => VerificationStatus::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function maskedMsisdn(): string
    {
        return \App\Domain\Otp\Services\PhoneNormalizer::mask($this->msisdn);
    }

    public function attemptsRemaining(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }

    /** @return array<int, array{channel: string, provider: string, timeout: int}> */
    public function plan(): array
    {
        return $this->channel_plan ?? [];
    }

    /** @return array{channel: string, provider: string, timeout: int}|null */
    public function stepAt(int $index): ?array
    {
        return $this->plan()[$index] ?? null;
    }
}
