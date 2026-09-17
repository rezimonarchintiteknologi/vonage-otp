<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Provider aktif
    |--------------------------------------------------------------------------
    | Ganti nilai ini untuk pindah vendor tanpa menyentuh controller.
    */
    'provider' => env('OTP_PROVIDER', 'vonage'),

    'vonage' => [
        'api_key'    => env('VONAGE_API_KEY'),
        'api_secret' => env('VONAGE_API_SECRET'),
        'base_url'   => 'https://api.nexmo.com/v2/verify',
        'timeout'    => (int) env('VONAGE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Perilaku OTP
    |--------------------------------------------------------------------------
    | channels  : urutan fallback, maksimal 3 langkah.
    |             Opsi: silent_auth, whatsapp, sms, voice
    | channel_timeout : detik sebelum Vonage pindah ke channel berikutnya.
    |             Range 15-900. Default Vonage 180 terlalu lama untuk OTP.
    */
    'brand'            => env('OTP_BRAND', 'MyApp'),
    'code_length'      => (int) env('OTP_CODE_LENGTH', 6),
    'channel_timeout'  => (int) env('OTP_CHANNEL_TIMEOUT', 30),
    'locale'           => env('OTP_LOCALE', 'id-id'),
    'channels'         => array_slice(
        array_filter(array_map('trim', explode(',', env('OTP_CHANNELS', 'whatsapp,sms')))),
        0,
        3
    ),
    'reference_ttl'       => (int) env('OTP_REFERENCE_TTL', 600),
    'max_verify_attempts' => (int) env('OTP_MAX_VERIFY_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Proteksi SMS pumping fraud
    |--------------------------------------------------------------------------
    | Tolak semua nomor di luar prefix ini. Satu baris konfigurasi yang
    | mencegah penyerang memicu OTP ke nomor premium luar negeri.
    */
    'allowed_prefixes'     => array_filter(array_map('trim', explode(',', env('PHONE_ALLOWED_PREFIXES', '62')))),
    'default_country_code' => env('PHONE_DEFAULT_COUNTRY_CODE', '62'),

    'rate_limit' => [
        'phone' => [
            'max'    => (int) env('RL_PHONE_MAX', 3),
            'window' => (int) env('RL_PHONE_WINDOW', 900),
        ],
        'ip' => [
            'max'    => (int) env('RL_IP_MAX', 10),
            'window' => (int) env('RL_IP_WINDOW', 900),
        ],
    ],
];
