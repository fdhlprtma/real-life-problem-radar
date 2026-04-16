<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

if (!Auth::isAdmin()) {
  header('Location: index.php');
  exit;
}

$styleVersion = (string) @filemtime(__DIR__ . '/assets/css/style.css');
$dashboardVersion = (string) @filemtime(__DIR__ . '/assets/css/dashboard.css');
$scriptVersion = (string) @filemtime(__DIR__ . '/assets/js/admin.js');
$pwaScriptVersion = (string) @filemtime(__DIR__ . '/assets/js/pwa.js');
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard - RLP Radar</title>
  <meta name="theme-color" content="#121212">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="RLP Radar">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="assets/img/favicon.ico">
  <link rel="apple-touch-icon" href="assets/img/pwa-icon-180.png">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= urlencode($styleVersion) ?>">
  <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= urlencode($dashboardVersion) ?>">
</head>

<body class="dashboard-ui admin-ui">
  <div class="noise"></div>
  <main class="dash-shell">
    <aside class="dash-sidebar panel">
      <div class="dash-brand">
        <div class="dash-brand__meta">
          <strong>RLP Radar</strong>
          <small>Admin Console</small>
        </div>
      </div>
      <p class="eyebrow">Admin Ops Center</p>
      <button class="btn btn-secondary side-btn is-active" data-view="dashboardView">Dashboard</button>
      <button class="btn btn-secondary side-btn" data-view="reportsView">Kelola Laporan</button>
      <button class="btn btn-secondary side-btn" data-view="govView">Pendaftar Pemerintah</button>
      <button class="btn btn-secondary side-btn" data-view="usersView">Daftar Pengguna</button>

      <div class="sidebar-footer">
        <button id="logoutBtn" class="btn btn-primary" type="button">Logout</button>
      </div>
    </aside>

    <section class="dash-content">
      <section class="panel dash-view active" id="dashboardView">
        <div class="admin-head">
          <div>
            <h1>Dashboard Admin</h1>
            <p class="panel-subtitle">Statistik ringkas laporan dan pendaftar instansi.</p>
          </div>
          <div class="dashboard-chip">Overview</div>
        </div>

        <div class="admin-stats" id="adminStats"></div>
        <div class="dashboard-grid dashboard-grid--charts">
          <article class="panel dashboard-card chart-card">
            <h3>Distribusi Kategori Laporan</h3>
            <canvas id="categoryChart" aria-label="Chart kategori laporan"></canvas>
          </article>

          <article class="panel dashboard-card chart-card">
            <h3>Proporsi Status Laporan</h3>
            <canvas id="statusChart" aria-label="Chart status laporan"></canvas>
          </article>
        </div>

        <div class="dashboard-grid">
          <article class="panel dashboard-card">
            <h3>Ringkasan Operasional</h3>
            <div id="dashboardOpsSummary" class="dashboard-kpis"></div>
          </article>

          <article class="panel dashboard-card">
            <h3>Laporan Terbaru</h3>
            <div id="dashboardRecentReports" class="dashboard-list"></div>
          </article>

          <article class="panel dashboard-card">
            <h3>Pendaftar Pemerintah Terbaru</h3>
            <div id="dashboardRecentGovernment" class="dashboard-list"></div>
          </article>
        </div>

      </section>

      <section class="panel dash-view hidden" id="reportsView">
        <div class="admin-head">
          <div>
            <h2>Kelola Laporan</h2>
            <p class="panel-subtitle">Filter, sorting, dan update status laporan masyarakat.</p>
          </div>
        </div>

        <div class="admin-filter">
          <input id="searchInput" type="text" placeholder="Cari judul, deskripsi, reporter...">
          <select id="statusFilter">
            <option value="all">Semua Status</option>
            <option value="open">Belum Diproses</option>
            <option value="in_progress">In Progress</option>
            <option value="resolved">Resolved</option>
            <option value="hidden">Disembunyikan</option>
          </select>
          <select id="perPageSelect">
            <option value="10">10 / hal</option>
            <option value="25" selected>25 / hal</option>
            <option value="50">50 / hal</option>
            <option value="100">100 / hal</option>
          </select>
          <button id="applyFilterBtn" class="btn btn-secondary" type="button">Terapkan</button>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th class="sortable" data-col="id">ID <span class="sort-icon"></span></th>
                <th>Masalah</th>
                <th>Lokasi</th>
                <th>Reporter</th>
                <th class="sortable" data-col="urgency_ai">AI <span class="sort-icon"></span></th>
                <th class="sortable" data-col="status">Status <span class="sort-icon"></span></th>
                <th class="sortable" data-col="confirms">Votes <span class="sort-icon"></span></th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="adminReportsTable"></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="paginationBar"></div>
      </section>

      <section class="panel dash-view hidden" id="govView">
        <h2>Pendaftar Instansi Pemerintah</h2>
        <div class="admin-filter">
          <select id="govStatusFilter">
            <option value="pending" selected>Pending</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
            <option value="all">Semua</option>
          </select>
          <button id="refreshGovBtn" class="btn btn-secondary" type="button">Refresh Pendaftar</button>
        </div>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Instansi</th>
                <th>Wilayah</th>
                <th>PIC & Kontak</th>
                <th>Dokumen & Verifikasi</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="govRequestsTable"></tbody>
          </table>
        </div>
      </section>

      <section class="panel dash-view hidden" id="usersView">
        <div class="admin-head">
          <div>
            <h2>Daftar Pengguna</h2>
            <p class="panel-subtitle">Kelola akun masyarakat dan pemerintah yang terdaftar.</p>
          </div>
        </div>

        <div class="admin-filter">
          <input id="userSearchInput" type="text" placeholder="Cari nama atau email...">
          <select id="userRoleFilter">
            <option value="all">Semua Peran</option>
            <option value="user">Masyarakat</option>
            <option value="government">Pemerintah</option>
          </select>
          <select id="userPerPageSelect">
            <option value="10">10 / hal</option>
            <option value="25" selected>25 / hal</option>
            <option value="50">50 / hal</option>
          </select>
          <button id="applyUserFilterBtn" class="btn btn-secondary" type="button">Terapkan</button>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Nama</th>
                <th>Email</th>
                <th>Peran</th>
                <th>Status Akun</th>
                <th>Instansi / Wilayah</th>
                <th>Bergabung</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="usersTable"></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="usersPaginationBar"></div>
      </section>
    </section>
  </main>

  <dialog id="editUserDialog" class="auth-dialog">
    <div class="auth-form">
      <h3>Edit Pengguna</h3>
      <input type="hidden" id="editUserId">
      <div class="form-group">
        <label for="editUserName">Nama</label>
        <input id="editUserName" type="text" placeholder="Nama lengkap">
      </div>
      <div class="form-group">
        <label for="editUserEmail">Email</label>
        <input id="editUserEmail" type="email" placeholder="Alamat email">
      </div>
      <div class="form-group">
        <label for="editUserStatus">Status Akun</label>
        <select id="editUserStatus">
          <option value="active">Aktif</option>
          <option value="inactive">Non-aktif</option>
          <option value="pending">Pending</option>
          <option value="verified">Terverifikasi</option>
          <option value="rejected">Ditolak</option>
        </select>
      </div>
      <div class="form-actions">
        <button id="saveEditUserBtn" class="btn btn-primary" type="button">Simpan</button>
        <button id="closeEditUserBtn" class="btn btn-secondary" type="button">Batal</button>
      </div>
    </div>
  </dialog>

  <dialog id="reportDetailDialog" class="auth-dialog report-detail-dialog">
    <div class="auth-form">
      <h3>Detail Laporan</h3>
      <div id="reportDetailBody" class="report-detail-body"></div>
      <div class="form-actions">
        <button id="closeReportDetailBtn" class="btn btn-secondary" type="button">Tutup</button>
      </div>
    </div>
  </dialog>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script src="assets/js/admin.js?v=<?= urlencode($scriptVersion) ?>"></script>
  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>