/**
 * Store sederhana untuk development.
 *
 * PRODUKSI: ganti dengan Redis. Interface-nya sengaja dibuat kecil
 * (get / set / del / incr) supaya penggantiannya cuma satu file.
 * In-memory store TIDAK aman untuk multi-instance: tiap instance
 * punya state sendiri, jadi rate limit dan reference akan bocor.
 */
export class MemoryStore {
  #map = new Map();

  async get(key) {
    const entry = this.#map.get(key);
    if (!entry) return null;
    if (entry.expiresAt <= Date.now()) {
      this.#map.delete(key);
      return null;
    }
    return entry.value;
  }

  async set(key, value, ttlSec) {
    this.#map.set(key, { value, expiresAt: Date.now() + ttlSec * 1000 });
  }

  async del(key) {
    this.#map.delete(key);
  }

  /** Increment counter, TTL hanya diset saat counter pertama kali dibuat. */
  async incr(key, ttlSec) {
    const existing = this.#map.get(key);
    const alive = existing && existing.expiresAt > Date.now();
    const next = (alive ? existing.value : 0) + 1;
    this.#map.set(key, {
      value: next,
      expiresAt: alive ? existing.expiresAt : Date.now() + ttlSec * 1000,
    });
    return next;
  }
}
