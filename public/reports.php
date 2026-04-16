<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

$user = Auth::user();
if ($user === null || ($user['role'] ?? '') !== 'user') {
  header('Location: index.php');
  exit;
}

$styleVersion = (string) @filemtime(__DIR__ . '/assets/css/style.css');
$appJsVersion = (string) @filemtime(__DIR__ . '/assets/js/app.js');
$pwaScriptVersion = (string) @filemtime(__DIR__ . '/assets/js/pwa.js');
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RLP Radar - Dashboard Masyarakat</title>
  <link rel="icon" href="assets/img/favicon.ico">
  <meta name="description" content="Feed laporan publik, peta, dan fitur pelaporan masyarakat.">
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($styleVersion !== false ? $styleVersion : '1') ?>">
</head>

<body class="citizen-ui">
  <div class="noise"></div>

  <div class="mobile-menu-bar" aria-hidden="true">
    <button id="mobileMenuBtn" class="mobile-menu-btn" type="button" aria-label="Buka menu">
      ☰
    </button>
    <strong>Dashboard Masyarakat</strong>
  </div>
  <div id="mobileMenuOverlay" class="mobile-menu-overlay"></div>

  <main class="dash-shell citizen-shell">
    <aside class="dash-sidebar panel">
      <div class="sidebar-session-row">
        <span id="authState">Memuat sesi...</span>
      </div>
      <p class="eyebrow">Menu Masyarakat</p>
      <button class="side-btn is-active" data-view="feedView">Feed Laporan</button>
      <button class="side-btn" data-view="createView">Buat Laporan</button>
      <button class="side-btn" data-view="myReportsView">Laporan Saya</button>
      <button class="side-btn side-btn--alert" data-view="rejectedReportsView">Laporan Tertolak</button>
      <button class="side-btn side-btn--notif" data-view="notificationsView">
        Notifikasi <span id="notifUnreadCount" class="notif-badge">0</span>
      </button>
      <button class="side-btn" data-view="settingsView">Pengaturan</button>
      <button class="side-btn" data-view="provinceView">Daftar Provinsi</button>
      <button class="side-btn" data-view="mapView">Peta & Heatmap</button>
      <div class="sidebar-footer">
        <button id="logoutBtn" class="btn btn-primary" type="button">Logout</button>
      </div>
    </aside>

    <section class="dash-content">
      <section id="feedView" class="panel dash-view active">
        <div class="list-head">
          <h2>Feed Laporan Terbaru</h2>
        </div>
        <div class="hero__stats mini-stats">
          <div class="stat-card"><span>Total Laporan</span><strong id="statTotal">0</strong></div>
          <div class="stat-card"><span>Urgensi Critical</span><strong id="statCritical">0</strong></div>
          <div class="stat-card"><span>Konfirmasi Publik</span><strong id="statConfirm">0</strong></div>
        </div>
        <label>Filter Provinsi
          <select id="provinceFilter">
            <option value="all">Semua Provinsi</option>
          </select>
        </label>
        <div id="reportsList" class="report-list"></div>
      </section>

      <section id="createView" class="panel dash-view hidden">
        <h2>Buat Laporan Baru</h2>
        <p class="panel-subtitle">Isi wilayah untuk mendukung auto-routing ke instansi yang tepat.</p>
        <form id="reportForm" enctype="multipart/form-data">
          <div class="grid-two">
            <label>Judul Masalah
              <input type="text" name="title" required>
            </label>
            <label>Kategori
              <select name="category_user" required>
                <option value="banjir">Banjir</option>
                <option value="jalan_rusak">Jalan Rusak</option>
                <option value="sampah">Sampah</option>
                <option value="kriminalitas">Kriminalitas</option>
                <option value="kemacetan">Kemacetan</option>
                <option value="listrik">Listrik</option>
                <option value="lainnya" selected>Lainnya</option>
              </select>
            </label>
          </div>

          <label>Deskripsi
            <textarea name="description" rows="4" required></textarea>
          </label>

          <div class="grid-two">
            <label>Provinsi
              <select name="province" id="provinceSelect">
                <option value="">Pilih provinsi...</option>
              </select>
            </label>
            <label>Kabupaten/Kota
              <select name="city" id="citySelect" disabled>
                <option value="">Pilih kabupaten/kota...</option>
              </select>
            </label>
            <label>Kecamatan
              <select name="district" id="districtSelect" disabled>
                <option value="">Pilih kecamatan...</option>
              </select>
            </label>
            <label>Kelurahan/Desa
              <select name="subdistrict" id="subdistrictSelect" disabled>
                <option value="">Pilih kelurahan/desa...</option>
              </select>
            </label>
          </div>

          <input type="hidden" name="latitude" id="latitudeInput" required>
          <input type="hidden" name="longitude" id="longitudeInput" required>

          <div class="create-map-box">
            <div class="create-map-box__head">
              <strong>Pilih Titik Lokasi di Peta</strong>
              <small>Klik peta untuk isi koordinat dan deteksi wilayah otomatis.</small>
            </div>
            <div class="create-map-search">
              <input id="mapLocationSearchInput" type="text" placeholder="Cari lokasi di peta..." list="mapLocationSuggestionList">
              <datalist id="mapLocationSuggestionList"></datalist>
              <button id="mapLocationSearchBtn" class="btn btn-secondary" type="button">Cari</button>
            </div>
            <div id="createReportMap"></div>
          </div>

          <label>Upload Foto/Video (maks. 5 file)
            <input type="file" name="media[]" accept="image/jpeg,image/png,image/webp,video/mp4" multiple>
          </label>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Kirim Laporan + Analisis AI</button>
            <button type="button" id="useMapCenterBtn" class="btn btn-secondary">Gunakan Titik Tengah Peta</button>
            <button type="button" id="useCurrentLocationBtn" class="btn btn-secondary">Ambil Lokasi Terkini</button>
          </div>
          <p class="meta" id="currentLocationFeedback"></p>
        </form>
        <div class="feedback" id="formFeedback"></div>
      </section>

      <section id="myReportsView" class="panel dash-view hidden">
        <div class="list-head">
          <h2>Laporan Saya</h2>
        </div>
        <p class="panel-subtitle">Daftar semua laporan yang Anda kirim. Anda dapat menghapus laporan dari sini.</p>
        <div id="myReportsList" class="report-list"></div>
      </section>

      <section id="rejectedReportsView" class="panel dash-view hidden">
        <div class="list-head">
          <h2>Laporan Tertolak</h2>
        </div>
        <p class="panel-subtitle">
          Laporan di sini ditolak otomatis karena bukti visual tidak sesuai deskripsi. Laporan tidak hilang, cukup ganti foto yang relevan lalu kirim ulang.
        </p>
        <div id="rejectedReportsList" class="report-list"></div>
      </section>

      <section id="notificationsView" class="panel dash-view hidden">
        <div class="list-head">
          <h2>Notifikasi</h2>
          <button id="markNotifReadBtn" class="btn btn-secondary" type="button">Tandai Semua Dibaca</button>
        </div>
        <p class="panel-subtitle">Anda akan menerima notifikasi saat laporan Anda dikomentari atau divalidasi publik.</p>
        <div id="notificationsList" class="notification-list"></div>
      </section>

      <section id="settingsView" class="panel dash-view hidden">
        <div class="list-head">
          <h2>Pengaturan Akun</h2>
        </div>
        <p class="panel-subtitle">Ubah nama, email, dan password akun masyarakat Anda.</p>

        <div class="settings-grid">
          <form id="profileSettingsForm" class="settings-card">
            <h3>Profil</h3>
            <label>Nama
              <input id="settingsNameInput" name="name" type="text" required>
            </label>
            <label>Email (Gmail)
              <input id="settingsEmailInput" name="email" type="email" required>
            </label>
            <div class="form-actions">
              <button class="btn btn-primary" type="submit">Simpan Profil</button>
            </div>
            <p id="profileSettingsFeedback" class="feedback"></p>
          </form>

          <form id="passwordSettingsForm" class="settings-card">
            <h3>Password</h3>
            <label>Password Saat Ini
              <input name="current_password" type="password" required>
            </label>
            <label>Password Baru
              <input name="new_password" type="password" minlength="6" required>
            </label>
            <label>Konfirmasi Password Baru
              <input name="confirm_password" type="password" minlength="6" required>
            </label>
            <div class="form-actions">
              <button class="btn btn-primary" type="submit">Ubah Password</button>
            </div>
            <p id="passwordSettingsFeedback" class="feedback"></p>
          </form>

          <div class="settings-card">
            <h3>Tampilan</h3>
            <label>Mode Tema
              <select id="themeSelector">
                <option value="light">Mode Terang</option>
                <option value="dark">Mode Gelap</option>
              </select>
            </label>
            <p class="settings-card__hint">Pilih tema tampilan sesuai preferensi Anda.</p>
          </div>
        </div>
      </section>

      <section id="provinceView" class="panel dash-view hidden">
        <h2>List Provinsi dan Laporan</h2>
        <div id="provinceList" class="report-list"></div>
      </section>

      <section id="mapView" class="panel dash-view hidden">
        <div class="map-toolbar">
          <h2>Peta Interaktif Laporan</h2>
        </div>
        <div id="map"></div>

        <div class="predictor">
          <h3>Prediksi Risiko Area</h3>
          <p>Prediksi berbasis pola laporan terbaru pada area sekitar pusat peta.</p>
          <button id="predictRiskBtn" class="btn btn-primary">Prediksi 1-2 Hari</button>
          <div id="predictionResult" class="prediction-result"></div>
        </div>
      </section>
    </section>
  </main>

  <!-- Modal Komentar -->
  <div id="commentModal" class="comment-modal" role="dialog" aria-modal="true" aria-labelledby="commentModalTitle">
    <div class="comment-modal__backdrop" onclick="closeCommentModal()"></div>
    <div class="comment-modal__panel">
      <div class="comment-modal__header">
        <h3 id="commentModalTitle">Komentar Laporan</h3>
        <button class="comment-modal__close" onclick="closeCommentModal()" type="button" aria-label="Tutup">✕</button>
      </div>
      <div class="comment-modal__body">
        <div id="commentModalList" class="comment-modal__list"></div>
      </div>
      <div class="comment-modal__footer">
        <textarea id="commentModalInput" class="comment-modal__input" placeholder="Tulis komentar... (maks. 500 kata)" rows="3"></textarea>
        <div class="comment-form-footer">
          <span class="comment-word-count" id="commentModalWc">0 / 500 kata</span>
          <button class="btn btn-primary btn-sm" type="button" onclick="submitModalComment()">Kirim</button>
        </div>
        <p class="comment-feedback" id="commentModalFeedback"></p>
      </div>
    </div>
  </div>

  <div id="resubmitModal" class="comment-modal" role="dialog" aria-modal="true" aria-labelledby="resubmitModalTitle">
    <div class="comment-modal__backdrop" onclick="closeResubmitModal()"></div>
    <div class="comment-modal__panel resubmit-modal__panel">
      <div class="comment-modal__header">
        <h3 id="resubmitModalTitle">Kirim Ulang Bukti Gambar</h3>
        <button class="comment-modal__close" onclick="closeResubmitModal()" type="button" aria-label="Tutup">✕</button>
      </div>
      <div class="comment-modal__body">
        <p id="resubmitModalDesc" class="resubmit-modal__desc"></p>
        <div class="resubmit-modal__tip">
          Gunakan foto yang benar-benar menggambarkan kejadian sesuai deskripsi laporan (maks. 5 gambar, format JPG/PNG/WEBP).
        </div>
      </div>
      <div class="comment-modal__footer">
        <input id="resubmitMediaInput" class="resubmit-modal__input" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <div class="comment-form-footer">
          <span id="resubmitModalMeta" class="comment-word-count">Belum ada file dipilih.</span>
          <button class="btn btn-primary btn-sm" type="button" onclick="submitResubmission()">Kirim Ulang ke AI</button>
        </div>
        <p class="comment-feedback" id="resubmitModalFeedback"></p>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>
  <script src="assets/js/app.js?v=<?= urlencode($appJsVersion !== false ? $appJsVersion : '1') ?>"></script>
  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>