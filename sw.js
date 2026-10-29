/* Service worker del Cotizador.
 * - Paginas PHP: siempre desde la red (datos al dia); sin conexion muestra offline.html.
 * - CSS, JS, iconos y librerias: se guardan en cache para cargar rapido.
 */
const VERSION = 'cotizador-v29';
const ESTATICOS = [
  'offline.html',
  'assets/css/tailwind.css',
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/js/chat.js',
  'assets/vendor/lucide.min.js',
  'assets/icons/icono-192.png',
  'assets/icons/icono-512.png'
];

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(VERSION).then((c) => c.addAll(ESTATICOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(
    caches.keys()
      .then((claves) => Promise.all(claves.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Navegacion (paginas): red primero, pagina sin conexion como respaldo
  if (req.mode === 'navigate') {
    ev.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }

  // Recursos estaticos versionados: cache primero y se actualiza en segundo plano
  if (/\/assets\//.test(url.pathname)) {
    ev.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(req).then((guardado) => {
          const red = fetch(req).then((resp) => {
            if (resp.ok) cache.put(req, resp.clone());
            return resp;
          }).catch(() => guardado);
          return guardado || red;
        })
      )
    );
  }
  // Todo lo demas (PDF, adjuntos, eventos.php) va directo a la red
});
