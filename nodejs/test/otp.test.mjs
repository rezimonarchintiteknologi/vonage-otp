import { config } from '../src/config.js';
import { normalizeMsisdn, maskMsisdn } from '../src/lib/phone.js';
import { MemoryStore } from '../src/lib/store.js';
import { OtpService } from '../src/otp.service.js';
import { OtpError, OtpErrorCode } from '../src/lib/errors.js';

let pass = 0, fail = 0;
const t = (name, fn) => { try { fn(); console.log('  ok  ', name); pass++; } catch (e) { console.log('  FAIL', name, '->', e.message); fail++; } };
const eq = (a, b) => { if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error(`${JSON.stringify(a)} != ${JSON.stringify(b)}`); };

console.log('\n[phone normalizer]');
t('0812 lokal -> 62', () => eq(normalizeMsisdn('081234567890', config.phone).msisdn, '6281234567890'));
t('+62 dengan spasi', () => eq(normalizeMsisdn('+62 812 3456 7890', config.phone).msisdn, '6281234567890'));
t('dash dan kurung', () => eq(normalizeMsisdn('(0812) 3456-7890', config.phone).msisdn, '6281234567890'));
t('prefix dial 00 tidak dobel', () => eq(normalizeMsisdn('0062812345678', config.phone).msisdn, '62812345678'));
t('nomor luar negeri ditolak', () => eq(normalizeMsisdn('+14155552671', config.phone).rejected, 'COUNTRY_NOT_ALLOWED'));
t('terlalu pendek', () => eq(normalizeMsisdn('12345', config.phone), null));
t('mask', () => eq(maskMsisdn('628123456789'), '6281****6789'));

console.log('\n[otp service - happy path]');
const mock = {
  name: 'mock',
  sent: [],
  async send({ msisdn }) { this.sent.push(msisdn); return { requestId: 'req-123' }; },
  async check({ code }) { if (code === '123456') return { ok: true }; throw new OtpError(OtpErrorCode.INVALID_CODE, 400, 'salah'); },
};
const svc = new OtpService({ provider: mock, store: new MemoryStore() });

const run = async () => {
  const r = await svc.request({ phone: '081234567890', ip: '1.2.3.4' });
  t('reference dibuat', () => { if (!r.reference || r.reference.length !== 32) throw new Error('bad ref: ' + r.reference); });
  t('request_id tidak bocor ke client', () => { if (JSON.stringify(r).includes('req-123')) throw new Error('bocor!'); });
  t('masked phone', () => eq(r.maskedPhone, '6281****7890'));
  t('workflow channels', () => eq(r.channels, ['whatsapp','sms']));

  t('msisdn dikirim tanpa plus', () => eq(mock.sent[0], '6281234567890'));

  console.log('\n[otp service - verify]');
  try { await svc.verify({ reference: r.reference, code: '000000' }); t('kode salah harus gagal', () => { throw new Error('tidak throw'); }); }
  catch (e) { t('kode salah -> INVALID_CODE', () => eq(e.code, 'INVALID_CODE')); }

  const ok = await svc.verify({ reference: r.reference, code: '123456' });
  t('kode benar -> verified', () => eq(ok.verified, true));

  try { await svc.verify({ reference: r.reference, code: '123456' }); t('reference sekali pakai', () => { throw new Error('masih bisa dipakai ulang'); }); }
  catch (e) { t('reference dihapus setelah sukses', () => eq(e.code, 'REFERENCE_NOT_FOUND')); }

  console.log('\n[rate limit per nomor]');
  const svc2 = new OtpService({ provider: mock, store: new MemoryStore() });
  for (let i = 0; i < 3; i++) await svc2.request({ phone: '081299998888', ip: '9.9.9.9' });
  try { await svc2.request({ phone: '081299998888', ip: '9.9.9.9' }); t('limit ke-4 harus ditolak', () => { throw new Error('tidak ditolak'); }); }
  catch (e) { t('permintaan ke-4 -> RATE_LIMITED', () => eq(e.code, 'RATE_LIMITED')); }

  console.log('\n[anti SMS pumping]');
  try { await svc.request({ phone: '+14155552671', ip: '1.1.1.1' }); t('nomor asing harus ditolak', () => { throw new Error('lolos'); }); }
  catch (e) { t('nomor non-62 -> COUNTRY_NOT_ALLOWED', () => eq(e.code, 'COUNTRY_NOT_ALLOWED')); }

  console.log(`\n=== ${pass} lulus, ${fail} gagal ===`);
  process.exit(fail ? 1 : 0);
};
run();
