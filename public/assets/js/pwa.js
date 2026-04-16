(() => {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  const isIndexPage = () => {
    const path = window.location.pathname.toLowerCase();
    return path.endsWith('/index.php') || path === '/' || path.endsWith('/public') || path.endsWith('/public/');
  };

  const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent);
  const isSafari = () => /safari/i.test(window.navigator.userAgent) && !/crios|fxios|edgios|chrome|android/i.test(window.navigator.userAgent);

  let deferredPrompt = null;
  let installButton = null;
  let installToast = null;

  function ensureInstallButton() {
    if (installButton) {
      return installButton;
    }

    installButton = document.createElement('button');
    installButton.type = 'button';
    installButton.className = 'btn btn-secondary pwa-install-btn is-hidden';
    installButton.textContent = 'Install App';
    installButton.addEventListener('click', async () => {
      if (!deferredPrompt && isIos() && isSafari() && !isStandalone()) {
        showInstalledToast('Di Safari iPhone/iPad, buka menu Share lalu pilih Add to Home Screen.');
        return;
      }

      if (!deferredPrompt) {
        return;
      }

      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      hideInstallButton();
    });

    const target = document.querySelector('.topbar__actions')
      || document.querySelector('.sidebar-footer')
      || document.querySelector('.mobile-menu-bar')
      || document.body;

    if (target === document.body) {
      installButton.classList.add('pwa-install-fab');
      document.body.appendChild(installButton);
    } else {
      target.prepend(installButton);
    }

    return installButton;
  }

  function showInstallButton() {
    if (!isIndexPage() || isStandalone()) {
      return;
    }

    if (isIos() && isSafari()) {
      ensureInstallButton().textContent = 'Install';
    }

    ensureInstallButton().classList.remove('is-hidden');
  }

  function hideInstallButton() {
    if (installButton) {
      installButton.classList.add('is-hidden');
    }
  }

  function showInstalledToast(message = 'RLP Radar siap dipakai dari layar utama perangkat Anda.') {
    if (!installToast) {
      installToast = document.createElement('div');
      installToast.className = 'pwa-install-toast';
      document.body.appendChild(installToast);
    }

    installToast.textContent = message;
    installToast.classList.add('is-visible');
    window.setTimeout(() => installToast && installToast.classList.remove('is-visible'), 2800);
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    if (!isIndexPage()) {
      return;
    }

    event.preventDefault();
    deferredPrompt = event;
    showInstallButton();
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    hideInstallButton();
    showInstalledToast();
  });

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js', { scope: './' }).catch(() => {
      hideInstallButton();
    });

    if (!isIndexPage()) {
      hideInstallButton();
      return;
    }

    if (isStandalone()) {
      hideInstallButton();
      return;
    }

    if (isIos() && isSafari()) {
      showInstallButton();
    }
  });
})();