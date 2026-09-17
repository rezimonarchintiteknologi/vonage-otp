<?php

declare(strict_types=1);

namespace App\Domain\Otp\Services;

use App\Domain\Otp\Exceptions\OtpException;

/**
 * Normalisasi nomor ke E.164 tanpa tanda plus (contoh: 6281234567890),
 * plus dua turunan yang dipakai di seluruh sistem: hash untuk pencarian
 * tanpa membuka nomor, dan operator untuk dimensi metrik.
 */
final class PhoneNormalizer
{
    /**
     * Prefix nasional (setelah 62) ke nama operator. Dipakai sebagai dimensi
     * metrik — rata-rata global menyembunyikan rute buruk ke satu operator.
     *
     * @var array<string, string>
     */
    private const OPERATOR_PREFIXES = [
        '811' => 'telkomsel', '812' => 'telkomsel', '813' => 'telkomsel',
        '821' => 'telkomsel', '822' => 'telkomsel', '823' => 'telkomsel',
        '851' => 'telkomsel', '852' => 'telkomsel', '853' => 'telkomsel',

        '814' => 'indosat', '815' => 'indosat', '816' => 'indosat',
        '855' => 'indosat', '856' => 'indosat', '857' => 'indosat', '858' => 'indosat',

        '817' => 'xl', '818' => 'xl', '819' => 'xl',
        '859' => 'xl', '877' => 'xl', '878' => 'xl',

        '831' => 'axis', '832' => 'axis', '833' => 'axis', '838' => 'axis',

        '895' => 'tri', '896' => 'tri', '897' => 'tri', '898' => 'tri', '899' => 'tri',

        '881' => 'smartfren', '882' => 'smartfren', '883' => 'smartfren',
        '884' => 'smartfren', '885' => 'smartfren', '886' => 'smartfren',
        '887' => 'smartfren', '888' => 'smartfren', '889' => 'smartfren',
    ];

    public function __construct(
        private readonly string $defaultCountryCode = '62',
    ) {}

    /**
     * @param  list<string>  $allowedPrefixes  prefix negara yang boleh dilayani
     *
     * @throws OtpException
     */
    public function normalize(string $raw, array $allowedPrefixes): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            throw OtpException::invalidPhone();
        }

        // 0812... → 62812...
        if (str_starts_with($digits, '0')) {
            $digits = $this->defaultCountryCode.substr($digits, 1);
        }

        // 0062812... → 62812...
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw OtpException::invalidPhone('Panjang nomor di luar rentang E.164');
        }

        $matched = null;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($digits, (string) $prefix)) {
                $matched = (string) $prefix;
                break;
            }
        }

        if ($matched === null) {
            throw OtpException::countryNotAllowed(substr($digits, 0, 3));
        }

        return $digits;
    }

    /** Hash pencarian. Pakai SHA-256 polos: ini indeks, bukan rahasia. */
    public static function hash(string $msisdn): string
    {
        return hash('sha256', $msisdn);
    }

    /** 6281234567890 → 6281****7890 */
    public static function mask(string $msisdn): string
    {
        $length = strlen($msisdn);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($msisdn, 0, 4).str_repeat('*', $length - 8).substr($msisdn, -4);
    }

    /** Operator dari prefix. `unknown` kalau nomor bukan Indonesia atau prefix baru. */
    public static function operator(string $msisdn): string
    {
        if (! str_starts_with($msisdn, '62')) {
            return 'unknown';
        }

        return self::OPERATOR_PREFIXES[substr($msisdn, 2, 3)] ?? 'unknown';
    }

    public static function countryPrefix(string $msisdn): string
    {
        return substr($msisdn, 0, 2);
    }
}
