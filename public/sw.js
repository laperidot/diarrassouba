/* Service worker DIARRASSOUBA — stratégie prudente :
 * - les pages et données privées ne sont JAMAIS mises en cache (réseau uniquement) ;
 * - seuls les fichiers statiques publics (CSS, JS, icônes, polices, page hors ligne) le sont ;
 * - hors connexion, une page dédiée explique la situation ; aucune opération n'est simulée.
 */
const VERSION = 'di-v2.3.0';
const STATIC = VERSION + '-static';
const scope = self.registration.scope; // se termine par /
const PRECACHE = ['offline.html', 'assets/css/app.css', 'assets/js/app.js', 'assets/js/public.js', 'assets/js/theme.js', 'assets/icons/icon.svg', 'assets/icons/icon-192.png', 'manifest.webmanifest'].map((p) => new URL(p, scope).toString());

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('message', (e) => { if (e.data === 'skipWaiting') self.skipWaiting(); });

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // POST : jamais intercepté
  const url = new URL(req.url);

  // Polices Google : cache d'abord (fichiers publics et immuables)
  if (url.hostname === 'fonts.gstatic.com' || url.hostname === 'fonts.googleapis.com') {
    e.respondWith(caches.open(VERSION + '-fonts').then(async (c) => {
      const hit = await c.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok || res.type === 'opaque') c.put(req, res.clone());
      return res;
    }));
    return;
  }
  if (url.origin !== self.location.origin) return;

  // Fichiers statiques publics. Leur URL porte la version (?v=…) : même version -> cache ; nouvelle version -> réseau.
  // Une version différente n'est servie depuis le cache qu'en secours, hors connexion (sinon page neuve + ancien CSS).
  if (url.pathname.includes('/assets/')) {
    e.respondWith(caches.open(STATIC).then(async (c) => {
      const exact = await c.match(req);
      if (exact) return exact;
      try {
        const res = await fetch(req);
        if (res.ok) c.put(req, res.clone());
        return res;
      } catch (err) {
        return (await c.match(req, { ignoreSearch: true })) || Response.error();
      }
    }));
    return;
  }

  // Navigation : réseau uniquement, page hors ligne en secours (aucune page privée en cache)
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(new URL('offline.html', scope).toString())));
  }
  // Tout le reste (API, PDF, pièces jointes) : comportement réseau normal, sans cache.
});
