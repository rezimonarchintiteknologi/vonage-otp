<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Otp\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'verification_id', 'tenant_id', 'step', 'channel', 'provider',
        'provider_msg_id', 'status', 'error_code', 'failure_type', 'operator',
        'attempted_at', 'delivered_at', 'latency_ms', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'status' => DeliveryStatus::class,
            'attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }
}
