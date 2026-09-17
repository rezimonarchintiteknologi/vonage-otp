import 'dotenv/config';

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Env var wajib belum diisi: ${name}`);
  return value;
}

function list(name, fallback) {
  return (process.env[name] || fallback)
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

export const config = {
  port: Number(process.env.PORT || 3000),

  vonage: {
    apiKey: required('VONAGE_API_KEY'),
    apiSecret: required('VONAGE_API_SECRET'),
    baseUrl: 'https://api.nexmo.com/v2/verify',
    timeoutMs: Number(process.env.VONAGE_TIMEOUT_MS || 10000),
  },

  otp: {
    brand: process.env.OTP_BRAND || 'MyApp',
    codeLength: Number(process.env.OTP_CODE_LENGTH || 6),
    channelTimeout: Number(process.env.OTP_CHANNEL_TIMEOUT || 30),
    locale: process.env.OTP_LOCALE || 'id-id',
    channels: list('OTP_CHANNELS', 'whatsapp,sms').slice(0, 3),
    referenceTtlSec: Number(process.env.OTP_REFERENCE_TTL || 600),
    maxVerifyAttempts: Number(process.env.OTP_MAX_VERIFY_ATTEMPTS || 5),
  },

  phone: {
    allowedPrefixes: list('PHONE_ALLOWED_PREFIXES', '62'),
    defaultCountryCode: process.env.PHONE_DEFAULT_COUNTRY_CODE || '62',
  },

  rateLimit: {
    perPhone: {
      max: Number(process.env.RL_PHONE_MAX || 3),
      windowSec: Number(process.env.RL_PHONE_WINDOW || 900),
    },
    perIp: {
      max: Number(process.env.RL_IP_MAX || 10),
      windowSec: Number(process.env.RL_IP_WINDOW || 900),
    },
  },
};
