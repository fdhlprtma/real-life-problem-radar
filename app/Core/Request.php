<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
  public static function method(): string
  {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  }

  public static function input(string $key, ?string $default = null): ?string
  {
    if (isset($_POST[$key])) {
      return trim((string) $_POST[$key]);
    }

    if (isset($_GET[$key])) {
      return trim((string) $_GET[$key]);
    }

    return $default;
  }

  public static function jsonBody(): array
  {
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
      return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
  }

  public static function ipAddress(): string
  {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
  }
}
