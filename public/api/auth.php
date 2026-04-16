<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;

$action = strtolower((string) Request::input('action', 'me'));

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!RateLimit::allow('auth:' . $ip, 15, 60)) {
  Response::json(['ok' => false, 'message' => 'Terlalu banyak percobaan. Coba lagi dalam 1 menit.'], 429);
}

if (Request::method() === 'GET' && $action === 'me') {
  $user = Auth::user();
  Response::json([
    'ok' => true,
    'data' => $user,
  ]);
}

if (Request::method() !== 'POST') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

if ($action === 'logout') {
  Auth::logout();
  Response::json(['ok' => true, 'message' => 'Logout berhasil.']);
}

if ($action === 'update_profile') {
  Auth::requireLoginJson();

  $current = Auth::user();
  if (($current['role'] ?? '') !== 'user') {
    Response::json(['ok' => false, 'message' => 'Hanya akun masyarakat yang dapat mengubah profil di menu ini.'], 403);
  }

  $userId = (int) (Auth::userId() ?? 0);
  $body = Request::jsonBody();
  $name = trim((string) ($body['name'] ?? Request::input('name', '')));
  $email = strtolower(trim((string) ($body['email'] ?? Request::input('email', ''))));

  if ($name === '' || $email === '') {
    Response::json(['ok' => false, 'message' => 'Nama dan email wajib diisi.'], 422);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::json(['ok' => false, 'message' => 'Format email tidak valid.'], 422);
  }

  if ($userRepository->emailExistsExceptUser($email, $userId)) {
    Response::json(['ok' => false, 'message' => 'Email sudah digunakan akun lain.'], 409);
  }

  $userRepository->updateCitizenProfile($userId, $name, $email);
  $freshUser = $userRepository->findById($userId);
  if ($freshUser === null) {
    Response::json(['ok' => false, 'message' => 'Gagal memuat data profil terbaru.'], 500);
  }

  Auth::login($freshUser);

  Response::json([
    'ok' => true,
    'message' => 'Profil berhasil diperbarui.',
    'data' => [
      'id' => (int) $freshUser['id'],
      'name' => $freshUser['name'],
      'email' => $freshUser['email'],
      'role' => $freshUser['role'],
      'account_type' => $freshUser['account_type'] ?? 'citizen',
      'account_status' => $freshUser['account_status'] ?? 'active',
      'suspended_until' => $freshUser['suspended_until'] ?? null,
      'suspension_reason' => $freshUser['suspension_reason'] ?? null,
    ],
  ]);
}

if ($action === 'change_password') {
  Auth::requireLoginJson();

  $current = Auth::user();
  if (($current['role'] ?? '') !== 'user') {
    Response::json(['ok' => false, 'message' => 'Hanya akun masyarakat yang dapat mengubah password di menu ini.'], 403);
  }

  $userId = (int) (Auth::userId() ?? 0);
  $body = Request::jsonBody();
  $currentPassword = (string) ($body['current_password'] ?? Request::input('current_password', ''));
  $newPassword = (string) ($body['new_password'] ?? Request::input('new_password', ''));
  $confirmPassword = (string) ($body['confirm_password'] ?? Request::input('confirm_password', ''));

  if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    Response::json(['ok' => false, 'message' => 'Semua field password wajib diisi.'], 422);
  }

  if ($newPassword !== $confirmPassword) {
    Response::json(['ok' => false, 'message' => 'Konfirmasi password baru tidak sama.'], 422);
  }

  if (strlen($newPassword) < 6) {
    Response::json(['ok' => false, 'message' => 'Password baru minimal 6 karakter.'], 422);
  }

  $freshUser = $userRepository->findById($userId);
  if ($freshUser === null) {
    Response::json(['ok' => false, 'message' => 'Pengguna tidak ditemukan.'], 404);
  }

  if (!password_verify($currentPassword, (string) $freshUser['password_hash'])) {
    Response::json(['ok' => false, 'message' => 'Password saat ini tidak sesuai.'], 401);
  }

  $userRepository->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));

  Response::json([
    'ok' => true,
    'message' => 'Password berhasil diperbarui.',
  ]);
}

