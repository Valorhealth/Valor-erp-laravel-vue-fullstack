CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGSERIAL PRIMARY KEY,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id BIGINT NULL,
    username VARCHAR(255) NULL,
    role VARCHAR(255) NULL,
    department VARCHAR(255) NULL,
    action VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    http_method VARCHAR(10) NOT NULL,
    route_uri TEXT NULL,
    target_type VARCHAR(100) NULL,
    target_id VARCHAR(255) NULL,
    request_data JSONB NULL,
    response_status INTEGER NULL,
    was_successful BOOLEAN NOT NULL DEFAULT TRUE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    duration_ms NUMERIC(12, 2) NULL
);

CREATE INDEX IF NOT EXISTS audit_logs_occurred_at_idx
    ON audit_logs (occurred_at DESC);
CREATE INDEX IF NOT EXISTS audit_logs_username_occurred_at_idx
    ON audit_logs (username, occurred_at DESC);
CREATE INDEX IF NOT EXISTS audit_logs_category_action_idx
    ON audit_logs (category, action);
CREATE INDEX IF NOT EXISTS audit_logs_target_idx
    ON audit_logs (target_type, target_id);
CREATE INDEX IF NOT EXISTS audit_logs_success_occurred_at_idx
    ON audit_logs (was_successful, occurred_at DESC);

