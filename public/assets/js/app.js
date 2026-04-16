const state = {
  reports: [],
  myReports: [],
  rejectedReports: [],
  notifications: [],
  unreadNotifications: 0,
  filteredReports: [],
  map: null,
  createMap: null,
  createMarker: null,
  markersLayer: null,
  heatLayer: null,
  heatPoints: [],
  heatVisible: true,
  currentUser: null,
  reverseGeocodeAbort: null,
  mapSearchAbort: null,
  regionSyncTimer: null,
  commentModalReportId: null,
  resubmitReportId: null,
  theme: 'light',
};

const REGION_API_BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';

const iconByCategory = {
  banjir: '🌊',
  jalan_rusak: '🛣️',
  sampah: '🗑️',
  kriminalitas: '🚨',
  kemacetan: '🚗',
  listrik: '⚡',
  lainnya: '📍',
};

function statusLabel(value) {
  const status = String(value || 'open').trim().toLowerCase();
  if (status === 'in_progress') return 'Sedang Diproses';
  if (status === 'resolved') return 'Selesai';
  if (status === 'hidden') return 'Disembunyikan oleh Admin';
  if (status === 'rejected_pending_media') return 'Tertolak: Gambar Tidak Sesuai';
  return 'Belum Diproses';
}

function fmtDate(value) {
  const d = new Date(String(value || '').replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString('id-ID');
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function endpoint(path) {
  return `api/${path}`;
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const data = await response.json();
  if (!response.ok || !data.ok) {
    throw new Error(data.message || 'Request gagal');
  }
  return data;
}

function setAuthUi() {
  const authState = document.getElementById('authState');
  if (!state.currentUser) {
    authState.textContent = 'Belum login';
    return;
  }

  authState.textContent = `Login sebagai ${state.currentUser.name} (${state.currentUser.role})`;

  const nameInput = document.getElementById('settingsNameInput');
  const emailInput = document.getElementById('settingsEmailInput');
  if (nameInput instanceof HTMLInputElement) {
    nameInput.value = String(state.currentUser.name || '');
  }
  if (emailInput instanceof HTMLInputElement) {
    emailInput.value = String(state.currentUser.email || '');
  }
}

function activateView(target) {
  const sideButtons = document.querySelectorAll('.side-btn[data-view]');
  const views = document.querySelectorAll('.dash-view');

  sideButtons.forEach((btn) => {
    btn.classList.toggle('is-active', btn.dataset.view === target);
  });

  views.forEach((view) => {
    const active = view.id === target;
    view.classList.toggle('hidden', !active);
    view.classList.toggle('active', active);
  });

  if (target === 'mapView' && state.map) {
    setTimeout(() => {
      state.map.invalidateSize();
      ensureHeatLayerVisible();
    }, 120);
  }

  if (target === 'createView' && state.createMap) {
    setTimeout(() => state.createMap.invalidateSize(), 120);
  }

  if (target === 'notificationsView') {
    loadNotifications().catch(() => {
      // Keep UI responsive on notification load failure.
    });
  }

  if (window.matchMedia('(max-width: 980px)').matches) {
    document.body.classList.remove('mobile-menu-open');
  }
}

function initMobileMenu() {
  const menuBtn = document.getElementById('mobileMenuBtn');
  const overlay = document.getElementById('mobileMenuOverlay');

  if (!menuBtn || !overlay) return;

  menuBtn.addEventListener('click', () => {
    document.body.classList.toggle('mobile-menu-open');
  });

  overlay.addEventListener('click', () => {
    document.body.classList.remove('mobile-menu-open');
  });

  window.addEventListener('resize', () => {
    if (!window.matchMedia('(max-width: 980px)').matches) {
      document.body.classList.remove('mobile-menu-open');
    }
  });
}

function initSidebar() {
  const sideButtons = document.querySelectorAll('.side-btn[data-view]');

  sideButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      activateView(btn.dataset.view);
    });
  });
}

function isMapViewVisible() {
  const mapView = document.getElementById('mapView');
  if (!mapView) return false;
  return mapView.classList.contains('active') && !mapView.classList.contains('hidden');
}

function ensureHeatLayerVisible() {
  if (!state.map || !state.heatLayer) return;

  const shouldShow = state.heatVisible && isMapViewVisible();
  const hasLayer = state.map.hasLayer(state.heatLayer);

  if (shouldShow && !hasLayer) {
    state.heatLayer.addTo(state.map);
  }

  if (!shouldShow && hasLayer) {
    state.map.removeLayer(state.heatLayer);
  }
}

