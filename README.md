# OTP WhatsApp → SMS dengan Vonage Verify v2

Implementasi siap pakai untuk **Node.js (Express)** dan **Laravel**, dengan pola adapter supaya kamu tidak terkunci ke satu vendor.

Fallback WhatsApp → SMS ditangani Vonage lewat array `workflow`. Tidak ada state machine, timer, atau webhook listener yang perlu kamu tulis.

---

## Dokumentasi resmi

| Topik | Link |
|---|---|
| API Reference Verify v2 | https://developer.vonage.com/en/api/verify.v2 |
| Overview & konsep | https://developer.vonage.com/en/verify/overview |
| Node SDK (`@vonage/server-sdk`) | https://github.com/Vonage/vonage-node-sdk |
| Node SDK — paket verify2 | https://github.com/Vonage/vonage-node-sdk/blob/3.x/packages/verify2/README.md |
| PHP SDK (`vonage/client`) | https://github.com/Vonage/vonage-php-sdk-core |
| Dashboard (API key & secret) | https://dashboard.nexmo.com |

**Catatan:** implementasi di sini memakai **REST langsung** lewat `fetch` (Node) dan `Http` facade (Laravel), bukan SDK. Alasannya: kontrak REST-nya stabil dan terdokumentasi lengkap, tidak menambah dependensi, dan lebih mudah di-mock saat testing. Kalau kamu lebih suka SDK, tinggal ganti isi file provider — interface-nya tidak berubah.

---

## Cara kerja

```
Client  ──POST /api/otp/request──►  OtpService  ──►  VonageVerifyProvider  ──►  Vonage
                                        │                                        │
                                   reference acak                         workflow:
                                   (bukan request_id)                     1. whatsapp
                                                                          2. sms  (setelah channel_timeout)
Client  ──POST /api/otp/verify ──►  OtpService  ──►  VonageVerifyProvider  ──►  Vonage
```

Satu kode berlaku untuk semua channel. Kalau WhatsApp tidak terkirim dalam `channel_timeout` detik, Vonage otomatis mengirim SMS dengan **kode yang sama**.

---

## Endpoint yang dihasilkan

### `POST /api/otp/request`

```json
{ "phone": "081234567890" }
```

Respons `202`:

```json
{
  "data": {
    "reference": "a3f9...c21e",
    "masked_phone": "6281****7890",
    "channels": ["whatsapp", "sms"],
    "channel_timeout": 30,
    "expires_in": 600
  }
}
```

`reference` adalah token acak milik aplikasimu, **bukan** `request_id` Vonage. Identitas internal provider tidak pernah keluar ke client.

### `POST /api/otp/verify`

```json
{ "reference": "a3f9...c21e", "code": "123456" }
```

Respons `200`:

```json
{ "data": { "verified": true, "masked_phone": "6281****7890", "attempts": 1 } }
```

### Kode error

| Code | HTTP | Arti |
|---|---|---|
| `INVALID_PHONE` | 422 | Format nomor tidak valid |
| `COUNTRY_NOT_ALLOWED` | 403 | Di luar prefix yang diizinkan |
| `RATE_LIMITED` | 429 | Batas permintaan tercapai |
| `CONCURRENT_REQUEST` | 409 | Masih ada OTP aktif untuk nomor ini |
| `REFERENCE_NOT_FOUND` | 404 | Sesi tidak ada atau sudah dipakai |
| `INVALID_CODE` | 400 | Kode salah |
| `EXPIRED` | 410 | Workflow sudah berakhir |
| `TOO_MANY_ATTEMPTS` | 429 | Percobaan salah melebihi batas |
| `PROVIDER_UNAVAILABLE` | 502/504 | Vonage tidak bisa dihubungi |

---

## Node.js

```bash
cd nodejs
cp .env.example .env      # isi VONAGE_API_KEY dan VONAGE_API_SECRET
npm install
npm test                  # 17 test dengan provider mock, tanpa panggil API
npm start
```

