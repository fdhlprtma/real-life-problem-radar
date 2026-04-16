<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Repositories\ReportRepository;

$pdo = Database::connection();
$repo = new ReportRepository($pdo);
echo 'DB ok' . PHP_EOL;

$report = $repo->findById(2);
echo 'Report: ' . ($report ? $report['title'] : 'tidak ditemukan') . PHP_EOL;

try {
    $repo->addVote(2, 1, 'confirm', '127.0.0.1');
    echo 'addVote OK' . PHP_EOL;
} catch (Throwable $e) {
    echo 'addVote Error: ' . $e->getMessage() . PHP_EOL;
}

$after = $repo->findById(2);
echo 'Confirms after: ' . ($after['confirms'] ?? '?') . PHP_EOL;
