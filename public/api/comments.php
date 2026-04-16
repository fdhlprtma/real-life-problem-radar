<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommentRepository;
use App\Repositories\NotificationRepository;

$commentRepository = new CommentRepository($pdo);
$notificationRepository = new NotificationRepository($pdo);

// GET: ambil semua komentar untuk satu laporan
if (Request::method() === 'GET') {
  $reportId = (int) ($_GET['report_id'] ?? 0);

  if ($reportId <= 0) {
    Response::json(['ok' => false, 'message' => 'report_id tidak valid.'], 422);
  }

  $report = $reportRepository->findById($reportId);
  if ($report === null) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
  }

  if ((string) ($report['status'] ?? '') === 'hidden') {
    Response::json(['ok' => false, 'message' => 'Laporan ini sedang disembunyikan oleh admin.'], 403);
  }

  Response::json([
    'ok'   => true,
    'data' => $commentRepository->getByReport($reportId),
  ]);
}

// POST: tambah komentar baru
if (Request::method() === 'POST') {
  Auth::requireLoginJson();

  $body     = Request::jsonBody();
  $reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
  $content  = isset($body['content']) ? trim((string) $body['content']) : '';

  if ($reportId <= 0) {
    Response::json(['ok' => false, 'message' => 'report_id tidak valid.'], 422);
  }

  $report = $reportRepository->findById($reportId);
  if ($report === null) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
  }

  if ((string) ($report['status'] ?? '') === 'hidden') {
    Response::json(['ok' => false, 'message' => 'Laporan ini sedang disembunyikan oleh admin.'], 403);
  }

  if ($content === '') {
    Response::json(['ok' => false, 'message' => 'Komentar tidak boleh kosong.'], 422);
  }

  $currentUserId = (int) Auth::userId();
  ensureNotSuspendedForComment($userRepository, $currentUserId);

  $moderation = $aiClassifier->detectJudolPromotion($content);
  if ((bool) ($moderation['is_judol_promotion'] ?? false)) {
    suspendUserForJudolComment(
      $userRepository,
      $currentUserId,
      (string) ($moderation['reason'] ?? 'Promosi judi online terdeteksi pada komentar.')
    );

    Response::json([
      'ok' => false,
      'message' => 'Komentar terdeteksi mempromosikan judi online. Akun Anda ditangguhkan dari aktivitas komentar/laporan selama 1 hari.',
    ], 403);
  }

  try {
    $commentId = $commentRepository->create($reportId, $currentUserId, $content);

    $ownerId = (int) ($report['user_id'] ?? 0);
    $actorId = (int) Auth::userId();
    if ($ownerId > 0 && $ownerId !== $actorId) {
      $actor = Auth::user();
      $actorName = trim((string) ($actor['name'] ?? 'Pengguna'));
      $reportTitle = trim((string) ($report['title'] ?? 'Laporan Anda'));
      $notificationRepository->create(
        $ownerId,
        $reportId,
        $actorId,
        'report_comment',
        'Komentar baru pada laporan',
        $actorName . ' mengomentari laporan Anda: ' . $reportTitle
      );
    }

    Response::json([
      'ok'      => true,
      'message' => 'Komentar berhasil ditambahkan.',
      'data'    => ['id' => $commentId],
    ], 201);
  } catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
  } catch (\OverflowException $e) {
    Response::json(['ok' => false, 'message' => $e->getMessage()], 429);
  }
}

function ensureNotSuspendedForComment($userRepository, int $userId): void
{
  if ($userId <= 0) {
    Response::json(['ok' => false, 'message' => 'Pengguna tidak valid.'], 401);
  }

  $suspension = $userRepository->getActiveSuspension($userId);
  if ($suspension === null) {
    return;
  }

  $until = (string) ($suspension['suspended_until'] ?? '');
  $reason = trim((string) ($suspension['suspension_reason'] ?? ''));
  $message = 'Akun Anda sedang ditangguhkan sampai ' . $until . '. Anda tidak dapat mengirim komentar selama masa tangguh.';
  if ($reason !== '') {
    $message .= ' Alasan: ' . $reason;
  }

  Response::json([
    'ok' => false,
    'message' => $message,
  ], 403);
}

function suspendUserForJudolComment($userRepository, int $userId, string $reason): void
{
  $finalReason = trim($reason) !== '' ? trim($reason) : 'Promosi judi online terdeteksi oleh sistem moderasi.';
  $userRepository->suspendForHours($userId, 24, $finalReason);

  $fresh = $userRepository->findById($userId);
  if (is_array($fresh)) {
    Auth::login($fresh);
  }
}

// PATCH: edit komentar sendiri
if (Request::method() === 'PATCH') {
  Auth::requireLoginJson();

  $body      = Request::jsonBody();
  $commentId = isset($body['comment_id']) ? (int) $body['comment_id'] : 0;
  $content   = isset($body['content']) ? trim((string) $body['content']) : '';

  if ($commentId <= 0) {
    Response::json(['ok' => false, 'message' => 'comment_id tidak valid.'], 422);
  }

  try {
    $commentRepository->update($commentId, Auth::userId(), $content);
    Response::json(['ok' => true, 'message' => 'Komentar berhasil diperbarui.']);
  } catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
  }
}

// DELETE: hapus komentar sendiri
if (Request::method() === 'DELETE') {
  Auth::requireLoginJson();

  $body      = Request::jsonBody();
  $commentId = isset($body['comment_id']) ? (int) $body['comment_id'] : 0;

  if ($commentId <= 0) {
    Response::json(['ok' => false, 'message' => 'comment_id tidak valid.'], 422);
  }

  try {
    $commentRepository->delete($commentId, Auth::userId());
    Response::json(['ok' => true, 'message' => 'Komentar berhasil dihapus.']);
  } catch (\InvalidArgumentException $e) {
    Response::json(['ok' => false, 'message' => $e->getMessage()], 403);
  }
}

Response::json(['ok' => false, 'message' => 'Method not allowed.'], 405);
