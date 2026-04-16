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
  sortBy: 'created_at',
  sortDir: 'DESC',
};

const dashboardState = {
  stats: { report: {}, government: {} },
  reports: [],
  governmentRequests: [],
};

const chartState = {
  category: null,
  status: null,
};

const userTableState = {
  page: 1,
  loaded: false,
};

function renderCharts() {
  if (typeof window.Chart === 'undefined') {
    return;
  }

  const categoryCanvas = document.getElementById('categoryChart');
  const statusCanvas = document.getElementById('statusChart');
  if (!categoryCanvas || !statusCanvas) {
    return;
  }

  const categoryCounts = {};
  for (const row of dashboardState.reports) {
    const key = safeText(row.category_ai, 'lainnya');
    categoryCounts[key] = (categoryCounts[key] || 0) + 1;
  }

  const categoryLabels = Object.keys(categoryCounts).length > 0
    ? Object.keys(categoryCounts)
    : ['belum_ada_data'];
  const categoryValues = Object.values(categoryCounts).length > 0
    ? Object.values(categoryCounts)
    : [0];

  const reportStats = dashboardState.stats.report || {};
  const statusLabels = ['Belum Diproses', 'Sedang Diproses', 'Selesai'];
  const statusValues = [
    Number(reportStats.open_reports || 0),
    Number(reportStats.in_progress_reports || 0),
    Number(reportStats.resolved_reports || 0),
  ];

  if (chartState.category) {
    chartState.category.destroy();
  }
  chartState.category = new window.Chart(categoryCanvas, {
    type: 'bar',
    data: {
      labels: categoryLabels,
      datasets: [{
        label: 'Jumlah Laporan',
        data: categoryValues,
        backgroundColor: '#2f2f2f',
        borderRadius: 8,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: {
          ticks: { color: '#4f4f4f' },
          grid: { color: '#e3e3e3' },
        },
        y: {
          beginAtZero: true,
          ticks: { precision: 0, color: '#4f4f4f' },
          grid: { color: '#e3e3e3' },
        },
      },
    },
  });

  if (chartState.status) {
    chartState.status.destroy();
  }
  chartState.status = new window.Chart(statusCanvas, {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{
        data: statusValues,
        backgroundColor: ['#2f2f2f', '#8d8d8d', '#d0d0d0'],
        hoverBackgroundColor: ['#1f1f1f', '#7d7d7d', '#c2c2c2'],
        borderColor: '#ffffff',
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: '#3f3f3f' },
        },
      },
      cutout: '62%',
    },
  });
}

function renderDashboardDetails() {
  const report = dashboardState.stats.report || {};
  const government = dashboardState.stats.government || {};

  const opsEl = document.getElementById('dashboardOpsSummary');
  const recentReportsEl = document.getElementById('dashboardRecentReports');
  const recentGovEl = document.getElementById('dashboardRecentGovernment');

  if (opsEl) {
    opsEl.innerHTML = `
      <div class="kpi-row"><span>Total Laporan</span><strong>${Number(report.total_reports || 0)}</strong></div>
      <div class="kpi-row"><span>Critical</span><strong>${Number(report.critical_reports || 0)}</strong></div>
      <div class="kpi-row"><span>Instansi Pending</span><strong>${Number(government.pending || 0)}</strong></div>
      <div class="kpi-row"><span>Instansi Verified</span><strong>${Number(government.verified || 0)}</strong></div>
    `;
  }

  if (recentReportsEl) {
    const reports = dashboardState.reports.slice(0, 5);
    if (!reports.length) {
      recentReportsEl.innerHTML = '<div class="dashboard-list-item"><small>Belum ada laporan terbaru.</small></div>';
    } else {
      recentReportsEl.innerHTML = reports.map((r) => `
        <div class="dashboard-list-item">
          <strong>${escapeHtml(safeText(r.title))}</strong><br>
          <small>${escapeHtml(reportStatusLabel(r.status))} | ${escapeHtml(safeText(r.category_ai))}</small>
        </div>
      `).join('');
    }
  }

  if (recentGovEl) {
    const applicants = dashboardState.governmentRequests.slice(0, 5);
    if (!applicants.length) {
      recentGovEl.innerHTML = '<div class="dashboard-list-item"><small>Belum ada pendaftar instansi.</small></div>';
    } else {
      recentGovEl.innerHTML = applicants.map((u) => `
        <div class="dashboard-list-item">
          <strong>${escapeHtml(safeText(u.agency_name))}</strong><br>
          <small>${escapeHtml(safeText(u.account_status, 'pending'))} | ${escapeHtml(safeText(u.region_city))}</small>
        </div>
      `).join('');
    }
  }

  renderCharts();
}

