<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
  private static ?PDO $connection = null;

  public static function connection(): PDO
  {
    if (self::$connection !== null) {
      return self::$connection;
    }

    $driver = env('DB_DRIVER', 'sqlite');

    try {
      if ($driver === 'sqlite') {
        $databasePath = BASE_PATH . '/' . ltrim((string) env('DB_DATABASE', 'storage/database.sqlite'), '/');
        $dsn = 'sqlite:' . $databasePath;
        self::$connection = new PDO($dsn);
      } elseif ($driver === 'mysql') {
        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (string) env('DB_PORT', '3306');
        $database = (string) env('DB_NAME', 'rlp_radar');
        $username = (string) env('DB_USERNAME', 'root');
        $password = (string) env('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        self::$connection = new PDO($dsn, $username, $password);
      } else {
        throw new RuntimeException('DB_DRIVER tidak didukung. Gunakan sqlite atau mysql.');
      }

      self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

      if ($driver === 'sqlite') {
        $busyTimeoutSeconds = max(1, (int) env('DB_BUSY_TIMEOUT', '10'));
        self::$connection->setAttribute(PDO::ATTR_TIMEOUT, $busyTimeoutSeconds);
        self::$connection->exec('PRAGMA busy_timeout = ' . ($busyTimeoutSeconds * 1000));
        self::$connection->exec('PRAGMA journal_mode = WAL');
        self::$connection->exec('PRAGMA synchronous = NORMAL');
        self::$connection->exec('PRAGMA foreign_keys = ON');
      }

      return self::$connection;
    } catch (PDOException $exception) {
      throw new RuntimeException('Database connection failed: ' . $exception->getMessage());
    }
  }
}
