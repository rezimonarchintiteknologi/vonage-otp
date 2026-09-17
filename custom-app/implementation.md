# Status Implementasi

Pelacakan pengerjaan `otp-implementation-plan.md`.

**Legenda:** ✅ selesai · X belum · ⏳ sedang dikerjakan · ⛔ di luar kendali kode (butuh aksi manusia/eksternal)

**Terakhir diperbarui:** 2026-09-17 · **Test:** 44 lulus (29 unit, 15 feature)

---

## Lingkungan Kerja

| Item | Status | Catatan |
|---|---|---|
| PHP 8.4.16 | ✅ | Terpasang, ekstensi `pdo_pgsql`, `pgsql`, `redis` aktif |
| Composer 2.10.1 | ✅ | — |
| Redis 8.2.1 | ✅ | Berjalan via `brew services` |
| PostgreSQL 16.15 | ✅ | `brew services start postgresql@16`, database `otp_service` + `otp_service_test` |
| Project root | ✅ | Laravel 13.32 di `custom-app/` |

**Penyimpangan dari rencana:** rencana menulis Laravel 12; yang dipasang Laravel 13 (versi stabil terbaru, sama dengan `laravel-app/` di repo ini). Tidak ada API yang dipakai rencana ini berubah di antara keduanya.

---

## Fase 0 — Persiapan

| # | Tugas | Status | Catatan |
|---|---|---|---|
| 0.1 | Verifikasi bisnis Meta | ⛔ | Butuh dokumen legal PT — aksi manusia |
| 0.2 | Beli 2 SIM baru | ⛔ | Aksi manusia |
| 0.3 | Meta Developer account + nomor test | ⛔ | Butuh login akun — bisa dikerjakan bersama lewat browser Playwright |
| 0.4 | Daftarkan 5 nomor penerima | ⛔ | Menyusul 0.3 |
| 0.5 | System User + token permanen | ⛔ | Menyusul 0.3 |
| 0.6 | Provisioning PostgreSQL 16 + Redis 7 (lokal) | ✅ | Keduanya berjalan; staging belum |
| 0.7 | Inisialisasi repo, CI, environment config | ⏳ | `.env`/`.env.example`/`config/otp.php` siap; CI pipeline belum |
| 0.8 | Hubungi aggregator SMS | ⛔ | Aksi manusia |

### Acceptance criteria Fase 0

- [ ] X — `curl` Graph API mengirim `hello_world` dari nomor test
- [ ] X — Token permanen di secret manager
- [x] ✅ — `php artisan migrate` jalan di PostgreSQL lokal
- [ ] X — CI hijau pada commit pertama
- [ ] X — Verifikasi bisnis Meta "in review"

---

## Fase 1 — Inti yang Berfungsi

### 1.1 Migration & Model

| Item | Status |
|---|---|
| Migration `tenants` | ✅ |
| Migration `applications` | ✅ |
| Migration `api_keys` | ✅ |
| Migration `verifications` | ✅ |
| Migration `deliveries` | ✅ |
| Partial unique index `verifications_active_unique` | ✅ |
| Partial index `verifications_expiring` | ✅ |
| Index `(msisdn_hash, created_at)` | ✅ |
| Model `Tenant` | ✅ |
| Model `Application` | ✅ |
| Model `ApiKey` | ✅ |
| Model `Verification` | ✅ |
| Model `Delivery` | ✅ |
| Seeder tenant + application internal | ✅ |

### 1.2–1.6 Domain & HTTP

| Item | Status |
|---|---|
| `Enums/Channel` | ✅ |
| `Enums/VerificationStatus` | ✅ |
| `Enums/DeliveryStatus` | ✅ |
| `Enums/FailureType` | ✅ |
| `Exceptions/OtpException` | ✅ |
| `Contracts/MessageSender` | ✅ |
| `Contracts/VerificationRepository` | ✅ |
| `Services/CodeGenerator` (CSPRNG + HMAC pepper) | ✅ |
| `Services/PhoneNormalizer` (E.164, guard prefix) | ✅ |
| `Services/OtpService` (generate, kirim, verifikasi) | ✅ |
| Konsumsi atomik satu query | ✅ |
| `Providers/MetaCloudApiSender` | ✅ |
| `Providers/SandboxSender` | ✅ |
| Middleware `AuthenticateApiKey` | ✅ |
| `POST /v1/verifications` | ✅ |
| `POST /v1/verifications/{id}/check` | ✅ |
| `GET /v1/verifications/{id}` | ✅ |
| `DELETE /v1/verifications/{id}` | ✅ |

### Acceptance criteria Fase 1