function renderStats(stats) {
  const report = stats.report || {};
  const government = stats.government || {};

  document.getElementById('adminStats').innerHTML = `
    <div class="stat-card"><span>Total Laporan</span><strong>${Number(report.total_reports || 0)}</strong></div>
    <div class="stat-card"><span>Belum Diproses</span><strong>${Number(report.open_reports || 0)}</strong></div>
    <div class="stat-card"><span>In Progress</span><strong>${Number(report.in_progress_reports || 0)}</strong></div>
    <div class="stat-card"><span>Resolved</span><strong>${Number(report.resolved_reports || 0)}</strong></div>
    <div class="stat-card"><span>Pendaftar Pending</span><strong>${Number(government.pending || 0)}</strong></div>
  `;
}

function reportRow(report) {
  const title = safeText(report.title);
  const desc = safeText(report.description, '').slice(0, 100);
  const reporterName = safeText(report.reporter_name);
  const reporterEmail = safeText(report.reporter_email);
  const category = safeText(report.category_ai);
  const urgency = safeText(report.urgency_ai);
  const confirms = Number(report.confirms || 0);
  const rejects = Number(report.rejects || 0);
  const reportStatus = String(report.status || 'open').trim().toLowerCase();
  const openLabel = reportStatus === 'hidden' ? 'Unhide' : 'Belum Diproses';
  const hideButton = reportStatus === 'hidden'
    ? ''
    : `<button class="btn btn-ghost btn-xs report-action" data-report-id="${Number(report.id || 0)}" data-status="hidden">Hide</button>`;

  const lat = Number(report.latitude);
  const lng = Number(report.longitude);
  const location = Number.isFinite(lat) && Number.isFinite(lng)
    ? `${lat.toFixed(4)}, ${lng.toFixed(4)}`
    : '-';

  const region = [report.province, report.city, report.district, report.subdistrict]
    .map((v) => safeText(v, ''))
    .filter(Boolean)
    .join(', ');

  const mediaPaths = getMediaPaths(report);
  const firstMedia = mediaPaths.length > 0 ? `../${mediaPaths[0]}` : null;
  const thumbHtml = firstMedia
    ? `<img class="report-thumb" src="${escapeHtml(firstMedia)}" alt="Bukti laporan">`
    : '<small>Tanpa foto</small>';

  const rowPayload = encodeURIComponent(JSON.stringify({
    id: Number(report.id || 0),
    title,
    description: safeText(report.description, ''),
    category,
    urgency,
    status: safeText(report.status, 'open'),
    reporterName,
    reporterEmail,
    latitude: Number.isFinite(lat) ? lat : null,
    longitude: Number.isFinite(lng) ? lng : null,
    location,
    region,
    mediaPaths,
  }));

  return `
    <tr>
      <td>${Number(report.id || 0)}</td>
      <td>
        <strong>${escapeHtml(title)}</strong><br>
        <small>${escapeHtml(desc || '-')}</small><br>
        ${thumbHtml}
      </td>
      <td>${escapeHtml(location)}<br><small>${escapeHtml(region || '-')}</small></td>
      <td>${escapeHtml(`${reporterName} (${reporterEmail})`)}</td>
      <td>${escapeHtml(category)} | ${escapeHtml(urgency)}</td>
      <td><span class="status-pill status-${escapeHtml(safeText(report.status, 'open'))}">${escapeHtml(reportStatusLabel(report.status))}</span></td>
      <td>+${confirms} / -${rejects}</td>
      <td>
        <div class="admin-row-actions admin-row-actions--report">
          <div class="action-utility">
            <button class="btn btn-secondary btn-xs detail-report-action" data-report='${rowPayload}'>Lihat Lengkap</button>
            <a class="btn btn-ghost btn-xs" href="api/export.php?report_id=${Number(report.id || 0)}" target="_blank" rel="noopener">PDF</a>
          </div>
          <div class="action-status-label">Ubah Status</div>
          <div class="action-status-grid">
            <button class="btn btn-ghost btn-xs report-action" data-report-id="${Number(report.id || 0)}" data-status="open">${openLabel}</button>
            <button class="btn btn-ghost btn-xs report-action" data-report-id="${Number(report.id || 0)}" data-status="in_progress">Proses</button>
            <button class="btn btn-ghost btn-xs report-action" data-report-id="${Number(report.id || 0)}" data-status="resolved">Selesai</button>
            ${hideButton}
          </div>
          <div>
            <button class="btn btn-ghost btn-xs action-delete-btn delete-report-action" data-report-id="${Number(report.id || 0)}">Hapus Laporan</button>
          </div>
        </div>
      </td>
    </tr>
  `;
}

