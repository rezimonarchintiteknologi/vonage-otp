import { Router } from 'express';
import { OtpError } from '../lib/errors.js';

export function createOtpRouter(otpService) {
  const router = Router();

  const clientIp = (req) =>
    (req.headers['x-forwarded-for'] || '').split(',')[0].trim() || req.ip;

  router.post('/request', async (req, res, next) => {
    try {
      const { phone, locale } = req.body ?? {};
      if (!phone) {
        return res.status(422).json({ error: { code: 'INVALID_PHONE', message: 'phone wajib diisi' } });
      }
      const result = await otpService.request({ phone, locale, ip: clientIp(req) });
      res.status(202).json({ data: result });
    } catch (error) {
      next(error);
    }
  });

  router.post('/verify', async (req, res, next) => {
    try {
      const { reference, code } = req.body ?? {};
      if (!reference || !code) {
        return res
          .status(422)
          .json({ error: { code: 'VALIDATION_ERROR', message: 'reference dan code wajib diisi' } });
      }
      const result = await otpService.verify({ reference, code: String(code) });
      res.json({ data: result });
    } catch (error) {
      next(error);
    }
  });

  return router;
}

export function otpErrorHandler(error, req, res, next) {
  if (res.headersSent) return next(error);

  if (error instanceof OtpError) {
    // Jangan pernah log `code` OTP. Log identitas request saja.
    console.warn('[otp]', error.code, error.message, error.meta);
    return res.status(error.httpStatus).json({
      error: { code: error.code, message: error.message, ...error.meta },
    });
  }

  console.error('[otp] unexpected', error);
  return res.status(500).json({ error: { code: 'INTERNAL_ERROR', message: 'Terjadi kesalahan internal' } });
}
