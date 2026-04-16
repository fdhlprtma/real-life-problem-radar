<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

$pwaScriptVersion = (string) @filemtime(__DIR__ . '/assets/js/pwa.js');

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
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RLP Radar - Beranda</title>
  <meta name="description" content="Platform pelaporan masyarakat dan respons instansi pemerintah berbasis AI.">
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
  <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
  <div class="noise"></div>
  <nav class="topbar">
    <div class="topbar__inner">
      <strong>RLP Radar</strong>
      <div class="topbar__actions">
        <a href="login.php" class="btn btn-primary">Masuk</a>
      </div>
    </div>
  </nav>

  <header class="hero landing-hero">
    <div class="hero__left">
      <p class="eyebrow">Public Report Routing Platform</p>
      <h1>Ubah Keluhan Menjadi Perubahan Nyata</h1>
      <p class="hero__desc">
        RLP Radar adalah platform pelaporan masalah publik untuk masyarakat dan instansi pemerintah.
        Laporan diklasifikasikan AI, divalidasi publik, lalu ditindaklanjuti instansi sesuai wilayah tanggung jawab.
      </p>
      <div class="form-actions">
        <a class="btn btn-secondary" href="#kemampuan">Lihat Kapabilitas</a>
        <a class="btn btn-primary" href="#aksi">Jelajahi Alur Kerja</a>
      </div>
    </div>
    <div class="landing-hero__map" aria-hidden="true">
      <img class="landing-hero__map-image" src="assets/img/map.png" alt="Peta Indonesia">
    </div>
  </header>

  <main class="landing-main">
    <section class="landing-kpis reveal">
      <article class="kpi-card">
        <span>Routing Laporan</span>
        <strong>Otomatis Berbasis Wilayah</strong>
        <p>Laporan masuk langsung diarahkan ke instansi terkait sesuai cakupan tanggung jawab.</p>
      </article>
      <article class="kpi-card">
        <span>Validasi Publik</span>
        <strong>Lebih Kredibel</strong>
        <p>Proses verifikasi komunitas membantu meminimalkan laporan duplikat atau tidak valid.</p>
      </article>
      <article class="kpi-card">
        <span>Monitoring</span>
        <strong>Status Real-Time</strong>
        <p>Progress laporan dapat dipantau dari tahap masuk hingga dinyatakan selesai.</p>
      </article>
    </section>

    <section id="kemampuan" class="landing-section reveal delay-1">
      <div class="section-head">
        <p class="eyebrow">Kapabilitas Platform</p>
        <h2>Dirancang untuk Respons Publik yang Cepat, Tepat, dan Terukur</h2>
      </div>
      <div class="feature-grid">
        <article class="feature-item">
          <h3>Klasifikasi AI</h3>
          <p>Model AI membantu menentukan kategori dan urgensi awal agar triase lebih efisien.</p>
        </article>
        <article class="feature-item">
          <h3>Geotag Presisi</h3>
          <p>Titik lokasi peta membuat laporan mudah diverifikasi dan cepat ditindaklanjuti.</p>
        </article>
        <article class="feature-item">
          <h3>Verifikasi Instansi</h3>
          <p>Akun pemerintah melewati proses validasi dokumen sebelum memperoleh akses penuh.</p>
        </article>
        <article class="feature-item">
          <h3>Audit Progress</h3>
          <p>Setiap perubahan status tercatat sebagai jejak kerja untuk evaluasi layanan berkala.</p>
        </article>
      </div>
    </section>

    <section id="aksi" class="landing-section landing-split reveal delay-2">
      <article class="split-panel">
        <h3>Untuk Masyarakat</h3>
        <ul class="flow-list">
          <li>Kirim laporan dengan bukti visual dan koordinat lokasi.</li>
          <li>Dapatkan notifikasi perkembangan penanganan secara berkala.</li>
          <li>Pantau hasil tindak lanjut hingga laporan ditutup.</li>
        </ul>
      </article>
      <article class="split-panel split-panel-dark">
        <h3>Untuk Instansi</h3>
        <ul class="flow-list">
          <li>Terima laporan terstruktur berdasarkan kategori dan wilayah kerja.</li>
          <li>Kelola prioritas kasus melalui dashboard operasional terpadu.</li>
          <li>Perbarui status penanganan sebagai bentuk transparansi publik.</li>
        </ul>
      </article>
    </section>

    <section class="landing-section reveal">
      <div class="section-head">
        <p class="eyebrow">Siklus Operasional</p>
        <h2>Satu Alur End-to-End dari Aduan hingga Resolusi</h2>
      </div>
      <div class="timeline">
        <article class="timeline-item"><span>01</span>
          <p>Laporan diterima dan dianalisis otomatis oleh sistem.</p>
        </article>
        <article class="timeline-item"><span>02</span>
          <p>Admin memvalidasi lalu mendistribusikan ke instansi berwenang.</p>
        </article>
        <article class="timeline-item"><span>03</span>
          <p>Instansi memperbarui progres dan bukti tindak lanjut lapangan.</p>
        </article>
        <article class="timeline-item"><span>04</span>
          <p>Status ditutup setelah penyelesaian terverifikasi.</p>
        </article>
      </div>
    </section>

    <section class="cta-band reveal delay-1">
      <div>
        <p class="eyebrow">Mulai Sekarang</p>
        <h2>Bangun Layanan Publik yang Lebih Responsif bersama RLP Radar</h2>
      </div>
      <div class="form-actions">
        <a class="btn btn-secondary" href="#kemampuan">Lihat Kapabilitas</a>
        <a class="btn btn-primary" href="#aksi">Lihat Alur Penanganan</a>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="site-footer__inner">
      <div class="site-footer__brand">
        <strong>RLP Radar</strong>
        <p>Platform pelaporan publik untuk kolaborasi masyarakat dan instansi pemerintah secara transparan.</p>
      </div>
      <div class="site-footer__menu">
        <div>
          <h4>Platform</h4>
          <a href="#kemampuan">Kapabilitas</a>
          <a href="#aksi">Alur Kerja</a>
        </div>
        <div>
          <h4>Akses</h4>
          <a href="privacy.php">Kebijakan Privasi</a>
          <a href="terms.php">Syarat Layanan</a>
        </div>
      </div>
    </div>
    <div class="site-footer__bottom">
      <p>&copy; <?php echo date('Y'); ?> RLP Radar. Seluruh hak cipta dilindungi.</p>
    </div>
  </footer>

  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>