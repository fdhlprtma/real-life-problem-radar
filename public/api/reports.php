<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;

if (Request::method() === 'GET') {
  $mine = (string) ($_GET['mine'] ?? '0');
  $statusFilter = trim((string) ($_GET['status'] ?? ''));

  if ($mine === '1') {
    Auth::requireLoginJson();
    $userId = Auth::userId();
    $data = $reportRepository->listByUser($userId, 300, $statusFilter !== '' ? $statusFilter : null);
    Response::json([
      'ok' => true,
      'data' => $data,
    ]);
  }

  Response::json([
    'ok' => true,
    'data' => $reportRepository->listLatest(300),
  ]);
}

if (Request::method() === 'DELETE') {
  Auth::requireLoginJson();

  $currentUser = Auth::user();
  if (($currentUser['role'] ?? 'user') !== 'user') {
    Response::json(['ok' => false, 'message' => 'Hanya akun masyarakat yang dapat menghapus laporan sendiri.'], 403);
  }

  $body = Request::jsonBody();
  $reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
  if ($reportId <= 0) {
    Response::json(['ok' => false, 'message' => 'report_id tidak valid.'], 422);
  }

  $existingReport = $reportRepository->findById($reportId);
  if ($existingReport === null || (int) ($existingReport['user_id'] ?? 0) !== (int) Auth::userId()) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan atau bukan milik Anda.'], 404);
  }

  $mediaPaths = extractReportMediaPaths($existingReport);

  $deleted = $reportRepository->deleteByIdAndUser($reportId, Auth::userId());
  if (!$deleted) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan atau bukan milik Anda.'], 404);
  }

  deleteStoredMediaFiles($mediaPaths);

  Response::json([
    'ok' => true,
    'message' => 'Laporan berhasil dihapus.',
  ]);
}

if (Request::method() !== 'POST') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'replace_media') {
  Auth::requireLoginJson();

  $currentUser = Auth::user();
  if (($currentUser['role'] ?? 'user') !== 'user') {
    Response::json(['ok' => false, 'message' => 'Hanya akun masyarakat yang dapat memperbarui bukti laporan.'], 403);
  }

  $reportId = (int) Request::input('report_id', '0');
  if ($reportId <= 0) {
    Response::json(['ok' => false, 'message' => 'report_id tidak valid.'], 422);
  }

  $existing = $reportRepository->findById($reportId);
  if ($existing === null || (int) ($existing['user_id'] ?? 0) !== (int) Auth::userId()) {
    Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan atau bukan milik Anda.'], 404);
  }

  if ((string) ($existing['status'] ?? '') !== 'rejected_pending_media') {
    Response::json(['ok' => false, 'message' => 'Laporan ini tidak berada pada status tertolak karena mismatch gambar.'], 422);
  }

  $oldMediaPaths = extractReportMediaPaths($existing);

  $uploaded = processUploadedMedia(
    $_FILES['media'] ?? [],
    ['image/jpeg', 'image/png', 'image/webp'],
    ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    5
  );

  if (count($uploaded['paths']) === 0 || $uploaded['firstAbsPath'] === null || $uploaded['firstMime'] === null) {
    Response::json(['ok' => false, 'message' => 'Silakan upload minimal 1 gambar (JPG/PNG/WEBP) yang sesuai deskripsi.'], 422);
  }

  $description = (string) ($existing['description'] ?? '');
  $ai = $aiClassifier->classify($description, $uploaded['firstAbsPath'], $uploaded['firstMime']);

  $isConsistent = (bool) ($ai['is_consistent'] ?? true);
  $nextStatus = $isConsistent ? 'open' : 'rejected_pending_media';
  $summary = mergeConsistencySummary((string) ($ai['ai_summary'] ?? ''), (string) ($ai['consistency_reason'] ?? ''), !$isConsistent);

  $updated = $reportRepository->updateMediaAndAnalysisByIdAndUser($reportId, Auth::userId(), [
    'media_path' => $uploaded['paths'][0] ?? null,
    'media_paths' => json_encode($uploaded['paths'], JSON_UNESCAPED_SLASHES),
    'media_type' => $uploaded['firstMime'],
    'category_ai' => (string) ($ai['category_ai'] ?? 'lainnya'),
    'urgency_ai' => (string) ($ai['urgency_ai'] ?? 'normal'),
    'confidence_ai' => (float) ($ai['confidence_ai'] ?? 0.6),
    'ai_summary' => $summary,
    'status' => $nextStatus,
    'updated_at' => date('Y-m-d H:i:s'),
  ]);

  if (!$updated) {
    Response::json(['ok' => false, 'message' => 'Gagal memperbarui laporan.'], 500);
  }

  deleteStoredMediaFiles($oldMediaPaths);

  $fresh = $reportRepository->findById($reportId);
  Response::json([
    'ok' => true,
    'message' => $nextStatus === 'open'
      ? 'Bukti berhasil diperbarui dan laporan diterima kembali untuk diproses.'
      : 'Bukti sudah diperbarui, tetapi masih belum sesuai deskripsi. Silakan coba gambar lain yang lebih relevan.',
    'data' => $fresh,
  ]);
}

