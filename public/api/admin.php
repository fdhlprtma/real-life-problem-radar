<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

Auth::requireAdminJson();

$action = strtolower((string) Request::input('action', 'stats'));

if (Request::method() === 'GET' && $action === 'stats') {
  Response::json([
    'ok' => true,
    'data' => [
      'report' => $reportRepository->statsSummary(),
      'government' => $userRepository->governmentStats(),
    ],
  ]);
}

if (Request::method() === 'GET' && $action === 'gov_requests') {
  $status = (string) (Request::input('status', 'pending') ?? 'pending');
  if (!in_array($status, ['all', 'pending', 'verified', 'rejected'], true)) {
    Response::json(['ok' => false, 'message' => 'Status pendaftaran tidak valid.'], 422);
  }

  Response::json([
    'ok' => true,
    'data' => $userRepository->listGovernmentAccounts($status),
  ]);
}

if (Request::method() === 'GET' && $action === 'reports') {
  $status  = (string) (Request::input('status', 'all') ?? 'all');
  $q       = (string) (Request::input('q', '') ?? '');
  $page    = max(1, (int) (Request::input('page', '1') ?? '1'));
  $perPage = min(100, max(10, (int) (Request::input('per_page', '25') ?? '25')));
  $sortBy  = (string) (Request::input('sort_by', 'created_at') ?? 'created_at');
  $sortDir = (string) (Request::input('sort_dir', 'DESC') ?? 'DESC');

  $result = $reportRepository->listForAdmin($status, $q, $page, $perPage, $sortBy, $sortDir);
  Response::json([
    'ok'   => true,
    'data' => $result['data'],
    'meta' => [
      'total'       => $result['total'],
      'page'        => $result['page'],
      'per_page'    => $result['per_page'],
      'total_pages' => $result['total_pages'],
    ],
  ]);
}

if (Request::method() === 'POST' && $action === 'status') {
  $body = Request::jsonBody();
  $reportId = (int) ($body['report_id'] ?? 0);
  $status = strtolower((string) ($body['status'] ?? 'open'));

  if ($reportId <= 0 || !in_array($status, ['open', 'in_progress', 'resolved', 'hidden'], true)) {
    Response::json(['ok' => false, 'message' => 'Payload status tidak valid.'], 422);
  }

  $report = $reportRepository->findById($reportId);
  if ($report === null) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
  }

  $reportRepository->updateStatus($reportId, $status);

  Response::json([
    'ok' => true,
    'message' => 'Status laporan diperbarui.',
    'data' => $reportRepository->findById($reportId),
  ]);
}

if (Request::method() === 'POST' && $action === 'report_delete') {
  $body = Request::jsonBody();
  $reportId = (int) ($body['report_id'] ?? 0);

  if ($reportId <= 0) {
    Response::json(['ok' => false, 'message' => 'ID laporan tidak valid.'], 422);
  }

  $report = $reportRepository->findById($reportId);
  if ($report === null) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
  }

  $reportRepository->deleteById($reportId);

  Response::json([
    'ok' => true,
    'message' => 'Laporan berhasil dihapus.',
  ]);
}

if (Request::method() === 'POST' && $action === 'gov_status') {
  $body = Request::jsonBody();
  $userId = (int) ($body['user_id'] ?? 0);
  $status = strtolower(trim((string) ($body['status'] ?? 'pending')));
  $note = trim((string) ($body['note'] ?? ''));

  if ($userId <= 0 || !in_array($status, ['pending', 'verified', 'rejected'], true)) {
    Response::json(['ok' => false, 'message' => 'Payload status pendaftar tidak valid.'], 422);
  }

  $target = $userRepository->findById($userId);
  if ($target === null || (string) ($target['role'] ?? '') !== 'government') {
    Response::json(['ok' => false, 'message' => 'Akun instansi tidak ditemukan.'], 404);
  }

  $adminId = (int) (Auth::userId() ?? 0);
  $userRepository->updateGovernmentStatus($userId, $status, $adminId, $note);

  Response::json([
    'ok' => true,
    'message' => 'Status pendaftar instansi diperbarui.',
    'data' => $userRepository->findById($userId),
  ]);
}

if (Request::method() === 'GET' && $action === 'users') {
  $role    = (string) (Request::input('role', 'all') ?? 'all');
  $q       = (string) (Request::input('q', '') ?? '');
  $page    = max(1, (int) (Request::input('page', '1') ?? '1'));
  $perPage = min(100, max(10, (int) (Request::input('per_page', '25') ?? '25')));

  if (!in_array($role, ['all', 'user', 'government', 'admin'], true)) {
    Response::json(['ok' => false, 'message' => 'Filter role tidak valid.'], 422);
  }

  $result = $userRepository->listAllUsers($role, $q, $page, $perPage);
  Response::json([
    'ok'   => true,
    'data' => $result['data'],
    'meta' => [
      'total'       => $result['total'],
      'page'        => $result['page'],
      'per_page'    => $result['per_page'],
      'total_pages' => $result['total_pages'],
    ],
  ]);
}

if (Request::method() === 'POST' && $action === 'user_update') {
  $body          = Request::jsonBody();
  $userId        = (int) ($body['user_id'] ?? 0);
  $name          = trim((string) ($body['name'] ?? ''));
  $email         = trim((string) ($body['email'] ?? ''));
  $accountStatus = strtolower(trim((string) ($body['account_status'] ?? 'active')));

  if ($userId <= 0 || $name === '' || $email === '') {
    Response::json(['ok' => false, 'message' => 'Data pengguna tidak lengkap.'], 422);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::json(['ok' => false, 'message' => 'Format email tidak valid.'], 422);
  }

  if (!in_array($accountStatus, ['active', 'inactive', 'pending', 'verified', 'rejected'], true)) {
    Response::json(['ok' => false, 'message' => 'Status akun tidak valid.'], 422);
  }

  if ($userRepository->findById($userId) === null) {
    Response::json(['ok' => false, 'message' => 'Pengguna tidak ditemukan.'], 404);
  }

  $userRepository->updateUser($userId, $name, $email, $accountStatus);

  Response::json([
    'ok'      => true,
    'message' => 'Data pengguna diperbarui.',
    'data'    => $userRepository->findById($userId),
  ]);
}

if (Request::method() === 'POST' && $action === 'user_delete') {
  $body   = Request::jsonBody();
  $userId = (int) ($body['user_id'] ?? 0);

  if ($userId <= 0) {
    Response::json(['ok' => false, 'message' => 'ID pengguna tidak valid.'], 422);
  }

  $adminId = (int) (Auth::userId() ?? 0);
  if ($userId === $adminId) {
    Response::json(['ok' => false, 'message' => 'Tidak dapat menghapus akun sendiri.'], 403);
  }

  if ($userRepository->findById($userId) === null) {
    Response::json(['ok' => false, 'message' => 'Pengguna tidak ditemukan.'], 404);
  }

  $userRepository->deleteUser($userId);

  Response::json(['ok' => true, 'message' => 'Pengguna berhasil dihapus.']);
}

Response::json(['ok' => false, 'message' => 'Action tidak dikenali.'], 422);