function govRow(user) {
  const id = Number(user.id || 0);
  const status = safeText(user.account_status, 'pending');
  const region = [user.region_province, user.region_city, user.region_district, user.region_subdistrict]
    .map((v) => safeText(v, ''))
    .filter(Boolean)
    .join(', ');

  const agency = safeText(user.agency_name);
  const agencyType = safeText(user.agency_type);
  const agencySector = safeText(user.agency_sector);
  const officerName = safeText(user.officer_name);
  const officerPos = safeText(user.officer_position);
  const officerNip = safeText(user.officer_nip);
  const officerPhone = safeText(user.officer_phone);
  const email = safeText(user.email);
  const officialDomain = Number(user.official_email_domain_valid || 0) === 1 ? 'go.id valid' : 'non go.id';
  const declarationData = Number(user.declaration_data_true || 0) === 1 ? 'Data: disetujui' : 'Data: belum';
  const declarationFollowup = Number(user.declaration_followup || 0) === 1 ? 'Follow-up: disetujui' : 'Follow-up: belum';
  const createdAt = safeText(user.created_at);

  const doc = user.government_document_path
    ? `<a class="btn btn-ghost" target="_blank" rel="noopener" href="../${escapeHtml(user.government_document_path)}">Lihat Dokumen</a>`
    : '<small>Dokumen belum diupload</small>';

  return `
    <tr>
      <td>
        <strong>${escapeHtml(agency)}</strong><br>
        <small>${escapeHtml(agencyType)} | ${escapeHtml(agencySector)}</small><br>
        <small>Email domain: ${escapeHtml(officialDomain)}</small><br>
        <small>Daftar: ${escapeHtml(createdAt)}</small>
      </td>
      <td>${escapeHtml(region || '-')}</td>
      <td>
        ${escapeHtml(officerName)}<br>
        <small>${escapeHtml(officerPos)}</small><br>
        <small>NIP: ${escapeHtml(officerNip)}</small><br>
        <small>HP: ${escapeHtml(officerPhone)}</small><br>
        <small>${escapeHtml(email)}</small>
      </td>
      <td>
        ${doc}<br>
        <small>${escapeHtml(declarationData)}</small><br>
        <small>${escapeHtml(declarationFollowup)}</small>
      </td>
      <td><span class="status-pill status-${escapeHtml(status)}">${escapeHtml(status)}</span></td>
      <td>
        <div class="admin-row-actions admin-row-actions--government">
          <button class="btn btn-ghost gov-action" data-user-id="${id}" data-status="verified">Approve</button>
          <button class="btn btn-ghost gov-action" data-user-id="${id}" data-status="rejected">Reject</button>
          <button class="btn btn-ghost gov-action" data-user-id="${id}" data-status="pending">Pending</button>
        </div>
      </td>
    </tr>
  `;
}

function renderPagination(meta) {
  const bar = document.getElementById('paginationBar');
  if (!meta || Number(meta.total_pages || 0) <= 1) {
    bar.innerHTML = `<span class="page-info">Total: ${Number(meta ? meta.total : 0)} laporan</span>`;
    return;
  }

  const page = Number(meta.page || 1);
  const totalPages = Number(meta.total_pages || 1);
  const total = Number(meta.total || 0);

  let html = `<span class="page-info">Total: ${total} | Halaman ${page} / ${totalPages}</span><div class="page-buttons">`;
  html += `<button class="btn btn-ghost page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Prev</button>`;

  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let p = start; p <= end; p += 1) {
    html += `<button class="btn ${p === page ? 'btn-primary' : 'btn-ghost'} page-btn" data-page="${p}">${p}</button>`;
  }

  html += `<button class="btn btn-ghost page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next</button></div>`;
  bar.innerHTML = html;
}

function updateSortIcons() {
  document.querySelectorAll('th.sortable').forEach((th) => {
    const col = th.dataset.col;
    const icon = th.querySelector('.sort-icon');
    if (!icon) return;
    icon.textContent = col === tableState.sortBy ? (tableState.sortDir === 'ASC' ? ' ▲' : ' ▼') : '';
  });
}

