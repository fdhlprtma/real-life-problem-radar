async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();

  let data = null;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error('Respons server tidak valid. Silakan refresh halaman.');
  }

  if (!response.ok || !data.ok) {
    throw new Error(data.message || 'Request gagal');
  }

  return data;
}

function safeText(value, fallback = '-') {
  if (value === null || value === undefined) return fallback;
  const text = String(value).trim();
  return text === '' ? fallback : text;
}

function reportStatusLabel(status) {
  const normalized = String(status || 'open').trim().toLowerCase();
  if (normalized === 'in_progress') return 'Sedang Diproses';
  if (normalized === 'resolved') return 'Selesai';
  if (normalized === 'hidden') return 'Disembunyikan';
  return 'Belum Diproses';
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function getMediaPaths(report) {
  const raw = report.media_paths;
  if (typeof raw === 'string' && raw.trim() !== '') {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) {
        return parsed.filter((p) => typeof p === 'string' && p.trim() !== '');
      }
    } catch {
      // ignore
    }
  }

  if (typeof report.media_path === 'string' && report.media_path.trim() !== '') {
    return [report.media_path];
  }

  return [];
}

const tableState = {
  page: 1,
};

const govCharts = {
  status: null,
  category: null,
  trend: null,
};

function destroyGovCharts() {
  Object.keys(govCharts).forEach((key) => {
    if (govCharts[key] && typeof govCharts[key].destroy === 'function') {
      govCharts[key].destroy();
    }
    govCharts[key] = null;
  });
}

