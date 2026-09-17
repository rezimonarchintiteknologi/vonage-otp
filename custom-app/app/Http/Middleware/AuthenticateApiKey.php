<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Otp\Exceptions\OtpException;
use App\Jobs\TouchApiKey;
use App\Models\ApiKey;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard kustom, bukan Sanctum: kunci di sini terikat ke application dengan
 * mode live/test, bukan ke user.
 */
final class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || ! str_starts_with($token, 'otp_')) {
            throw new OtpException(OtpException::UNAUTHORIZED, 401, 'API key tidak disertakan atau formatnya salah');
        }

        $key = ApiKey::query()
            ->with(['tenant', 'application'])
            ->whereNull('revoked_at')
            ->where('key_hash', ApiKey::hashToken($token))
            ->first();

        if ($key === null) {
            throw new OtpException(OtpException::UNAUTHORIZED, 401, 'API key tidak dikenal atau sudah dicabut');
        }

        if (! $key->tenant->isActive()) {
            throw new OtpException(OtpException::UNAUTHORIZED, 403, 'Tenant sedang ditangguhkan');
        }

        if (! $key->application->isActive()) {
            throw new OtpException(OtpException::UNAUTHORIZED, 403, 'Application sedang dinonaktifkan');
        }

        $this->context->set($key);

        // Jangan blokir response hanya untuk mencatat pemakaian terakhir.
        TouchApiKey::dispatch($key->id);

        return $next($request);
    }
}
