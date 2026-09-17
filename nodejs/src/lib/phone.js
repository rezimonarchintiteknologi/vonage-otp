/**
 * Vonage memakai format E.164 TANPA tanda plus.
 * 0812-3456-789  -> 628123456789
 * +62 812 3456789 -> 628123456789
 */
export function normalizeMsisdn(input, { allowedPrefixes, defaultCountryCode }) {
  if (typeof input !== 'string' && typeof input !== 'number') return null;

  let digits = String(input).replace(/\D/g, '');
  if (!digits) return null;

  if (digits.startsWith('00')) {
    // 00 = prefix dial internasional. Sisanya sudah kode negara.
    // 0062812... -> 62812...
    digits = digits.slice(2);
  } else if (digits.startsWith('0')) {
    // 0 = trunk prefix lokal. Ganti dengan kode negara.
    // 0812... -> 62812...
    digits = defaultCountryCode + digits.slice(1);
  }

  if (digits.length < 10 || digits.length > 15) return null;

  // Pertahanan utama terhadap SMS pumping fraud:
  // tolak semua nomor di luar negara yang kita layani.
  const allowed = allowedPrefixes.some((prefix) => digits.startsWith(prefix));
  if (!allowed) return { rejected: 'COUNTRY_NOT_ALLOWED', msisdn: digits };

  return { msisdn: digits };
}

/** Untuk log dan response: 628123456789 -> 6281****6789 */
export function maskMsisdn(msisdn) {
  if (!msisdn || msisdn.length < 8) return '***';
  return `${msisdn.slice(0, 4)}****${msisdn.slice(-4)}`;
}
