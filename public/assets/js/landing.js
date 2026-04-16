async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const data = await response.json();
  if (!response.ok || !data.ok) {
    throw new Error(data.message || 'Request gagal');
  }
  return data;
}

const authDialog = document.getElementById('authDialog');
const authTitle = document.getElementById('authTitle');
const authMode = document.getElementById('authMode');
const authFeedback = document.getElementById('authFeedback');
const accountType = document.getElementById('accountType');

function openAuth(mode) {
  authMode.value = mode;
  authTitle.textContent = mode === 'register' ? 'Register Akun' : 'Login';

  document.getElementById('registerTypeWrap').classList.toggle('hidden', mode !== 'register');
  document.getElementById('confirmPasswordWrap').classList.toggle('hidden', mode !== 'register');

  if (mode !== 'register') {
    document.getElementById('citizenFields').classList.add('hidden');
    document.getElementById('governmentFields').classList.add('hidden');
  } else {
    renderRegisterType();
  }

  authFeedback.textContent = '';
  authDialog.showModal();
}

function renderRegisterType() {
  const isGovernment = accountType.value === 'government';
  document.getElementById('citizenFields').classList.toggle('hidden', isGovernment);
  document.getElementById('governmentFields').classList.toggle('hidden', !isGovernment);

  const govDoc = document.getElementById('governmentDocument');
  if (govDoc) {
    govDoc.required = isGovernment;
  }
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

async function submitAuth(event) {
  event.preventDefault();

  const mode = authMode.value;
  const payload = new FormData();
  payload.set('email', document.getElementById('authEmail').value.trim());
  payload.set('password', document.getElementById('authPassword').value);

  if (mode === 'register') {
    payload.set('confirm_password', document.getElementById('authConfirmPassword').value);
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

      const doc = document.getElementById('governmentDocument').files[0];
      if (doc) {
        payload.set('government_document', doc);
      }
    }
  }

  authFeedback.textContent = 'Memproses...';

  try {
    const response = await fetchJson(`api/auth.php?action=${mode}`, {
      method: 'POST',
      body: payload,
    });

    authFeedback.textContent = response.message;

    if (mode === 'register' && accountType.value === 'government') {
      return;
    }

    setTimeout(() => redirectByRole(response.data), 300);
  } catch (error) {
    authFeedback.textContent = error.message;
  }
}

function bindAuthButtons(ids, mode) {
  ids.forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
      element.addEventListener('click', () => openAuth(mode));
    }
  });
}

bindAuthButtons(['openLoginBtn'], 'login');
bindAuthButtons(['openRegisterBtn'], 'register');

document.getElementById('closeAuthBtn').addEventListener('click', () => authDialog.close());
document.getElementById('authForm').addEventListener('submit', submitAuth);
accountType.addEventListener('change', renderRegisterType);
