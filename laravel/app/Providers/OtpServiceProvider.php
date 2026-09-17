<?php

namespace App\Providers;

use App\Services\Otp\Contracts\OtpProvider;
use App\Services\Otp\OtpService;
use App\Services\Otp\PhoneNormalizer;
use App\Services\Otp\Providers\VonageVerifyProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Daftarkan di bootstrap/providers.php (Laravel 11+)
 * atau config/app.php -> providers (Laravel 10 ke bawah).
 */
class OtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpProvider::class, function ($app) {
            $config = config('otp');

            return match ($config['provider']) {
                'vonage' => new VonageVerifyProvider($config),
                // 'twilio' => new TwilioVerifyProvider($config),
                // 'meta'   => new MetaCloudApiProvider($config),
                default  => throw new \InvalidArgumentException(
                    "Provider OTP tidak dikenal: {$config['provider']}"
                ),
            };
        });

        $this->app->singleton(OtpService::class, function ($app) {
            $config = config('otp');

            return new OtpService(
                $app->make(OtpProvider::class),
                new PhoneNormalizer(
                    $config['allowed_prefixes'],
                    $config['default_country_code'],
                ),
                $config,
            );
        });
    }
}
