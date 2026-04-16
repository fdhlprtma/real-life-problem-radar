<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
  public static function user(): ?array
  {
    $user = $_SESSION['auth_user'] ?? null;
    return is_array($user) ? $user : null;
  }

  public static function userId(): ?int
  {
    $user = self::user();
    if ($user === null || !isset($user['id'])) {
      return null;
    }

    return (int) $user['id'];
  }

  public static function login(array $user): void
  {
    $_SESSION['auth_user'] = [
      'id' => (int) $user['id'],
      'name' => (string) $user['name'],
      'email' => (string) $user['email'],
      'role' => (string) $user['role'],
      'account_type' => (string) ($user['account_type'] ?? 'citizen'),
      'account_status' => (string) ($user['account_status'] ?? 'active'),
      'suspended_until' => $user['suspended_until'] ?? null,
      'suspension_reason' => $user['suspension_reason'] ?? null,
    ];
  }

  public static function logout(): void
  {
    unset($_SESSION['auth_user']);
  }

  public static function isAdmin(): bool
  {
    $user = self::user();
    return $user !== null && (($user['role'] ?? '') === 'admin');
  }

  public static function isGovernment(): bool
  {
    $user = self::user();
    return $user !== null && (($user['role'] ?? '') === 'government');
  }

  public static function requireLoginJson(): void
  {
    if (self::user() === null) {
      Response::json([
        'ok' => false,
        'message' => 'Silakan login terlebih dahulu.',
      ], 401);
    }
  }

  public static function requireAdminJson(): void
  {
    self::requireLoginJson();

    if (!self::isAdmin()) {
      Response::json([
        'ok' => false,
        'message' => 'Akses admin dibutuhkan.',
      ], 403);
    }
  }
}
