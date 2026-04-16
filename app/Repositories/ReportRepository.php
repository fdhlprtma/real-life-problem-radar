<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class ReportRepository
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    $this->ensureSchema();
  }

  public function create(array $data): int
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO reports (
        user_id, title, description, category_user, category_ai, urgency_ai, confidence_ai,
        ai_summary, media_path, media_paths, media_type,
        province, city, district, subdistrict,
        latitude, longitude, status, created_at, updated_at
      ) VALUES (
        :user_id, :title, :description, :category_user, :category_ai, :urgency_ai, :confidence_ai,
        :ai_summary, :media_path, :media_paths, :media_type,
        :province, :city, :district, :subdistrict,
        :latitude, :longitude, :status, :created_at, :updated_at
      )'
    );

    $params = [
      ':user_id'       => $data['user_id'] ?? null,
      ':title'         => $data['title'],
      ':description'   => $data['description'],
      ':category_user' => $data['category_user'],
      ':category_ai'   => $data['category_ai'],
      ':urgency_ai'    => $data['urgency_ai'],
      ':confidence_ai' => $data['confidence_ai'],
      ':ai_summary'    => $data['ai_summary'],
      ':media_path'    => $data['media_path'],
      ':media_paths'   => $data['media_paths'] ?? null,
      ':media_type'    => $data['media_type'],
      ':province'      => $data['province'] ?? null,
      ':city'          => $data['city'] ?? null,
      ':district'      => $data['district'] ?? null,
      ':subdistrict'   => $data['subdistrict'] ?? null,
      ':latitude'      => $data['latitude'],
      ':longitude'     => $data['longitude'],
      ':status'        => $data['status'] ?? 'open',
      ':created_at'    => $data['created_at'],
      ':updated_at'    => $data['updated_at'],
    ];

    $attempt = 0;
    while (true) {
      try {
        $stmt->execute($params);
        break;
      } catch (PDOException $exception) {
        $isSqlite = ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
        $isLocked = stripos($exception->getMessage(), 'database is locked') !== false;

        if ($isSqlite && $isLocked && $attempt < 3) {
          $attempt++;
          usleep(200000);
          continue;
        }

        throw $exception;
      }
    }

    return (int) $this->pdo->lastInsertId();
  }

  public function listLatest(int $limit = 200): array
  {
    $sql = "
      SELECT
        r.*,
        u.name AS reporter_name,
        COALESCE(SUM(CASE WHEN v.vote_type = 'confirm' THEN 1 ELSE 0 END), 0) AS confirms,
        COALESCE(SUM(CASE WHEN v.vote_type = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
      FROM reports r
      LEFT JOIN votes v ON v.report_id = r.id
      LEFT JOIN users u ON u.id = r.user_id
      WHERE r.status NOT IN ('rejected_pending_media', 'hidden')
      GROUP BY r.id
      ORDER BY r.created_at DESC
      LIMIT :limit
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
  }

  public function listByUser(int $userId, int $limit = 200, ?string $status = null): array
  {
    $whereStatus = '';
    if ($status !== null && $status !== '') {
      $whereStatus = ' AND r.status = :status';
    }

    $sql = "
      SELECT
        r.*,
        u.name AS reporter_name,
        COALESCE(SUM(CASE WHEN v.vote_type = 'confirm' THEN 1 ELSE 0 END), 0) AS confirms,
        COALESCE(SUM(CASE WHEN v.vote_type = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
      FROM reports r
      LEFT JOIN votes v ON v.report_id = r.id
      LEFT JOIN users u ON u.id = r.user_id
      WHERE r.user_id = :user_id {$whereStatus}
      GROUP BY r.id
      ORDER BY r.created_at DESC
      LIMIT :limit
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    if ($whereStatus !== '') {
      $stmt->bindValue(':status', $status);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
  }

  public function findById(int $id): ?array
  {
    $sql = "
      SELECT
        r.*,
        u.name AS reporter_name,
        COALESCE(SUM(CASE WHEN v.vote_type = 'confirm' THEN 1 ELSE 0 END), 0) AS confirms,
        COALESCE(SUM(CASE WHEN v.vote_type = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
      FROM reports r
      LEFT JOIN votes v ON v.report_id = r.id
      LEFT JOIN users u ON u.id = r.user_id
      WHERE r.id = :id
      GROUP BY r.id
      LIMIT 1
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
  }

  public function addVote(int $reportId, int $userId, string $voteType, ?string $ipAddress): void
  {
    $existing = $this->pdo->prepare('SELECT id FROM votes WHERE report_id = :report_id AND user_id = :user_id LIMIT 1');
    $existing->execute([
      ':report_id' => $reportId,
      ':user_id' => $userId,
    ]);

    $row = $existing->fetch();
    if ($row !== false) {
      $stmt = $this->pdo->prepare('UPDATE votes SET vote_type = :vote_type, ip_address = :ip_address, updated_at = :updated_at WHERE id = :id');
      $stmt->execute([
        ':vote_type' => $voteType,
        ':ip_address' => $ipAddress,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => (int) $row['id'],
      ]);
      return;
    }

    $stmt = $this->pdo->prepare(
      'INSERT INTO votes (report_id, user_id, vote_type, ip_address, created_at, updated_at)
      VALUES (:report_id, :user_id, :vote_type, :ip_address, :created_at, :updated_at)'
    );

    $now = date('Y-m-d H:i:s');
    $stmt->execute([
      ':report_id' => $reportId,
      ':user_id' => $userId,
      ':vote_type' => $voteType,
      ':ip_address' => $ipAddress,
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);
  }

  public function updateStatus(int $reportId, string $status): void
  {
    $stmt = $this->pdo->prepare('UPDATE reports SET status = :status, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
      ':status' => $status,
      ':updated_at' => date('Y-m-d H:i:s'),
      ':id' => $reportId,
    ]);
  }

  public function deleteById(int $reportId): void
  {
    $stmt = $this->pdo->prepare('DELETE FROM reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
  }

  public function deleteByIdAndUser(int $reportId, int $userId): bool
  {
    $stmt = $this->pdo->prepare('DELETE FROM reports WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
      ':id' => $reportId,
      ':user_id' => $userId,
    ]);

    return $stmt->rowCount() > 0;
  }

  public function updateMediaAndAnalysisByIdAndUser(int $reportId, int $userId, array $data): bool
  {
    $stmt = $this->pdo->prepare(
      'UPDATE reports
       SET media_path = :media_path,
           media_paths = :media_paths,
           media_type = :media_type,
           category_ai = :category_ai,
           urgency_ai = :urgency_ai,
           confidence_ai = :confidence_ai,
           ai_summary = :ai_summary,
           status = :status,
           updated_at = :updated_at
       WHERE id = :id AND user_id = :user_id'
    );

    $stmt->execute([
      ':media_path' => $data['media_path'] ?? null,
      ':media_paths' => $data['media_paths'] ?? null,
      ':media_type' => $data['media_type'] ?? null,
      ':category_ai' => (string) ($data['category_ai'] ?? 'lainnya'),
      ':urgency_ai' => (string) ($data['urgency_ai'] ?? 'normal'),
      ':confidence_ai' => (float) ($data['confidence_ai'] ?? 0.5),
      ':ai_summary' => (string) ($data['ai_summary'] ?? ''),
      ':status' => (string) ($data['status'] ?? 'open'),
      ':updated_at' => (string) ($data['updated_at'] ?? date('Y-m-d H:i:s')),
      ':id' => $reportId,
      ':user_id' => $userId,
    ]);

    return $stmt->rowCount() > 0;
  }

  public function listForAdmin(
    string $status = 'all',
    string $q = '',
    int $page = 1,
    int $perPage = 25,
    string $sortBy = 'created_at',
    string $sortDir = 'DESC'
  ): array {
    $conditions = [];
    $params     = [];

    if ($status !== 'all') {
      $conditions[] = 'r.status = :status';
      $params[':status'] = $status;
    }

    if ($q !== '') {
      $conditions[] = '(r.title LIKE :q OR r.description LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }

    $whereSql = count($conditions) > 0
      ? 'WHERE ' . implode(' AND ', $conditions)
      : '';

    // Whitelist kolom dan arah sort untuk keamanan
    $allowedSort = ['id', 'created_at', 'urgency_ai', 'status', 'confirms'];
    $sortBy  = in_array($sortBy, $allowedSort, true) ? $sortBy : 'created_at';
    $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

    // Hitung total baris (tanpa LIMIT)
    $countSql = "
      SELECT COUNT(DISTINCT r.id) AS total
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      {$whereSql}
    ";
    $countStmt = $this->pdo->prepare($countSql);
    foreach ($params as $key => $value) {
      $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    $offset  = ($page - 1) * $perPage;

    $sql = "
      SELECT
        r.*,
        u.name AS reporter_name,
        u.email AS reporter_email,
        COALESCE(SUM(CASE WHEN v.vote_type = 'confirm' THEN 1 ELSE 0 END), 0) AS confirms,
        COALESCE(SUM(CASE WHEN v.vote_type = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      LEFT JOIN votes v ON v.report_id = r.id
      {$whereSql}
      GROUP BY r.id
      ORDER BY {$sortBy} {$sortDir}
      LIMIT :limit OFFSET :offset
    ";

    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'data'        => $stmt->fetchAll(),
      'total'       => $total,
      'page'        => $page,
      'per_page'    => $perPage,
      'total_pages' => (int) ceil($total / $perPage),
    ];
  }

  public function statsSummary(): array
  {
    $total = $this->scalarInt('SELECT COUNT(*) AS total FROM reports');
    $open = $this->scalarInt("SELECT COUNT(*) AS total FROM reports WHERE status = 'open'");
    $inProgress = $this->scalarInt("SELECT COUNT(*) AS total FROM reports WHERE status = 'in_progress'");
    $resolved = $this->scalarInt("SELECT COUNT(*) AS total FROM reports WHERE status = 'resolved'");
    $critical = $this->scalarInt("SELECT COUNT(*) AS total FROM reports WHERE urgency_ai = 'critical'");

    return [
      'total_reports' => $total,
      'open_reports' => $open,
      'in_progress_reports' => $inProgress,
      'resolved_reports' => $resolved,
      'critical_reports' => $critical,
    ];
  }

  public function statsSummaryForGovernment(array $region): array
  {
    $filters = [];
    $params = [];
    $this->appendRegionConditions($filters, $params, $region, 'r');
    $filters[] = "r.status NOT IN ('rejected_pending_media', 'hidden')";
    $where = count($filters) > 0 ? 'WHERE ' . implode(' AND ', $filters) : '';

    $total = $this->scalarIntWithParams("SELECT COUNT(*) AS total FROM reports r {$where}", $params);
    $open = $this->scalarIntWithParams("SELECT COUNT(*) AS total FROM reports r {$where} " . (count($filters) > 0 ? 'AND' : 'WHERE') . " r.status = 'open'", $params);
    $inProgress = $this->scalarIntWithParams("SELECT COUNT(*) AS total FROM reports r {$where} " . (count($filters) > 0 ? 'AND' : 'WHERE') . " r.status = 'in_progress'", $params);
    $resolved = $this->scalarIntWithParams("SELECT COUNT(*) AS total FROM reports r {$where} " . (count($filters) > 0 ? 'AND' : 'WHERE') . " r.status = 'resolved'", $params);

    return [
      'total_reports' => $total,
      'open_reports' => $open,
      'in_progress_reports' => $inProgress,
      'resolved_reports' => $resolved,
    ];
  }

  public function listForGovernment(
    array $region,
    string $status = 'all',
    string $q = '',
    int $page = 1,
    int $perPage = 25
  ): array {
    $conditions = [];
    $params = [];

    $this->appendRegionConditions($conditions, $params, $region, 'r');
    $conditions[] = "r.status NOT IN ('rejected_pending_media', 'hidden')";

    if ($status !== 'all') {
      $conditions[] = 'r.status = :status';
      $params[':status'] = $status;
    }

    if ($q !== '') {
      $conditions[] = '(r.title LIKE :q OR r.description LIKE :q OR r.province LIKE :q OR r.city LIKE :q OR r.district LIKE :q OR r.subdistrict LIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }

    $whereSql = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countSql = "
      SELECT COUNT(DISTINCT r.id) AS total
      FROM reports r
      {$whereSql}
    ";
    $countStmt = $this->pdo->prepare($countSql);
    foreach ($params as $k => $v) {
      $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;

    $sql = "
      SELECT
        r.*,
        u.name AS reporter_name,
        COALESCE(SUM(CASE WHEN v.vote_type = 'confirm' THEN 1 ELSE 0 END), 0) AS confirms,
        COALESCE(SUM(CASE WHEN v.vote_type = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      LEFT JOIN votes v ON v.report_id = r.id
      {$whereSql}
      GROUP BY r.id
      ORDER BY r.created_at DESC
      LIMIT :limit OFFSET :offset
    ";

    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'data' => $stmt->fetchAll(),
      'total' => $total,
      'page' => $page,
      'per_page' => $perPage,
      'total_pages' => (int) ceil($total / $perPage),
    ];
  }

  public function analyticsForGovernment(array $region, int $days = 14): array
  {
    $days = max(7, min(30, $days));

    $conditions = [];
    $params = [];
    $this->appendRegionConditions($conditions, $params, $region, 'r');
    $conditions[] = "r.status NOT IN ('rejected_pending_media', 'hidden')";

    $whereSql = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $statusSql = "
      SELECT r.status, COUNT(*) AS total
      FROM reports r
      {$whereSql}
      GROUP BY r.status
    ";
    $statusStmt = $this->pdo->prepare($statusSql);
    $statusStmt->execute($params);
    $statusRows = $statusStmt->fetchAll();

    $statusCounts = [
      'open' => 0,
      'in_progress' => 0,
      'resolved' => 0,
    ];
    foreach ($statusRows as $row) {
      $key = (string) ($row['status'] ?? '');
      if (array_key_exists($key, $statusCounts)) {
        $statusCounts[$key] = (int) ($row['total'] ?? 0);
      }
    }

    $categorySql = "
      SELECT COALESCE(NULLIF(TRIM(r.category_ai), ''), 'Lainnya') AS category_name, COUNT(*) AS total
      FROM reports r
      {$whereSql}
      GROUP BY category_name
      ORDER BY total DESC
      LIMIT 6
    ";
    $categoryStmt = $this->pdo->prepare($categorySql);
    $categoryStmt->execute($params);
    $categoryRows = $categoryStmt->fetchAll();

    $categoryCounts = array_map(static function (array $row): array {
      return [
        'name' => (string) ($row['category_name'] ?? 'Lainnya'),
        'total' => (int) ($row['total'] ?? 0),
      ];
    }, $categoryRows);

    $cutoff = date('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days 00:00:00'));
    $trendConditions = $conditions;
    $trendConditions[] = 'r.created_at >= :trend_cutoff';
    $trendParams = $params;
    $trendParams[':trend_cutoff'] = $cutoff;
    $trendWhere = 'WHERE ' . implode(' AND ', $trendConditions);

    $trendSql = "
      SELECT
        DATE(r.created_at) AS report_date,
        COUNT(*) AS total,
        SUM(CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END) AS resolved
      FROM reports r
      {$trendWhere}
      GROUP BY DATE(r.created_at)
      ORDER BY DATE(r.created_at) ASC
    ";

    $trendStmt = $this->pdo->prepare($trendSql);
    $trendStmt->execute($trendParams);
    $trendRows = $trendStmt->fetchAll();

    $trendByDate = [];
    foreach ($trendRows as $row) {
      $dateKey = (string) ($row['report_date'] ?? '');
      if ($dateKey === '') {
        continue;
      }
      $trendByDate[$dateKey] = [
        'total' => (int) ($row['total'] ?? 0),
        'resolved' => (int) ($row['resolved'] ?? 0),
      ];
    }

    $labels = [];
    $trendTotal = [];
    $trendResolved = [];
    for ($i = $days - 1; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime('-' . $i . ' days'));
      $labels[] = date('d M', strtotime($date));
      $trendTotal[] = (int) (($trendByDate[$date]['total'] ?? 0));
      $trendResolved[] = (int) (($trendByDate[$date]['resolved'] ?? 0));
    }

    return [
      'status_counts' => $statusCounts,
      'category_counts' => $categoryCounts,
      'trend' => [
        'labels' => $labels,
        'total_reports' => $trendTotal,
        'resolved_reports' => $trendResolved,
      ],
      'window_days' => $days,
    ];
  }

  public function heatmapPoints(): array
  {
    $sql = "
      SELECT
        ROUND(latitude, 3) AS latitude,
        ROUND(longitude, 3) AS longitude,
        COUNT(*) AS total
      FROM reports
      WHERE status NOT IN ('rejected_pending_media', 'hidden')
      GROUP BY ROUND(latitude, 3), ROUND(longitude, 3)
      ORDER BY total DESC
    ";

    $stmt = $this->pdo->query($sql);
    return $stmt->fetchAll();
  }

  public function recentByCategoryAndRange(string $category, float $latitude, float $longitude, int $hours = 48): array
  {
    $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));

    $sql = "
      SELECT *
      FROM reports
      WHERE category_ai = :category
        AND status NOT IN ('rejected_pending_media', 'hidden')
        AND created_at >= :cutoff
        AND latitude BETWEEN :lat_min AND :lat_max
        AND longitude BETWEEN :lng_min AND :lng_max
    ";

    $latDelta = 0.15;
    $lngDelta = 0.15;

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      ':category' => $category,
      ':cutoff' => $cutoff,
      ':lat_min' => $latitude - $latDelta,
      ':lat_max' => $latitude + $latDelta,
      ':lng_min' => $longitude - $lngDelta,
      ':lng_max' => $longitude + $lngDelta,
    ]);

    return $stmt->fetchAll();
  }

  private function scalarInt(string $sql): int
  {
    $stmt = $this->pdo->query($sql);
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
  }

  private function scalarIntWithParams(string $sql, array $params): int
  {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
  }

  private function appendRegionConditions(array &$conditions, array &$params, array $region, string $alias): void
  {
    $province = $this->normalizeRegionValue((string) ($region['province'] ?? ''));
    if ($province !== '') {
      $conditions[] = "REPLACE(REPLACE(REPLACE(LOWER(TRIM({$alias}.province)), ' ', ''), '-', ''), '.', '') = :region_province";
      $params[':region_province'] = $province;
    }

    $city = $this->normalizeRegionValue((string) ($region['city'] ?? ''));
    if ($city !== '') {
      $conditions[] = "REPLACE(REPLACE(REPLACE(LOWER(TRIM({$alias}.city)), ' ', ''), '-', ''), '.', '') = :region_city";
      $params[':region_city'] = $city;
    }

    $district = $this->normalizeRegionValue((string) ($region['district'] ?? ''));
    if ($district !== '') {
      $conditions[] = "REPLACE(REPLACE(REPLACE(LOWER(TRIM({$alias}.district)), ' ', ''), '-', ''), '.', '') = :region_district";
      $params[':region_district'] = $district;
    }

    $subdistrict = $this->normalizeRegionValue((string) ($region['subdistrict'] ?? ''));
    if ($subdistrict !== '') {
      $conditions[] = "REPLACE(REPLACE(REPLACE(LOWER(TRIM({$alias}.subdistrict)), ' ', ''), '-', ''), '.', '') = :region_subdistrict";
      $params[':region_subdistrict'] = $subdistrict;
    }
  }

  private function normalizeRegionValue(string $value): string
  {
    $value = strtolower(trim($value));
    if ($value === '') {
      return '';
    }

    $value = str_replace(['sulwesi'], ['sulawesi'], $value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

    return trim($value);
  }

  private function ensureSchema(): void
  {
    $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
      $this->ensureSqliteColumn('province', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('city', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('district', 'TEXT DEFAULT NULL');
      $this->ensureSqliteColumn('subdistrict', 'TEXT DEFAULT NULL');
      return;
    }

    $this->ensureMysqlColumn('province', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('city', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('district', 'VARCHAR(120) NULL');
    $this->ensureMysqlColumn('subdistrict', 'VARCHAR(120) NULL');
  }

  private function ensureSqliteColumn(string $name, string $definition): void
  {
    $stmt = $this->pdo->query('PRAGMA table_info(reports)');
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
      if (($row['name'] ?? '') === $name) {
        return;
      }
    }

    $this->pdo->exec("ALTER TABLE reports ADD COLUMN {$name} {$definition}");
  }

  private function ensureMysqlColumn(string $name, string $definition): void
  {
    $stmt = $this->pdo->prepare('SHOW COLUMNS FROM reports LIKE :name');
    $stmt->execute([':name' => $name]);
    if ($stmt->fetch() !== false) {
      return;
    }

    $this->pdo->exec("ALTER TABLE reports ADD COLUMN {$name} {$definition}");
  }
}
