<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

$user = Auth::user();
if ($user === null || ($user['role'] ?? '') !== 'government') {
  header('Location: index.php');
  exit;
}

$styleVersion = (string) @filemtime(__DIR__ . '/assets/css/style.css');
$dashboardVersion = (string) @filemtime(__DIR__ . '/assets/css/dashboard.css');
$scriptVersion = (string) @filemtime(__DIR__ . '/assets/js/government.js');
$pwaScriptVersion = (string) @filemtime(__DIR__ . '/assets/js/pwa.js');
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Pemerintah - RLP Radar</title>
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

<body class="dashboard-ui government-ui">
  <div class="noise"></div>
  <main class="dash-shell">
    <aside class="dash-sidebar panel">
      <div class="dash-brand">
        <div class="dash-brand__meta">
          <strong>RLP Radar</strong>
          <small>Government Console</small>
        </div>
      </div>
      <p class="eyebrow">Government Dashboard</p>
      <button class="btn btn-secondary side-btn is-active" data-view="govSummaryView">Ringkasan</button>
      <button class="btn btn-secondary side-btn" data-view="govReportsView">Laporan Wilayah</button>
      <div class="sidebar-footer">
        <button id="logoutBtn" class="btn btn-primary" type="button">Logout</button>
      </div>
    </aside>

    <section class="dash-content">
      <section class="panel dash-view active" id="govSummaryView">
        <div class="admin-head">
          <!-- <div class="dashboard-chip">Government Workspace</div> -->
        </div>
        <h1 id="govAgencyName">Dashboard Instansi</h1>
        <p id="govAgencyMeta" class="panel-subtitle"></p>
        <div id="govBadges" class="report-actions"></div>
        <div class="admin-stats" id="govStats"></div>
        <div class="dashboard-grid dashboard-grid--charts" id="govChartGrid">
          <article class="panel dashboard-card chart-card">
            <h3>Status Laporan</h3>
            <canvas id="govStatusChart" aria-label="Grafik status laporan"></canvas>
          </article>
          <article class="panel dashboard-card chart-card">
            <h3>Top Kategori Laporan</h3>
            <canvas id="govCategoryChart" aria-label="Grafik kategori laporan"></canvas>
          </article>
          <article class="panel dashboard-card chart-card chart-card--wide">
            <h3>Tren 14 Hari Terakhir</h3>
            <canvas id="govTrendChart" aria-label="Grafik tren laporan"></canvas>
          </article>
        </div>
      </section>

      <section class="panel dash-view hidden" id="govReportsView">
        <div class="admin-filter">
          <input id="govSearchInput" type="text" placeholder="Cari laporan di wilayah Anda...">
          <select id="govReportStatusFilter">
            <option value="all">Semua Status</option>
            <option value="open">Belum Diproses</option>
            <option value="in_progress">In Progress</option>
            <option value="resolved">Resolved</option>
          </select>
          <select id="govPerPageSelect">
            <option value="10">10 / hal</option>
            <option value="25" selected>25 / hal</option>
            <option value="50">50 / hal</option>
          </select>
          <button id="govApplyFilterBtn" class="btn btn-secondary" type="button">Terapkan</button>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Laporan</th>
                <th>Lokasi</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="govReportsTable"></tbody>
          </table>
        </div>
        <div class="pagination-bar" id="govPaginationBar"></div>
      </section>
    </section>
  </main>

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
  <script src="assets/js/government.js?v=<?= urlencode($scriptVersion) ?>"></script>
  <script src="assets/js/pwa.js?v=<?= urlencode($pwaScriptVersion !== false ? $pwaScriptVersion : '1') ?>"></script>
</body>

</html>