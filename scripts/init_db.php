<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$driver = env('DB_DRIVER', 'sqlite');
$schemaFilename = $driver === 'mysql' ? 'schema_mysql.sql' : 'schema.sql';
$schemaPath = BASE_PATH . '/database/' . $schemaFilename;
if (!is_file($schemaPath)) {
  echo "Schema file not found at {$schemaPath}" . PHP_EOL;
  exit(1);
}

$schemaSql = file_get_contents($schemaPath);
if ($schemaSql === false) {
  echo "Failed to read schema file." . PHP_EOL;
  exit(1);
}

$pdo = Database::connection();

$statements = array_filter(array_map('trim', explode(';', $schemaSql)));
foreach ($statements as $statement) {
  safeExec($pdo, $statement);
}

// Backfill ringan untuk database lama yang sudah terlanjur terbentuk.
safeExec($pdo, 'ALTER TABLE reports ADD COLUMN user_id INTEGER DEFAULT NULL');
safeExec($pdo, 'ALTER TABLE votes ADD COLUMN user_id INTEGER DEFAULT NULL');
safeExec($pdo, 'ALTER TABLE votes ADD COLUMN updated_at TEXT DEFAULT NULL');
safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_reports_user_id ON reports (user_id)');
safeExec($pdo, 'CREATE UNIQUE INDEX IF NOT EXISTS uq_votes_report_user ON votes (report_id, user_id)');
safeExec($pdo, 'ALTER TABLE reports ADD COLUMN media_paths TEXT DEFAULT NULL');

echo "Database initialized successfully." . PHP_EOL;

function safeExec(\PDO $pdo, string $sql): void
{
  try {
    $pdo->exec($sql);
  } catch (\Throwable $exception) {
    // Sengaja diabaikan agar inisialisasi tetap idempotent pada skema yang sudah sesuai.
  }
}
