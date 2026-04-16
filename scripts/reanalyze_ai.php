<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\AiClassifier;

if (PHP_SAPI !== 'cli') {
  echo "Script ini hanya bisa dijalankan via CLI." . PHP_EOL;
  exit(1);
}

$options = getopt('', ['limit::', 'offset::', 'dry-run', 'flat-only']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 200;
$offset = isset($options['offset']) ? max(0, (int) $options['offset']) : 0;
$dryRun = array_key_exists('dry-run', $options);
$flatOnly = array_key_exists('flat-only', $options);

$pdo = Database::connection();
$aiClassifier = new AiClassifier();

$rows = fetchReports($pdo, $limit, $offset);
if (count($rows) === 0) {
  echo "Tidak ada laporan yang diproses." . PHP_EOL;
  exit(0);
}

$processed = 0;
$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
  $processed++;

  $id = (int) ($row['id'] ?? 0);
  $description = trim((string) ($row['description'] ?? ''));
  if ($id <= 0 || $description === '') {
    $skipped++;
    echo "[SKIP] report_id={$id}: deskripsi kosong/invalid" . PHP_EOL;
    continue;
  }

  $existingSummary = trim((string) ($row['ai_summary'] ?? ''));
  if ($flatOnly && !thisIsFlat($existingSummary)) {
    $skipped++;
    echo "[SKIP] report_id={$id}: summary tidak terdeteksi flat" . PHP_EOL;
    continue;
  }

  [$absImagePath, $mimeType] = resolveImageEvidence($row);
  $newAi = $aiClassifier->classify($description, $absImagePath, $mimeType);

  $newCategory = (string) ($newAi['category_ai'] ?? 'lainnya');
  $newUrgency = (string) ($newAi['urgency_ai'] ?? 'normal');
  $newConfidence = (float) ($newAi['confidence_ai'] ?? 0.6);
  $newSummary = trim((string) ($newAi['ai_summary'] ?? 'Analisis AI tidak tersedia.'));

  $same =
    $newCategory === (string) ($row['category_ai'] ?? '')
    && $newUrgency === (string) ($row['urgency_ai'] ?? '')
    && abs($newConfidence - (float) ($row['confidence_ai'] ?? 0.0)) < 0.0001
    && $newSummary === $existingSummary;

  if ($same) {
    $skipped++;
    echo "[SKIP] report_id={$id}: hasil tidak berubah" . PHP_EOL;
    continue;
  }

  if (!$dryRun) {
    $stmt = $pdo->prepare(
      'UPDATE reports
       SET category_ai = :category_ai,
           urgency_ai = :urgency_ai,
           confidence_ai = :confidence_ai,
           ai_summary = :ai_summary,
           updated_at = :updated_at
       WHERE id = :id'
    );

    $stmt->execute([
      ':category_ai' => $newCategory,
      ':urgency_ai' => $newUrgency,
      ':confidence_ai' => $newConfidence,
      ':ai_summary' => $newSummary,
      ':updated_at' => date('Y-m-d H:i:s'),
      ':id' => $id,
    ]);
  }

  $updated++;
  $summaryPreview = mb_substr(str_replace(["\r", "\n"], ' ', $newSummary), 0, 120);
  echo sprintf(
    "[%s] report_id=%d category=%s urgency=%s summary=%s%s",
    $dryRun ? 'DRY' : 'OK',
    $id,
    $newCategory,
    $newUrgency,
    $summaryPreview,
    mb_strlen($newSummary) > 120 ? '...' : ''
  ) . PHP_EOL;
}

echo PHP_EOL;
echo "Selesai. processed={$processed}, updated={$updated}, skipped={$skipped}" . PHP_EOL;
if ($dryRun) {
  echo "Mode dry-run aktif: tidak ada perubahan database." . PHP_EOL;
}

function fetchReports(PDO $pdo, int $limit, int $offset): array
{
  $stmt = $pdo->prepare(
    'SELECT id, description, media_path, media_type, category_ai, urgency_ai, confidence_ai, ai_summary
     FROM reports
     ORDER BY id DESC
     LIMIT :limit OFFSET :offset'
  );
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll();
}

function resolveImageEvidence(array $report): array
{
  $mediaPath = trim((string) ($report['media_path'] ?? ''));
  if ($mediaPath === '') {
    return [null, null];
  }

  $absolutePath = BASE_PATH . '/' . ltrim($mediaPath, '/');
  if (!is_file($absolutePath)) {
    return [null, null];
  }

  $mimeType = trim((string) ($report['media_type'] ?? ''));
  if ($mimeType === '' && function_exists('mime_content_type')) {
    $detected = mime_content_type($absolutePath);
    $mimeType = is_string($detected) ? trim($detected) : '';
  }

  // Izinkan gambar dan video agar classifier bisa extract frame dari video
  if (!str_starts_with($mimeType, 'image/') && $mimeType !== 'video/mp4') {
    return [null, null];
  }

  return [$absolutePath, $mimeType];
}

function thisIsFlat(string $summary): bool
{
  $text = mb_strtolower(trim($summary));
  if ($text === '') {
    return true;
  }

  $markers = [
    'indikasi utama mengarah ke kategori',
    'analisis didasarkan pada deskripsi laporan',
    'dampak yang mungkin terjadi adalah terganggunya aktivitas warga',
    'disarankan verifikasi lapangan',
    'klasifikasi fallback berbasis kata kunci lokal',
  ];

  $hits = 0;
  foreach ($markers as $marker) {
    if (mb_strpos($text, $marker) !== false) {
      $hits++;
    }
  }

  return $hits >= 2;
}