function renderGovCharts(analytics) {
  const chartGrid = document.getElementById('govChartGrid');
  if (!chartGrid) return;

  if (typeof window.Chart === 'undefined') {
    chartGrid.innerHTML = '<div class="panel">Library chart gagal dimuat. Coba refresh halaman.</div>';
    return;
  }

  destroyGovCharts();

  const statusCounts = analytics?.status_counts || {};
  const categoryCounts = Array.isArray(analytics?.category_counts) ? analytics.category_counts : [];
  const trend = analytics?.trend || { labels: [], total_reports: [], resolved_reports: [] };

  const statusCanvas = document.getElementById('govStatusChart');
  const categoryCanvas = document.getElementById('govCategoryChart');
  const trendCanvas = document.getElementById('govTrendChart');
  if (!statusCanvas || !categoryCanvas || !trendCanvas) return;

  govCharts.status = new Chart(statusCanvas, {
    type: 'bar',
    data: {
      labels: ['Belum Diproses', 'Sedang Diproses', 'Selesai'],
      datasets: [{
        label: 'Jumlah Laporan',
        data: [
          Number(statusCounts.open || 0),
          Number(statusCounts.in_progress || 0),
          Number(statusCounts.resolved || 0),
        ],
        backgroundColor: ['#d1d5db', '#9ca3af', '#111827'],
        borderRadius: 8,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });

  govCharts.category = new Chart(categoryCanvas, {
    type: 'doughnut',
    data: {
      labels: categoryCounts.map((item) => safeText(item.name, 'Lainnya')),
      datasets: [{
        data: categoryCounts.map((item) => Number(item.total || 0)),
        backgroundColor: ['#111827', '#374151', '#6b7280', '#9ca3af', '#d1d5db', '#e5e7eb'],
        borderColor: '#f9fafb',
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true },
        },
      },
    },
  });

  govCharts.trend = new Chart(trendCanvas, {
    type: 'line',
    data: {
      labels: Array.isArray(trend.labels) ? trend.labels : [],
      datasets: [
        {
          label: 'Laporan Masuk',
          data: Array.isArray(trend.total_reports) ? trend.total_reports : [],
          borderColor: '#111827',
          backgroundColor: 'rgba(17, 24, 39, 0.1)',
          tension: 0.35,
          fill: true,
          pointRadius: 2,
        },
        {
          label: 'Laporan Selesai',
          data: Array.isArray(trend.resolved_reports) ? trend.resolved_reports : [],
          borderColor: '#6b7280',
          backgroundColor: 'rgba(107, 114, 128, 0.08)',
          tension: 0.35,
          fill: false,
          pointRadius: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom' },
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });
}

function renderGovStats(payload) {
  const region = payload.region || {};
  const stats = payload.stats || {};

  const regionLabel = [region.province, region.city, region.district, region.subdistrict]
    .map((v) => safeText(v, ''))
    .filter(Boolean)
    .join(', ');

  document.getElementById('govAgencyName').textContent = safeText(payload.agency_name, 'Dashboard Instansi');
  document.getElementById('govAgencyMeta').textContent = `${safeText(payload.agency_type)} | ${safeText(payload.agency_sector)} | ${regionLabel || '-'}`;

  document.getElementById('govStats').innerHTML = `
    <div class="stat-card"><span>Total Laporan Wilayah</span><strong>${Number(stats.total_reports || 0)}</strong></div>
    <div class="stat-card"><span>Belum Diproses</span><strong>${Number(stats.open_reports || 0)}</strong></div>
    <div class="stat-card"><span>Sedang Diproses</span><strong>${Number(stats.in_progress_reports || 0)}</strong></div>
    <div class="stat-card"><span>Selesai</span><strong>${Number(stats.resolved_reports || 0)}</strong></div>
  `;

  document.getElementById('govBadges').innerHTML = `
    <span class="badge badge-normal">Verified Government</span>
    <span class="badge ${stats.badge_active_responder ? 'badge-normal' : 'badge-critical'}">${stats.badge_active_responder ? 'Active Responder' : 'Belum Active Responder'}</span>
  `;
}

function reportRow(report, rowNumber) {
  const title = safeText(report.title);
  const description = safeText(report.description, '').slice(0, 120);
  const category = safeText(report.category_ai);
  const urgency = safeText(report.urgency_ai);
  const reporterName = safeText(report.reporter_name);
  const createdAt = safeText(report.created_at);
  const region = [report.province, report.city, report.district, report.subdistrict]
    .map((v) => safeText(v, ''))
    .filter(Boolean)
    .join(', ');

  const lat = Number(report.latitude);
  const lng = Number(report.longitude);
  const location = Number.isFinite(lat) && Number.isFinite(lng)
    ? `${lat.toFixed(4)}, ${lng.toFixed(4)}`
    : '-';

  const status = safeText(report.status, 'open');
  const mediaPaths = getMediaPaths(report);
  const rowPayload = encodeURIComponent(JSON.stringify({
    id: Number(report.id || 0),
    title,
    description: safeText(report.description, ''),
    category,
    urgency,
    status,
    reporterName,
    reporterEmail: safeText(report.reporter_email, '-'),
    latitude: Number.isFinite(lat) ? lat : null,
    longitude: Number.isFinite(lng) ? lng : null,
    location,
    region,
    mediaPaths,
  }));

  return `
    <tr>
      <td>${rowNumber}</td>
      <td>
        <strong>${escapeHtml(title)}</strong><br>
        <small>${escapeHtml(description || '-')}</small><br>
        <small>${escapeHtml(`${category} | ${urgency}`)}</small><br>
        <small>Pelapor: ${escapeHtml(reporterName)}</small><br>
        <small>${escapeHtml(createdAt)}</small>
      </td>
      <td>${escapeHtml(region || '-')}<br><small>${escapeHtml(location)}</small></td>
      <td><span class="status-pill status-${escapeHtml(status)}">${escapeHtml(reportStatusLabel(status))}</span></td>
      <td>
        <div class="admin-row-actions admin-row-actions--report">
          <div class="action-utility">
            <button class="btn btn-secondary btn-xs detail-report-action" data-report='${rowPayload}'>Lihat Detail</button>
          </div>
          <div class="action-status-label">Ubah Status</div>
          <div class="action-status-grid">
            <button class="btn btn-ghost btn-xs gov-report-action" data-report-id="${Number(report.id || 0)}" data-status="open">Belum Diproses</button>
            <button class="btn btn-ghost btn-xs gov-report-action" data-report-id="${Number(report.id || 0)}" data-status="in_progress">Sedang Diproses</button>
            <button class="btn btn-ghost btn-xs gov-report-action" data-report-id="${Number(report.id || 0)}" data-status="resolved">Selesai</button>
          </div>
        </div>
      </td>
    </tr>
  `;
}

function renderPagination(meta) {
  const bar = document.getElementById('govPaginationBar');
  if (!meta || Number(meta.total_pages || 0) <= 1) {
    bar.innerHTML = `<span class="page-info">Total: ${Number(meta ? meta.total : 0)} laporan</span>`;
    return;
  }

  const page = Number(meta.page || 1);
  const totalPages = Number(meta.total_pages || 1);
  const total = Number(meta.total || 0);

  let html = `<span class="page-info">Total: ${total} | Halaman ${page} / ${totalPages}</span><div class="page-buttons">`;
  html += `<button class="btn btn-ghost gov-page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Prev</button>`;

  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let p = start; p <= end; p += 1) {
    html += `<button class="btn ${p === page ? 'btn-primary' : 'btn-ghost'} gov-page-btn" data-page="${p}">${p}</button>`;
  }

  html += `<button class="btn btn-ghost gov-page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next</button></div>`;
  bar.innerHTML = html;
}

async function loadStats() {
  const res = await fetchJson('api/government.php?action=stats');
  renderGovStats(res.data || {});
}

async function loadReports() {
  const status = encodeURIComponent(document.getElementById('govReportStatusFilter').value);
  const q = encodeURIComponent(document.getElementById('govSearchInput').value.trim());
  const perPageParam = encodeURIComponent(document.getElementById('govPerPageSelect').value);

  const res = await fetchJson(`api/government.php?action=reports&status=${status}&q=${q}&page=${tableState.page}&per_page=${perPageParam}`);
  const rows = Array.isArray(res.data) ? res.data : [];

  const tbody = document.getElementById('govReportsTable');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5">Tidak ada data laporan untuk wilayah ini.</td></tr>';
    renderPagination(res.meta || null);
    return;
  }

  const meta = res.meta || {};
  const page = Number(meta.page || tableState.page || 1);
  const perPage = Number(meta.per_page || document.getElementById('govPerPageSelect').value || 25);
  const startNumber = ((page - 1) * perPage) + 1;

  tbody.innerHTML = rows.map((report, index) => reportRow(report, startNumber + index)).join('');
  renderPagination(res.meta || null);
}

async function loadAnalytics() {
  const res = await fetchJson('api/government.php?action=analytics&days=14');
  renderGovCharts(res.data || {});
}

async function setStatus(reportId, status) {
  await fetchJson('api/government.php?action=status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: reportId, status }),
  });

  await Promise.all([loadStats(), loadReports(), loadAnalytics()]);
}

function initSidebar() {
  const buttons = document.querySelectorAll('.side-btn[data-view]');
  const views = document.querySelectorAll('.dash-view');

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.view;
      buttons.forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');

      views.forEach((view) => {
        const active = view.id === target;
        view.classList.toggle('hidden', !active);
        view.classList.toggle('active', active);
      });
    });
  });
}