Auth::requireLoginJson();

$currentUser = Auth::user();
if (($currentUser['role'] ?? 'user') !== 'user') {
  Response::json(['ok' => false, 'message' => 'Hanya akun masyarakat yang dapat membuat laporan.'], 403);
}

$currentUserId = Auth::userId();
ensureNotSuspendedForAction($userRepository, $currentUserId, 'membuat laporan');

// Rate-limit: max 3 laporan per hari per IP (reset setiap tanggal server)
$reportIp = Request::ipAddress();
$dailyKey = 'report_post_daily:' . $reportIp . ':' . date('Y-m-d');
if (!RateLimit::allow($dailyKey, 3, 86400)) {
  Response::json(['ok' => false, 'message' => 'Batas harian tercapai. Maksimal 3 laporan per hari.'], 429);
}

$title        = Request::input('title', '');
$description  = Request::input('description', '');
$categoryUser = Request::input('category_user', 'lainnya');
$province     = trim((string) Request::input('province', ''));
$city         = trim((string) Request::input('city', ''));
$district     = trim((string) Request::input('district', ''));
$subdistrict  = trim((string) Request::input('subdistrict', ''));
$latitude     = (float) Request::input('latitude', '0');
$longitude    = (float) Request::input('longitude', '0');

if ($title === '' || $description === '') {
  Response::json(['ok' => false, 'message' => 'Judul dan deskripsi wajib diisi.'], 422);
}

$moderation = $aiClassifier->detectJudolPromotion($title . "\n" . $description);
if ((bool) ($moderation['is_judol_promotion'] ?? false)) {
  suspendUserForJudol($userRepository, $currentUserId, (string) ($moderation['reason'] ?? 'Promosi judi online terdeteksi pada laporan.'));
  Response::json([
    'ok' => false,
    'message' => 'Laporan terdeteksi mempromosikan judi online. Akun Anda ditangguhkan dari aktivitas komentar/laporan selama 1 hari.',
  ], 403);
}

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
  Response::json(['ok' => false, 'message' => 'Koordinat tidak valid.'], 422);
}

$uploaded = processUploadedMedia(
  $_FILES['media'] ?? [],
  ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'],
  ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4'],
  5
);

$mediaPath = $uploaded['paths'][0] ?? null;
$mediaType = $uploaded['firstMime'] ?? (count($uploaded['paths']) > 0 ? 'video/mp4' : null);
$mediaPathsJson = count($uploaded['paths']) > 0 ? json_encode($uploaded['paths'], JSON_UNESCAPED_SLASHES) : null;

