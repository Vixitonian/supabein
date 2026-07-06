<?php
// Served (via .htaccess rewrite) at the same URL the service worker has
// always been registered under, /dashboard/sw.js — so no client-side
// registration change is needed and every previously-installed worker just
// picks this up as its next update.
//
// CACHE_NAME is derived from the assets' own content hashes (shared with
// index.php's ?v= params via assets-version.php) so this file's own bytes
// change whenever a deploy changes so much as one byte of app.js/app.css/
// router.js/manifest.json — which is what makes a browser see it as a new
// worker and run the install/activate cycle (whose activate handler deletes
// every cache whose name != CACHE_NAME).
//
// This worker deliberately does NOT cache-first (or cache at all) JS/CSS/
// icons — it used to, and that was the wrong call twice over: a hand-
// maintained CACHE_NAME string once went un-bumped and pinned clients to a
// stale app.js indefinitely (fixed by deriving it automatically), and later
// a real user still saw stale content even with automatic content-hash
// versioning, because the SW's OWN update-detection is a whole separate
// asynchronous dance (install → activate → clients.claim → controllerchange
// → reload) that depends on the browser getting around to checking at all,
// on cache.addAll succeeding for every precached URL, and generally on a lot
// of moving parts a simple asset load doesn't need. The versioned ?v= URLs
// already make plain HTTP caching completely safe on their own (confirmed
// live: app.css serves Cache-Control: public, max-age=604800 under its
// hash-keyed URL) — a normal browser cache always fetches a new ?v= URL
// fresh the instant a deploy changes it, with none of a service worker's
// extra failure modes. Removing the redundant SW-level cache for these
// paths removes the entire bug class rather than trying to make its update
// detection bulletproof a third time.
require_once __DIR__ . '/assets-version.php';

header('Content-Type: text/javascript; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$dir = __DIR__ . '/assets/';
$version = substr(md5(
    sb_file_hash($dir . 'app.js') .
    sb_file_hash($dir . 'app.css') .
    sb_file_hash($dir . 'router.js') .
    sb_file_hash(__DIR__ . '/manifest.json')
), 0, 10);
$cacheName = 'supabein-v' . $version;
?>
'use strict';

const CACHE_NAME = <?= json_encode($cacheName) ?>;

// Only the shell HTML — the offline navigation fallback below reads this.
// JS/CSS/icons are never cache-first'd (see the header comment), so
// precaching them here would just be dead weight nothing ever reads.
const PRECACHE_ASSETS = [
  '/dashboard/',
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

  // JS/CSS/icons under /dashboard/assets/ and /dashboard/icons/ are deliberately
  // NOT intercepted here — no event.respondWith() means the browser handles
  // them exactly as it would with no service worker at all, via its normal
  // HTTP cache honoring the origin's Cache-Control/ETag (see header comment).

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
