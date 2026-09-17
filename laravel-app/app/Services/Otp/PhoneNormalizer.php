<?php

namespace App\Services\Otp;

/**
 * Vonage memakai E.164 TANPA tanda plus.
 * 0812-3456-789   -> 628123456789
 * +62 812 3456789 -> 628123456789
 */
class PhoneNormalizer
{
    public function __construct(
        private readonly array $allowedPrefixes,
        private readonly string $defaultCountryCode,
    ) {}

    /**
     * @return array{msisdn: ?string, rejected: ?string}
     */
    public function normalize(string $input): array
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';

        if ($digits === '') {
            return ['msisdn' => null, 'rejected' => null];
        }

        if (str_starts_with($digits, '00')) {
            // 00 = prefix dial internasional. Sisanya sudah kode negara.
            // 0062812... -> 62812...
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            // 0 = trunk prefix lokal. Ganti dengan kode negara.
            // 0812... -> 62812...
            $digits = $this->defaultCountryCode . substr($digits, 1);
        }

        $length = strlen($digits);
        if ($length < 10 || $length > 15) {
            return ['msisdn' => null, 'rejected' => null];
        }

        foreach ($this->allowedPrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return ['msisdn' => $digits, 'rejected' => null];
            }
        }

        return ['msisdn' => null, 'rejected' => 'COUNTRY_NOT_ALLOWED'];
    }

    public static function mask(string $msisdn): string
    {
        if (strlen($msisdn) < 8) {
            return '***';
        }

        return substr($msisdn, 0, 4) . '****' . substr($msisdn, -4);
    }
}
