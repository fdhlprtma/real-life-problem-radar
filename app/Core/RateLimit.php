<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiter berbasis file. Tidak memerlukan ekstensi tambahan (APCu/Redis).
 */
final class RateLimit
{
  /**
   * Kembalikan true jika request diizinkan, false jika melebihi batas.
   *
   * @param string $key          Identifier unik (mis. "auth:127.0.0.1")
   * @param int    $maxRequests  Jumlah maksimal hit dalam jendela waktu
   * @param int    $windowSeconds Lama jendela waktu (detik)
   */
  public static function allow(string $key, int $maxRequests, int $windowSeconds): bool
  {
    $file = BASE_PATH . '/storage/logs/rl_' . md5($key) . '.json';
    $now  = time();
    $hits = [];

    if (is_file($file)) {
      $raw = file_get_contents($file);
      if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
          $hits = $decoded;
        }
      }
    }

    // Buang timestamp di luar jendela waktu
    $cutoff = $now - $windowSeconds;
    $hits   = array_values(array_filter($hits, static fn(int $t) => $t > $cutoff));

    if (count($hits) >= $maxRequests) {
      return false;
    }

    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);

    return true;
  }
}
