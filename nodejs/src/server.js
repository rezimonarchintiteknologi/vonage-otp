import express from 'express';
import { config } from './config.js';
import { MemoryStore } from './lib/store.js';
import { VonageVerifyProvider } from './providers/vonage.provider.js';
import { OtpService } from './otp.service.js';
import { createOtpRouter, otpErrorHandler } from './routes/otp.routes.js';

const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1); // penting kalau di belakang nginx / load balancer
app.use(express.json({ limit: '16kb' }));

const otpService = new OtpService({
  provider: new VonageVerifyProvider(),
  store: new MemoryStore(), // PRODUKSI: ganti dengan RedisStore
});

app.get('/health', (_req, res) => res.json({ ok: true }));
app.use('/api/otp', createOtpRouter(otpService));
app.use(otpErrorHandler);

app.listen(config.port, () => {
  console.log(`OTP service berjalan di port ${config.port}`);
  console.log(`Channel: ${config.otp.channels.join(' -> ')} (timeout ${config.otp.channelTimeout}s)`);
});
