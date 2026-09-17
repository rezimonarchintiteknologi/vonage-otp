<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ApiKey;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TouchApiKey implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $apiKeyId) {}

    public function handle(): void
    {
        ApiKey::withoutTimestamps(
            fn () => ApiKey::where('id', $this->apiKeyId)->update(['last_used_at' => now()])
        );
    }
}
