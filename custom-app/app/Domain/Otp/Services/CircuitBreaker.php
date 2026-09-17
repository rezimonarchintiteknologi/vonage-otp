<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Circuit breaker per provider dengan jendela geser.
 *
 * Breaker berlaku lintas tenant: kalau satu provider sedang buruk, ia buruk
 * untuk semua orang, dan memaksa tenant lain menemukannya sendiri hanya
 * menambah kegagalan yang bisa dihindari.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $window = 300,
        private readonly int $minSamples = 10,
        private readonly float $errorRate = 0.5,
        private readonly int $cooldown = 120,
    ) {}

    public function isOpen(string $provider): bool
    {
        return $this->state($provider) === self::OPEN;
    }

    public function state(string $provider): string
    {
        $openedAt = $this->cache->get($this->key($provider, 'opened_at'));

        if ($openedAt === null) {
            return self::CLOSED;
        }

        // Setelah cooldown lewat, satu percobaan diizinkan lewat untuk
        // mengetahui apakah provider sudah pulih.
        return (time() - (int) $openedAt) >= $this->cooldown
            ? self::HALF_OPEN
            : self::OPEN;
    }

    /** Boleh mengirim lewat provider ini sekarang? */
    public function allows(string $provider): bool
    {
        return $this->state($provider) !== self::OPEN;
    }

    public function recordSuccess(string $provider): void
    {
        $this->increment($provider, 'success');

        // Percobaan half-open yang berhasil menutup breaker seketika.
        if ($this->state($provider) === self::HALF_OPEN) {
            $this->close($provider);
        }
    }

    public function recordFailure(string $provider): void
    {
        $failures = $this->increment($provider, 'failure');
        $successes = (int) $this->cache->get($this->bucketKey($provider, 'success'), 0);
        $total = $failures + $successes;

        if ($total < $this->minSamples) {
            return;
        }

        if (($failures / $total) >= $this->errorRate) {
            $this->open($provider);
        }
    }

    public function open(string $provider): void
    {
        $this->cache->put($this->key($provider, 'opened_at'), time(), $this->cooldown * 10);
    }

    public function close(string $provider): void
    {
        $this->cache->forget($this->key($provider, 'opened_at'));
        $this->cache->forget($this->bucketKey($provider, 'failure'));
        $this->cache->forget($this->bucketKey($provider, 'success'));
    }

    /** @return array{state: string, failures: int, successes: int} */
    public function stats(string $provider): array
    {
        return [
            'state' => $this->state($provider),
            'failures' => (int) $this->cache->get($this->bucketKey($provider, 'failure'), 0),
            'successes' => (int) $this->cache->get($this->bucketKey($provider, 'success'), 0),
        ];
    }

    private function increment(string $provider, string $kind): int
    {
        $key = $this->bucketKey($provider, $kind);

        // `add` memasang TTL hanya saat bucket pertama kali dibuat, sehingga
        // jendela benar-benar bergeser dan tidak diperpanjang tiap increment.
        $this->cache->add($key, 0, $this->window * 2);

        return (int) $this->cache->increment($key);
    }

    private function bucketKey(string $provider, string $kind): string
    {
        $bucket = intdiv(time(), $this->window);

        return $this->key($provider, "{$kind}:{$bucket}");
    }

    private function key(string $provider, string $suffix): string
    {
        return "otp:breaker:{$provider}:{$suffix}";
    }
}