function normalizeRegionText(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/\b(kota|kabupaten|kab\.|kec\.|kecamatan|kelurahan|desa)\b/g, ' ')
    .replace(/[^a-z0-9\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function findBestRegionOption(options, rawValue) {
  const normalizedTarget = normalizeRegionText(rawValue);
  if (normalizedTarget === '') {
    return null;
  }

  const exact = options.find((opt) => normalizeRegionText(opt.value) === normalizedTarget);
  if (exact) {
    return exact;
  }

  const fuzzy = options.find((opt) => {
    const normalizedOption = normalizeRegionText(opt.value);
    if (normalizedOption === '') return false;
    return normalizedOption.includes(normalizedTarget) || normalizedTarget.includes(normalizedOption);
  });

  return fuzzy || null;
}

function setFieldValue(name, value) {
  const input = document.querySelector(`#reportForm [name="${name}"]`);
  if (!input) {
    return;
  }

  if (input instanceof HTMLSelectElement) {
    if (String(value || '').trim() === '') {
      return;
    }

    const options = Array.from(input.options || []).filter((opt) => String(opt.value || '').trim() !== '');
    const matched = findBestRegionOption(options, String(value || ''));
    if (matched) {
      input.value = matched.value;
    }
    return;
  }

  input.value = value;
}

function setCoordinates(lat, lng) {
  const latInput = document.getElementById('latitudeInput');
  const lngInput = document.getElementById('longitudeInput');
  latInput.value = Number(lat).toFixed(6);
  lngInput.value = Number(lng).toFixed(6);
}

async function syncRegionDropdownByNames(region) {
  const provinceSelect = document.getElementById('provinceSelect');
  const citySelect = document.getElementById('citySelect');
  const districtSelect = document.getElementById('districtSelect');
  const subdistrictSelect = document.getElementById('subdistrictSelect');

  if (!(provinceSelect instanceof HTMLSelectElement)
      || !(citySelect instanceof HTMLSelectElement)
      || !(districtSelect instanceof HTMLSelectElement)
      || !(subdistrictSelect instanceof HTMLSelectElement)) {
    return;
  }

  if (provinceSelect.options.length <= 1) {
    try {
      const provinces = await fetchRegionRows('provinces.json');
      populateRegionSelect(provinceSelect, provinces, 'Pilih provinsi...');
    } catch {
      // Keep form usable if region API fails.
    }
  }

  const provinceName = String(region.province || '').trim();
  const cityName = String(region.city || '').trim();
  const districtName = String(region.district || '').trim();
  const subdistrictName = String(region.subdistrict || '').trim();

  let provinceId = '';
  if (provinceName !== '') {
    const pOptions = Array.from(provinceSelect.options || []).filter((opt) => String(opt.value || '').trim() !== '');
    const pMatch = findBestRegionOption(pOptions, provinceName);
    if (pMatch) {
      provinceSelect.value = pMatch.value;
      provinceId = String(pMatch.dataset.id || '').trim();
    }
  }

  if (provinceId === '') {
    return;
  }

  try {
    const cities = await fetchRegionRows(`regencies/${provinceId}.json`);
    populateRegionSelect(citySelect, cities, 'Pilih kabupaten/kota...');
  } catch {
    return;
  }

  let cityId = '';
  if (cityName !== '') {
    const cOptions = Array.from(citySelect.options || []).filter((opt) => String(opt.value || '').trim() !== '');
    const cMatch = findBestRegionOption(cOptions, cityName);
    if (cMatch) {
      citySelect.value = cMatch.value;
      cityId = String(cMatch.dataset.id || '').trim();
    }
  }

  if (cityId === '') {
    return;
  }

  try {
    const districts = await fetchRegionRows(`districts/${cityId}.json`);
    populateRegionSelect(districtSelect, districts, 'Pilih kecamatan...');
  } catch {
    return;
  }

  let districtId = '';
  if (districtName !== '') {
    const dOptions = Array.from(districtSelect.options || []).filter((opt) => String(opt.value || '').trim() !== '');
    const dMatch = findBestRegionOption(dOptions, districtName);
    if (dMatch) {
      districtSelect.value = dMatch.value;
      districtId = String(dMatch.dataset.id || '').trim();
    }
  }

  if (districtId === '') {
    return;
  }

  try {
    const villages = await fetchRegionRows(`villages/${districtId}.json`);
    populateRegionSelect(subdistrictSelect, villages, 'Pilih kelurahan/desa...');
  } catch {
    return;
  }

  if (subdistrictName !== '') {
    const sOptions = Array.from(subdistrictSelect.options || []).filter((opt) => String(opt.value || '').trim() !== '');
    const sMatch = findBestRegionOption(sOptions, subdistrictName);
    if (sMatch) {
      subdistrictSelect.value = sMatch.value;
    }
  }
}

async function reverseGeocodeAndFill(lat, lng) {
  if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
    return;
  }

  if (state.reverseGeocodeAbort) {
    state.reverseGeocodeAbort.abort();
  }

  const controller = new AbortController();
  state.reverseGeocodeAbort = controller;

  try {
    const response = await fetch(
      `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(String(lat))}&lon=${encodeURIComponent(String(lng))}&addressdetails=1&accept-language=id`,
      {
        headers: {
          Accept: 'application/json',
        },
        signal: controller.signal,
      },
    );

    if (!response.ok) {
      return;
    }

    const data = await response.json();
    const address = data.address || {};

    const province = address.state || address.province || '';
    const city = address.city || address.regency || address.county || address.municipality || '';
    const district = address.city_district || address.district || address.suburb || '';
    const subdistrict = address.village || address.hamlet || address.quarter || address.neighbourhood || '';

    await syncRegionDropdownByNames({ province, city, district, subdistrict });
    if (province) setFieldValue('province', province);
    if (city) setFieldValue('city', city);
    if (district) setFieldValue('district', district);
    if (subdistrict) setFieldValue('subdistrict', subdistrict);
  } catch (error) {
    if (error?.name !== 'AbortError') {
      // Keep manual input untouched if reverse geocoding fails.
    }
  } finally {
    if (state.reverseGeocodeAbort === controller) {
      state.reverseGeocodeAbort = null;
    }
  }
}

async function updateLocationFromMap(lat, lng) {
  setCoordinates(lat, lng);
  await reverseGeocodeAndFill(lat, lng);
}

function populateRegionSelect(selectEl, rows, placeholder) {
  if (!(selectEl instanceof HTMLSelectElement)) return;

  const options = [`<option value="">${placeholder}</option>`];
  rows.forEach((row) => {
    const id = escapeHtml(String(row.id || ''));
    const name = escapeHtml(String(row.name || ''));
    options.push(`<option value="${name}" data-id="${id}">${name}</option>`);
  });

  selectEl.innerHTML = options.join('');
  selectEl.disabled = rows.length === 0;
}

function clearRegionSelect(selectEl, placeholder) {
  if (!(selectEl instanceof HTMLSelectElement)) return;
  selectEl.innerHTML = `<option value="">${placeholder}</option>`;
  selectEl.disabled = true;
}

function selectedRegionId(selectEl) {
  if (!(selectEl instanceof HTMLSelectElement)) return '';
  const option = selectEl.selectedOptions?.[0];
  if (!option) return '';
  return String(option.dataset.id || '').trim();
}

async function fetchRegionRows(path) {
  const response = await fetch(`${REGION_API_BASE}/${path}`, {
    headers: { Accept: 'application/json' },
  });
  if (!response.ok) {
    throw new Error('Gagal memuat data wilayah dari API.');
  }

  const data = await response.json();
  return Array.isArray(data) ? data : [];
}

async function geocodeLocationQuery(query) {
  const q = String(query || '').trim();
  if (q === '') return null;

  if (state.mapSearchAbort) {
    state.mapSearchAbort.abort();
  }

  const controller = new AbortController();
  state.mapSearchAbort = controller;

  try {
    const response = await fetch(
      `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&accept-language=id&countrycodes=id&q=${encodeURIComponent(q)}`,
      {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      },
    );

    if (!response.ok) {
      return null;
    }

    const rows = await response.json();
    if (!Array.isArray(rows) || rows.length === 0) {
      return null;
    }

    const first = rows[0];
    const lat = Number(first.lat);
    const lng = Number(first.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      return null;
    }

    return { lat, lng };
  } catch (error) {
    if (error?.name === 'AbortError') return null;
    return null;
  } finally {
    if (state.mapSearchAbort === controller) {
      state.mapSearchAbort = null;
    }
  }
}

async function applyMapPoint(lat, lng) {
  if (!state.createMap) return;

  state.createMap.setView([lat, lng], Math.max(state.createMap.getZoom(), 14));
  if (!state.createMarker) {
    state.createMarker = L.marker([lat, lng]).addTo(state.createMap);
  } else {
    state.createMarker.setLatLng([lat, lng]);
  }

  await updateLocationFromMap(lat, lng);
}

function scheduleRegionToMapSync() {
  if (state.regionSyncTimer) {
    clearTimeout(state.regionSyncTimer);
  }

  state.regionSyncTimer = setTimeout(async () => {
    const province = String(document.getElementById('provinceSelect')?.value || '').trim();
    const city = String(document.getElementById('citySelect')?.value || '').trim();
    const district = String(document.getElementById('districtSelect')?.value || '').trim();
    const subdistrict = String(document.getElementById('subdistrictSelect')?.value || '').trim();

    const parts = [subdistrict, district, city, province, 'Indonesia'].filter(Boolean);
    if (parts.length < 3) {
      return;
    }

    const point = await geocodeLocationQuery(parts.join(', '));
    if (!point) return;

    await applyMapPoint(point.lat, point.lng);
  }, 350);
}

async function initRegionDropdowns() {
  const provinceSelect = document.getElementById('provinceSelect');
  const citySelect = document.getElementById('citySelect');
  const districtSelect = document.getElementById('districtSelect');
  const subdistrictSelect = document.getElementById('subdistrictSelect');

  if (!(provinceSelect instanceof HTMLSelectElement)
      || !(citySelect instanceof HTMLSelectElement)
      || !(districtSelect instanceof HTMLSelectElement)
      || !(subdistrictSelect instanceof HTMLSelectElement)) {
    return;
  }

  clearRegionSelect(citySelect, 'Pilih kabupaten/kota...');
  clearRegionSelect(districtSelect, 'Pilih kecamatan...');
  clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

  try {
    const provinces = await fetchRegionRows('provinces.json');
    populateRegionSelect(provinceSelect, provinces, 'Pilih provinsi...');
  } catch {
    // Keep form usable even if region API fails.
  }

  provinceSelect.addEventListener('change', async () => {
    clearRegionSelect(citySelect, 'Pilih kabupaten/kota...');
    clearRegionSelect(districtSelect, 'Pilih kecamatan...');
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const provinceId = selectedRegionId(provinceSelect);
    if (provinceId !== '') {
      try {
        const cities = await fetchRegionRows(`regencies/${provinceId}.json`);
        populateRegionSelect(citySelect, cities, 'Pilih kabupaten/kota...');
      } catch {
        // noop
      }
    }

    scheduleRegionToMapSync();
  });

  citySelect.addEventListener('change', async () => {
    clearRegionSelect(districtSelect, 'Pilih kecamatan...');
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const cityId = selectedRegionId(citySelect);
    if (cityId !== '') {
      try {
        const districts = await fetchRegionRows(`districts/${cityId}.json`);
        populateRegionSelect(districtSelect, districts, 'Pilih kecamatan...');
      } catch {
        // noop
      }
    }

    scheduleRegionToMapSync();
  });

  districtSelect.addEventListener('change', async () => {
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const districtId = selectedRegionId(districtSelect);
    if (districtId !== '') {
      try {
        const villages = await fetchRegionRows(`villages/${districtId}.json`);
        populateRegionSelect(subdistrictSelect, villages, 'Pilih kelurahan/desa...');
      } catch {
        // noop
      }
    }

    scheduleRegionToMapSync();
  });

  subdistrictSelect.addEventListener('change', () => {
    scheduleRegionToMapSync();
  });
}

async function searchLocationOnCreateMap() {
  const input = document.getElementById('mapLocationSearchInput');
  if (!(input instanceof HTMLInputElement)) return;

  const query = input.value.trim();
  if (query === '') return;

  const point = await geocodeLocationQuery(query + ', Indonesia');
  if (!point) {
    alert('Lokasi tidak ditemukan. Coba kata kunci lain.');
    return;
  }

  await applyMapPoint(point.lat, point.lng);
}

async function useCurrentLocation() {
  const feedback = document.getElementById('currentLocationFeedback');

  if (!('geolocation' in navigator)) {
    if (feedback) feedback.textContent = 'Browser tidak mendukung fitur lokasi terkini.';
    return;
  }

  if (feedback) feedback.textContent = 'Mengambil lokasi terkini...';

  try {
    const position = await new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 0,
      });
    });

    const lat = Number(position?.coords?.latitude);
    const lng = Number(position?.coords?.longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      throw new Error('Koordinat lokasi tidak valid.');
    }

    await applyMapPoint(lat, lng);
    if (feedback) feedback.textContent = 'Lokasi terkini berhasil digunakan.';
  } catch (error) {
    const code = Number(error?.code || 0);
    let message = 'Gagal mengambil lokasi terkini.';
    if (code === 1) message = 'Izin lokasi ditolak. Aktifkan izin lokasi di browser Anda.';
    if (code === 2) message = 'Lokasi tidak tersedia. Coba lagi di area dengan sinyal lebih baik.';
    if (code === 3) message = 'Pengambilan lokasi timeout. Silakan coba lagi.';
    if (feedback) feedback.textContent = message;
  }
}

