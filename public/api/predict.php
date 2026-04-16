<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Request;
use App\Core\Response;

if (Request::method() !== 'GET') {
  Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$latitude = (float) Request::input('latitude', '-6.2');
$longitude = (float) Request::input('longitude', '106.816');

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
  Response::json(['ok' => false, 'message' => 'Koordinat tidak valid.'], 422);
}

$prediction = $riskPredictor->predict($latitude, $longitude);

Response::json([
  'ok' => true,
  'data' => $prediction,
]);
