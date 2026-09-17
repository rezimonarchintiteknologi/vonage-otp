/**
 * Error domain milik aplikasi, bukan error vendor.
 * Ini yang bikin kita bisa ganti provider tanpa mengubah controller.
 */
export class OtpError extends Error {
  constructor(code, httpStatus = 400, message = code, meta = {}) {
    super(message);
    this.name = 'OtpError';
    this.code = code;
    this.httpStatus = httpStatus;
    this.meta = meta;
  }
}

export const OtpErrorCode = {
  INVALID_PHONE: 'INVALID_PHONE',
  COUNTRY_NOT_ALLOWED: 'COUNTRY_NOT_ALLOWED',
  RATE_LIMITED: 'RATE_LIMITED',
  CONCURRENT_REQUEST: 'CONCURRENT_REQUEST',
  PROVIDER_ERROR: 'PROVIDER_ERROR',
  PROVIDER_UNAVAILABLE: 'PROVIDER_UNAVAILABLE',
  REFERENCE_NOT_FOUND: 'REFERENCE_NOT_FOUND',
  INVALID_CODE: 'INVALID_CODE',
  EXPIRED: 'EXPIRED',
  TOO_MANY_ATTEMPTS: 'TOO_MANY_ATTEMPTS',
};