$email = strtolower(trim((string) Request::input('email', '')));
$password = (string) Request::input('password', '');

if ($email === '' || $password === '') {
  Response::json(['ok' => false, 'message' => 'Email dan password wajib diisi.'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  Response::json(['ok' => false, 'message' => 'Format email tidak valid.'], 422);
}

if ($action === 'register') {
  $accountType = strtolower(trim((string) Request::input('account_type', 'citizen')));
  if (!in_array($accountType, ['citizen', 'government'], true)) {
    Response::json(['ok' => false, 'message' => 'Tipe akun tidak valid.'], 422);
  }

  $confirmPassword = (string) Request::input('confirm_password', '');
  if ($password !== $confirmPassword) {
    Response::json(['ok' => false, 'message' => 'Konfirmasi password tidak sama.'], 422);
  }

  if (strlen($password) < 6) {
    Response::json(['ok' => false, 'message' => 'Password minimal 6 karakter.'], 422);
  }

  $existing = $userRepository->findByEmail($email);
  if ($existing !== null) {
    Response::json(['ok' => false, 'message' => 'Email sudah terdaftar.'], 409);
  }

  if ($accountType === 'citizen') {
    $name = trim((string) Request::input('name', ''));
    if ($name === '') {
      Response::json(['ok' => false, 'message' => 'Nama wajib diisi.'], 422);
    }

    $isFirstUser = $userRepository->countAll() === 0;
    $role = $isFirstUser ? 'admin' : 'user';

    $userId = $userRepository->create($name, $email, password_hash($password, PASSWORD_DEFAULT), $role);
    $user = $userRepository->findById($userId);

    if ($user === null) {
      Response::json(['ok' => false, 'message' => 'Gagal membuat akun.'], 500);
    }

    Auth::login($user);

    Response::json([
      'ok' => true,
      'message' => 'Registrasi masyarakat berhasil.',
      'data' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'account_type' => $user['account_type'] ?? 'citizen',
        'account_status' => $user['account_status'] ?? 'active',
        'suspended_until' => $user['suspended_until'] ?? null,
        'suspension_reason' => $user['suspension_reason'] ?? null,
      ],
    ], 201);
  }

  $agencyName = trim((string) Request::input('agency_name', ''));
  $agencyType = trim((string) Request::input('agency_type', ''));
  $agencySector = trim((string) Request::input('agency_sector', ''));
  $regionProvince = trim((string) Request::input('region_province', ''));
  $regionCity = trim((string) Request::input('region_city', ''));
  $regionDistrict = trim((string) Request::input('region_district', ''));
  $regionSubdistrict = trim((string) Request::input('region_subdistrict', ''));
  $officerName = trim((string) Request::input('officer_name', ''));
  $officerPosition = trim((string) Request::input('officer_position', ''));
  $officerNip = trim((string) Request::input('officer_nip', ''));
  $officerPhone = trim((string) Request::input('officer_phone', ''));
  $agreeData = (string) Request::input('declaration_data_true', '') === '1';
  $agreeFollowup = (string) Request::input('declaration_followup', '') === '1';

  if (
    $agencyName === '' || $agencyType === '' || $agencySector === '' ||
    $regionProvince === '' || $regionCity === '' || $regionDistrict === '' || $regionSubdistrict === '' ||
    $officerName === '' || $officerPosition === '' || $officerNip === '' || $officerPhone === ''
  ) {
    Response::json(['ok' => false, 'message' => 'Semua data instansi, wilayah, dan penanggung jawab wajib diisi.'], 422);
  }

  if (!$agreeData || !$agreeFollowup) {
    Response::json(['ok' => false, 'message' => 'Persetujuan wajib dicentang.'], 422);
  }

  $isGoId = (bool) preg_match('/@([a-z0-9-]+\.)*go\.id$/i', $email);
  $documentPath = null;

  if (!isset($_FILES['government_document']) || (int) ($_FILES['government_document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    Response::json([
      'ok' => false,
      'message' => 'Dokumen instansi wajib diupload (contoh: SK penugasan, surat mandat, atau kartu identitas pegawai/dinas). Format: PDF/JPG/PNG/WEBP.',
    ], 422);
  }

  if ((int) $_FILES['government_document']['error'] !== UPLOAD_ERR_OK) {
    Response::json(['ok' => false, 'message' => 'Gagal mengunggah dokumen instansi. Silakan coba lagi.'], 422);
  }

  $tmpPath = (string) $_FILES['government_document']['tmp_name'];
  $mimeType = (string) mime_content_type($tmpPath);
  $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

  if (!in_array($mimeType, $allowed, true)) {
    Response::json(['ok' => false, 'message' => 'Format dokumen tidak didukung. Gunakan PDF/JPG/PNG/WEBP.'], 422);
  }

  $extMap = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  ];

  $filename = 'gov_doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($extMap[$mimeType] ?? 'bin');
  $absPath = BASE_PATH . '/storage/government_docs/' . $filename;

  if (!move_uploaded_file($tmpPath, $absPath)) {
    Response::json(['ok' => false, 'message' => 'Gagal mengunggah dokumen instansi.'], 500);
  }

  $documentPath = 'storage/government_docs/' . $filename;

  $govId = $userRepository->createGovernment([
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'agency_name' => $agencyName,
    'agency_type' => $agencyType,
    'agency_sector' => $agencySector,
    'region_province' => $regionProvince,
    'region_city' => $regionCity,
    'region_district' => $regionDistrict,
    'region_subdistrict' => $regionSubdistrict,
    'officer_name' => $officerName,
    'officer_position' => $officerPosition,
    'officer_nip' => $officerNip,
    'officer_phone' => $officerPhone,
    'official_email_domain_valid' => $isGoId,
    'government_document_path' => $documentPath,
    'declaration_data_true' => $agreeData,
    'declaration_followup' => $agreeFollowup,
  ]);

  $user = $userRepository->findById($govId);
  if ($user === null) {
    Response::json(['ok' => false, 'message' => 'Gagal membuat akun instansi.'], 500);
  }

  Response::json([
    'ok' => true,
    'message' => $isGoId
      ? 'Registrasi instansi berhasil. Status akun: Pending verifikasi admin.'
      : 'Registrasi berhasil, namun email resmi bukan domain go.id. Status akun: Pending verifikasi admin.',
    'data' => [
      'id' => (int) $user['id'],
      'account_status' => $user['account_status'],
    ],
  ], 201);
}