- [ ] ⛔ — `POST /v1/verifications` mengirim WhatsApp nyata < 3 detik — butuh kredensial Meta (Fase 0.3–0.5). Jalur kodenya sudah diuji penuh dengan `Http::fake()`
- [x] ✅ — Kode benar → `approved`; salah → `attempts` naik
- [x] ✅ — Percobaan ke-6 ditolak `too_many_attempts`
- [x] ✅ — Reference sekali pakai (`already_consumed` pada pemakaian kedua)
- [x] ✅ — Dua request paralel → satu `verification_exists` (partial unique index)
- [x] ✅ — Kode OTP tidak muncul di response; belum ada test khusus untuk isi log
- [ ] X — Coverage domain layer ≥ 80% — belum diukur

---

## Fase 2 — Fallback & Ketahanan

| Item | Status |
|---|---|
| `Services/ChannelRouter` | ✅ |
| `Jobs/DispatchChannelStep` | ✅ |
| `Jobs/AdvanceChannelPlan` (delayed) | ✅ |
| Klasifikasi error PERMANENT/TEMPORARY | ✅ |
| `Services/CircuitBreaker` | ✅ |
| Tabel + cache `phone_capabilities` | ✅ |
| `Webhooks/MetaWebhookHandler` + verifikasi `X-Hub-Signature-256` | X |
| `Webhooks/SmsWebhookHandler` | X |
| `Providers/JatisSmsSender` | ✅ |
| Horizon terpasang | ✅ |

### Acceptance criteria Fase 2

- [ ] X — Nomor tanpa WhatsApp: SMS < 5 detik, tidak tunggu 30 detik
- [ ] X — Nomor WA offline: SMS setelah `channel_timeout`
- [ ] X — Kode SMS identik dengan kode WhatsApp
- [ ] X — Webhook `delivered` → job fallback tidak mengirim
- [ ] X — Circuit breaker terbuka saat provider gagal 100%
- [ ] X — Request kedua memakai channel hasil cache
- [ ] X — Horizon menampilkan delayed job

---

## Fase 3 — Multi-Tenant & Open API

| Item | Status |
|---|---|
| CRUD tenant / application / API key | X |
| Kunci ditampilkan sekali saja | ✅ |
| Test mode + `SandboxSender` | ⏳ |
| Nomor ajaib (`628000000001/2/3/9`) | X |
| Middleware `EnforceIdempotency` | X |
| Middleware `EnforceRateLimit` (4 lapis) | X |
| Row Level Security + `EnforceTenantContext` | X |
| Webhook keluar + HMAC signature | X |
| Tabel `usage_records` + agregasi | X |
| `UsageController` | X |
| `WebhookEndpointController` | X |
| OpenAPI 3.1 di `/v1/openapi.json` | X |
| Dokumentasi publik (cURL, PHP, JS, Python) | X |

### Acceptance criteria Fase 3

- [ ] X — Tenant A tidak bisa baca/ubah data tenant B di setiap endpoint
- [ ] X — RLS aktif, query tanpa `app.tenant_id` → nol baris
- [ ] X — Kunci test tidak memanggil provider nyata
- [ ] X — Semua nomor ajaib berperilaku sesuai tabel
- [ ] X — Idempotency mengembalikan respons identik
- [ ] X — Webhook keluar terverifikasi contoh kode
- [ ] X — OpenAPI bisa diimpor ke Postman
- [ ] X — Developer baru berhasil integrasi hanya dari dokumentasi

---

## Fase 4 — Siap Produksi

| Item | Status |
|---|---|
| `Support/Metrics` + metrik wajib | ⏳ | 
| Dimensi `operator` dari prefix nomor | ✅ |
| Definisi alert | X |
| Load test k6 | X |
| `otp:expire-stale` | ✅ |
| `otp:prune` | ✅ |
| `otp:aggregate-usage` | X |
| `otp:tenant:create` | ✅ |
| `otp:app:create` | ✅ |
| `otp:key:create` | ✅ |
| `otp:key:revoke` | ✅ |
| `otp:test-send` | ✅ |
| Runbook operasional | X |

### Acceptance criteria Fase 4

- [ ] X — Dashboard delivery rate per channel per operator
- [ ] X — Semua alert diuji
- [ ] X — Load test lulus target
- [ ] X — Runbook ditulis dan diuji orang lain
- [ ] X — Backup database otomatis + restore diuji

---

## Testing

| Lapis | Status |
|---|---|
| Unit (`CodeGenerator`, `PhoneNormalizer`) ✅ · (`ChannelRouter`, `CircuitBreaker`) X | ⏳ |
| Feature — semua endpoint + kode error Fase 1 ✅ · idempotency X | ⏳ |
| Tenancy (isolasi antar tenant) | X |
| Integration (provider nyata) | ⛔ |
| Load (k6) | X |

---

## Checklist Go-Live

Semua item pada bagian 13 rencana masih X. Akan diisi setelah Fase 4 selesai.
