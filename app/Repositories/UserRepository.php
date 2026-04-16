<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    $this->ensureSchema();
  }

  public function countAll(): int
  {
    $stmt = $this->pdo->query('SELECT COUNT(*) AS total FROM users');
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
  }

  public function create(string $name, string $email, string $passwordHash, string $role = 'user'): int
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO users (
        name, email, password_hash, role, account_type, account_status,
        official_email_domain_valid, created_at
      ) VALUES (
        :name, :email, :password_hash, :role, :account_type, :account_status,
        :official_email_domain_valid, :created_at
      )'
    );

    $stmt->execute([
      ':name' => $name,
      ':email' => $email,
      ':password_hash' => $passwordHash,
      ':role' => $role,
      ':account_type' => 'citizen',
      ':account_status' => 'active',
      ':official_email_domain_valid' => 0,
      ':created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $this->pdo->lastInsertId();
  }

  public function createGovernment(array $data): int
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO users (
        name, email, password_hash, role, account_type, account_status,
        agency_name, agency_type, agency_sector,
        region_province, region_city, region_district, region_subdistrict,
        officer_name, officer_position, officer_nip, officer_phone,
        official_email_domain_valid,
        government_document_path,
        declaration_data_true, declaration_followup,
        created_at
      ) VALUES (
        :name, :email, :password_hash, :role, :account_type, :account_status,
        :agency_name, :agency_type, :agency_sector,
        :region_province, :region_city, :region_district, :region_subdistrict,
        :officer_name, :officer_position, :officer_nip, :officer_phone,
        :official_email_domain_valid,
        :government_document_path,
        :declaration_data_true, :declaration_followup,
        :created_at
      )'
    );

    $stmt->execute([
      ':name' => $data['officer_name'],
      ':email' => $data['email'],
      ':password_hash' => $data['password_hash'],
      ':role' => 'government',
      ':account_type' => 'government',
      ':account_status' => 'pending',
      ':agency_name' => $data['agency_name'],
      ':agency_type' => $data['agency_type'],
      ':agency_sector' => $data['agency_sector'],
      ':region_province' => $data['region_province'],
      ':region_city' => $data['region_city'],
      ':region_district' => $data['region_district'],
      ':region_subdistrict' => $data['region_subdistrict'],
      ':officer_name' => $data['officer_name'],
      ':officer_position' => $data['officer_position'],
      ':officer_nip' => $data['officer_nip'],
      ':officer_phone' => $data['officer_phone'],
      ':official_email_domain_valid' => $data['official_email_domain_valid'] ? 1 : 0,
      ':government_document_path' => $data['government_document_path'] ?? null,
      ':declaration_data_true' => $data['declaration_data_true'] ? 1 : 0,
      ':declaration_followup' => $data['declaration_followup'] ? 1 : 0,
      ':created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) $this->pdo->lastInsertId();
  }

  public function findByEmail(string $email): ?array
  {
    $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
  }

  public function emailExistsExceptUser(string $email, int $userId): bool
  {
    $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $stmt->execute([
      ':email' => $email,
      ':id' => $userId,
    ]);

    return $stmt->fetch() !== false;
  }

  public function findById(int $id): ?array
  {
    $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
  }

  public function listGovernmentAccounts(string $status = 'pending'): array
  {
    $sql = 'SELECT * FROM users WHERE role = :role';
    $params = [':role' => 'government'];

    if ($status !== 'all') {
      $sql .= ' AND account_status = :status';
      $params[':status'] = $status;
    }

    $sql .= ' ORDER BY created_at DESC';

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
  }

  public function updateGovernmentStatus(int $userId, string $status, int $reviewedBy, string $note = ''): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE users SET
        account_status = :account_status,
        reviewed_by = :reviewed_by,
        reviewed_at = :reviewed_at,
        review_note = :review_note
      WHERE id = :id AND role = :role'
    );

    $stmt->execute([
      ':account_status' => $status,
      ':reviewed_by' => $reviewedBy,
      ':reviewed_at' => date('Y-m-d H:i:s'),
      ':review_note' => $note,
      ':id' => $userId,
      ':role' => 'government',
    ]);
  }

  public function governmentStats(): array
  {
    return [
      'total' => $this->countByRoleAndStatus('government', 'all'),
      'pending' => $this->countByRoleAndStatus('government', 'pending'),
      'verified' => $this->countByRoleAndStatus('government', 'verified'),
      'rejected' => $this->countByRoleAndStatus('government', 'rejected'),
    ];
  }

  public function listAllUsers(string $role, string $q, int $page, int $perPage): array
  {
    $conditions = [];
    $params = [];

    if ($role !== 'all') {
      $conditions[] = 'role = :role';
      $params[':role'] = $role;
    }

    if ($q !== '') {
      $conditions[] = '(LOWER(name) LIKE :q OR LOWER(email) LIKE :q)';
      $params[':q'] = '%' . strtolower($q) . '%';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $offset = ($page - 1) * $perPage;

    $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM users {$where}");
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $stmt = $this->pdo->prepare(
      "SELECT id, name, email, role, account_type, account_status,
              agency_name, region_province, region_city, created_at
       FROM users {$where}
       ORDER BY created_at DESC
       LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
      $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

    return [
      'data'        => $stmt->fetchAll(),
      'total'       => $total,
      'page'        => $page,
      'per_page'    => $perPage,
      'total_pages' => max(1, $totalPages),
    ];
  }

  public function updateUser(int $id, string $name, string $email, string $accountStatus): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE users SET name = :name, email = :email, account_status = :account_status WHERE id = :id'
    );
    $stmt->execute([
      ':name'           => $name,
      ':email'          => $email,
      ':account_status' => $accountStatus,
      ':id'             => $id,
    ]);
  }

  public function updateCitizenProfile(int $id, string $name, string $email): void
  {
    $stmt = $this->pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
    $stmt->execute([
      ':name' => $name,
      ':email' => $email,
      ':id' => $id,
    ]);
  }

  public function updatePasswordHash(int $id, string $passwordHash): void
  {
    $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
      ':password_hash' => $passwordHash,
      ':id' => $id,
    ]);
  }

  public function suspendForHours(int $id, int $hours, string $reason): void
  {
    $until = date('Y-m-d H:i:s', time() + max(1, $hours) * 3600);

    $stmt = $this->pdo->prepare(
      'UPDATE users
       SET suspended_until = :suspended_until,
           suspension_reason = :suspension_reason
       WHERE id = :id'
    );

    $stmt->execute([
      ':suspended_until' => $until,
      ':suspension_reason' => $reason,
      ':id' => $id,
    ]);
  }

  public function getActiveSuspension(int $id): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT suspended_until, suspension_reason
       FROM users
       WHERE id = :id
         AND suspended_until IS NOT NULL
         AND suspended_until > :now
       LIMIT 1'
    );

    $stmt->execute([
      ':id' => $id,
      ':now' => date('Y-m-d H:i:s'),
    ]);

    $row = $stmt->fetch();
    if ($row === false) {
      return null;
    }

    return [
      'suspended_until' => (string) ($row['suspended_until'] ?? ''),
      'suspension_reason' => (string) ($row['suspension_reason'] ?? ''),
    ];
  }

  public function deleteUser(int $id): void
  {
    $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
  }

  private function countByRoleAndStatus(string $role, string $status): int
  {
    if ($status === 'all') {
      $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE role = :role');
      $stmt->execute([':role' => $role]);
      $row = $stmt->fetch();
      return (int) ($row['total'] ?? 0);
    }

    $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE role = :role AND account_status = :status');
    $stmt->execute([
      ':role' => $role,
      ':status' => $status,
    ]);
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0);
  }

  private function ensureSchema(): void
  {
    $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
      $this->ensureSqliteColumn('account_type', "TEXT NOT NULL DEFAULT 'citizen'");
      $this->ensureSqliteColumn('account_status', "TEXT NOT NULL DEFAULT 'active'");
      $this->ensureSqliteColumn('agency_name', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('agency_type', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('agency_sector', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('region_province', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('region_city', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('region_district', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('region_subdistrict', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('officer_name', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('officer_position', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('officer_nip', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('officer_phone', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('official_email_domain_valid', 'INTEGER NOT NULL DEFAULT 0');
      $this->ensureSqliteColumn('government_document_path', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('declaration_data_true', 'INTEGER NOT NULL DEFAULT 0');
      $this->ensureSqliteColumn('declaration_followup', 'INTEGER NOT NULL DEFAULT 0');
      $this->ensureSqliteColumn('reviewed_by', 'INTEGER DEFAULT NULL');
      $this->ensureSqliteColumn('reviewed_at', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('review_note', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('suspended_until', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('suspension_reason', 'TEXT DEFAULT NULL');
      return;
    }

    $this->ensureMysqlColumn('account_type', "VARCHAR(20) NOT NULL DEFAULT 'citizen'");
    $this->ensureMysqlColumn('account_status', "VARCHAR(20) NOT NULL DEFAULT 'active'");
    $this->ensureMysqlColumn('agency_name', 'VARCHAR(190) NULL');
    $this->ensureMysqlColumn('agency_type', 'VARCHAR(80) NULL');
    $this->ensureMysqlColumn('agency_sector', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('region_province', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('region_city', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('region_district', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('region_subdistrict', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('officer_name', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('officer_position', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('officer_nip', 'VARCHAR(64) NULL');
    $this->ensureMysqlColumn('officer_phone', 'VARCHAR(32) NULL');
    $this->ensureMysqlColumn('official_email_domain_valid', 'TINYINT(1) NOT NULL DEFAULT 0');
    $this->ensureMysqlColumn('government_document_path', 'VARCHAR(255) NULL');
    $this->ensureMysqlColumn('declaration_data_true', 'TINYINT(1) NOT NULL DEFAULT 0');
    $this->ensureMysqlColumn('declaration_followup', 'TINYINT(1) NOT NULL DEFAULT 0');
    $this->ensureMysqlColumn('reviewed_by', 'BIGINT UNSIGNED NULL');
    $this->ensureMysqlColumn('reviewed_at', 'DATETIME NULL');
    $this->ensureMysqlColumn('review_note', 'TEXT NULL');
    $this->ensureMysqlColumn('suspended_until', 'DATETIME NULL');
    $this->ensureMysqlColumn('suspension_reason', 'TEXT NULL');
  }

  private function ensureSqliteColumn(string $name, string $definition): void
  {
    $stmt = $this->pdo->query('PRAGMA table_info(users)');
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
      if (($row['name'] ?? '') === $name) {
        return;
      }
    }

    $this->pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
  }

  private function ensureMysqlColumn(string $name, string $definition): void
  {
    $stmt = $this->pdo->prepare('SHOW COLUMNS FROM users LIKE :name');
    $stmt->execute([':name' => $name]);
    if ($stmt->fetch() !== false) {
      return;
    }

    $this->pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
  }
}
