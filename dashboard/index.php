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
</body>
</html>
