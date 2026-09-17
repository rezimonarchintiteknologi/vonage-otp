<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Penampung metrik sederhana di atas cache, dengan keluaran format teks
 * Prometheus lewat `GET /metrics`.
 *
 * Bentuknya sengaja minimal supaya tidak ada dependensi baru. Kalau nanti
 * dipasang exporter sungguhan, yang berubah hanya kelas ini — pemanggilnya
 * tidak tahu bedanya.
 */
final class Metrics
{
    private const INDEX = 'otp:metrics:index';

    /** Bucket histogram latency dalam detik. */
    private const BUCKETS = [0.5, 1, 2, 3, 5, 10, 30];

    public function __construct(private readonly Cache $cache) {}

    /** @param array<string, string|int|null> $labels */
    public function increment(string $name, array $labels = [], int $by = 1): void
    {
        $key = $this->key($name, $labels);
        $this->register($key);
        $this->cache->add($key, 0, now()->addDays(2));
        $this->cache->increment($key, $by);
    }

    /** @param array<string, string|int|null> $labels */
    public function observe(string $name, float $seconds, array $labels = []): void
    {
        foreach (self::BUCKETS as $bucket) {
            if ($seconds <= $bucket) {
                $this->increment($name.'_bucket', $labels + ['le' => (string) $bucket]);
            }
        }

        $this->increment($name.'_bucket', $labels + ['le' => '+Inf']);
        $this->increment($name.'_count', $labels);
        $this->increment($name.'_sum_ms', $labels, (int) round($seconds * 1000));
    }

    /** @param array<string, string|int|null> $labels */
    public function gauge(string $name, float $value, array $labels = []): void
    {
        $key = $this->key($name, $labels);
        $this->register($key);
        $this->cache->put($key, $value, now()->addDays(2));
    }

    /** Keluaran format teks Prometheus. */
    public function render(): string
    {
        $lines = [];

        foreach ($this->index() as $key) {
            $value = $this->cache->get($key);

            if ($value === null) {
                continue;
            }

            $lines[] = substr($key, strlen('otp:metric:')).' '.$value;
        }

        sort($lines);

        return implode("\n", $lines)."\n";
    }

    public function flush(): void
    {
        foreach ($this->index() as $key) {
            $this->cache->forget($key);
        }

        $this->cache->forget(self::INDEX);
    }

    /** @param array<string, string|int|null> $labels */
    private function key(string $name, array $labels): string
    {
        $labels = array_filter($labels, static fn ($v) => $v !== null && $v !== '');
        ksort($labels);

        $rendered = implode(',', array_map(
            static fn ($k, $v) => sprintf('%s="%s"', $k, str_replace('"', '', (string) $v)),
            array_keys($labels),
            $labels,
        ));

        return 'otp:metric:'.$name.($rendered === '' ? '' : '{'.$rendered.'}');
    }

    private function register(string $key): void
    {
        $index = $this->index();

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            $this->cache->put(self::INDEX, $index, now()->addDays(2));
        }
    }

    /** @return list<string> */
    private function index(): array
    {
        return (array) $this->cache->get(self::INDEX, []);
    }
}