if ($action === 'login') {
  $user = $userRepository->findByEmail($email);
  if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
    Response::json(['ok' => false, 'message' => 'Email atau password salah.'], 401);
  }

  $role = (string) ($user['role'] ?? 'user');
  $accountStatus = (string) ($user['account_status'] ?? 'active');

  if ($role === 'government' && $accountStatus !== 'verified') {
    if ($accountStatus === 'rejected') {
      Response::json(['ok' => false, 'message' => 'Akun instansi ditolak admin. Silakan hubungi admin platform.'], 403);
    }

    Response::json(['ok' => false, 'message' => 'Akun instansi masih pending verifikasi admin.'], 403);
  }

  Auth::login($user);

  Response::json([
    'ok' => true,
    'message' => 'Login berhasil.',
    'data' => [
      'id' => (int) $user['id'],
      'name' => $user['name'],
      'email' => $user['email'],
      'role' => $user['role'],
      'account_type' => $user['account_type'] ?? 'citizen',
      'account_status' => $user['account_status'] ?? 'active',
      'suspended_until' => $user['suspended_until'] ?? null,
      'suspension_reason' => $user['suspension_reason'] ?? null,
    ],
  ]);
}

Response::json(['ok' => false, 'message' => 'Action tidak dikenal.'], 422);