function initCreateMapSearch() {
  const input = document.getElementById('mapLocationSearchInput');
  const datalist = document.getElementById('mapLocationSuggestionList');
  const button = document.getElementById('mapLocationSearchBtn');

  if (!(input instanceof HTMLInputElement) || !(datalist instanceof HTMLDataListElement)) {
    return;
  }

  let timer = null;
  input.addEventListener('input', () => {
    const query = input.value.trim();
    if (timer) clearTimeout(timer);

    if (query.length < 3) {
      datalist.innerHTML = '';
      return;
    }

    timer = setTimeout(async () => {
      try {
        const response = await fetch(
          `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&accept-language=id&countrycodes=id&q=${encodeURIComponent(query)}`,
          { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) return;

        const rows = await response.json();
        if (!Array.isArray(rows)) return;

        datalist.innerHTML = rows
          .map((row) => `<option value="${escapeHtml(String(row.display_name || ''))}"></option>`)
          .join('');
      } catch {
        // noop
      }
    }, 280);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      searchLocationOnCreateMap().catch(() => {
        // noop
      });
    }
  });

  if (button instanceof HTMLButtonElement) {
    button.addEventListener('click', () => {
      searchLocationOnCreateMap().catch(() => {
        // noop
      });
    });
  }
}

function initCreateMap() {
  const mapContainer = document.getElementById('createReportMap');
  if (!mapContainer) return;

  const initial = [-6.2, 106.816666];
  state.createMap = L.map('createReportMap').setView(initial, 11);

  initRegionDropdowns().catch(() => {
    // Region API is optional; form still works with map click.
  });
  initCreateMapSearch();

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(state.createMap);

  state.createMap.on('click', async (e) => {
    const { lat, lng } = e.latlng;

    if (!state.createMarker) {
      state.createMarker = L.marker([lat, lng]).addTo(state.createMap);
    } else {
      state.createMarker.setLatLng([lat, lng]);
    }

    await updateLocationFromMap(lat, lng);
  });

  // Seed default coordinates from initial map center so hidden fields are always populated.
  updateLocationFromMap(initial[0], initial[1]).catch(() => {
    setCoordinates(initial[0], initial[1]);
  });

  document.getElementById('useMapCenterBtn').addEventListener('click', async () => {
    const center = state.createMap.getCenter();

    if (!state.createMarker) {
      state.createMarker = L.marker([center.lat, center.lng]).addTo(state.createMap);
    } else {
      state.createMarker.setLatLng([center.lat, center.lng]);
    }

    await updateLocationFromMap(center.lat, center.lng);
  });

  const latInput = document.getElementById('latitudeInput');
  const lngInput = document.getElementById('longitudeInput');
  const syncFromInput = async () => {
    const lat = Number(latInput.value);
    const lng = Number(lngInput.value);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    state.createMap.setView([lat, lng], Math.max(state.createMap.getZoom(), 14));
    if (!state.createMarker) {
      state.createMarker = L.marker([lat, lng]).addTo(state.createMap);
    } else {
      state.createMarker.setLatLng([lat, lng]);
    }

    await reverseGeocodeAndFill(lat, lng);
  };

  latInput.addEventListener('change', syncFromInput);
  lngInput.addEventListener('change', syncFromInput);
}

