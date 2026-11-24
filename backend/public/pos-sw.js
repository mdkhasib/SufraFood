/*
 * Sufra GroupOps — POS service worker (scope /pos).
 * Keeps a copy of the POS screen and its styles/scripts so the POS opens again
 * even when the server / network is down. Bills made offline are synced by the page.
 */
const CACHE = 'sufra-pos-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (k !== CACHE) await caches.delete(k);
  await self.clients.claim();
})()));

const ASSET_HOSTS = /(^|\.)cdn\.tailwindcss\.com$|(^|\.)cdn\.jsdelivr\.net$|(^|\.)fonts\.googleapis\.com$|(^|\.)fonts\.gstatic\.com$|(^|\.)cdnjs\.cloudflare\.com$/;

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const u = new URL(req.url);
  const same = u.origin === self.location.origin;
  const isPos = same && u.pathname === '/pos';
  const isAsset = ASSET_HOSTS.test(u.hostname) || (same && (/^\/js\//.test(u.pathname) || /\.(png|jpe?g|svg|ico|webp|css|woff2?)$/.test(u.pathname)));
  if (!isPos && !isAsset) return;

  e.respondWith((async () => {
    const cache = await caches.open(CACHE);
    try {
      const res = await fetch(req);
      // keep the POS page only when it really is the POS (not a login redirect / error)
      if (isPos ? (res.ok && !res.redirected) : (res.ok || res.type === 'opaque')) await cache.put(req, res.clone());
      return res;
    } catch (err) {
      const hit = await cache.match(req, { ignoreSearch: isPos });
      if (hit) return hit;
      if (isPos) return new Response('<meta charset="utf-8"><h2 style="font-family:sans-serif">POS is offline</h2><p style="font-family:sans-serif">Open the POS once while the server is reachable (with your shift open). After that it opens without network too.</p>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      throw err;
    }
  })());
});
