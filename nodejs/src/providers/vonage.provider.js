import { config } from '../config.js';
import { OtpError, OtpErrorCode } from '../lib/errors.js';

/**
 * Adapter Vonage Verify v2.
 *
 * Dokumentasi: https://developer.vonage.com/en/api/verify.v2
 *
 * Endpoint:
 *   POST   /v2/verify              -> buat request, balik request_id
 *   POST   /v2/verify/{request_id} -> cek kode
 *   DELETE /v2/verify/{request_id} -> batalkan
 *
 * Fallback antar channel ditangani Vonage lewat array `workflow`.
 * Kita TIDAK menulis timer atau state machine sendiri.
 */
export class VonageVerifyProvider {
  get name() {
    return 'vonage-verify-v2';
  }

  #authHeader() {
    const raw = `${config.vonage.apiKey}:${config.vonage.apiSecret}`;
    return `Basic ${Buffer.from(raw).toString('base64')}`;
  }

  async #call(method, path, body) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.vonage.timeoutMs);

    try {
      const response = await fetch(`${config.vonage.baseUrl}${path}`, {
        method,
        headers: {
          Authorization: this.#authHeader(),
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });

      const text = await response.text();
      let payload = null;
      if (text) {
        try {
          payload = JSON.parse(text);
        } catch {
          payload = { raw: text };
        }
      }
      return { status: response.status, payload };
    } catch (error) {
      if (error.name === 'AbortError') {
        throw new OtpError(
          OtpErrorCode.PROVIDER_UNAVAILABLE,
          504,
          'Vonage tidak merespons dalam batas waktu',
        );
      }
      throw new OtpError(
        OtpErrorCode.PROVIDER_UNAVAILABLE,
        502,
        `Gagal menghubungi Vonage: ${error.message}`,
      );
    } finally {
      clearTimeout(timer);
    }
  }

  /**
   * Vonage memakai format problem+json (RFC 7807):
   *   { "type": "https://developer.vonage.com/api-errors/verify#conflict",
   *     "title": "Conflict", "detail": "...", "instance": "..." }
   * Kita ambil slug dari `type`, fallback ke `title`.
   */
  #slug(payload) {
    const fromType = String(payload?.type || '').split('#').pop();
    const raw = fromType || payload?.title || '';
    return String(raw).toLowerCase().replace(/[\s_]+/g, '-');
  }

  /** Kirim OTP. Vonage yang mengurus urutan channel & fallback. */
  async send({ msisdn, locale }) {
    const workflow = config.otp.channels.map((channel) => ({
      channel,
      to: msisdn,
    }));

    const { status, payload } = await this.#call('POST', '', {
      brand: config.otp.brand,
      code_length: config.otp.codeLength,
      channel_timeout: config.otp.channelTimeout,
      locale: locale || config.otp.locale,
      workflow,
    });

    if (status === 202 || status === 200) {
      return {
        requestId: payload.request_id,
        checkUrl: payload.check_url ?? null, // hanya terisi untuk silent_auth
      };
    }

    const slug = this.#slug(payload);

    if (status === 409 || slug === 'conflict') {
      throw new OtpError(
        OtpErrorCode.CONCURRENT_REQUEST,
        409,
        'Masih ada permintaan OTP aktif untuk nomor ini',
      );
    }
    if (status === 429) {
      throw new OtpError(OtpErrorCode.RATE_LIMITED, 429, 'Rate limit Vonage tercapai');
    }
    if (status === 422) {
      throw new OtpError(
        OtpErrorCode.PROVIDER_ERROR,
        422,
        payload?.detail || 'Parameter ditolak Vonage',
        { slug },
      );
    }

    throw new OtpError(
      OtpErrorCode.PROVIDER_ERROR,
      502,
      payload?.detail || `Vonage mengembalikan status ${status}`,
      { slug, status },
    );
  }

  /** Cek kode. Balik { ok: true } atau lempar OtpError yang sudah dinormalkan. */
  async check({ requestId, code }) {
    const { status, payload } = await this.#call('POST', `/${requestId}`, { code });

    if (status === 200 && payload?.status === 'completed') {
      return { ok: true };
    }

    const slug = this.#slug(payload);

    if (slug === 'invalid-code' || status === 400) {
      throw new OtpError(OtpErrorCode.INVALID_CODE, 400, 'Kode verifikasi tidak cocok');
    }
    if (slug === 'request-not-found' || status === 404) {
      throw new OtpError(
        OtpErrorCode.REFERENCE_NOT_FOUND,
        404,
        'Permintaan OTP tidak ditemukan atau sudah selesai',
      );
    }
    if (slug === 'expired' || status === 410) {
      throw new OtpError(
        OtpErrorCode.EXPIRED,
        410,
        'Permintaan OTP sudah kedaluwarsa. Minta kode baru.',
      );
    }
    if (status === 409) {
      throw new OtpError(
        OtpErrorCode.TOO_MANY_ATTEMPTS,
        409,
        'Terlalu banyak percobaan salah',
      );
    }

    throw new OtpError(
      OtpErrorCode.PROVIDER_ERROR,
      502,
      payload?.detail || `Vonage mengembalikan status ${status}`,
      { slug, status },
    );
  }

  /** Batalkan request. Vonage hanya mengizinkan 30 detik setelah request dibuat. */
  async cancel({ requestId }) {
    const { status } = await this.#call('DELETE', `/${requestId}`);
    return status === 204 || status === 200;
  }
}
