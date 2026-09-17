import crypto from 'node:crypto';
import { config } from './config.js';
import { OtpError, OtpErrorCode } from './lib/errors.js';
import { normalizeMsisdn, maskMsisdn } from './lib/phone.js';

/**
 * Layer ini yang membuat kita tidak terkunci ke vendor manapun.
 * Controller bicara ke OtpService, OtpService bicara ke provider lewat
 * interface { send, check, cancel }. Ganti ke Twilio, Infobip, atau
 * Meta Cloud API langsung = tulis satu file provider baru.
 */
export class OtpService {
  constructor({ provider, store }) {
    this.provider = provider;
    this.store = store;
  }

  #refKey(reference) {
    return `otp:ref:${reference}`;
  }

  async #enforceRateLimit(scope, identifier, rule) {
    const key = `otp:rl:${scope}:${identifier}`;
    const hits = await this.store.incr(key, rule.windowSec);
    if (hits > rule.max) {
      throw new OtpError(
        OtpErrorCode.RATE_LIMITED,
        429,
        'Terlalu banyak permintaan. Coba lagi nanti.',
        { scope, retryAfterSec: rule.windowSec },
      );
    }
  }

  /**
   * Minta OTP.
   * Mengembalikan `reference` acak, BUKAN request_id Vonage.
   * Client tidak perlu tahu identitas internal provider.
   */
  async request({ phone, ip, locale }) {
    const parsed = normalizeMsisdn(phone, config.phone);

    if (!parsed) {
      throw new OtpError(OtpErrorCode.INVALID_PHONE, 422, 'Format nomor tidak valid');
    }
    if (parsed.rejected) {
      throw new OtpError(
        OtpErrorCode.COUNTRY_NOT_ALLOWED,
        403,
        'Nomor di luar wilayah layanan',
      );
    }

    const { msisdn } = parsed;

    await this.#enforceRateLimit('phone', msisdn, config.rateLimit.perPhone);
    if (ip) await this.#enforceRateLimit('ip', ip, config.rateLimit.perIp);

    const result = await this.provider.send({ msisdn, locale });

    const reference = crypto.randomBytes(16).toString('hex');
    await this.store.set(
      this.#refKey(reference),
      {
        requestId: result.requestId,
        msisdn,
        provider: this.provider.name,
        attempts: 0,
        createdAt: Date.now(),
      },
      config.otp.referenceTtlSec,
    );

    return {
      reference,
      maskedPhone: maskMsisdn(msisdn),
      channels: config.otp.channels,
      channelTimeoutSec: config.otp.channelTimeout,
      expiresInSec: config.otp.referenceTtlSec,
    };
  }

  /** Verifikasi kode yang dimasukkan user. */
  async verify({ reference, code }) {
    const key = this.#refKey(reference);
    const record = await this.store.get(key);

    if (!record) {
      throw new OtpError(
        OtpErrorCode.REFERENCE_NOT_FOUND,
        404,
        'Sesi verifikasi tidak ditemukan atau sudah kedaluwarsa',
      );
    }

    // Batas percobaan di sisi kita sendiri, tidak hanya mengandalkan provider.
    if (record.attempts >= config.otp.maxVerifyAttempts) {
      await this.store.del(key);
      throw new OtpError(
        OtpErrorCode.TOO_MANY_ATTEMPTS,
        429,
        'Terlalu banyak percobaan salah. Minta kode baru.',
      );
    }

    record.attempts += 1;
    await this.store.set(key, record, config.otp.referenceTtlSec);

    try {
      await this.provider.check({ requestId: record.requestId, code });
    } catch (error) {
      // Sesi mati di sisi provider -> bersihkan juga di sisi kita.
      if (
        error instanceof OtpError &&
        [OtpErrorCode.EXPIRED, OtpErrorCode.REFERENCE_NOT_FOUND].includes(error.code)
      ) {
        await this.store.del(key);
      }
      throw error;
    }

    await this.store.del(key);

    return {
      verified: true,
      msisdn: record.msisdn,
      maskedPhone: maskMsisdn(record.msisdn),
      attempts: record.attempts,
    };
  }
}
