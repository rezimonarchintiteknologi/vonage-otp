<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiKey;
use App\Models\Application;
use App\Models\Tenant;
use RuntimeException;

/**
 * Identitas pemanggil untuk satu request. Diisi oleh AuthenticateApiKey dan
 * dibaca oleh controller, rate limiter, dan middleware RLS — supaya tidak ada
 * kode yang perlu menebak tenant dari parameter route.
 */
final class TenantContext
{
    private ?ApiKey $apiKey = null;

    public function set(ApiKey $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function isSet(): bool
    {
        return $this->apiKey !== null;
    }

    public function apiKey(): ApiKey
    {
        return $this->apiKey ?? throw new RuntimeException('Tidak ada API key pada konteks request ini.');
    }

    public function tenantId(): string
    {
        return $this->apiKey()->tenant_id;
    }

    public function tenant(): Tenant
    {
        return $this->apiKey()->tenant;
    }

    public function application(): Application
    {
        return $this->apiKey()->application;
    }

    public function mode(): string
    {
        return $this->apiKey()->mode;
    }
}
