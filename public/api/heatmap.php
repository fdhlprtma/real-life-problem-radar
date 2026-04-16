<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Request;
use App\Core\Response;

if (Request::method() !== 'GET') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$rows = $reportRepository->heatmapPoints();
$points = [];

foreach ($rows as $row) {
  $points[] = [
    (float) $row['latitude'],
    (float) $row['longitude'],
    min(1.0, ((int) $row['total']) / 10),
  ];
}

Response::json([
  'ok' => true,
  'data' => $points,
]);
