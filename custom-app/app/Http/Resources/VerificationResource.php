<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Verification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Verification
 */
final class VerificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'masked_phone' => $this->maskedMsisdn(),
            'purpose' => $this->purpose,
            'mode' => $this->mode,
            'channels' => array_column($this->plan(), 'channel'),
            'channel_used' => $this->channel_used,
            'attempts' => $this->attempts,
            'attempts_remaining' => $this->attemptsRemaining(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
