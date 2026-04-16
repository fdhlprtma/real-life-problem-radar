<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Request;
use App\Core\Response;

if (Request::method() !== 'GET') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$reportId = (int) Request::input('report_id', '0');
if ($reportId <= 0) {
  Response::json(['ok' => false, 'message' => 'report_id wajib diisi.'], 422);
}

$report = $reportRepository->findById($reportId);
if ($report === null) {
  Response::json(['ok' => false, 'message' => 'Laporan tidak ditemukan.'], 404);
}

if ((string) ($report['status'] ?? '') === 'hidden') {
  Response::json(['ok' => false, 'message' => 'Laporan ini sedang disembunyikan oleh admin.'], 403);
}

$filename = 'laporan-' . $reportId . '-' . date('YmdHis') . '.pdf';
$absolutePath = BASE_PATH . '/storage/reports/' . $filename;

$pdfReportService->generate($report, $absolutePath);

if (!is_file($absolutePath)) {
  Response::json(['ok' => false, 'message' => 'Gagal membuat PDF.'], 500);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($absolutePath));
readfile($absolutePath);
exit;
