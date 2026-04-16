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
  <title>Daftar - RLP Radar</title>
  <meta name="theme-color" content="#121212">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="RLP Radar">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="manifest" href="manifest.json">
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
    <section class="auth-panel panel auth-panel--wide">
      <p class="eyebrow">Registrasi Akun</p>
      <h1>Buat Akun Baru</h1>
      <p class="panel-subtitle">Pilih tipe akun lalu lengkapi data sesuai kebutuhan.</p>

      <form id="registerForm" class="auth-form-page" enctype="multipart/form-data" novalidate>
        <label>Tipe Akun
          <select id="accountType" required>
            <option value="citizen">Masyarakat</option>
            <option value="government">Pemerintah</option>
          </select>
        </label>

        <div id="citizenFields">
          <label>Nama
            <input type="text" id="citizenName" autocomplete="name">
          </label>
        </div>

        <div id="governmentFields" class="hidden gov-fields">
          <h4>Informasi Instansi</h4>
          <label>Nama Instansi
            <input type="text" id="agencyName" placeholder="Contoh: Dinas PUPR Kota Makassar">
          </label>
          <label>Jenis Instansi
            <select id="agencyType">
              <option value="">Pilih jenis</option>
              <option value="Pemerintah Pusat">Pemerintah Pusat</option>
              <option value="Pemerintah Provinsi">Pemerintah Provinsi</option>
              <option value="Pemerintah Kota/Kabupaten">Pemerintah Kota/Kabupaten</option>
              <option value="Kecamatan">Kecamatan</option>
              <option value="Kelurahan/Desa">Kelurahan/Desa</option>
            </select>
          </label>
          <label>Bidang
            <input type="text" id="agencySector" placeholder="Contoh: Infrastruktur">
          </label>

          <h4>Wilayah Tanggung Jawab</h4>
          <div class="grid-two">
            <label>Provinsi
              <select id="regionProvince">
                <option value="">Pilih provinsi...</option>
              </select>
            </label>
            <label>Kabupaten/Kota
              <select id="regionCity" disabled>
                <option value="">Pilih kabupaten/kota...</option>
              </select>
            </label>
            <label>Kecamatan
              <select id="regionDistrict" disabled>
                <option value="">Pilih kecamatan...</option>
              </select>
            </label>
            <label>Kelurahan/Desa
              <select id="regionSubdistrict" disabled>
                <option value="">Pilih kelurahan/desa...</option>
              </select>
            </label>
          </div>

          <h4>Data Penanggung Jawab</h4>
          <label>Nama Lengkap<input type="text" id="officerName"></label>
          <label>Jabatan<input type="text" id="officerPosition"></label>
          <label>NIP<input type="text" id="officerNip"></label>
          <label>Nomor HP Aktif<input type="text" id="officerPhone"></label>
          <label>Dokumen Instansi (PDF/JPG/PNG/WEBP)
            <input type="file" id="governmentDocument" accept="application/pdf,image/jpeg,image/png,image/webp">
          </label>
          <p class="gov-doc-hint">
            Wajib upload salah satu dokumen resmi: SK penugasan, surat mandat instansi, atau kartu identitas pegawai/dinas.
            Format yang diterima: PDF, JPG, PNG, WEBP.
          </p>

          <label class="checkbox-row"><input type="checkbox" id="declarationDataTrue"> Saya menyatakan data ini benar</label>
          <label class="checkbox-row"><input type="checkbox" id="declarationFollowup"> Saya bersedia menindaklanjuti laporan masyarakat</label>
        </div>

        <label>Email
          <input type="email" id="registerEmail" required autocomplete="email">
        </label>

        <label>Password
          <input type="password" id="registerPassword" required autocomplete="new-password">
        </label>

        <label>Konfirmasi Password
          <input type="password" id="registerConfirmPassword" required autocomplete="new-password">
        </label>

        <p id="registerFeedback" class="feedback"></p>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Daftar</button>
        </div>

        <p class="auth-switch-note">Sudah punya akun? <a href="login.php">Masuk di sini</a>.</p>
      </form>
    </section>
  </main>

  <script src="assets/js/auth-pages.js?v=<?= urlencode($scriptVersion !== false ? $scriptVersion : '1') ?>"></script>
  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>