function initMap() {
  const initial = [-6.2, 106.816666];

  state.map = L.map('map').setView(initial, 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(state.map);

  state.markersLayer = L.layerGroup().addTo(state.map);

}

function renderProvinceFilter() {
  const select = document.getElementById('provinceFilter');
  const unique = Array.from(new Set(state.reports.map((r) => String(r.province || '').trim()).filter(Boolean))).sort();
  const current = select.value || 'all';

  select.innerHTML = '<option value="all">Semua Provinsi</option>' + unique.map((p) => `<option value="${p}">${p}</option>`).join('');
  select.value = unique.includes(current) ? current : 'all';
}

function applyProvinceFilter() {
  const province = document.getElementById('provinceFilter').value;
  if (province === 'all') {
    state.filteredReports = [...state.reports];
  } else {
    state.filteredReports = state.reports.filter((r) => String(r.province || '') === province);
  }

  renderReports();
  renderProvinceList();
  updateMarkers();
}

function renderProvinceList() {
  const container = document.getElementById('provinceList');
  if (!state.reports.length) {
    container.innerHTML = '<p class="province-empty">Belum ada data provinsi.</p>';
    return;
  }

  const grouped = {};
  state.reports.forEach((r) => {
    const key = r.province && String(r.province).trim() !== '' ? String(r.province).trim() : 'Tanpa Provinsi';
    grouped[key] = grouped[key] || [];
    grouped[key].push(r);
  });

  const html = Object.keys(grouped).sort().map((province) => {
    const reports = grouped[province];
    const rows = reports.slice(0, 8).map((r) => `
      <li class="province-report-row">
        <div class="province-report-main">
          <p class="province-report-title">${r.title}</p>
          <span class="status-pill status-${String(r.status || 'open')}">${statusLabel(r.status)}</span>
        </div>
        <button class="btn btn-ghost province-open-report" type="button" data-report-id="${Number(r.id || 0)}" data-province="${province}">Lihat</button>
      </li>
    `).join('');

    const moreCount = Math.max(0, reports.length - 8);
    return `
      <article class="report-item province-card">
        <div class="province-card__head">
          <h4>${province}</h4>
          <span class="province-total-pill">${reports.length} laporan</span>
        </div>
        <p class="meta">Menampilkan 8 laporan terbaru pada provinsi ini.</p>
        <ul class="province-report-list">${rows}</ul>
        ${moreCount > 0 ? `<p class="province-more">+${moreCount} laporan lainnya</p>` : ''}
      </article>
    `;
  }).join('');

  container.innerHTML = html;
}

function updateMarkers() {
  if (!state.markersLayer) return;

  state.markersLayer.clearLayers();

  state.filteredReports.forEach((report) => {
    const lat = Number(report.latitude);
    const lng = Number(report.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    const marker = L.marker([lat, lng]);
    marker.bindPopup(`
      <strong>${iconByCategory[report.category_ai] || '📍'} ${report.title}</strong><br>
      <small>${report.category_ai} | ${report.urgency_ai}</small><br>
      <small>Status: ${statusLabel(report.status)}</small><br>
      <small>+${report.confirms} / -${report.rejects}</small>
    `);

    marker.addTo(state.markersLayer);
  });
}

function updateHeatmap(points) {
  state.heatPoints = Array.isArray(points) ? points : [];

  if (state.heatLayer) {
    state.map.removeLayer(state.heatLayer);
  }

  state.heatLayer = L.heatLayer(state.heatPoints, {
    radius: 25,
    blur: 20,
    minOpacity: 0.35,
    gradient: {
      0.2: '#fbf8b4',
      0.4: '#fca311',
      0.7: '#e85d04',
      1.0: '#9d0208',
    },
  });

  ensureHeatLayerVisible();
}

function renderReports() {
  const container = document.getElementById('reportsList');

  if (state.filteredReports.length === 0) {
    container.innerHTML = '<p>Belum ada laporan pada filter ini.</p>';
    document.getElementById('statTotal').textContent = '0';
    document.getElementById('statCritical').textContent = '0';
    document.getElementById('statConfirm').textContent = '0';
    return;
  }

  container.innerHTML = state.filteredReports.map((report) => {
    const urgencyClass = report.urgency_ai === 'critical' ? 'badge-critical' : 'badge-normal';
    const reportStatus = String(report.status || 'open');
    const mediaPaths = report.media_paths
      ? (() => { try { return JSON.parse(report.media_paths); } catch { return [report.media_path].filter(Boolean); } })()
      : (report.media_path ? [report.media_path] : []);

    const mediaHtml = mediaPaths.length > 0
      ? mediaPaths.map((p) => {
          const url = `../${p}`;
          const isVideo = String(p).toLowerCase().endsWith('.mp4');
          return isVideo
            ? `<video class="report-media" src="${url}" controls></video>`
            : `<a href="${url}" target="_blank" rel="noopener"><img class="report-media" src="${url}" alt="bukti"></a>`;
        }).join('')
      : '';

    const region = [report.province, report.city, report.district, report.subdistrict]
      .filter((x) => String(x || '').trim() !== '')
      .join(', ');

    return `
      <article class="report-item" id="report-item-${Number(report.id || 0)}">
        <h4 class="report-title">${report.title}</h4>
        <p class="meta report-meta">${fmtDate(report.created_at)} | ${report.category_ai} | Pelapor: ${report.reporter_name || 'Anonim'}
          <span class="badge ${urgencyClass}">${report.urgency_ai}</span>
        </p>
        <p class="meta report-status-row">
          <strong>Status tindak lanjut:</strong>
          <span class="status-pill status-${reportStatus}">${statusLabel(report.status)}</span>
        </p>
        <p class="report-description">${report.description}</p>
        <p class="report-ai"><strong>AI:</strong> ${report.ai_summary}</p>
        <div class="report-media-grid">${mediaHtml}</div>
        <p class="meta report-meta">Wilayah: ${region || '-'}</p>
        <p class="meta report-meta">Lokasi: ${Number(report.latitude).toFixed(5)}, ${Number(report.longitude).toFixed(5)}</p>
        <p class="meta report-meta">Validasi publik: +${report.confirms} / -${report.rejects}</p>
        <div class="report-actions report-actions--icon-only">
          <button class="btn btn-secondary action-icon-btn" type="button" title="Konfirmasi" aria-label="Konfirmasi" onclick="voteReport(${report.id}, 'confirm')">
            <span class="icon-glyph" aria-hidden="true">&#10003;</span>
            <span class="sr-only">Konfirmasi</span>
          </button>
          <button class="btn btn-secondary action-icon-btn" type="button" title="Tolak" aria-label="Tolak" onclick="voteReport(${report.id}, 'reject')">
            <span class="icon-glyph" aria-hidden="true">&#10005;</span>
            <span class="sr-only">Tolak</span>
          </button>
          <a class="btn btn-ghost action-icon-btn" href="api/export.php?report_id=${report.id}" target="_blank" rel="noopener" title="Export PDF" aria-label="Export PDF">
            <span class="icon-glyph" aria-hidden="true">&#10515;</span>
            <span class="sr-only">Export PDF</span>
          </a>
          <button class="btn btn-ghost action-icon-btn" type="button" title="Komentar" aria-label="Komentar" onclick="openCommentModal(${report.id})">
            <svg class="action-icon-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M5 6.5h14a1.5 1.5 0 0 1 1.5 1.5v7A1.5 1.5 0 0 1 19 16.5h-7.3l-3.8 3v-3H5A1.5 1.5 0 0 1 3.5 15V8A1.5 1.5 0 0 1 5 6.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="sr-only">Komentar</span>
          </button>
        </div>
      </article>
    `;
  }).join('');

  const total = state.filteredReports.length;
  const critical = state.filteredReports.filter((r) => r.urgency_ai === 'critical').length;
  const confirms = state.filteredReports.reduce((sum, r) => sum + Number(r.confirms || 0), 0);

  document.getElementById('statTotal').textContent = String(total);
  document.getElementById('statCritical').textContent = String(critical);
  document.getElementById('statConfirm').textContent = String(confirms);
}

function renderMyReports() {
  const container = document.getElementById('myReportsList');
  if (!container) return;

  const myReports = state.myReports.filter((r) => String(r.status || '') !== 'rejected_pending_media');

  if (!myReports.length) {
    container.innerHTML = '<p>Anda belum pernah membuat laporan.</p>';
    return;
  }

  container.innerHTML = myReports.map((report) => {
    const reportStatus = String(report.status || 'open');
    const region = [report.province, report.city, report.district, report.subdistrict]
      .filter((x) => String(x || '').trim() !== '')
      .join(', ');

    return `
      <article class="report-item">
        <h4 class="report-title">${escapeHtml(report.title || '-')}</h4>
        <p class="meta report-meta">${fmtDate(report.created_at)} | ${escapeHtml(report.category_ai || '-')}</p>
        <p class="meta report-status-row">
          <strong>Status tindak lanjut:</strong>
          <span class="status-pill status-${reportStatus}">${statusLabel(report.status)}</span>
        </p>
        <p class="report-description">${escapeHtml(report.description || '-')}</p>
        <p class="meta report-meta">Wilayah: ${escapeHtml(region || '-')}</p>
        <div class="report-actions">
          <a class="btn btn-ghost" href="api/export.php?report_id=${Number(report.id)}" target="_blank" rel="noopener">Export PDF</a>
          <button class="btn btn-secondary" type="button" onclick="deleteMyReport(${Number(report.id)})">Hapus Laporan</button>
        </div>
      </article>
    `;
  }).join('');
}

function renderRejectedReports() {
  const container = document.getElementById('rejectedReportsList');
  if (!container) return;

  if (!state.rejectedReports.length) {
    container.innerHTML = '<p>Tidak ada laporan tertolak. Semua laporan Anda saat ini sudah lolos validasi visual.</p>';
    return;
  }

  container.innerHTML = state.rejectedReports.map((report) => {
    const reason = String(report.ai_summary || '').trim();

    return `
      <article class="report-item report-item--rejected">
        <h4 class="report-title">${escapeHtml(report.title || '-')}</h4>
        <p class="meta report-meta">${fmtDate(report.created_at)} | ${escapeHtml(report.category_ai || '-')}</p>
        <p class="meta report-status-row">
          <strong>Status:</strong>
          <span class="status-pill status-rejected_pending_media">${statusLabel(report.status)}</span>
        </p>
        <p class="report-description">${escapeHtml(report.description || '-')}</p>
        <div class="report-rejected-reason">
          <strong>Catatan AI:</strong>
          <p>${escapeHtml(reason || 'Bukti visual tidak konsisten dengan deskripsi laporan.')}</p>
        </div>
        <div class="report-actions">
          <button class="btn btn-primary" type="button" onclick="openResubmitModal(${Number(report.id)})">Ganti Gambar Sekarang</button>
        </div>
      </article>
    `;
  }).join('');
}

function openProvinceReport(reportId, province) {
  const provinceFilter = document.getElementById('provinceFilter');

  if (provinceFilter) {
    if (province) {
      provinceFilter.value = province;
    } else {
      provinceFilter.value = 'all';
    }
    applyProvinceFilter();
  }

  activateView('feedView');

  setTimeout(() => {
    const target = document.getElementById(`report-item-${Number(reportId || 0)}`);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      target.classList.add('report-item-focus');
      window.setTimeout(() => target.classList.remove('report-item-focus'), 1500);
    }
  }, 120);
}

async function loadReportsAndMap() {
  const [reportsRes, heatmapRes] = await Promise.all([
    fetchJson(endpoint('reports.php')),
    fetchJson(endpoint('heatmap.php')),
  ]);

  state.reports = reportsRes.data;
  renderProvinceFilter();
  applyProvinceFilter();
  updateHeatmap(heatmapRes.data);
}

async function loadMyReports() {
  const myReportsRes = await fetchJson(endpoint('reports.php?mine=1'));
  state.myReports = Array.isArray(myReportsRes.data) ? myReportsRes.data : [];
  renderMyReports();
}

async function loadRejectedReports() {
  const rejectedRes = await fetchJson(endpoint('reports.php?mine=1&status=rejected_pending_media'));
  state.rejectedReports = Array.isArray(rejectedRes.data) ? rejectedRes.data : [];
  renderRejectedReports();
}

function renderNotifications() {
  const container = document.getElementById('notificationsList');
  const badge = document.getElementById('notifUnreadCount');

  if (badge) {
    badge.textContent = String(state.unreadNotifications);
    badge.classList.toggle('is-empty', state.unreadNotifications <= 0);
  }

  if (!container) return;

  if (!state.notifications.length) {
    container.innerHTML = '<p class="notification-empty">Belum ada notifikasi.</p>';
    return;
  }

  container.innerHTML = state.notifications.map((notif) => {
    const isRead = Number(notif.is_read || 0) === 1;
    const reportId = Number(notif.report_id || 0);

    return `
      <article class="notification-item ${isRead ? 'is-read' : 'is-unread'}">
        <div class="notification-item__head">
          <strong>${escapeHtml(notif.title || 'Notifikasi')}</strong>
          <small>${fmtDate(notif.created_at)}</small>
        </div>
        <p class="notification-item__body">${escapeHtml(notif.message || '-')}</p>
        ${reportId > 0 ? `<button class="btn btn-ghost btn-sm notification-open-report" type="button" data-report-id="${reportId}">Buka Laporan</button>` : ''}
      </article>
    `;
  }).join('');
}

async function loadNotifications() {
  const res = await fetchJson(endpoint('notifications.php?limit=50'));
  state.notifications = Array.isArray(res.data) ? res.data : [];
  state.unreadNotifications = Number(res?.meta?.unread || 0);
  renderNotifications();
}

async function markAllNotificationsRead() {
  await fetchJson(endpoint('notifications.php?action=mark_all_read'), {
    method: 'POST',
  });

  state.notifications = state.notifications.map((n) => ({ ...n, is_read: 1 }));
  state.unreadNotifications = 0;
  renderNotifications();
}

async function submitReport(event) {
  event.preventDefault();

  const feedback = document.getElementById('formFeedback');
  feedback.textContent = 'Mengirim laporan dan menjalankan klasifikasi AI...';

  const form = event.currentTarget;
  const formData = new FormData(form);

  try {
    const res = await fetchJson(endpoint('reports.php'), {
      method: 'POST',
      body: formData,
    });

    feedback.textContent = res.message || 'Laporan berhasil dibuat.';
    form.reset();
    await Promise.all([loadReportsAndMap(), loadMyReports(), loadRejectedReports()]);

    const reportStatus = String(res?.data?.status || 'open');
    if (reportStatus === 'rejected_pending_media') {
      document.querySelector('.side-btn[data-view="rejectedReportsView"]')?.click();
    } else {
      document.querySelector('.side-btn[data-view="feedView"]')?.click();
    }
  } catch (error) {
    feedback.textContent = error.message;
  }
}

window.deleteMyReport = async function deleteMyReport(reportId) {
  if (!confirm('Yakin ingin menghapus laporan ini?')) return;

  try {
    await fetchJson(endpoint('reports.php'), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ report_id: reportId }),
    });

    await Promise.all([loadReportsAndMap(), loadMyReports(), loadRejectedReports()]);
    alert('Laporan berhasil dihapus.');
  } catch (error) {
    alert(error.message);
  }
};

