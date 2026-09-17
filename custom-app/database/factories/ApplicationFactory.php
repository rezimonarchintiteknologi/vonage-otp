<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
final class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'brand' => $this->faker->company(),
            'status' => 'active',
            'config' => [
                'channels' => ['whatsapp', 'sms'],
                'channel_timeout' => 30,
                'ttl' => 300,
                'code_length' => 6,
                'max_attempts' => 5,
                'allow_override' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): self
    {
        return $this->state(fn (array $attributes) => [
            'config' => array_merge($attributes['config'] ?? [], $config),
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn () => ['status' => 'disabled']);
    }
}