Uji cepat:

```bash
curl -X POST localhost:3000/api/otp/request \
  -H 'Content-Type: application/json' \
  -d '{"phone":"081234567890"}'
```

**Sebelum produksi:** ganti `MemoryStore` di `src/server.js` dengan implementasi Redis. Interface-nya cuma empat method (`get`, `set`, `del`, `incr`), jadi penggantiannya satu file.

---

## Laravel

Salin folder `laravel/` ke project kamu, lalu:

1. Daftarkan provider di `bootstrap/providers.php` (Laravel 11+) atau `config/app.php` (Laravel 10 ke bawah):

   ```php
   App\Providers\OtpServiceProvider::class,
   ```

2. Salin isi `routes/api-otp.php` ke `routes/api.php`.

3. Tambahkan variabel dari `.env.example` ke `.env`.

4. Pakai `CACHE_STORE=redis` di produksi — `RateLimiter` dan penyimpanan reference bergantung pada cache yang dibagi antar instance.

---

## Konfigurasi yang penting

| Variabel | Default | Catatan |
|---|---|---|
| `OTP_CHANNELS` | `whatsapp,sms` | Urutan fallback, maksimal 3 langkah. Opsi lain: `silent_auth`, `voice` |
| `OTP_CHANNEL_TIMEOUT` | `30` | Detik sebelum pindah channel. Range Vonage 15–900, **default mereka 180 — terlalu lama untuk OTP** |
| `OTP_BRAND` | — | Nama yang muncul di pesan |
| `PHONE_ALLOWED_PREFIXES` | `62` | Pertahanan utama terhadap SMS pumping fraud |
| `OTP_MAX_VERIFY_ATTEMPTS` | `5` | Batas percobaan di sisi kita, bukan hanya provider |

### Soal `silent_auth`

Kalau ditaruh sebagai langkah pertama (`silent_auth,whatsapp,sms`), verifikasi terjadi lewat koneksi data seluler tanpa user memasukkan kode sama sekali. Respons akan berisi `check_url` yang harus dipanggil dari sisi client. **Konfirmasi dulu cakupan operatornya di Indonesia ke Vonage** sebelum mengandalkannya.

---

## Testing sebelum berlangganan

- **Node**: `npm test` menjalankan 17 test dengan provider mock — tidak memanggil API Vonage, tidak keluar biaya.
- **Vonage**: buka trial di dashboard, ada kredit gratis untuk beberapa verifikasi nyata ke nomor +62.
- **WhatsApp secara umum**: nomor test gratis dari Meta Cloud API adalah cara termurah menguji template dan delivery ke nomor Indonesia, dan berlaku apa pun vendor yang akhirnya dipilih.

---

## Checklist keamanan

- [x] Kode OTP tidak pernah ditulis ke log
- [x] `request_id` provider tidak bocor ke client
- [x] Reference sekali pakai, dihapus setelah verifikasi berhasil
- [x] Rate limit per nomor **dan** per IP
- [x] Prefix negara dibatasi (anti SMS pumping)
- [x] Batas percobaan verifikasi di sisi server
- [ ] Ganti store in-memory dengan Redis
- [ ] Pasang `trust proxy` sesuai topologi load balancer kamu
- [ ] Tambahkan metrik: delivery rate dan latency per `channel × operator`

---

## Ganti provider nanti

Yang perlu ditulis cuma satu file:

- **Node**: class baru di `src/providers/` dengan method `send`, `check`, `cancel`
- **Laravel**: class baru di `app/Services/Otp/Providers/` yang mengimplementasikan `OtpProvider`, lalu tambahkan di `match` pada `OtpServiceProvider`

`OtpService`, controller, dan kontrak API tidak berubah sama sekali. Ini yang memungkinkan kamu pindah ke Meta Cloud API langsung saat volume sudah besar, tanpa menyentuh kode bisnis.
