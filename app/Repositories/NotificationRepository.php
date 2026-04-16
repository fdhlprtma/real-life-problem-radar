<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NotificationRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    $this->ensureSchema();
  }

  public function create(int $userId, int $reportId, int $actorUserId, string $type, string $title, string $message): int
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO notifications (
        user_id, report_id, actor_user_id, type, title, message, is_read, created_at
      ) VALUES (
        :user_id, :report_id, :actor_user_id, :type, :title, :message, :is_read, :created_at
      )'
    );

    $stmt->execute([
      ':user_id' => $userId,
      ':report_id' => $reportId,
      ':actor_user_id' => $actorUserId,
      ':type' => $type,
      ':title' => $title,
      ':message' => $message,
      ':is_read' => 0,
      ':created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $this->pdo->lastInsertId();
  }

  public function listByUser(int $userId, int $limit = 50): array
  {
    $limit = max(1, min(200, $limit));

    $stmt = $this->pdo->prepare(
      'SELECT n.*, u.name AS actor_name, r.title AS report_title
       FROM notifications n
       LEFT JOIN users u ON u.id = n.actor_user_id
       LEFT JOIN reports r ON r.id = n.report_id
       WHERE n.user_id = :user_id
       ORDER BY n.created_at DESC, n.id DESC
       LIMIT :limit'
    );

    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
  }

  public function unreadCount(int $userId): int
  {
    $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute([':user_id' => $userId]);

    return (int) (($stmt->fetch()['total'] ?? 0));
  }

  public function markAllAsRead(int $userId): void
  {
    $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute([':user_id' => $userId]);
  }

  private function ensureSchema(): void
  {
    $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
      $this->pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          user_id BIGINT UNSIGNED NOT NULL,
          report_id BIGINT UNSIGNED NULL,
          actor_user_id BIGINT UNSIGNED NULL,
          type VARCHAR(40) NOT NULL,
          title VARCHAR(190) NOT NULL,
          message TEXT NOT NULL,
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL,
          CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
          CONSTRAINT fk_notifications_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
          CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
      );
      return;
    }

    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        report_id INTEGER DEFAULT NULL,
        actor_user_id INTEGER DEFAULT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
      )'
    );

    $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications (user_id, created_at)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications (user_id, is_read)');
  }
}
