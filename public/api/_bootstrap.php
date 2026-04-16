<?php

declare(strict_types=1);

// Pastikan error PHP tidak bocor sebagai HTML ke dalam respons JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

// Global handler: uncaught exception selalu dikembalikan sebagai JSON.
set_exception_handler(static function (\Throwable $e): void {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  error_log('[API Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  echo json_encode([
    'ok' => false,
    'message' => 'Terjadi kesalahan server. Silakan coba lagi.',
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit(1);
});

// Fatal error (misalnya OOM, compile error di include) juga dikembalikan sebagai JSON.
register_shutdown_function(static function (): void {
  $error = error_get_last();
  if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[API Fatal] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    echo json_encode([
      'ok' => false,
      'message' => 'Terjadi kesalahan server. Silakan coba lagi.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
});

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\AiClassifier;
use App\Services\PdfReportService;
use App\Services\RiskPredictor;

$pdo = Database::connection();
$reportRepository = new ReportRepository($pdo);
$userRepository = new UserRepository($pdo);
$aiClassifier = new AiClassifier();
$riskPredictor = new RiskPredictor($reportRepository);
$pdfReportService = new PdfReportService();
