<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

$user = Auth::user();
if ($user !== null) {
  $role = (string) ($user['role'] ?? 'user');
  if ($role === 'admin') {
    header('Location: admin.php');
    exit;
  }
  if ($role === 'government') {
    header('Location: government.php');
    exit;
  }
  header('Location: reports.php');
  exit;
}

$styleVersion = (string) @filemtime(__DIR__ . '/assets/css/style.css');
$scriptVersion = (string) @filemtime(__DIR__ . '/assets/js/auth-pages.js');
$pwaScriptVersion = (string) @filemtime(__DIR__ . '/assets/js/pwa.js');
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk - RLP Radar</title>
  <meta name="theme-color" content="#121212">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="RLP Radar">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="assets/img/favicon.ico">
  <link rel="apple-touch-icon" href="assets/img/pwa-icon-180.png">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($styleVersion !== false ? $styleVersion : '1') ?>">
</head>

<body class="auth-page">
  <div class="noise"></div>

  <nav class="topbar">
    <div class="topbar__inner">
      <strong>RLP Radar</strong>
    </div>
  </nav>

  <main class="auth-shell">
    <section class="auth-panel panel">
      <p class="eyebrow">Akses Platform</p>
      <h1>Masuk ke Akun Anda</h1>
      <p class="panel-subtitle">Gunakan email dan password yang terdaftar untuk melanjutkan.</p>

      <form id="loginForm" class="auth-form-page" novalidate>
        <label>Email
          <input type="email" id="loginEmail" required autocomplete="email">
        </label>

        <label>Password
          <input type="password" id="loginPassword" required autocomplete="current-password">
        </label>

        <p id="loginFeedback" class="feedback"></p>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Masuk</button>
        </div>

        <p class="auth-switch-note">Belum punya akun? <a href="register.php">Daftar di sini</a>.</p>
      </form>
    </section>
  </main>

  <script src="assets/js/auth-pages.js?v=<?= urlencode($scriptVersion !== false ? $scriptVersion : '1') ?>"></script>
  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>