window.openResubmitModal = function openResubmitModal(reportId) {
  const report = state.rejectedReports.find((r) => Number(r.id) === Number(reportId));
  if (!report) return;

  state.resubmitReportId = Number(reportId);

  const modal = document.getElementById('resubmitModal');
  const desc = document.getElementById('resubmitModalDesc');
  const input = document.getElementById('resubmitMediaInput');
  const feedback = document.getElementById('resubmitModalFeedback');
  const meta = document.getElementById('resubmitModalMeta');

  if (desc) {
    desc.textContent = `Deskripsi laporan: ${report.description || '-'}`;
  }

  if (input) {
    input.value = '';
  }

  if (feedback) feedback.textContent = '';
  if (meta) meta.textContent = 'Belum ada file dipilih.';

  modal.classList.add('is-open');
  document.body.style.overflow = 'hidden';
};

window.closeResubmitModal = function closeResubmitModal() {
  const modal = document.getElementById('resubmitModal');
  modal.classList.remove('is-open');
  document.body.style.overflow = '';
  state.resubmitReportId = null;
};

window.submitResubmission = async function submitResubmission() {
  if (!state.resubmitReportId) return;

  const input = document.getElementById('resubmitMediaInput');
  const feedback = document.getElementById('resubmitModalFeedback');
  if (!(input instanceof HTMLInputElement) || !feedback) return;

  const files = input.files;
  if (!files || files.length === 0) {
    feedback.textContent = 'Pilih minimal 1 gambar untuk dikirim ulang.';
    return;
  }

  const formData = new FormData();
  formData.append('report_id', String(state.resubmitReportId));
  Array.from(files).forEach((file) => formData.append('media[]', file));

  feedback.textContent = 'Memvalidasi ulang bukti dengan AI...';

  try {
    const res = await fetchJson(endpoint('reports.php?action=replace_media'), {
      method: 'POST',
      body: formData,
    });

    feedback.textContent = res.message || 'Bukti berhasil diperbarui.';
    await Promise.all([loadReportsAndMap(), loadMyReports(), loadRejectedReports()]);

    if (String(res?.data?.status || '') === 'open') {
      closeResubmitModal();
      document.querySelector('.side-btn[data-view="myReportsView"]')?.click();
      alert('Laporan Anda berhasil lolos validasi visual dan dipindahkan ke Laporan Saya.');
    }
  } catch (error) {
    feedback.textContent = error.message;
  }
};

