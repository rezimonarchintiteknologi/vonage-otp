# Paket & Dependensi

Semua yang ditambahkan di luar skeleton Laravel bawaan, beserta alasannya.
Rencana menyebut Laravel 12; yang terpasang Laravel 13 (versi stabil saat
pengerjaan). Tidak ada API yang dipakai rencana ini berubah di antara keduanya.

**Terakhir diperbarui:** 2026-09-17

---

## Composer — produksi

| Paket | Versi | Alasan |
|---|---|---|
| `laravel/framework` | ^13.17 | Skeleton bawaan |
| `laravel/tinker` | ^3.0 | Skeleton bawaan |
| `laravel/horizon` | ^5.49 | Dashboard dan supervisor untuk antrean Redis. Delayed job fallback harus terlihat dan bisa diawasi — ini syarat Fase 2 |

Tidak ada SDK provider yang dipasang. Panggilan ke Meta Cloud API dan
aggregator SMS memakai `Http` facade langsung: kontrak REST-nya stabil, tidak
menambah dependensi, dan jauh lebih mudah di-`Http::fake()` saat testing.

## Composer — pengembangan

| Paket | Versi | Alasan |
|---|---|---|
| `pestphp/pest` | ^4.7 | Runner test yang diminta rencana |
| `pestphp/pest-plugin-laravel` | ^4.1 | Helper Laravel untuk Pest |
| `phpunit/phpunit` | ^13.3 | Naik dari ^12.5 karena Pest 4 mensyaratkannya |
| `laravel/pint` | ^1.27 | Skeleton bawaan |
| `laravel/pail` | ^1.2.5 | Skeleton bawaan |
| `mockery/mockery` | ^1.6 | Skeleton bawaan |
| `nunomaduro/collision` | ^8.6 | Skeleton bawaan |
| `fakerphp/faker` | ^1.23 | Skeleton bawaan |

## Sistem

| Komponen | Versi | Catatan |
|---|---|---|
| PHP | 8.4.16 | Ekstensi wajib: `pdo_pgsql`, `pgsql`, `redis` — ketiganya sudah aktif |
| PostgreSQL | 16.15 | Dipasang lewat `brew install postgresql@16`. Rencana mensyaratkan PostgreSQL, bukan MySQL: partial unique index, Row Level Security, dan JSONB semuanya dipakai |
| Redis | 8.2.1 | Sudah berjalan lewat `brew services`. Dipakai untuk cache, antrean, rate limit, dan state circuit breaker |
| Composer | 2.10.1 | — |

Database yang dibuat: `otp_service` (pengembangan) dan `otp_service_test` (test).

## Perubahan konfigurasi yang perlu diketahui

- `config/database.php` — koneksi `pgsql` mendapat `'timezone' => 'UTC'`.
  Tanpa ini, sesi PostgreSQL memakai zona waktu OS (+07 di mesin ini),
  `timestamptz` ditulis dengan offset yang salah, dan setiap perbandingan
  `expires_at` meleset tujuh jam. Bug ini muncul nyata saat test Fase 1 dan
  bukan hal yang akan terlihat tanpa test.
- `phpunit.xml` — test berjalan di PostgreSQL (`otp_service_test`), bukan
  SQLite in-memory. Partial unique index dan RLS tidak ada di SQLite, jadi
  menguji di sana berarti tidak menguji hal yang justru paling penting.
- `phpunit.xml` — `QUEUE_CONNECTION=database`, bukan `sync`. Driver `sync`
  mengabaikan `delay()`, padahal delay itulah mekanisme fallback yang diuji.

## Yang sengaja tidak dipasang

| Paket | Alasan |
|---|---|
| SDK Vonage / Twilio | Arsitektur ini memegang kode OTP sendiri; provider hanya pipa kirim |
| Laravel Sanctum | Kunci terikat ke application dengan mode live/test, bukan ke user — guard kustom lebih sederhana daripada menekuk Sanctum |
| Laravel Octane | Ditambahkan kalau latency terbukti jadi masalah, bukan sebelumnya |
| Library Prometheus | `app/Support/Metrics.php` cukup untuk keluaran format teks. Kalau nanti dipasang exporter sungguhan, hanya kelas itu yang berubah |
| `predis/predis` | Ekstensi `phpredis` sudah ada dan lebih cepat |
