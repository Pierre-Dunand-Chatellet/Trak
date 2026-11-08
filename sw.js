// Trak — service worker : l'application s'ouvre sans reseau (au bord du bassin).
//
// - La page trak.html : reseau d'abord (pour recevoir les mises a jour), copie en
//   cache si le reseau ne repond pas en 4 s ou pas du tout.
// - Polices, icones, manifeste : cache d'abord (ils ne changent pas).
//   >>> Changer VERSION des qu'un de ces fichiers change, sinon l'ancien reste servi.
// - L'API (api/) n'est jamais mise en cache : la synchro reste en direct, et les
//   modifications faites hors ligne attendent dans le navigateur (file d'envoi de Trak).
const VERSION = "trak-2026-11-07";
const FICHIERS = [
  "trak.html",
  "manifest.json",
  "fonts/fonts.css",
  "fonts/Geist-normal-400-latin.woff2",
  "fonts/Geist-normal-400-latin-ext.woff2",
  "fonts/JetBrainsMono-normal-400-latin.woff2",
  "fonts/JetBrainsMono-normal-400-latin-ext.woff2",
  "fonts/Syne-normal-400-800-latin.woff2",
  "fonts/Syne-normal-400-800-latin-ext.woff2",
  "Image/favicon.svg",
  "Image/favicon-32.png",
  "Image/apple-touch-icon.png",
  "Image/icone-512.png"
];

self.addEventListener("install", (ev) => {
  ev.waitUntil(caches.open(VERSION).then((c) => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (ev) => {
  ev.waitUntil(caches.keys()
    .then((cles) => Promise.all(cles.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

function reseauAvecDelai(requete, ms) {
  return new Promise((ok, ko) => {
    const minuterie = setTimeout(() => ko(new Error("delai")), ms);
    fetch(requete).then((r) => { clearTimeout(minuterie); ok(r); }, (e) => { clearTimeout(minuterie); ko(e); });
  });
}

self.addEventListener("fetch", (ev) => {
  const req = ev.request;
  const url = new URL(req.url);
  if (req.method !== "GET" || url.origin !== self.location.origin || url.pathname.includes("/api/")) return;

  // La page de l'application (avec ou sans ?v=...) : reseau d'abord.
  if (url.pathname.endsWith("/trak.html")) {
    ev.respondWith(
      reseauAvecDelai(req, 4000)
        .then((r) => {
          if (r.ok) { const copie = r.clone(); caches.open(VERSION).then((c) => c.put("trak.html", copie)); }
          return r;
        })
        .catch(() => caches.match("trak.html"))
    );
    return;
  }

  // Le reste : cache d'abord, reseau sinon (sans rien ajouter au cache).
  ev.respondWith(caches.match(req, { ignoreSearch: true }).then((r) => r || fetch(req)));
});