function bindActions() {
  document.getElementById('govApplyFilterBtn').addEventListener('click', () => {
    tableState.page = 1;
    loadReports().catch((error) => alert(error.message));
  });

  document.getElementById('govPaginationBar').addEventListener('click', (event) => {
    const btn = event.target.closest('.gov-page-btn');
    if (!btn) return;

    const page = Number(btn.dataset.page || 1);
    if (!Number.isFinite(page) || page < 1) return;

    tableState.page = page;
    loadReports().catch((error) => alert(error.message));
  });

  document.getElementById('govReportsTable').addEventListener('click', (event) => {
    const detailBtn = event.target.closest('.detail-report-action');
    if (detailBtn) {
      try {
        const payload = JSON.parse(decodeURIComponent(detailBtn.dataset.report || ''));
        openReportDetail(payload);
      } catch {
        alert('Gagal membuka detail laporan.');
      }
      return;
    }

    const btn = event.target.closest('.gov-report-action');
    if (!btn) return;

    const reportId = Number(btn.dataset.reportId || 0);
    const status = safeText(btn.dataset.status, 'open');
    if (reportId <= 0) {
      alert('ID laporan tidak valid.');
      return;
    }

    setStatus(reportId, status).catch((error) => alert(error.message));
  });

  document.getElementById('logoutBtn').addEventListener('click', async () => {
    try {
      await fetchJson('api/auth.php?action=logout', { method: 'POST' });
      window.location.href = 'index.php';
    } catch (error) {
      alert(error.message);
    }
  });

  const closeDetailBtn = document.getElementById('closeReportDetailBtn');
  if (closeDetailBtn) {
    closeDetailBtn.addEventListener('click', () => {
      const dialog = document.getElementById('reportDetailDialog');
      if (dialog && typeof dialog.close === 'function') {
        dialog.close();
      }
    });
  }
}

