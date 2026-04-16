<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use InvalidArgumentException;
use OverflowException;

final class CommentRepository
{
  private const MAX_PER_USER_PER_REPORT = 5;
  private const MAX_WORDS = 500;

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    $this->ensureSchema();
  }

  private function ensureSchema(): void
  {
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
      )'
    );

    $this->pdo->exec(
      'CREATE INDEX IF NOT EXISTS idx_comments_report_id ON comments (report_id)'
    );
  }

  /** Hitung jumlah kata dalam teks (mendukung bahasa Indonesia). */
  private function countWords(string $text): int
  {
    $text = trim(strip_tags($text));
    if ($text === '') {
      return 0;
    }
    return count(preg_split('/\s+/u', $text));
  }

  /** Ambil semua komentar untuk satu laporan beserta nama pelapor. */
  public function getByReport(int $reportId): array
  {
    $stmt = $this->pdo->prepare(
      'SELECT c.id, c.report_id, c.user_id, c.content, c.created_at,
              u.name AS user_name
       FROM comments c
       JOIN users u ON u.id = c.user_id
       WHERE c.report_id = :report_id
       ORDER BY c.created_at ASC'
    );
    $stmt->execute([':report_id' => $reportId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Hitung berapa komentar yang sudah dibuat user ini di laporan ini. */
  public function countByUserAndReport(int $userId, int $reportId): int
  {
    $stmt = $this->pdo->prepare(
      'SELECT COUNT(*) AS total FROM comments WHERE user_id = :user_id AND report_id = :report_id'
    );
    $stmt->execute([':user_id' => $userId, ':report_id' => $reportId]);
    return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
  }

  /**
   * Tambah komentar baru.
   *
   * @throws InvalidArgumentException jika melebihi batas kata
   * @throws OverflowException        jika melebihi batas komentar per laporan
   */
  public function create(int $reportId, int $userId, string $content): int
  {
    $content = trim($content);

    if ($content === '') {
      throw new InvalidArgumentException('Komentar tidak boleh kosong.');
    }

    if ($this->countWords($content) > self::MAX_WORDS) {
      throw new InvalidArgumentException(
        'Komentar melebihi batas ' . self::MAX_WORDS . ' kata.'
      );
    }

    if ($this->countByUserAndReport($userId, $reportId) >= self::MAX_PER_USER_PER_REPORT) {
      throw new OverflowException(
        'Batas komentar per laporan adalah ' . self::MAX_PER_USER_PER_REPORT . ' komentar.'
      );
    }

    $stmt = $this->pdo->prepare(
      'INSERT INTO comments (report_id, user_id, content, created_at)
       VALUES (:report_id, :user_id, :content, :created_at)'
    );

    $stmt->execute([
      ':report_id'  => $reportId,
      ':user_id'    => $userId,
      ':content'    => $content,
      ':created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $this->pdo->lastInsertId();
  }

  public function findById(int $id): ?array
  {
    $stmt = $this->pdo->prepare('SELECT * FROM comments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
  }

  /** @throws InvalidArgumentException */
  public function update(int $id, int $userId, string $content): void
  {
    $content = trim($content);

    if ($content === '') {
      throw new InvalidArgumentException('Komentar tidak boleh kosong.');
    }

    if ($this->countWords($content) > self::MAX_WORDS) {
      throw new InvalidArgumentException('Komentar melebihi batas ' . self::MAX_WORDS . ' kata.');
    }

    $comment = $this->findById($id);
    if ($comment === null) {
      throw new InvalidArgumentException('Komentar tidak ditemukan.');
    }

    if ((int) $comment['user_id'] !== $userId) {
      throw new InvalidArgumentException('Anda tidak berhak mengedit komentar ini.');
    }

    $stmt = $this->pdo->prepare('UPDATE comments SET content = :content WHERE id = :id');
    $stmt->execute([':content' => $content, ':id' => $id]);
  }

  /** @throws InvalidArgumentException */
  public function delete(int $id, int $userId): void
  {
    $comment = $this->findById($id);
    if ($comment === null) {
      throw new InvalidArgumentException('Komentar tidak ditemukan.');
    }

    if ((int) $comment['user_id'] !== $userId) {
      throw new InvalidArgumentException('Anda tidak berhak menghapus komentar ini.');
    }

    $stmt = $this->pdo->prepare('DELETE FROM comments WHERE id = :id');
    $stmt->execute([':id' => $id]);
  }
}
