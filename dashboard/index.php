<?php
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
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="icons/icon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
  <link rel="icon" type="image/svg+xml" href="icons/icon-192.svg">
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
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/dashboard/sw.js', { scope: '/dashboard/' });
      });
    }
  </script>
</body>
</html>