async function loadStats() {
  const res = await fetchJson('api/admin.php?action=stats');
  const data = res.data || {};
  dashboardState.stats = data;
  renderStats(data);
  renderDashboardDetails();
}

async function loadReports() {
  const status = encodeURIComponent(document.getElementById('statusFilter').value);
  const q = encodeURIComponent(document.getElementById('searchInput').value.trim());
  const perPage = encodeURIComponent(document.getElementById('perPageSelect').value);
  const { page, sortBy, sortDir } = tableState;

  const res = await fetchJson(
    `api/admin.php?action=reports&status=${status}&q=${q}&page=${page}&per_page=${perPage}&sort_by=${sortBy}&sort_dir=${sortDir}`
  );

  const tbody = document.getElementById('adminReportsTable');
  const rows = Array.isArray(res.data) ? res.data : [];
  dashboardState.reports = rows;
  renderDashboardDetails();
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8">Tidak ada data.</td></tr>';
    renderPagination(res.meta || null);
    return;
  }

  tbody.innerHTML = rows.map(reportRow).join('');
  renderPagination(res.meta || null);
  updateSortIcons();
}

async function loadGovernmentRequests() {
  const status = encodeURIComponent(document.getElementById('govStatusFilter').value);
  const res = await fetchJson(`api/admin.php?action=gov_requests&status=${status}`);

  const tbody = document.getElementById('govRequestsTable');
  const rows = Array.isArray(res.data) ? res.data : [];
  dashboardState.governmentRequests = rows;
  renderDashboardDetails();

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6">Belum ada data pendaftar.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(govRow).join('');
}

async function setStatus(reportId, status) {
  await fetchJson('api/admin.php?action=status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: reportId, status }),
  });

  const statusFilter = document.getElementById('statusFilter');
  if (statusFilter && statusFilter.value === 'hidden' && status !== 'hidden') {
    // Setelah unhide, tampilkan lagi semua status agar admin tetap melihat item yang baru dipindahkan.
    statusFilter.value = 'all';
    tableState.page = 1;
  }

  await Promise.all([loadStats(), loadReports()]);

  if (status === 'hidden') {
    alert('Laporan berhasil di-hide.');
  } else if (status === 'open') {
    alert('Laporan berhasil di-unhide.');
  }
}

async function setGovStatus(userId, status) {
  await fetchJson('api/admin.php?action=gov_status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId, status }),
  });

  await Promise.all([loadStats(), loadGovernmentRequests()]);
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
        const isActive = view.id === target;
        view.classList.toggle('hidden', !isActive);
        view.classList.toggle('active', isActive);
      });
    });
  });
}