window.voteReport = async function voteReport(reportId, voteType) {
  try {
    await fetchJson(endpoint('vote.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ report_id: reportId, vote_type: voteType }),
    });

    await loadReportsAndMap();
    await loadNotifications();
    const label = voteType === 'confirm' ? 'dikonfirmasi' : 'ditolak';
    alert(`Laporan berhasil ${label}. Terima kasih!`);
  } catch (error) {
    alert(error.message);
  }
};

window.openCommentModal = async function openCommentModal(reportId) {
  state.commentModalReportId = reportId;

  const modal = document.getElementById('commentModal');
  const titleEl = document.getElementById('commentModalTitle');
  const input = document.getElementById('commentModalInput');
  const feedback = document.getElementById('commentModalFeedback');

  const report = state.reports.find((r) => Number(r.id) === Number(reportId));
  if (titleEl) {
    titleEl.textContent = report ? `Komentar: ${report.title}` : 'Komentar Laporan';
  }

  if (input) {
    input.value = '';
    input.oninput = updateModalWordCount;
    updateModalWordCount();
  }

  if (feedback) feedback.textContent = '';

  modal.classList.add('is-open');
  document.body.style.overflow = 'hidden';

  await loadComments(reportId);
};

window.closeCommentModal = function closeCommentModal() {
  const modal = document.getElementById('commentModal');
  modal.classList.remove('is-open');
  document.body.style.overflow = '';
  state.commentModalReportId = null;
};

