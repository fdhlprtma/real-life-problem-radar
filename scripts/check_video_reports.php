<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();
$stmt = $pdo->query("SELECT id, title, media_type, ai_summary, created_at FROM reports WHERE media_type = 'video/mp4' ORDER BY id DESC LIMIT 5");
foreach ($stmt->fetchAll() as $r) {
  echo 'id=' . $r['id'] . ' created=' . $r['created_at'] . PHP_EOL;
  echo '  summary=' . substr((string)$r['ai_summary'], 0, 100) . PHP_EOL . PHP_EOL;
}
