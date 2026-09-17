# Rencana Implementasi — OTP Service Multi-Tenant

**Stack:** Laravel 12 · PostgreSQL 16 · Redis 7 · Horizon
**Cakupan:** Internal dulu, arsitektur multi-tenant sejak awal
**Channel:** WhatsApp (utama) → SMS (fallback), kode dikelola sendiri

Dokumen ini adalah rencana kerja. Spesifikasi teknis lengkapnya ada di `otp-multitenant-design.md`.

---

## Daftar Isi

1. [Keputusan yang Sudah Ditetapkan](#1-keputusan-yang-sudah-ditetapkan)
2. [Ringkasan Fase & Estimasi](#2-ringkasan-fase--estimasi)
3. [Struktur Project](#3-struktur-project)
4. [Fase 0 — Persiapan](#fase-0--persiapan)
5. [Fase 1 — Inti yang Berfungsi](#fase-1--inti-yang-berfungsi)
6. [Fase 2 — Fallback & Ketahanan](#fase-2--fallback--ketahanan)
7. [Fase 3 — Multi-Tenant & Open API](#fase-3--multi-tenant--open-api)
8. [Fase 4 — Siap Produksi](#fase-4--siap-produksi)
9. [Strategi Testing](#9-strategi-testing)
10. [Infrastruktur & Deployment](#10-infrastruktur--deployment)
11. [Runbook Operasional](#11-runbook-operasional)
12. [Risiko & Mitigasi](#12-risiko--mitigasi)
13. [Checklist Go-Live](#13-checklist-go-live)
14. [Lampiran](#14-lampiran)

---

## 1. Keputusan yang Sudah Ditetapkan

Keputusan berikut sudah final. Mengubahnya di tengah jalan mahal, jadi dicatat eksplisit di sini.

| Keputusan | Pilihan | Alasan singkat |
|---|---|---|
| Framework | Laravel 12 | Horizon untuk delayed job, talent pool Indonesia, batteries included |
| Database | **PostgreSQL**, bukan MySQL | Partial unique index, Row Level Security, JSONB |
| Cache & queue | Redis | TTL dan operasi atomik bawaan |
| Kepemilikan kode OTP | **Sendiri**, bukan provider | Fleksibilitas channel, biaya jauh lebih rendah, data milik sendiri |
| Auth API | Guard kustom | Sanctum terikat ke user; kunci kita terikat ke application dengan mode live/test |
| Channel utama | WhatsApp | Branding gratis, biaya per pesan lebih murah, one-tap autofill |
| Channel fallback | SMS | Universal, jaring pengaman untuk nomor tanpa WhatsApp |
| Provider WhatsApp fase 1 | Meta Cloud API langsung | Nomor test gratis, tanpa markup, tanpa kontrak |

### Yang sengaja ditunda

- **Go untuk jalur panas** — tinjau ulang hanya kalau volume melewati 100.000/hari
- **Laravel Octane** — tambahkan kalau latency jadi masalah, bukan sebelumnya
- **Miscall/Flash-call OTP** — evaluasi setelah punya data biaya SMS riil
- **Silent Network Auth** — cakupan operator Indonesia belum terkonfirmasi

---

## 2. Ringkasan Fase & Estimasi

Asumsi: **1–2 backend engineer**, bekerja penuh waktu.

| Fase | Hasil | Estimasi | Bisa paralel? |
|---|---|---|---|
| 0 — Persiapan | Akun, nomor, infrastruktur siap | 1–2 hari kerja + **lead time eksternal** | Ya, jalan sendiri |
| 1 — Inti | OTP WhatsApp berfungsi end-to-end | 5–8 hari | Butuh Fase 0 sebagian |
| 2 — Fallback | WA gagal jatuh ke SMS otomatis | 5–8 hari | Butuh Fase 1 |
| 3 — Multi-tenant & API | Tenant kedua bisa integrasi mandiri | 8–12 hari | Butuh Fase 2 |
| 4 — Produksi | Monitoring, runbook, go-live | 5–8 hari | Butuh Fase 3 |

**Total: 24–38 hari kerja engineer.** Dengan 1 orang sekitar 6–8 minggu; dengan 2 orang sekitar 4–5 minggu.

**Catatan penting soal jadwal:** verifikasi bisnis Meta punya lead time 3 hari sampai 3 minggu di luar kendalimu. Mulai Fase 0 hari ini juga, karena seluruh jalur WhatsApp produksi menunggunya. Pengembangan tetap bisa jalan penuh dengan nomor test Meta sementara menunggu.

---

## 3. Struktur Project

```
app/
├── Domain/
│   └── Otp/
│       ├── Contracts/
│       │   ├── MessageSender.php          # interface provider (pipa kirim)
│       │   └── VerificationRepository.php
│       ├── Services/
│       │   ├── OtpService.php             # generate, verifikasi, orkestrasi
│       │   ├── ChannelRouter.php           # susun & jalankan channel_plan
│       │   ├── CodeGenerator.php           # CSPRNG + HMAC dengan pepper
│       │   ├── PhoneNormalizer.php         # E.164 tanpa plus, guard prefix
│       │   └── CircuitBreaker.php
│       ├── Providers/
│       │   ├── MetaCloudApiSender.php      # WhatsApp
│       │   ├── KirimdevSender.php          # WhatsApp alternatif
│       │   ├── JatisSmsSender.php          # SMS
│       │   └── SandboxSender.php           # mode test, tidak mengirim apa pun
│       ├── Jobs/
│       │   ├── DispatchChannelStep.php     # kirim ke satu channel
│       │   └── AdvanceChannelPlan.php      # delayed job pemicu fallback
│       ├── Webhooks/
│       │   ├── MetaWebhookHandler.php
│       │   └── SmsWebhookHandler.php
│       ├── Enums/
│       │   ├── VerificationStatus.php
│       │   ├── DeliveryStatus.php
│       │   ├── Channel.php
│       │   └── FailureType.php             # PERMANENT | TEMPORARY
│       └── Exceptions/
│           └── OtpException.php
├── Models/
│   ├── Tenant.php
│   ├── Application.php
│   ├── ApiKey.php
│   ├── Verification.php
│   ├── Delivery.php
│   └── WebhookEndpoint.php
├── Http/
│   ├── Middleware/
│   │   ├── AuthenticateApiKey.php
│   │   ├── EnforceTenantContext.php        # set app.tenant_id untuk RLS
│   │   ├── EnforceIdempotency.php
│   │   └── EnforceRateLimit.php
│   ├── Controllers/Api/V1/
│   │   ├── VerificationController.php
│   │   ├── UsageController.php
│   │   └── WebhookEndpointController.php
│   ├── Requests/
│   └── Resources/
└── Support/
    └── Metrics.php

database/migrations/
routes/api.php
tests/
├── Unit/
├── Feature/
└── Tenancy/                                # test isolasi antar tenant
```

Pola **Domain layer terpisah** dipakai karena logika OTP akan tumbuh dan tidak boleh tercampur dengan controller. `MessageSender` sengaja dinamai begitu, bukan `OtpProvider` — provider di arsitektur ini benar-benar hanya pipa, bukan pemilik logika verifikasi.

---

## Fase 0 — Persiapan

**Tujuan:** semua yang punya lead time eksternal sudah jalan, supaya tidak jadi penghambat nanti.

### Tugas

| # | Tugas | Penanggung jawab | Lead time |
|---|---|---|---|
| 0.1 | Mulai verifikasi bisnis Meta (akta PT, NIB, bukti alamat, website) | Legal + Eng | **3 hari – 3 minggu** |
| 0.2 | Beli 2 SIM baru: satu untuk OTP, satu cadangan/marketing | Ops | 1 hari |
| 0.3 | Buat Meta Developer account + app tipe Business, ambil nomor test | Eng | 1 jam |
| 0.4 | Daftarkan 5 nomor penerima: Telkomsel, Indosat, XL, + 2 bebas | Eng | 1 jam |
| 0.5 | Buat System User + token permanen (token panel hanya 24 jam) | Eng | 30 menit |
| 0.6 | Provisioning PostgreSQL 16 + Redis 7 (dev & staging) | Eng | 2 jam |
| 0.7 | Inisialisasi repo, CI pipeline, environment config | Eng | 3 jam |
| 0.8 | Hubungi 2 aggregator SMS, minta penawaran + info Sender ID | Ops | 1–2 minggu |

### Peringatan

> **Jangan daftarkan nomor pribadi ke Cloud API.** Nomor yang sudah terdaftar tidak bisa lagi dipakai di aplikasi WhatsApp biasa, dan mengembalikannya merepotkan. Gunakan SIM baru.

### Acceptance criteria

- [ ] `curl` ke Graph API berhasil mengirim template `hello_world` dari nomor test Meta ke HP anggota tim
- [ ] Token permanen tersimpan di secret manager, bukan di repo
- [ ] `php artisan migrate` berjalan di PostgreSQL lokal
- [ ] CI hijau pada commit pertama
- [ ] Verifikasi bisnis Meta berstatus "in review" atau lebih maju

---

## Fase 1 — Inti yang Berfungsi

**Tujuan:** satu aplikasi internal bisa login memakai OTP WhatsApp. Belum ada fallback, belum ada multi-tenant penuh.

### 1.1 Migration & Model

Buat migration untuk: `tenants`, `applications`, `api_keys`, `verifications`, `deliveries`.

Skema lengkap ada di dokumen desain. Tiga hal yang **wajib** ada sejak migration pertama:

```php
// Satu OTP aktif per nomor per application — ditegakkan database, bukan aplikasi
DB::statement("
    CREATE UNIQUE INDEX verifications_active_unique
      ON verifications (application_id, msisdn)
      WHERE status = 'pending'
");

// Pembersihan efisien
DB::statement("
    CREATE INDEX verifications_expiring
      ON verifications (expires_at)
      WHERE status = 'pending'
");

// Pencarian tanpa membuka nomor
Schema::table('verifications', fn ($t) => $t->index(['msisdn_hash', 'created_at']));
```

### 1.2 CodeGenerator

```php
final class CodeGenerator
{
    public function __construct(private readonly string $pepper) {}

    public function generate(int $length = 6): string
    {
        $max = (10 ** $length) - 1;
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public function hash(string $code, string $msisdn): string
    {
        return hash_hmac('sha256', $code . ':' . $msisdn, $this->pepper);
    }

    public function verify(string $code, string $msisdn, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($code, $msisdn));
    }
}
```

Tiga hal yang tidak boleh dikompromikan di sini:

- `random_int()`, bukan `rand()` atau `mt_rand()` — harus CSPRNG
- Pepper dari `config('otp.pepper')` yang dibaca dari env atau KMS, **tidak pernah dari database**
- `hash_equals()` untuk perbandingan constant-time

### 1.3 Konsumsi atomik

Satu query, tanpa jendela race condition:

```php
$affected = DB::update("
    UPDATE verifications
       SET status = 'approved', consumed_at = now(), attempts = attempts + 1
     WHERE id = ?
       AND status = 'pending'
       AND expires_at > now()
       AND code_hash = ?
", [$id, $hash]);

if ($affected === 0) {
    // Naikkan attempts secara terpisah agar percobaan salah tetap terhitung
    DB::update("
        UPDATE verifications SET attempts = attempts + 1
         WHERE id = ? AND status = 'pending'
    ", [$id]);

    throw new OtpException(OtpException::INVALID_CODE, 400, 'Kode verifikasi tidak cocok');
}
```

### 1.4 Guard API key

Middleware `AuthenticateApiKey`:

1. Ambil token dari header `Authorization: Bearer otp_live_...`
2. Hitung `hash('sha256', $token)`
3. Cari di `api_keys` dengan `whereNull('revoked_at')`
4. Tolak kalau tenant `suspended` atau application tidak aktif
5. Ikat `tenant_id`, `application_id`, dan `mode` ke request context
6. Update `last_used_at` lewat job asinkron, jangan blokir response

### 1.5 Provider Meta Cloud API

`MetaCloudApiSender` mengimplementasikan `MessageSender`:

```php
interface MessageSender
{
    public function name(): string;
    public function channel(): Channel;

    /** @return array{provider_msg_id: ?string} */
    public function send(string $msisdn, string $code, array $options = []): array;

    /** Petakan error provider ke klasifikasi kita */
    public function classifyError(string $providerCode): FailureType;
}
```

Endpoint Meta: `POST https://graph.facebook.com/v21.0/{phone_number_id}/messages` dengan template kategori authentication dan kode sebagai parameter body.

### 1.6 Endpoint

- `POST /v1/verifications`
- `POST /v1/verifications/{id}/check`
- `GET /v1/verifications/{id}`
- `DELETE /v1/verifications/{id}`

### Deliverable Fase 1

- Migration lengkap + seeder untuk satu tenant dan satu application internal
- `OtpService` dengan generate, kirim, verifikasi
- `MetaCloudApiSender` berfungsi
- Guard API key
- 4 endpoint di atas
- Test unit untuk `CodeGenerator`, `PhoneNormalizer`, konsumsi atomik
- Test feature untuk alur happy path dan semua kode error

### Acceptance criteria

- [ ] `POST /v1/verifications` mengirim WhatsApp nyata ke nomor terdaftar dalam < 3 detik
- [ ] Kode benar → `approved`; kode salah → `attempts` naik dan sisa percobaan turun
- [ ] Percobaan ke-6 ditolak `too_many_attempts`
- [ ] Reference sekali pakai — verifikasi kedua dengan kode sama ditolak
- [ ] Dua request paralel ke nomor sama: satu berhasil, satu kena `verification_exists`
- [ ] Kode OTP tidak muncul di `storage/logs`, response, maupun Telescope
- [ ] Coverage domain layer ≥ 80%

---

## Fase 2 — Fallback & Ketahanan

**Tujuan:** WhatsApp gagal otomatis jatuh ke SMS, dengan kode yang sama.

### 2.1 ChannelRouter

Menyusun `channel_plan` dengan urutan prioritas:

1. `channels` dari request, kalau `allow_override` bernilai true
2. `default_plan` dari config application
3. Disesuaikan dengan cache `phone_capabilities` — nomor yang diketahui tidak punya WhatsApp langsung dimulai dari SMS

### 2.2 Delayed job

```php
// Setelah berhasil mengirim ke step saat ini
$job = AdvanceChannelPlan::dispatch($verification->id, $currentStep)
    ->delay(now()->addSeconds($config['channel_timeout']));

$verification->update(['pending_job_id' => $job->getJobId()]);
```

Saat webhook `delivered` masuk, batalkan job tersebut. Job yang tetap menyala akan memeriksa status terkini dan berhenti sendiri kalau sudah `delivered` — jadi pembatalan adalah optimasi, bukan syarat kebenaran.

### 2.3 Klasifikasi error

Ini yang membedakan implementasi bagus dan asal jalan:

| Kode | Klasifikasi | Aksi |
|---|---|---|
| `131026` — nomor tidak punya WhatsApp | **PERMANENT** | Lompat ke channel berikutnya **seketika**, jangan tunggu timer |
| `131047` — di luar jendela 24 jam | PERMANENT | Lompat channel |
| `130429` — rate limit provider | TEMPORARY | Retry channel sama dengan backoff |
| `131000` — error internal Meta | TEMPORARY | Retry, lalu lompat kalau tetap gagal |
| Timeout tanpa status | UNKNOWN | Tunggu `channel_timeout`, lalu lompat |

Menunggu 30 detik untuk error permanen adalah 30 detik yang dibuang dari hidup user, dan mereka sudah terlanjur menekan "kirim ulang".

### 2.4 Circuit breaker

Per provider, dengan jendela 5 menit. Kalau error rate melewati ambang, lewati provider itu untuk **semua** tenant sampai pulih. Simpan state di Redis, ekspos sebagai metrik gauge.

### 2.5 Cache kemampuan nomor

```sql
CREATE TABLE phone_capabilities (
  msisdn_hash  CHAR(64) PRIMARY KEY,
  wa_capable   BOOLEAN,
  last_checked TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at   TIMESTAMPTZ NOT NULL
);
```

Ini penghematan biaya terbesar yang bisa didapat tanpa menyentuh harga vendor. Simpan hash, bukan nomor.

### 2.6 Webhook masuk

Endpoint `POST /webhooks/meta` dan `POST /webhooks/sms`:

- Verifikasi signature (Meta memakai `X-Hub-Signature-256`)
- Balas `200` **secepatnya**, proses di queue — provider akan retry kalau kamu lambat
- Cocokkan lewat `provider_msg_id` ke baris `deliveries`
- Update status, picu langkah berikutnya kalau perlu

### Deliverable Fase 2

- `JatisSmsSender` atau aggregator terpilih
- `ChannelRouter` + `AdvanceChannelPlan` job
- Handler webhook untuk kedua provider
- `CircuitBreaker` + tabel `phone_capabilities`
- Horizon terpasang dan dashboard bisa diakses

### Acceptance criteria

- [ ] Nomor tanpa WhatsApp: SMS terkirim dalam < 5 detik, tidak menunggu 30 detik penuh
- [ ] Nomor dengan WhatsApp tapi offline: SMS terkirim setelah `channel_timeout`
- [ ] **Kode di SMS identik dengan kode di WhatsApp**
- [ ] Webhook `delivered` masuk → job fallback tidak mengirim apa pun
- [ ] Circuit breaker terbuka saat provider disimulasikan gagal 100%
- [ ] Permintaan kedua ke nomor yang sama langsung memakai channel hasil cache
- [ ] Horizon menampilkan delayed job dengan benar

---

## Fase 3 — Multi-Tenant & Open API

**Tujuan:** tenant kedua bisa integrasi mandiri tanpa bantuan tim kamu.

### 3.1 Manajemen tenant

CRUD untuk tenant, application, dan API key. Kunci ditampilkan **sekali saja** saat dibuat; setelahnya hanya `key_prefix` yang terlihat.

### 3.2 Test mode

Kunci `otp_test_` tidak pernah mengirim pesan nyata. `SandboxSender` langsung menandai `delivered`, kode selalu bernilai tetap.

Nomor ajaib wajib disediakan:

| Nomor | Simulasi |
|---|---|
| `628000000001` | Semua channel gagal |
| `628000000002` | WhatsApp gagal, SMS berhasil |
| `628000000003` | Timeout di semua channel |
| `628000000009` | Nomor tidak bisa menerima pesan |

Ini fitur yang paling kamu hargai sendiri saat mengevaluasi vendor. Tenant-mu akan merasakan hal yang sama.

### 3.3 Idempotency

Header `Idempotency-Key` wajib untuk semua `POST`. Simpan respons 24 jam. Body berbeda dengan kunci sama → `422 idempotency_key_reuse`.

### 3.4 Rate limit berlapis

Empat lapis: per tenant, per nomor, per IP, per prefix negara. Kembalikan header `X-RateLimit-*` dan `Retry-After`.

### 3.5 Row Level Security

```sql
ALTER TABLE verifications ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON verifications
  USING (tenant_id = current_setting('app.tenant_id')::uuid);
```

Middleware `EnforceTenantContext` menjalankan `SET LOCAL app.tenant_id` di awal setiap transaksi.

### 3.6 Webhook keluar

Event, signature HMAC dengan timestamp, retry backoff eksponensial, nonaktifkan endpoint setelah 24 jam gagal.

### 3.7 Metering & dokumentasi

Tabel `usage_records` agregat harian. Spesifikasi OpenAPI 3.1 di `/v1/openapi.json`. Dokumentasi publik dengan contoh cURL, PHP, JavaScript, dan Python.

### Acceptance criteria

- [ ] Test isolasi: tenant A tidak bisa membaca atau mengubah data tenant B lewat **setiap** endpoint
- [ ] RLS aktif — query tanpa `app.tenant_id` mengembalikan nol baris
- [ ] Kunci test tidak pernah memicu panggilan provider nyata
- [ ] Semua nomor ajaib berperilaku sesuai tabel
- [ ] Request duplikat dengan `Idempotency-Key` sama mengembalikan respons identik
- [ ] Webhook keluar terverifikasi dengan contoh kode yang kami sediakan
- [ ] Spesifikasi OpenAPI bisa diimpor ke Postman dan semua endpoint berhasil dipanggil
- [ ] **Uji nyata: seorang developer yang belum pernah melihat sistem ini berhasil integrasi hanya dari dokumentasi**

Poin terakhir itu bukan formalitas. Kalau dia perlu bertanya, dokumentasinya belum selesai.

---

## Fase 4 — Siap Produksi

### 4.1 Observability

Metrik wajib, semua dipecah per dimensi:

```
verification_created_total{tenant, application, purpose, mode}
delivery_attempt_total{tenant, channel, provider, status, error_code, operator}
delivery_latency_seconds{channel, provider, operator}    histogram
verification_approved_total{tenant, channel_used}
provider_circuit_state{provider}                          gauge
webhook_delivery_total{tenant, status}
```

**Dimensi `operator` tidak boleh dilewatkan.** Turunkan dari prefix nomor — 0811–0813 Telkomsel, 0814–0816 Indosat, 0817–0819 XL, dan seterusnya. Rata-rata global akan menyembunyikan rute yang buruk ke satu operator tertentu, dan tanpa angka per operator kamu tidak punya dasar menuntut perbaikan ke aggregator.

### 4.2 Alert

| Kondisi | Tingkat |
|---|---|
| Delivery rate satu channel < 85% selama 10 menit | Bangunkan orang |
| Circuit breaker terbuka | Bangunkan orang |
| Rasio approval turun > 30% dibanding baseline 7 hari | Bangunkan orang |
| Lonjakan permintaan dari satu IP atau prefix asing | Bangunkan orang |
| Antrean Horizon menumpuk > 1000 job | Peringatan |
| Kuota tenant hampir habis | Info |

### 4.3 Load test

Target realistis untuk internal: **50 request/detik berkelanjutan**, p95 < 500 ms untuk `POST /v1/verifications` tidak termasuk waktu provider.

Uji juga skenario yang sering terlupa: 1.000 webhook masuk bersamaan setelah burst pengiriman.

### 4.4 Job pembersih

```bash
php artisan otp:expire-stale        # tandai kedaluwarsa, tiap menit
php artisan otp:prune --days=90     # hapus verifikasi lama, harian
php artisan otp:aggregate-usage     # agregat ke usage_records, tiap jam
```

### Acceptance criteria

- [ ] Dashboard menampilkan delivery rate per channel per operator
- [ ] Semua alert sudah diuji dengan memicu kondisinya secara sengaja
- [ ] Load test lulus target
- [ ] Runbook sudah ditulis dan **diuji oleh orang yang bukan penulisnya**
- [ ] Backup database berjalan otomatis dan pernah diuji restore

---

## 9. Strategi Testing

| Lapis | Cakupan | Alat |
|---|---|---|
| Unit | `CodeGenerator`, `PhoneNormalizer`, `ChannelRouter`, `CircuitBreaker` | Pest |
| Feature | Semua endpoint, semua kode error, idempotency | Pest + `Http::fake()` |
| Tenancy | Isolasi antar tenant di setiap endpoint | Pest, folder terpisah |
| Integration | Provider nyata dengan nomor test Meta | Manual, tidak di CI |
| Load | Throughput dan burst webhook | k6 |

### Test isolasi tenant — wajib ada di CI

```php
it('mencegah tenant membaca verifikasi milik tenant lain', function () {
    [$tenantA, $keyA] = createTenantWithKey();
    [$tenantB, $keyB] = createTenantWithKey();

    $verification = createVerification($tenantB);

    $this->withToken($keyA)
         ->getJson("/v1/verifications/{$verification->id}")
         ->assertNotFound();   // 404, bukan 403 — jangan bocorkan keberadaannya
});
```

Detail kecil yang penting: balas **404, bukan 403**. Membalas 403 memberi tahu penyerang bahwa ID tersebut ada dan milik orang lain.

### Yang tidak boleh masuk CI

Panggilan nyata ke Meta atau aggregator SMS. Selalu `Http::fake()`. Integration test dengan provider nyata dijalankan manual sebelum rilis, bukan di setiap commit.

---

## 10. Infrastruktur & Deployment

### Komponen minimum

| Komponen | Spesifikasi awal | Catatan |
|---|---|---|
| App server | 2 vCPU, 4 GB | Naikkan berdasarkan data, bukan tebakan |
| PostgreSQL | 2 vCPU, 4 GB, SSD | Managed lebih baik daripada self-host |
| Redis | 1 GB | Persistence aktif — antrean job tidak boleh hilang saat restart |
| Queue worker | Proses terpisah dari web | Horizon, minimal 2 worker |
| Monitoring | Prometheus + Grafana, atau layanan terkelola | — |
| Error tracking | Sentry | Pastikan scrubbing aktif untuk field `code` |

Perkiraan biaya infrastruktur: **Rp 1–3 juta per bulan** untuk setup sederhana. Ini biaya **tetap** — besarnya sama baik kamu kirim 100 atau 100.000 OTP, dan inilah sebabnya bangun sendiri kalah di volume kecil dan menang di volume besar.

### Environment

`local` → `staging` → `production`. Staging memakai kunci `otp_test_` dan nomor test Meta. Production memakai nomor terverifikasi.

### Secret

Pepper, token Meta, kredensial aggregator, dan signing secret webhook **tidak pernah masuk repo**. Gunakan secret manager. Rotasi pepper butuh migrasi khusus — rencanakan sebelum produksi, karena setelah ada data aktif rotasinya jauh lebih rumit.

---

## 11. Runbook Operasional

Tulis dokumen terpisah berisi prosedur untuk situasi berikut. Setiap prosedur harus bisa diikuti oleh orang yang tidak menulis kodenya.

| Situasi | Isi runbook |
|---|---|
| Delivery rate WhatsApp anjlok | Cek status Meta, cek quality rating, cek circuit breaker, prosedur paksa semua trafik ke SMS |
| Aggregator SMS mati | Cara mengganti provider lewat config tanpa deploy |
| Antrean Horizon menumpuk | Diagnosa worker, cara menambah worker, cara membuang job usang dengan aman |
| Dugaan SMS pumping | Cara identifikasi pola, cara blokir prefix atau IP secara darurat |
| Kebocoran API key tenant | Prosedur pencabutan, notifikasi tenant, audit dampak |
| Template Meta ditolak | Alur banding, template cadangan |
| Nomor pengirim kena blokir | Prosedur pindah ke nomor cadangan |

Runbook terakhir itu alasan mengapa Fase 0 meminta membeli **dua** SIM.

---

## 12. Risiko & Mitigasi

| Risiko | Dampak | Kemungkinan | Mitigasi |
|---|---|---|---|
| Verifikasi bisnis Meta lama atau ditolak | Jalur WhatsApp produksi tertunda | Sedang | Mulai di Fase 0. Siapkan dokumen lengkap. Kembangkan pakai nomor test sambil menunggu |
| Registrasi Sender ID SMS lambat | Fallback tampil dengan nama generik | **Tinggi** | Mulai paralel di Fase 0. Terima sender ID bersama untuk sementara |
| Kualitas rute SMS buruk di satu operator | OTP gagal untuk sebagian user | Sedang | Metrik per operator sejak Fase 4. Siapkan aggregator kedua |
| Nomor pengirim WhatsApp kena blokir | OTP WhatsApp mati total | Rendah | Nomor cadangan sudah disiapkan. Pisahkan trafik OTP dari marketing |
| SMS pumping fraud | Tagihan membengkak | Sedang | Batas prefix negara sejak Fase 1, rate limit berlapis, alert lonjakan |
| Pepper bocor | Seluruh kode aktif bisa dibongkar | Rendah | KMS, tidak pernah di repo atau database, akses dibatasi |
| Kebocoran data antar tenant | Insiden keamanan serius | Rendah | Query scoping + RLS + test isolasi di CI, tiga lapis |
| Delayed job hilang saat restart | Fallback tidak pernah jalan | Sedang | Redis persistence, jangan pakai timer in-process, monitoring Horizon |

Dua risiko dengan kemungkinan tertinggi — Sender ID lambat dan rute SMS buruk — keduanya di luar kendalimu dan keduanya soal SMS. Ini alasan tambahan menjadikan WhatsApp channel utama, bukan sekadar soal biaya.

---

## 13. Checklist Go-Live

### Keamanan

- [ ] Pepper di KMS, bukan di env file yang ter-commit
- [ ] Kode OTP tidak muncul di log, APM, Sentry, atau respons API
- [ ] Nomor telepon dimasking di semua output
- [ ] API key tersimpan sebagai hash
- [ ] TLS wajib, HTTP ditolak
- [ ] Prefix negara dibatasi per application
- [ ] Rate limit aktif di keempat lapis
- [ ] Payload webhook tidak memuat kode
- [ ] Audit log untuk pembuatan dan pencabutan kunci
- [ ] Sentry scrubbing terkonfirmasi lewat pengujian nyata

### Keandalan

- [ ] Redis persistence aktif dan diuji dengan restart
- [ ] Horizon berjalan sebagai supervised process
- [ ] Circuit breaker diuji per provider
- [ ] Backup database otomatis + restore pernah diuji
- [ ] Nomor WhatsApp cadangan siap
- [ ] Aggregator SMS kedua sudah dikontak

### Observability

- [ ] Metrik per `channel × provider × operator` tampil di dashboard
- [ ] Semua alert diuji dengan memicu kondisinya
- [ ] `request_id` ada di setiap respons termasuk yang sukses
- [ ] Runbook ditulis dan diuji orang lain

### Operasional

- [ ] Job pembersih terjadwal dan pernah berjalan
- [ ] Kebijakan retensi data diterapkan
- [ ] Ada orang yang jelas bertanggung jawab saat alert menyala
- [ ] Prosedur eskalasi tertulis

---

## 14. Lampiran

### A. Environment variables

```bash
# Inti
OTP_PEPPER=                      # dari KMS. WAJIB. Jangan pernah di repo
OTP_DEFAULT_CODE_LENGTH=6
OTP_DEFAULT_TTL=300
OTP_DEFAULT_CHANNEL_TIMEOUT=30
OTP_MAX_VERIFY_ATTEMPTS=5

# Meta Cloud API
META_PHONE_NUMBER_ID=
META_WABA_ID=
META_ACCESS_TOKEN=               # token System User, tanpa kedaluwarsa
META_WEBHOOK_VERIFY_TOKEN=
META_APP_SECRET=                 # untuk verifikasi X-Hub-Signature-256
META_TEMPLATE_NAME=otp_authentication
META_TEMPLATE_LANG=id

# SMS
SMS_PROVIDER=jatis
SMS_API_KEY=
SMS_SENDER_ID=
SMS_WEBHOOK_SECRET=

# Guard nomor
PHONE_ALLOWED_PREFIXES=62
PHONE_DEFAULT_COUNTRY_CODE=62

# Infrastruktur
DB_CONNECTION=pgsql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
```

### B. Perintah artisan

```bash
php artisan otp:tenant:create      "PT Monarch Inti Teknologi"
php artisan otp:app:create         --tenant=... --brand="MonarchApp"
php artisan otp:key:create         --app=... --mode=test
php artisan otp:key:revoke         --id=...
php artisan otp:expire-stale
php artisan otp:prune              --days=90
php artisan otp:aggregate-usage
php artisan otp:test-send          --phone=628... --channel=whatsapp
```

`otp:test-send` adalah perintah yang paling sering kamu pakai saat development. Buat lebih awal dari yang terasa perlu.

### C. Urutan membaca dokumen

1. Rencana ini — apa yang dikerjakan dan kapan
2. `otp-multitenant-design.md` — spesifikasi teknis, skema, kontrak API
3. Paket kode `vonage-otp.zip` — pola adapter dan struktur service yang bisa dijadikan titik awal

### D. Yang ditinjau ulang setelah 3 bulan produksi

Setelah punya data nyata, tinjau kembali keputusan berikut:

- Rasio WhatsApp versus SMS — apakah cache `phone_capabilities` bekerja sebaik harapan
- Biaya riil per verifikasi — bandingkan dengan penawaran Vonage dan Twilio saat itu
- `channel_timeout` 30 detik — apakah terlalu cepat atau terlalu lambat berdasarkan distribusi latency nyata
- Apakah Miscall OTP layak ditambahkan sebagai lapis ketiga
- Apakah volume sudah membenarkan pemindahan jalur panas ke Go

Keputusan-keputusan itu sengaja diambil dengan data terbatas hari ini. Meninjaunya dengan data nyata bukan tanda perencanaan yang buruk — itu memang caranya bekerja.