async function loadComments(reportId) {
  const listEl = document.getElementById('commentModalList');
  if (!listEl) return;

  listEl.innerHTML = '<p class="comment-loading">Memuat komentar...</p>';

  try {
    const res = await fetchJson(endpoint(`comments.php?report_id=${reportId}`));
    const comments = res.data;
    const myId = state.currentUser ? Number(state.currentUser.id) : null;

    if (comments.length === 0) {
      listEl.innerHTML = '<p class="comment-empty">Belum ada komentar. Jadilah yang pertama berkomentar!</p>';
      return;
    }

    listEl.innerHTML = comments.map((c) => {
      const isMine = myId !== null && Number(c.user_id) === myId;
      const actions = isMine ? `
        <div class="comment-actions">
          <button class="comment-action-btn" onclick="startEditComment(${c.id}, this)" type="button">Edit</button>
          <button class="comment-action-btn comment-action-btn--danger" onclick="deleteComment(${c.id})" type="button">Hapus</button>
        </div>` : '';

      return `
        <div class="comment-item" data-comment-id="${c.id}">
          <div class="comment-meta">
            <span class="comment-author">${escapeHtml(c.user_name)}</span>
            <span class="comment-date">${fmtDate(c.created_at)}</span>
          </div>
          <p class="comment-content" id="comment-text-${c.id}">${escapeHtml(c.content)}</p>
          <div class="comment-edit-area" id="comment-edit-${c.id}" style="display:none;">
            <textarea class="comment-modal__input comment-edit-input" rows="3" id="comment-edit-input-${c.id}">${escapeHtml(c.content)}</textarea>
            <div class="comment-edit-footer">
              <button class="btn btn-primary btn-sm" onclick="saveEditComment(${c.id})" type="button">Simpan</button>
              <button class="btn btn-ghost btn-sm" onclick="cancelEditComment(${c.id})" type="button">Batal</button>
              <span class="comment-feedback" id="comment-edit-fb-${c.id}"></span>
            </div>
          </div>
          ${actions}
        </div>
      `;
    }).join('');
  } catch (error) {
    listEl.innerHTML = `<p class="comment-empty">Gagal memuat komentar: ${escapeHtml(error.message)}</p>`;
  }
}

window.startEditComment = function startEditComment(commentId, _btn) {
  document.getElementById(`comment-text-${commentId}`).style.display = 'none';
  document.getElementById(`comment-edit-${commentId}`).style.display = 'block';
  document.getElementById(`comment-edit-input-${commentId}`).focus();
};

window.cancelEditComment = function cancelEditComment(commentId) {
  document.getElementById(`comment-text-${commentId}`).style.display = '';
  document.getElementById(`comment-edit-${commentId}`).style.display = 'none';
  document.getElementById(`comment-edit-fb-${commentId}`).textContent = '';
};

window.saveEditComment = async function saveEditComment(commentId) {
  const input = document.getElementById(`comment-edit-input-${commentId}`);
  const feedback = document.getElementById(`comment-edit-fb-${commentId}`);
  const content = input ? input.value.trim() : '';

  if (content === '') {
    feedback.textContent = 'Komentar tidak boleh kosong.';
    return;
  }

  const words = content.split(/\s+/).length;
  if (words > 500) {
    feedback.textContent = 'Melebihi 500 kata.';
    return;
  }

  try {
    await fetchJson(endpoint('comments.php'), {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ comment_id: commentId, content }),
    });

    const reportId = state.commentModalReportId;
    await loadComments(reportId);
  } catch (error) {
    feedback.textContent = error.message;
  }
};

window.deleteComment = async function deleteComment(commentId) {
  if (!confirm('Hapus komentar ini?')) return;

  try {
    await fetchJson(endpoint('comments.php'), {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ comment_id: commentId }),
    });

    await loadComments(state.commentModalReportId);
  } catch (error) {
    alert(error.message);
  }
};

function updateModalWordCount() {
  const input = document.getElementById('commentModalInput');
  const counter = document.getElementById('commentModalWc');
  if (!input || !counter) return;

  const text = input.value.trim();
  const words = text === '' ? 0 : text.split(/\s+/).length;
  counter.textContent = `${words} / 500 kata`;
  counter.classList.toggle('comment-wc-over', words > 500);
}

window.submitModalComment = async function submitModalComment() {
  const reportId = state.commentModalReportId;
  if (!reportId) return;

  const input = document.getElementById('commentModalInput');
  const feedback = document.getElementById('commentModalFeedback');
  if (!input || !feedback) return;

  const content = input.value.trim();
  if (content === '') {
    feedback.textContent = 'Komentar tidak boleh kosong.';
    return;
  }

  const words = content.split(/\s+/).length;
  if (words > 500) {
    feedback.textContent = 'Komentar melebihi batas 500 kata.';
    return;
  }

  feedback.textContent = 'Mengirim...';

  try {
    await fetchJson(endpoint('comments.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ report_id: reportId, content }),
    });

    input.value = '';
    updateModalWordCount();
    feedback.textContent = '';
    await loadComments(reportId);
    await loadNotifications();
  } catch (error) {
    feedback.textContent = error.message;
  }
};

async function predictRiskAtCenter() {
  const center = state.map.getCenter();
  const resultEl = document.getElementById('predictionResult');
  resultEl.textContent = 'Memproses prediksi...';

  try {
    const res = await fetchJson(endpoint(`predict.php?latitude=${center.lat}&longitude=${center.lng}`));
    const d = res.data;
    resultEl.innerHTML = `
      <strong>Risk Score: ${d.risk_score} (${d.risk_level})</strong><br>
      ${d.message}<br>
      Sinyal: banjir ${d.signals.banjir_48_jam}, jalan rusak ${d.signals.jalan_rusak_72_jam}, kriminalitas ${d.signals.kriminalitas_72_jam}
    `;
  } catch (error) {
    resultEl.textContent = error.message;
  }
}

function initializeTheme() {
  const savedTheme = localStorage.getItem('rlp-theme-mode') || 'light';
  state.theme = savedTheme;
  applyTheme(savedTheme);

  const themeSelector = document.getElementById('themeSelector');
  if (themeSelector instanceof HTMLSelectElement) {
    themeSelector.value = savedTheme;
  }
}

