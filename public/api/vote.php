<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Repositories\NotificationRepository;

$notificationRepository = new NotificationRepository($pdo);

if (Request::method() !== 'POST') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

Auth::requireLoginJson();
$currentUserId = Auth::userId();

$body = Request::jsonBody();
$reportId = (int) ($body['report_id'] ?? 0);
$voteType = strtolower((string) ($body['vote_type'] ?? ''));

if ($reportId <= 0 || !in_array($voteType, ['confirm', 'reject'], true)) {
  Response::json(['ok' => false, 'message' => 'Data voting tidak valid.'], 422);
}

$report = $reportRepository->findById($reportId);
if ($report === null) {
  Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
}

if ((string) ($report['status'] ?? '') === 'hidden') {
  Response::json(['ok' => false, 'message' => 'Laporan ini sedang disembunyikan oleh admin.'], 403);
}

$reportRepository->addVote($reportId, (int) $currentUserId, $voteType, Request::ipAddress());
$updated = $reportRepository->findById($reportId);

$ownerId = (int) ($report['user_id'] ?? 0);
if ($ownerId > 0 && $ownerId !== (int) $currentUserId) {
  $actor = Auth::user();
  $actorName = trim((string) ($actor['name'] ?? 'Pengguna'));
  $reportTitle = trim((string) ($report['title'] ?? 'Laporan Anda'));
  $verb = $voteType === 'confirm' ? 'mengonfirmasi' : 'menolak';
  $notificationRepository->create(
    $ownerId,
    $reportId,
    (int) $currentUserId,
    'report_vote',
    'Update validasi laporan',
    $actorName . ' ' . $verb . ' laporan Anda: ' . $reportTitle
  );
}

Response::json([
  'ok' => true,
  'message' => 'Vote tersimpan.',
  'data' => $updated,
]);
