<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pepper
    |--------------------------------------------------------------------------
    |
    | Kunci rahasia untuk HMAC kode OTP. Dibaca dari environment atau KMS,
    | tidak pernah dari database. Tanpa nilai ini, kode tidak bisa di-hash
    | maupun diverifikasi.
    |
    */

    'pepper' => env('OTP_PEPPER'),

    /*
    |--------------------------------------------------------------------------
    | Default verifikasi
    |--------------------------------------------------------------------------
    |
    | Nilai bawaan saat application belum menetapkan konfigurasinya sendiri.
    |
    */

    'defaults' => [
        'code_length' => (int) env('OTP_DEFAULT_CODE_LENGTH', 6),
        'ttl' => (int) env('OTP_DEFAULT_TTL', 300),
        'channel_timeout' => (int) env('OTP_DEFAULT_CHANNEL_TIMEOUT', 30),
        'max_attempts' => (int) env('OTP_MAX_VERIFY_ATTEMPTS', 5),
        'channels' => ['whatsapp', 'sms'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard nomor
    |--------------------------------------------------------------------------
    */

    'phone' => [
        'default_country_code' => (string) env('PHONE_DEFAULT_COUNTRY_CODE', '62'),
        'allowed_prefixes' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('PHONE_ALLOWED_PREFIXES', '62')))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Pemetaan channel ke sender bawaan. Application boleh menimpa lewat
    | kolom config-nya sendiri.
    |
    */

    'providers' => [
        'whatsapp' => 'meta',
        'sms' => env('SMS_PROVIDER', 'jatis'),
    ],

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'waba_id' => env('META_WABA_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'template_name' => env('META_TEMPLATE_NAME', 'otp_authentication'),
        'template_lang' => env('META_TEMPLATE_LANG', 'id'),
        'timeout' => 10,
    ],

    'sms' => [
        'base_url' => env('SMS_BASE_URL', 'https://api.jatismobile.com'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID'),
        'webhook_secret' => env('SMS_WEBHOOK_SECRET'),
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Jendela pengamatan dalam detik, jumlah sampel minimum sebelum breaker
    | boleh membuka, ambang error rate, dan lama cooldown sebelum half-open.
    |
    */

    'breaker' => [
        'window' => (int) env('OTP_BREAKER_WINDOW', 300),
        'min_samples' => (int) env('OTP_BREAKER_MIN_SAMPLES', 10),
        'error_rate' => (float) env('OTP_BREAKER_ERROR_RATE', 0.5),
        'cooldown' => (int) env('OTP_BREAKER_COOLDOWN', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache kemampuan nomor
    |--------------------------------------------------------------------------
    */

    'phone_capability_ttl_days' => (int) env('OTP_PHONE_CAPABILITY_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency_ttl' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Rate limit berlapis
    |--------------------------------------------------------------------------
    |
    | Masing-masing lapis: jumlah maksimum permintaan per jendela (detik).
    |
    */

    'rate_limits' => [
        'tenant' => ['max' => 600, 'window' => 60],
        'msisdn' => ['max' => 5, 'window' => 3600],
        'ip' => ['max' => 30, 'window' => 60],
        'prefix' => ['max' => 300, 'window' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomor ajaib untuk mode test
    |--------------------------------------------------------------------------
    |
    | Hanya berlaku pada kunci otp_test_. Nomor di luar daftar ini berperilaku
    | normal: langsung delivered tanpa memanggil provider mana pun.
    |
    */

    'magic_numbers' => [
        '628000000001' => 'all_fail',
        '628000000002' => 'whatsapp_fail_sms_ok',
        '628000000003' => 'timeout_all',
        '628000000009' => 'undeliverable',
    ],

    'sandbox_code' => '123456',

    /*
    |--------------------------------------------------------------------------
    | Retensi
    |--------------------------------------------------------------------------
    */

    'prune_after_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Webhook keluar
    |--------------------------------------------------------------------------
    */

    'outgoing_webhook' => [
        'timeout' => 5,
        'max_attempts' => 8,
        'disable_after_hours' => 24,
    ],
];