function bindActions() {
  document.getElementById('applyFilterBtn').addEventListener('click', () => {
    tableState.page = 1;
    loadReports().catch((error) => alert(error.message));
  });

  document.getElementById('refreshGovBtn').addEventListener('click', () => {
    loadGovernmentRequests().catch((error) => alert(error.message));
  });

  document.getElementById('govStatusFilter').addEventListener('change', () => {
    loadGovernmentRequests().catch((error) => alert(error.message));
  });

  document.querySelectorAll('th.sortable').forEach((th) => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const col = th.dataset.col;
      if (!col) return;

      if (tableState.sortBy === col) {
        tableState.sortDir = tableState.sortDir === 'DESC' ? 'ASC' : 'DESC';
      } else {
        tableState.sortBy = col;
        tableState.sortDir = 'DESC';
      }

      tableState.page = 1;
      loadReports().catch((error) => alert(error.message));
    });
  });

  document.getElementById('paginationBar').addEventListener('click', (event) => {
    const btn = event.target.closest('.page-btn');
    if (!btn) return;

    const page = Number(btn.dataset.page || 1);
    if (!Number.isFinite(page) || page < 1) return;

    tableState.page = page;
    loadReports().catch((error) => alert(error.message));
  });

  document.getElementById('adminReportsTable').addEventListener('click', (event) => {
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

    const deleteBtn = event.target.closest('.delete-report-action');
    if (deleteBtn) {
      const reportId = Number(deleteBtn.dataset.reportId || 0);
      if (reportId <= 0) {
        alert('ID laporan tidak valid.');
        return;
      }

      deleteReport(reportId).catch((error) => alert(error.message));
      return;
    }

    const btn = event.target.closest('.report-action');
    if (!btn) return;

    const reportId = Number(btn.dataset.reportId || 0);
    const status = safeText(btn.dataset.status, 'open');
    if (reportId <= 0) {
      alert('ID laporan tidak valid.');
      return;
    }

    setStatus(reportId, status).catch((error) => alert(error.message));
  });

  document.getElementById('govRequestsTable').addEventListener('click', (event) => {
    const btn = event.target.closest('.gov-action');
    if (!btn) return;

    const userId = Number(btn.dataset.userId || 0);
    const status = safeText(btn.dataset.status, 'pending');
    if (userId <= 0) {
      alert('ID pendaftar tidak valid.');
      return;
    }

    setGovStatus(userId, status).catch((error) => alert(error.message));
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

  // --- User management ---

  const userNavBtn = document.querySelector('.side-btn[data-view="usersView"]');
  if (userNavBtn) {
    userNavBtn.addEventListener('click', () => {
      if (!userTableState.loaded) {
        userTableState.loaded = true;
        loadUsers().catch((error) => alert(error.message));
      }
    });
  }

  document.getElementById('applyUserFilterBtn')?.addEventListener('click', () => {
    userTableState.page = 1;
    loadUsers().catch((error) => alert(error.message));
  });

  document.getElementById('userSearchInput')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      userTableState.page = 1;
      loadUsers().catch((error) => alert(error.message));
    }
  });

  document.getElementById('usersTable')?.addEventListener('click', (event) => {
    const editBtn = event.target.closest('.edit-user-action');
    if (editBtn) {
      try {
        const payload = JSON.parse(decodeURIComponent(editBtn.dataset.user || ''));
        openEditUser(payload);
      } catch {
        alert('Gagal membuka form edit.');
      }
      return;
    }

    const deleteBtn = event.target.closest('.delete-user-action');
    if (!deleteBtn) return;

    const userId = Number(deleteBtn.dataset.userId || 0);
    if (userId <= 0) return;

    deleteUser(userId).catch((error) => alert(error.message));
  });

  document.getElementById('usersPaginationBar')?.addEventListener('click', (event) => {
    const btn = event.target.closest('.user-page-btn');
    if (!btn) return;

    const page = Number(btn.dataset.page || 1);
    if (!Number.isFinite(page) || page < 1) return;

    userTableState.page = page;
    loadUsers().catch((error) => alert(error.message));
  });

  document.getElementById('saveEditUserBtn')?.addEventListener('click', () => {
    saveEditUser().catch((error) => alert(error.message));
  });

  document.getElementById('closeEditUserBtn')?.addEventListener('click', () => {
    const dialog = document.getElementById('editUserDialog');
    if (dialog && typeof dialog.close === 'function') dialog.close();
  });
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
    <div class="report-detail-meta"><strong>Reporter:</strong> ${escapeHtml(safeText(data.reporterName))} (${escapeHtml(safeText(data.reporterEmail))})</div>
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

function userRoleLabel(role) {
  if (role === 'government') return 'Pemerintah';
  if (role === 'admin') return 'Admin';
  return 'Masyarakat';
}

function userAccountStatusLabel(status) {
  const map = {
    active: 'Aktif',
    inactive: 'Non-aktif',
    pending: 'Pending',
    verified: 'Terverifikasi',
    rejected: 'Ditolak',
  };
  return map[String(status || 'active').toLowerCase()] || String(status);
}

function userRow(user, rowNum) {
  const id = Number(user.id || 0);
  const name = safeText(user.name);
  const email = safeText(user.email);
  const role = safeText(user.role, 'user');
  const status = safeText(user.account_status, 'active');
  const agencyName = safeText(user.agency_name, '');
  const regionProvince = safeText(user.region_province, '');
  const regionCity = safeText(user.region_city, '');
  const createdAt = safeText(user.created_at);

  const locationInfo = role === 'government'
    ? [agencyName, regionCity, regionProvince].filter(Boolean).join(', ')
    : '-';

  const editPayload = encodeURIComponent(JSON.stringify({
    id,
    name: safeText(user.name, ''),
    email: safeText(user.email, ''),
    account_status: status,
  }));

  return `
    <tr>
      <td>${rowNum}</td>
      <td><strong>${escapeHtml(name)}</strong></td>
      <td>${escapeHtml(email)}</td>
      <td>${escapeHtml(userRoleLabel(role))}</td>
      <td><span class="status-pill status-${escapeHtml(status)}">${escapeHtml(userAccountStatusLabel(status))}</span></td>
      <td><small>${escapeHtml(locationInfo)}</small></td>
      <td><small>${escapeHtml(createdAt)}</small></td>
      <td>
        <div class="admin-row-actions">
          <button class="btn btn-secondary btn-xs edit-user-action" data-user='${editPayload}'>Edit</button>
          <button class="btn btn-ghost btn-xs delete-user-action" data-user-id="${id}">Hapus</button>
        </div>
      </td>
    </tr>
  `;
}