$ai = $aiClassifier->classify($description, $uploaded['firstAbsPath'], $uploaded['firstMime']);
$hasImageEvidence = $uploaded['firstAbsPath'] !== null;
$isConsistent = (bool) ($ai['is_consistent'] ?? true);
$status = ($hasImageEvidence && !$isConsistent) ? 'rejected_pending_media' : 'open';
$summary = mergeConsistencySummary((string) ($ai['ai_summary'] ?? ''), (string) ($ai['consistency_reason'] ?? ''), $status === 'rejected_pending_media');

$now = date('Y-m-d H:i:s');
$reportId = $reportRepository->create([
  'user_id' => $currentUserId,
  'title' => $title,
  'description' => $description,
  'category_user' => $categoryUser,
  'category_ai' => (string) ($ai['category_ai'] ?? 'lainnya'),
  'urgency_ai' => (string) ($ai['urgency_ai'] ?? 'normal'),
  'confidence_ai' => (float) ($ai['confidence_ai'] ?? 0.6),
  'ai_summary' => $summary,
  'media_path' => $mediaPath,
  'media_paths' => $mediaPathsJson,
  'media_type' => $mediaType,
  'province' => $province !== '' ? $province : null,
  'city' => $city !== '' ? $city : null,
  'district' => $district !== '' ? $district : null,
  'subdistrict' => $subdistrict !== '' ? $subdistrict : null,
  'latitude' => $latitude,
  'longitude' => $longitude,
  'status' => $status,
  'created_at' => $now,
  'updated_at' => $now,
]);

$newReport = $reportRepository->findById($reportId);

Response::json([
  'ok' => true,
  'message' => $status === 'rejected_pending_media'
    ? 'Laporan tersimpan tetapi ditolak otomatis karena gambar tidak sesuai deskripsi. Silakan perbarui gambar di menu Laporan Tertolak.'
    : 'Laporan berhasil dibuat.',
  'data' => $newReport,
], 201);

function ensureNotSuspendedForAction($userRepository, int $userId, string $actionLabel): void
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
  $message = 'Akun Anda sedang ditangguhkan sampai ' . $until . '. Anda tidak dapat ' . $actionLabel . ' selama masa tangguh.';
  if ($reason !== '') {
    $message .= ' Alasan: ' . $reason;
  }

  Response::json([
    'ok' => false,
    'message' => $message,
  ], 403);
}

function suspendUserForJudol($userRepository, int $userId, string $reason): void
{
  $finalReason = trim($reason) !== '' ? trim($reason) : 'Promosi judi online terdeteksi oleh sistem moderasi.';
  $userRepository->suspendForHours($userId, 24, $finalReason);

  $fresh = $userRepository->findById($userId);
  if (is_array($fresh)) {
    Auth::login($fresh);
  }
}

function extractReportMediaPaths(array $report): array
{
  $result = [];

  $mediaPathsRaw = $report['media_paths'] ?? null;
  if (is_string($mediaPathsRaw) && trim($mediaPathsRaw) !== '') {
    $decoded = json_decode($mediaPathsRaw, true);
    if (is_array($decoded)) {
      foreach ($decoded as $path) {
        $pathText = trim((string) $path);
        if ($pathText !== '') {
          $result[] = $pathText;
        }
      }
    }
  }

  $single = trim((string) ($report['media_path'] ?? ''));
  if ($single !== '') {
    $result[] = $single;
  }

  return array_values(array_unique($result));
}

function deleteStoredMediaFiles(array $paths): void
{
  foreach ($paths as $path) {
    $clean = ltrim(str_replace(['..\\', '../'], '', (string) $path), '\\/');
    if ($clean === '') {
      continue;
    }

    $absolute = BASE_PATH . '/' . $clean;
    if (is_file($absolute)) {
      @unlink($absolute);
    }
  }
}

