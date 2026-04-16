<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

Auth::requireLoginJson();

$user = Auth::user();
if (($user['role'] ?? '') !== 'government') {
  Response::json(['ok' => false, 'message' => 'Hanya akun pemerintah yang dapat mengakses endpoint ini.'], 403);
}

$fullUser = $userRepository->findById((int) ($user['id'] ?? 0));
if ($fullUser === null || ($fullUser['account_status'] ?? '') !== 'verified') {
  Response::json(['ok' => false, 'message' => 'Akun instansi belum terverifikasi.'], 403);
}

$action = strtolower((string) Request::input('action', 'stats'));
$region = [
  'province' => (string) ($fullUser['region_province'] ?? ''),
  'city' => (string) ($fullUser['region_city'] ?? ''),
  'district' => (string) ($fullUser['region_district'] ?? ''),
  'subdistrict' => (string) ($fullUser['region_subdistrict'] ?? ''),
];

if (Request::method() === 'GET' && $action === 'stats') {
  $stats = $reportRepository->statsSummaryForGovernment($region);
  $stats['badge_verified_government'] = true;
  $stats['badge_active_responder'] = ((int) $stats['resolved_reports']) > 0;

  Response::json([
    'ok' => true,
    'data' => [
      'agency_name' => $fullUser['agency_name'] ?? '-',
      'agency_type' => $fullUser['agency_type'] ?? '-',
      'agency_sector' => $fullUser['agency_sector'] ?? '-',
      'region' => $region,
      'stats' => $stats,
    ],
  ]);
}

if (Request::method() === 'GET' && $action === 'reports') {
  $status = (string) (Request::input('status', 'all') ?? 'all');
  $q = (string) (Request::input('q', '') ?? '');
  $page = max(1, (int) (Request::input('page', '1') ?? '1'));
  $perPage = min(100, max(10, (int) (Request::input('per_page', '25') ?? '25')));

  $result = $reportRepository->listForGovernment($region, $status, $q, $page, $perPage);
  Response::json([
    'ok' => true,
    'data' => $result['data'],
    'meta' => [
      'total' => $result['total'],
      'page' => $result['page'],
      'per_page' => $result['per_page'],
      'total_pages' => $result['total_pages'],
    ],
  ]);
}

if (Request::method() === 'GET' && $action === 'analytics') {
  $days = max(7, min(30, (int) (Request::input('days', '14') ?? '14')));
  $analytics = $reportRepository->analyticsForGovernment($region, $days);

  Response::json([
    'ok' => true,
    'data' => $analytics,
  ]);
}

if (Request::method() === 'POST' && $action === 'status') {
  $body = Request::jsonBody();
  $reportId = (int) ($body['report_id'] ?? 0);
  $status = strtolower((string) ($body['status'] ?? 'open'));

  if ($reportId <= 0 || !in_array($status, ['open', 'in_progress', 'resolved'], true)) {
    Response::json(['ok' => false, 'message' => 'Payload status tidak valid.'], 422);
  }

  $report = $reportRepository->findById($reportId);
  if ($report === null) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
  }

  foreach (['province', 'city', 'district', 'subdistrict'] as $key) {
    $target = normalizeRegionPart((string) ($region[$key] ?? ''));
    $actual = normalizeRegionPart((string) ($report[$key] ?? ''));
    if ($target !== '' && $actual !== $target) {
      Response::json(['ok' => false, 'message' => 'Laporan di luar wilayah tanggung jawab instansi.'], 403);
    }
  }

  $reportRepository->updateStatus($reportId, $status);

  Response::json([
    'ok' => true,
    'message' => 'Status laporan diperbarui.',
    'data' => $reportRepository->findById($reportId),
  ]);
}

Response::json(['ok' => false, 'message' => 'Action tidak dikenali.'], 422);

function normalizeRegionPart(string $value): string
{
  $value = strtolower(trim($value));
  if ($value === '') {
    return '';
  }

  $value = str_replace('sulwesi', 'sulawesi', $value);
  $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

  return trim($value);
}
