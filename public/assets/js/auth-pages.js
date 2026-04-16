async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const data = await response.json();
  if (!response.ok || !data.ok) {
    throw new Error(data.message || 'Request gagal');
  }
  return data;
}

const REGION_API_BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';

function clearRegionSelect(selectEl, placeholder) {
  if (!(selectEl instanceof HTMLSelectElement)) return;
  selectEl.innerHTML = `<option value="">${placeholder}</option>`;
  selectEl.disabled = true;
}

function populateRegionSelect(selectEl, rows, placeholder) {
  if (!(selectEl instanceof HTMLSelectElement)) return;

  const options = [`<option value="">${placeholder}</option>`];
  rows.forEach((row) => {
    const id = String(row?.id || '').trim();
    const name = String(row?.name || '').trim();
    if (id === '' || name === '') return;
    options.push(`<option value="${name}" data-id="${id}">${name}</option>`);
  });

  selectEl.innerHTML = options.join('');
  selectEl.disabled = rows.length === 0;
}

async function fetchRegionRows(path) {
  const response = await fetch(`${REGION_API_BASE}/${path}`, {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error('Gagal memuat data wilayah.');
  }

  const data = await response.json();
  return Array.isArray(data) ? data : [];
}

function selectedRegionId(selectEl) {
  if (!(selectEl instanceof HTMLSelectElement)) return '';
  const option = selectEl.selectedOptions?.[0];
  if (!option) return '';
  return String(option.dataset.id || '').trim();
}

function initGovernmentRegionDropdowns(feedbackEl) {
  const provinceSelect = document.getElementById('regionProvince');
  const citySelect = document.getElementById('regionCity');
  const districtSelect = document.getElementById('regionDistrict');
  const subdistrictSelect = document.getElementById('regionSubdistrict');

  if (!(provinceSelect instanceof HTMLSelectElement)
    || !(citySelect instanceof HTMLSelectElement)
    || !(districtSelect instanceof HTMLSelectElement)
    || !(subdistrictSelect instanceof HTMLSelectElement)) {
    return;
  }

  clearRegionSelect(citySelect, 'Pilih kabupaten/kota...');
  clearRegionSelect(districtSelect, 'Pilih kecamatan...');
  clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

  fetchRegionRows('provinces.json')
    .then((rows) => {
      populateRegionSelect(provinceSelect, rows, 'Pilih provinsi...');
    })
    .catch(() => {
      if (feedbackEl) {
        feedbackEl.textContent = 'Gagal memuat data wilayah. Coba refresh halaman.';
      }
    });

  provinceSelect.addEventListener('change', async () => {
    clearRegionSelect(citySelect, 'Pilih kabupaten/kota...');
    clearRegionSelect(districtSelect, 'Pilih kecamatan...');
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const provinceId = selectedRegionId(provinceSelect);
    if (provinceId === '') return;

    try {
      const rows = await fetchRegionRows(`regencies/${provinceId}.json`);
      populateRegionSelect(citySelect, rows, 'Pilih kabupaten/kota...');
    } catch {
      if (feedbackEl) {
        feedbackEl.textContent = 'Gagal memuat data kabupaten/kota.';
      }
    }
  });

  citySelect.addEventListener('change', async () => {
    clearRegionSelect(districtSelect, 'Pilih kecamatan...');
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const cityId = selectedRegionId(citySelect);
    if (cityId === '') return;

    try {
      const rows = await fetchRegionRows(`districts/${cityId}.json`);
      populateRegionSelect(districtSelect, rows, 'Pilih kecamatan...');
    } catch {
      if (feedbackEl) {
        feedbackEl.textContent = 'Gagal memuat data kecamatan.';
      }
    }
  });

  districtSelect.addEventListener('change', async () => {
    clearRegionSelect(subdistrictSelect, 'Pilih kelurahan/desa...');

    const districtId = selectedRegionId(districtSelect);
    if (districtId === '') return;

    try {
      const rows = await fetchRegionRows(`villages/${districtId}.json`);
      populateRegionSelect(subdistrictSelect, rows, 'Pilih kelurahan/desa...');
    } catch {
      if (feedbackEl) {
        feedbackEl.textContent = 'Gagal memuat data kelurahan/desa.';
      }
    }
  });
}

function redirectByRole(user) {
  if (user.role === 'admin') {
    window.location.href = 'admin.php';
    return;
  }

  if (user.role === 'government') {
    window.location.href = 'government.php';
    return;
  }

  window.location.href = 'reports.php';
}

function initLoginPage() {
  const form = document.getElementById('loginForm');
  if (!form) {
    return;
  }

  const feedback = document.getElementById('loginFeedback');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const payload = new FormData();
    payload.set('email', document.getElementById('loginEmail').value.trim());
    payload.set('password', document.getElementById('loginPassword').value);

    feedback.textContent = 'Memproses...';
    try {
      const response = await fetchJson('api/auth.php?action=login', {
        method: 'POST',
        body: payload,
      });
      feedback.textContent = response.message;
      setTimeout(() => redirectByRole(response.data), 250);
    } catch (error) {
      feedback.textContent = error.message;
    }
  });
}

