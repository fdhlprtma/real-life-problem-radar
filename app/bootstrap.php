<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');

spl_autoload_register(static function (string $class): void {
  $prefix = 'App\\';
  $baseDir = BASE_PATH . '/app/';

  if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
    return;
  }

  $relativeClass = substr($class, strlen($prefix));
  $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

  if (is_file($file)) {
    require_once $file;
  }
});

loadEnv(BASE_PATH . '/.env');
ensureStorage();

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
  session_name('rlp_radar_session');
  session_start();
}

function loadEnv(string $path): void
{
  if (!is_file($path)) {
    return;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return;
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }

    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $key = trim($key);
    $value = trim($value);

    if ($key === '') {
      continue;
    }

    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
  }
}

function env(string $key, ?string $default = null): ?string
{
  $value = $_ENV[$key] ?? getenv($key);

  if ($value === false || $value === null || $value === '') {
    return $default;
  }

  return (string) $value;
}

function envBool(string $key, bool $default = false): bool
{
  $value = strtolower((string) env($key, $default ? 'true' : 'false'));
  return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ensureStorage(): void
{
  $dirs = [
    BASE_PATH . '/storage',
    BASE_PATH . '/storage/uploads',
    BASE_PATH . '/storage/government_docs',
    BASE_PATH . '/storage/reports',
    BASE_PATH . '/storage/logs',
  ];

  foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
      mkdir($dir, 0775, true);
    }
  }
}