function renderUserPagination(meta) {
  const bar = document.getElementById('usersPaginationBar');
  if (!bar) return;
  if (!meta || Number(meta.total_pages || 0) <= 1) {
    bar.innerHTML = `<span class="page-info">Total: ${Number(meta ? meta.total : 0)} pengguna</span>`;
    return;
  }

  const page = Number(meta.page || 1);
  const totalPages = Number(meta.total_pages || 1);
  const total = Number(meta.total || 0);

  let html = `<span class="page-info">Total: ${total} | Halaman ${page} / ${totalPages}</span><div class="page-buttons">`;
  html += `<button class="btn btn-ghost user-page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Prev</button>`;

  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let p = start; p <= end; p += 1) {
    html += `<button class="btn ${p === page ? 'btn-primary' : 'btn-ghost'} user-page-btn" data-page="${p}">${p}</button>`;
  }

  html += `<button class="btn btn-ghost user-page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next</button></div>`;
  bar.innerHTML = html;
}

async function loadUsers() {
  const roleEl = document.getElementById('userRoleFilter');
  const searchEl = document.getElementById('userSearchInput');
  const perPageEl = document.getElementById('userPerPageSelect');
  if (!roleEl || !searchEl || !perPageEl) return;

  const role = encodeURIComponent(roleEl.value);
  const q = encodeURIComponent(searchEl.value.trim());
  const perPage = encodeURIComponent(perPageEl.value);
  const { page } = userTableState;

  const res = await fetchJson(`api/admin.php?action=users&role=${role}&q=${q}&page=${page}&per_page=${perPage}`);

  const tbody = document.getElementById('usersTable');
  if (!tbody) return;

  const rows = Array.isArray(res.data) ? res.data : [];
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8">Tidak ada data pengguna.</td></tr>';
    renderUserPagination(res.meta || null);
    return;
  }

  const meta = res.meta || {};
  const offset = (Number(meta.page || 1) - 1) * Number(meta.per_page || 25);
  tbody.innerHTML = rows.map((u, i) => userRow(u, offset + i + 1)).join('');
  renderUserPagination(meta);
}

function openEditUser(data) {
  const dialog = document.getElementById('editUserDialog');
  if (!dialog) return;

  document.getElementById('editUserId').value = String(data.id || '');
  document.getElementById('editUserName').value = String(data.name || '');
  document.getElementById('editUserEmail').value = String(data.email || '');
  document.getElementById('editUserStatus').value = String(data.account_status || 'active');

  if (typeof dialog.showModal === 'function') {
    dialog.showModal();
  }
}

async function saveEditUser() {
  const id = Number(document.getElementById('editUserId').value || 0);
  const name = document.getElementById('editUserName').value.trim();
  const email = document.getElementById('editUserEmail').value.trim();
  const accountStatus = document.getElementById('editUserStatus').value;

  if (id <= 0 || !name || !email) {
    alert('Lengkapi semua data pengguna.');
    return;
  }

  await fetchJson('api/admin.php?action=user_update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: id, name, email, account_status: accountStatus }),
  });

  const dialog = document.getElementById('editUserDialog');
  if (dialog && typeof dialog.close === 'function') dialog.close();

  await loadUsers();
}

async function deleteUser(userId) {
  if (!confirm('Hapus pengguna ini? Tindakan tidak dapat dibatalkan.')) return;

  await fetchJson('api/admin.php?action=user_delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId }),
  });

  await loadUsers();
}

async function deleteReport(reportId) {
  if (!confirm('Hapus laporan ini? Tindakan tidak dapat dibatalkan.')) return;

  await fetchJson('api/admin.php?action=report_delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: reportId }),
  });

  await Promise.all([loadStats(), loadReports()]);
}

(async function initAdmin() {
  initSidebar();
  bindActions();

  const tasks = await Promise.allSettled([loadStats(), loadReports(), loadGovernmentRequests()]);
  const failed = tasks.find((t) => t.status === 'rejected');
  if (failed && failed.reason) {
    alert(failed.reason.message || 'Sebagian data dashboard gagal dimuat.');
  }
})();
