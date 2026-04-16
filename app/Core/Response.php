<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
  public static function json(array $payload, int $statusCode = 200): void
  {
    // Buang output stray (warning/notice PHP) agar tidak merusak JSON.
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }
}
