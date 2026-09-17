<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Otp\Enums\VerificationStatus;
use App\Domain\Otp\Services\PhoneNormalizer;
use App\Models\Application;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Verification> */
final class VerificationFactory extends Factory
{
    protected $model = Verification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $application = Application::factory()->create();
        $msisdn = '62812'.$this->faker->unique()->numerify('########');

        return [
            'tenant_id' => $application->tenant_id,
            'application_id' => $application->id,
            'msisdn' => $msisdn,
            'msisdn_hash' => PhoneNormalizer::hash($msisdn),
            'code_hash' => hash('sha256', 'placeholder'),
            'status' => VerificationStatus::Pending->value,
            'purpose' => 'login',
            'mode' => 'live',
            'channel_plan' => [
                ['channel' => 'whatsapp', 'provider' => 'meta', 'timeout' => 30],
                ['channel' => 'sms', 'provider' => 'jatis', 'timeout' => 30],
            ],
            'current_step' => 0,
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'metadata' => [],
        ];
    }

    public function forApplication(Application $application): self
    {
        return $this->state(fn () => [
            'tenant_id' => $application->tenant_id,
            'application_id' => $application->id,
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
