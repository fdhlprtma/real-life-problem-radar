<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\NotificationRepository;

Auth::requireLoginJson();

$notificationRepository = new NotificationRepository($pdo);
$userId = (int) Auth::userId();

if (Request::method() === 'GET') {
  $limit = max(1, min(200, (int) (Request::input('limit', '50') ?? '50')));
  $data = $notificationRepository->listByUser($userId, $limit);

  Response::json([
    'ok' => true,
    'data' => $data,
    'meta' => [
      'unread' => $notificationRepository->unreadCount($userId),
    ],
  ]);
}

if (Request::method() === 'POST') {
  $action = strtolower((string) Request::input('action', ''));
  if ($action !== 'mark_all_read') {
    Response::json(['ok' => false, 'message' => 'Action tidak dikenali.'], 422);
  }

  $notificationRepository->markAllAsRead($userId);
  Response::json([
    'ok' => true,
    'message' => 'Semua notifikasi ditandai sudah dibaca.',
  ]);
}

Response::json(['ok' => false, 'message' => 'Method not allowed'], 405);
