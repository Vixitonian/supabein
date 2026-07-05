<?php
// The HTML shell must never be cached: it carries the ?v= asset versions
// below, so a stale shell pins the browser (and the cache-first service
// worker) to old asset versions and silently defeats the whole cache-bust
// scheme — a deployed fix then never reaches the user no matter how many
// times they reload. no-store keeps the shell always-fresh; the versioned
// assets it points at stay long-cacheable and immutable as before.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dir = __DIR__ . '/assets/';
$css = filemtime($dir . 'app.css');
$js  = filemtime($dir . 'app.js');
$rjs = filemtime($dir . 'router.js');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SupaBein</title>
  <meta name="theme-color" content="#3ecf8e">
  <meta name="description" content="Self-hosted BaaS — manage your projects, tables, and site deployments.">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SupaBein">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" type="image/svg+xml" href="icons/icon-192.svg">
  <link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="icons/icon-32.png">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="icons/icon-192.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css?v=<?= $css ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
</head>
<body>
  <div id="app">
    <div style="display:flex;align-items:center;justify-content:center;height:100vh;color:#8892a4">Loading…</div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
  <script src="assets/router.js?v=<?= $rjs ?>"></script>
  <script src="assets/app.js?v=<?= $js ?>"></script>
  <script>
    if ('serviceWorker' in navigator) {
      // Auto-reload once when a new service worker takes control, so deploys
      // that ship a new sw.js are picked up without manual cache clearing.
      var sbReloading = false;
      navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (sbReloading) return;
        sbReloading = true;
        window.location.reload();
      });
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/dashboard/sw.js', { scope: '/dashboard/', updateViaCache: 'none' })
          .then(function (reg) {
            // Force an update check on every load so a changed sw.js installs promptly.
            reg.update();
            if (reg.waiting && navigator.serviceWorker.controller) {
              // A new worker is already waiting — activate it now.
              reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
            reg.addEventListener('updatefound', function () {
              var nw = reg.installing;
              if (!nw) return;
              nw.addEventListener('statechange', function () {
                if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                  nw.postMessage({ type: 'SKIP_WAITING' });
                }
              });
            });
          });
      });
    }
  </script>
</body>
</html>
