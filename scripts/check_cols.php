<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$pdo = App\Core\Database::connection();
$driver = env('DB_DRIVER', 'sqlite');

if ($driver === 'sqlite') {
    $cols = $pdo->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['name'] . PHP_EOL;
    }
} else {
    $dbName = env('DB_NAME', 'rlp_radar');
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'reports'");
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo $c['COLUMN_NAME'] . PHP_EOL;
    }
}