function openReportDetail(data) {
  const dialog = document.getElementById('reportDetailDialog');
  const body = document.getElementById('reportDetailBody');
  if (!dialog || !body) return;

  const lat = Number(data.latitude);
  const lng = Number(data.longitude);
  const hasCoords = Number.isFinite(lat) && Number.isFinite(lng);
  const mapHtml = hasCoords
    ? `<iframe class="report-detail-map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://maps.google.com/maps?q=${lat},${lng}&z=16&output=embed"></iframe>`
    : '<small>Lokasi peta tidak tersedia.</small>';

  const mediaPaths = Array.isArray(data.mediaPaths) ? data.mediaPaths : [];
  const mediaHtml = mediaPaths.length > 0
    ? mediaPaths.map((p) => {
      const url = `../${p}`;
      if (String(p).toLowerCase().endsWith('.mp4')) {
        return `<video class="report-detail-media" src="${escapeHtml(url)}" controls></video>`;
      }
      return `<img class="report-detail-media" src="${escapeHtml(url)}" alt="Bukti laporan">`;
    }).join('')
    : '<small>Tidak ada media bukti.</small>';

  body.innerHTML = `
    <div class="report-detail-meta"><strong>Judul:</strong> ${escapeHtml(safeText(data.title))}</div>
    <div class="report-detail-meta"><strong>Pelapor:</strong> ${escapeHtml(safeText(data.reporterName))} (${escapeHtml(safeText(data.reporterEmail, '-'))})</div>
    <div class="report-detail-meta"><strong>Kategori/AI:</strong> ${escapeHtml(safeText(data.category))} | ${escapeHtml(safeText(data.urgency))}</div>
    <div class="report-detail-meta"><strong>Status:</strong> ${escapeHtml(reportStatusLabel(data.status))}</div>
    <div class="report-detail-meta"><strong>Lokasi:</strong> ${escapeHtml(safeText(data.location))}</div>
    <div class="report-detail-meta"><strong>Wilayah:</strong> ${escapeHtml(safeText(data.region))}</div>
    <div>${mapHtml}</div>
    <div class="report-detail-meta"><strong>Deskripsi:</strong><br>${escapeHtml(safeText(data.description, '-'))}</div>
    <div>${mediaHtml}</div>
  `;

  if (typeof dialog.showModal === 'function') {
    dialog.showModal();
  }
}

(async function initGovernment() {
  initSidebar();
  bindActions();

  const tasks = await Promise.allSettled([loadStats(), loadReports(), loadAnalytics()]);
  const failed = tasks.find((t) => t.status === 'rejected');
  if (failed && failed.reason) {
    alert(failed.reason.message || 'Sebagian data dashboard gagal dimuat.');
  }
})();