function initRegisterPage() {
  const form = document.getElementById('registerForm');
  if (!form) {
    return;
  }

  const accountType = document.getElementById('accountType');
  const citizenFields = document.getElementById('citizenFields');
  const governmentFields = document.getElementById('governmentFields');
  const feedback = document.getElementById('registerFeedback');
  const govDoc = document.getElementById('governmentDocument');
  const govRegionProvince = document.getElementById('regionProvince');
  const govRegionCity = document.getElementById('regionCity');
  const govRegionDistrict = document.getElementById('regionDistrict');
  const govRegionSubdistrict = document.getElementById('regionSubdistrict');

  initGovernmentRegionDropdowns(feedback);

  function renderRegisterType() {
    const isGovernment = accountType.value === 'government';
    citizenFields.classList.toggle('hidden', isGovernment);
    governmentFields.classList.toggle('hidden', !isGovernment);
    if (govDoc) {
      govDoc.required = isGovernment;
    }

    if (govRegionProvince instanceof HTMLSelectElement) {
      govRegionProvince.required = isGovernment;
    }
    if (govRegionCity instanceof HTMLSelectElement) {
      govRegionCity.required = isGovernment;
    }
    if (govRegionDistrict instanceof HTMLSelectElement) {
      govRegionDistrict.required = isGovernment;
    }
    if (govRegionSubdistrict instanceof HTMLSelectElement) {
      govRegionSubdistrict.required = isGovernment;
    }
  }

  accountType.addEventListener('change', renderRegisterType);
  renderRegisterType();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const payload = new FormData();
    payload.set('email', document.getElementById('registerEmail').value.trim());
    payload.set('password', document.getElementById('registerPassword').value);
    payload.set('confirm_password', document.getElementById('registerConfirmPassword').value);
    payload.set('account_type', accountType.value);

    if (accountType.value === 'citizen') {
      payload.set('name', document.getElementById('citizenName').value.trim());
    } else {
      payload.set('agency_name', document.getElementById('agencyName').value.trim());
      payload.set('agency_type', document.getElementById('agencyType').value.trim());
      payload.set('agency_sector', document.getElementById('agencySector').value.trim());
      payload.set('region_province', document.getElementById('regionProvince').value.trim());
      payload.set('region_city', document.getElementById('regionCity').value.trim());
      payload.set('region_district', document.getElementById('regionDistrict').value.trim());
      payload.set('region_subdistrict', document.getElementById('regionSubdistrict').value.trim());
      payload.set('officer_name', document.getElementById('officerName').value.trim());
      payload.set('officer_position', document.getElementById('officerPosition').value.trim());
      payload.set('officer_nip', document.getElementById('officerNip').value.trim());
      payload.set('officer_phone', document.getElementById('officerPhone').value.trim());
      payload.set('declaration_data_true', document.getElementById('declarationDataTrue').checked ? '1' : '0');
      payload.set('declaration_followup', document.getElementById('declarationFollowup').checked ? '1' : '0');

      const doc = govDoc.files[0];
      if (doc) {
        payload.set('government_document', doc);
      }
    }

    feedback.textContent = 'Memproses...';
    try {
      const response = await fetchJson('api/auth.php?action=register', {
        method: 'POST',
        body: payload,
      });

      feedback.textContent = response.message;

      if (accountType.value === 'government') {
        return;
      }

      setTimeout(() => redirectByRole(response.data), 250);
    } catch (error) {
      feedback.textContent = error.message;
    }
  });
}

initLoginPage();
initRegisterPage();