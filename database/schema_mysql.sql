CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    account_type VARCHAR(20) NOT NULL DEFAULT 'citizen',
    account_status VARCHAR(20) NOT NULL DEFAULT 'active',
    agency_name VARCHAR(190) NULL,
    agency_type VARCHAR(80) NULL,
    agency_sector VARCHAR(120) NULL,
    region_province VARCHAR(120) NULL,
    region_city VARCHAR(120) NULL,
    region_district VARCHAR(120) NULL,
    region_subdistrict VARCHAR(120) NULL,
    officer_name VARCHAR(120) NULL,
    officer_position VARCHAR(120) NULL,
    officer_nip VARCHAR(64) NULL,
    officer_phone VARCHAR(32) NULL,
    official_email_domain_valid TINYINT(1) NOT NULL DEFAULT 0,
    government_document_path VARCHAR(255) NULL,
    declaration_data_true TINYINT(1) NOT NULL DEFAULT 0,
    declaration_followup TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    suspended_until DATETIME NULL,
    suspension_reason TEXT NULL,
    created_at DATETIME NOT NULL
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    category_user VARCHAR(60) NOT NULL,
    category_ai VARCHAR(60) NOT NULL,
    urgency_ai VARCHAR(20) NOT NULL,
    confidence_ai DECIMAL(6, 4) NOT NULL,
    ai_summary TEXT NOT NULL,
    media_path VARCHAR(255) NULL,
    media_paths TEXT NULL,
    media_type VARCHAR(80) NULL,
    province VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    subdistrict VARCHAR(120) NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    vote_type VARCHAR(20) NOT NULL,
    ip_address VARCHAR(70) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_votes_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT uq_votes_report_user UNIQUE (report_id, user_id)
) ENGINE = InnoDB;

CREATE INDEX idx_reports_created_at ON reports (created_at);

CREATE INDEX idx_reports_category_ai ON reports (category_ai);

CREATE INDEX idx_votes_report_id ON votes (report_id);

CREATE TABLE IF NOT EXISTS comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_comments_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE INDEX idx_comments_report_id ON comments (report_id);

CREATE TABLE IF NOT EXISTS notifications (
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
) ENGINE = InnoDB;

CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at);

CREATE INDEX idx_notifications_unread ON notifications (user_id, is_read);