function applyTheme(theme) {
  const body = document.body;
  if (theme === 'dark') {
    body.classList.add('dark-mode');
  } else {
    body.classList.remove('dark-mode');
  }
  state.theme = theme;
  localStorage.setItem('rlp-theme-mode', theme);
}

function changeTheme(event) {
  const target = event.target;
  if (!(target instanceof HTMLSelectElement)) return;
  const newTheme = target.value;
  applyTheme(newTheme);
}

function bindUI() {
  document.getElementById('reportForm').addEventListener('submit', submitReport);
  document.getElementById('predictRiskBtn').addEventListener('click', predictRiskAtCenter);

  document.getElementById('provinceFilter').addEventListener('change', applyProvinceFilter);

  const provinceList = document.getElementById('provinceList');
  provinceList.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const openBtn = target.closest('.province-open-report');
    if (!(openBtn instanceof HTMLElement)) return;

    const reportId = Number(openBtn.dataset.reportId || 0);
    const province = String(openBtn.dataset.province || '');
    if (!Number.isFinite(reportId) || reportId <= 0) return;

    openProvinceReport(reportId, province);
  });

  const notificationsList = document.getElementById('notificationsList');
  if (notificationsList) {
    notificationsList.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      const openBtn = target.closest('.notification-open-report');
      if (!(openBtn instanceof HTMLElement)) return;

      const reportId = Number(openBtn.dataset.reportId || 0);
      if (!Number.isFinite(reportId) || reportId <= 0) return;

      openProvinceReport(reportId, '');
    });
  }

  const markAllReadBtn = document.getElementById('markNotifReadBtn');
  if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', () => {
      markAllNotificationsRead().catch((error) => alert(error.message));
    });
  }

  const currentLocationBtn = document.getElementById('useCurrentLocationBtn');
  if (currentLocationBtn) {
    currentLocationBtn.addEventListener('click', () => {
      useCurrentLocation().catch(() => {
        const feedback = document.getElementById('currentLocationFeedback');
        if (feedback) feedback.textContent = 'Gagal mengambil lokasi terkini.';
      });
    });
  }

  const profileSettingsForm = document.getElementById('profileSettingsForm');
  if (profileSettingsForm instanceof HTMLFormElement) {
    profileSettingsForm.addEventListener('submit', submitProfileSettings);
  }

  const passwordSettingsForm = document.getElementById('passwordSettingsForm');
  if (passwordSettingsForm instanceof HTMLFormElement) {
    passwordSettingsForm.addEventListener('submit', submitPasswordSettings);
  }

  const themeSelector = document.getElementById('themeSelector');
  if (themeSelector instanceof HTMLSelectElement) {
    themeSelector.addEventListener('change', changeTheme);
  }

  const toggleHeatmapBtn = document.getElementById('toggleHeatmapBtn');
  if (toggleHeatmapBtn) {
    toggleHeatmapBtn.textContent = state.heatVisible ? 'Sembunyikan Heatmap' : 'Tampilkan Heatmap';

    toggleHeatmapBtn.addEventListener('click', () => {
      state.heatVisible = !state.heatVisible;
      ensureHeatLayerVisible();
      toggleHeatmapBtn.textContent = state.heatVisible ? 'Sembunyikan Heatmap' : 'Tampilkan Heatmap';
    });
  }

  const resubmitMediaInput = document.getElementById('resubmitMediaInput');
  if (resubmitMediaInput instanceof HTMLInputElement) {
    resubmitMediaInput.addEventListener('change', () => {
      const meta = document.getElementById('resubmitModalMeta');
      if (!meta) return;

      const total = resubmitMediaInput.files?.length ?? 0;
      meta.textContent = total > 0
        ? `${total} file dipilih untuk validasi ulang.`
        : 'Belum ada file dipilih.';
    });
  }

  document.getElementById('logoutBtn').addEventListener('click', async () => {
    try {
      await fetchJson(endpoint('auth.php?action=logout'), { method: 'POST' });
      window.location.href = 'index.php';
    } catch (error) {
      alert(error.message);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.commentModalReportId !== null) {
      closeCommentModal();
    }

    if (e.key === 'Escape' && state.resubmitReportId !== null) {
      closeResubmitModal();
    }
  });
}

async function loadCurrentUser() {
  const res = await fetchJson(endpoint('auth.php?action=me'));
  state.currentUser = res.data;
  setAuthUi();
}

async function submitProfileSettings(event) {
  event.preventDefault();

  const form = event.currentTarget;
  if (!(form instanceof HTMLFormElement)) return;

  const feedback = document.getElementById('profileSettingsFeedback');
  const formData = new FormData(form);
  const name = String(formData.get('name') || '').trim();
  const email = String(formData.get('email') || '').trim();

  if (name === '' || email === '') {
    if (feedback) feedback.textContent = 'Nama dan email wajib diisi.';
    return;
  }

  if (feedback) feedback.textContent = 'Menyimpan profil...';

  try {
    const res = await fetchJson(endpoint('auth.php?action=update_profile'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, email }),
    });

    state.currentUser = res.data;
    setAuthUi();
    if (feedback) feedback.textContent = res.message || 'Profil berhasil diperbarui.';
  } catch (error) {
    if (feedback) feedback.textContent = error.message;
  }
}

async function submitPasswordSettings(event) {
  event.preventDefault();

  const form = event.currentTarget;
  if (!(form instanceof HTMLFormElement)) return;

  const feedback = document.getElementById('passwordSettingsFeedback');
  const formData = new FormData(form);
  const currentPassword = String(formData.get('current_password') || '');
  const newPassword = String(formData.get('new_password') || '');
  const confirmPassword = String(formData.get('confirm_password') || '');

  if (currentPassword === '' || newPassword === '' || confirmPassword === '') {
    if (feedback) feedback.textContent = 'Semua field password wajib diisi.';
    return;
  }

  if (newPassword !== confirmPassword) {
    if (feedback) feedback.textContent = 'Konfirmasi password baru tidak sama.';
    return;
  }

  if (newPassword.length < 6) {
    if (feedback) feedback.textContent = 'Password baru minimal 6 karakter.';
    return;
  }

  if (feedback) feedback.textContent = 'Menyimpan password...';

  try {
    const res = await fetchJson(endpoint('auth.php?action=change_password'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword,
      }),
    });

    form.reset();
    if (feedback) feedback.textContent = res.message || 'Password berhasil diperbarui.';
  } catch (error) {
    if (feedback) feedback.textContent = error.message;
  }
}

(async function init() {
  initializeTheme();
  initSidebar();
  initMobileMenu();
  initMap();
  initCreateMap();
  bindUI();
  await loadCurrentUser();
  await Promise.all([loadReportsAndMap(), loadMyReports(), loadRejectedReports(), loadNotifications()]);
})();
