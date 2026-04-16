CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    account_type TEXT NOT NULL DEFAULT 'citizen',
    account_status TEXT NOT NULL DEFAULT 'active',
    agency_name TEXT DEFAULT NULL,
    agency_type TEXT DEFAULT NULL,
    agency_sector TEXT DEFAULT NULL,
    region_province TEXT DEFAULT NULL,
    region_city TEXT DEFAULT NULL,
    region_district TEXT DEFAULT NULL,
    region_subdistrict TEXT DEFAULT NULL,
    officer_name TEXT DEFAULT NULL,
    officer_position TEXT DEFAULT NULL,
    officer_nip TEXT DEFAULT NULL,
    officer_phone TEXT DEFAULT NULL,
    official_email_domain_valid INTEGER NOT NULL DEFAULT 0,
    government_document_path TEXT DEFAULT NULL,
    declaration_data_true INTEGER NOT NULL DEFAULT 0,
    declaration_followup INTEGER NOT NULL DEFAULT 0,
    reviewed_by INTEGER DEFAULT NULL,
    reviewed_at TEXT DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    suspended_until TEXT DEFAULT NULL,
    suspension_reason TEXT DEFAULT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    category_user TEXT NOT NULL,
    category_ai TEXT NOT NULL,
    urgency_ai TEXT NOT NULL,
    confidence_ai REAL NOT NULL,
    ai_summary TEXT NOT NULL,
    media_path TEXT DEFAULT NULL,
    media_paths TEXT DEFAULT NULL,
    media_type TEXT DEFAULT NULL,
    province TEXT DEFAULT NULL,
    city TEXT DEFAULT NULL,
    district TEXT DEFAULT NULL,
    subdistrict TEXT DEFAULT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL,
    user_id INTEGER DEFAULT NULL,
    vote_type TEXT NOT NULL CHECK (
        vote_type IN ('confirm', 'reject')
    ),
    ip_address TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT DEFAULT NULL,
    FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    UNIQUE (report_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_reports_created_at ON reports (created_at);

CREATE INDEX IF NOT EXISTS idx_reports_category_ai ON reports (category_ai);

CREATE INDEX IF NOT EXISTS idx_reports_user_id ON reports (user_id);

CREATE INDEX IF NOT EXISTS idx_votes_report_id ON votes (report_id);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_comments_report_id ON comments (report_id);

CREATE TABLE IF NOT EXISTS notifications (
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
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications (user_id, created_at);

CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications (user_id, is_read);