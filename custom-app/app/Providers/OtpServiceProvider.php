<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Otp\Contracts\VerificationRepository;
use App\Domain\Otp\Services\ChannelDispatcher;
use App\Domain\Otp\Services\ChannelRouter;
use App\Domain\Otp\Services\CircuitBreaker;
use App\Domain\Otp\Services\CodeGenerator;
use App\Domain\Otp\Services\EloquentVerificationRepository;
use App\Domain\Otp\Services\OtpService;
use App\Domain\Otp\Services\PhoneCapabilityCache;
use App\Domain\Otp\Services\PhoneNormalizer;
use App\Domain\Otp\Services\SenderRegistry;
use App\Support\Metrics;
use App\Support\TenantContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class OtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(SenderRegistry::class);

        $this->app->singleton(Metrics::class, fn ($app) => new Metrics($app->make(CacheRepository::class)));

        $this->app->singleton(CodeGenerator::class, function (): CodeGenerator {
            return new CodeGenerator((string) config('otp.pepper'));
        });

        $this->app->singleton(PhoneNormalizer::class, function (): PhoneNormalizer {
            return new PhoneNormalizer((string) config('otp.phone.default_country_code'));
        });

        $this->app->singleton(CircuitBreaker::class, function (): CircuitBreaker {
            return new CircuitBreaker(
                Cache::store(),
                (int) config('otp.breaker.window'),
                (int) config('otp.breaker.min_samples'),
                (float) config('otp.breaker.error_rate'),
                (int) config('otp.breaker.cooldown'),
            );
        });

        $this->app->singleton(PhoneCapabilityCache::class, function (): PhoneCapabilityCache {
            return new PhoneCapabilityCache((int) config('otp.phone_capability_ttl_days'));
        });

        $this->app->singleton(EloquentVerificationRepository::class);
        $this->app->bind(VerificationRepository::class, EloquentVerificationRepository::class);

        $this->app->singleton(ChannelRouter::class);
        $this->app->singleton(ChannelDispatcher::class);
        $this->app->singleton(OtpService::class);
    }
}
