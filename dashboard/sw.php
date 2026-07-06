<?php
// Served (via .htaccess rewrite) at the same URL the service worker has
// always been registered under, /dashboard/sw.js — so no client-side
// registration change is needed and every previously-installed worker just
// picks this up as its next update.
//
// CACHE_NAME used to be a hand-maintained string bumped manually whenever a
// client-visible fix needed to reach already-installed clients — easy to
// forget (a real deploy shipped exactly that: the "Rows" column landed in
// app.js, the string constant never got bumped, and the installed worker's
// cache-first handler kept serving the old cached app.js indefinitely, since
// its own byte content — the only thing a browser compares to decide there's
// "an update" — hadn't changed either). Deriving it from the assets' own
// mtimes instead makes it automatic and impossible to forget: any deploy that
// changes a precached/versioned asset changes this file's output, which is
// exactly what makes a browser see it as a new worker and run the normal
// install/activate cycle (whose activate handler deletes every cache whose
// name != CACHE_NAME).
header('Content-Type: text/javascript; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$dir = __DIR__ . '/assets/';
$version = max(
    @filemtime($dir . 'app.js') ?: 0,
    @filemtime($dir . 'app.css') ?: 0,
    @filemtime($dir . 'router.js') ?: 0,
    @filemtime(__DIR__ . '/manifest.json') ?: 0
);
$cacheName = 'supabein-v' . $version;
?>
'use strict';

const CACHE_NAME = <?= json_encode($cacheName) ?>;

const PRECACHE_ASSETS = [
  '/dashboard/',
  '/dashboard/assets/app.js',
  '/dashboard/assets/router.js',
  '/dashboard/assets/app.css',
  '/dashboard/manifest.json',
];

// Allow the page to tell a waiting worker to activate immediately.
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Network-only for API requests — never serve stale API data
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request).catch(() =>
        new Response(
          JSON.stringify({ error: 'You are offline. Please check your connection.' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        )
      )
    );
    return;
  }

  // Cache-first for static assets (JS, CSS, icons, fonts)
  if (
    url.pathname.startsWith('/dashboard/assets/') ||
    url.pathname.startsWith('/dashboard/icons/')
  ) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          return response;
        });
      })
    );
    return;
  }

  // Network-first for dashboard navigation (HTML) — always pick up the latest
  // deploy on the first reload; the HTML carries the ?v= asset versions, so a
  // stale shell would otherwise keep loading stale JS/CSS. Fall back to cache
  // only when offline.
  if (url.pathname.startsWith('/dashboard')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          return response;
        })
        .catch(() =>
          caches.match(request).then((cached) => cached || caches.match('/dashboard/'))
        )
    );
    return;
  }
});