function mergeConsistencySummary(string $baseSummary, string $reason, bool $rejected): string
{
  $summary = trim($baseSummary) !== '' ? trim($baseSummary) : 'Analisis AI tidak tersedia.';
  if (!$rejected) {
    return $summary;
  }

  $reasonText = trim($reason) !== ''
    ? trim($reason)
    : 'Bukti visual tidak cukup relevan terhadap deskripsi kejadian.';

  return $summary
    . ' Catatan sistem: laporan tertolak sementara karena ketidaksesuaian deskripsi dan bukti visual. '
    . 'Alasan: ' . $reasonText . '. '
    . 'Silakan ganti gambar agar sesuai deskripsi.';
}

function processUploadedMedia(array $uploadedFiles, array $allowedMimes, array $extMap, int $maxFiles): array
{
  $collectedPaths = [];
  $firstAbsPath = null;
  $firstMime = null;

  if (empty($uploadedFiles) || !isset($uploadedFiles['error'])) {
    return [
      'paths' => $collectedPaths,
      'firstAbsPath' => $firstAbsPath,
      'firstMime' => $firstMime,
    ];
  }

  if (!is_array($uploadedFiles['error'])) {
    $uploadedFiles = array_map(static fn($v) => [$v], $uploadedFiles);
  }

  $count = min((int) count($uploadedFiles['error']), $maxFiles);
  for ($i = 0; $i < $count; $i++) {
    if ((int) $uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
      continue;
    }

    $tmpPath = (string) $uploadedFiles['tmp_name'][$i];
    $mimeType = (string) mime_content_type($tmpPath);
    if (!in_array($mimeType, $allowedMimes, true)) {
      continue;
    }

    $ext = $extMap[$mimeType] ?? 'bin';
    $filename = 'report_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = BASE_PATH . '/storage/uploads/' . $filename;

    if (str_starts_with($mimeType, 'image/')) {
      if (!compressImage($tmpPath, $absPath, $mimeType)) {
        if (!move_uploaded_file($tmpPath, $absPath)) {
          continue;
        }
      }
    } else {
      if (!move_uploaded_file($tmpPath, $absPath)) {
        continue;
      }
    }

    $relPath = 'storage/uploads/' . $filename;
    $collectedPaths[] = $relPath;

    if ($firstAbsPath === null && str_starts_with($mimeType, 'image/')) {
      $firstAbsPath = $absPath;
      $firstMime = $mimeType;
    }
  }

  return [
    'paths' => $collectedPaths,
    'firstAbsPath' => $firstAbsPath,
    'firstMime' => $firstMime,
  ];
}

// ---------------------------------------------------------------------------
// Helper: kompres & resize gambar menggunakan GD (maks 1920px lebar, q=82)
// ---------------------------------------------------------------------------
function compressImage(string $src, string $dest, string $mimeType, int $maxWidth = 1920, int $quality = 82): bool
{
  if (!function_exists('imagecreatefromjpeg')) {
    return false;
  }

  $img = match ($mimeType) {
    'image/jpeg' => @imagecreatefromjpeg($src),
    'image/png' => @imagecreatefrompng($src),
    'image/webp' => @imagecreatefromwebp($src),
    default => false,
  };

  if ($img === false) {
    return false;
  }

  $origW = imagesx($img);
  $origH = imagesy($img);

  if ($origW > $maxWidth) {
    $scale = $maxWidth / $origW;
    $newW = $maxWidth;
    $newH = (int) round($origH * $scale);
    $canvas = imagecreatetruecolor($newW, $newH);

    if ($mimeType === 'image/png') {
      imagealphablending($canvas, false);
      imagesavealpha($canvas, true);
    }

    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);
    $img = $canvas;
  }

  $ok = match ($mimeType) {
    'image/jpeg' => imagejpeg($img, $dest, $quality),
    'image/png' => imagepng($img, $dest, (int) round((100 - $quality) / 11.1)),
    'image/webp' => imagewebp($img, $dest, $quality),
    default => false,
  };

  imagedestroy($img);
  return $ok !== false;
}
