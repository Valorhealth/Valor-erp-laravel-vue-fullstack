-- Performance indexes used by Laravel migration 2026_10_09_000001.
-- This file is kept for environments initialized from the standalone SQL schema.

CREATE INDEX IF NOT EXISTS sales_call_activities_date_product_idx
    ON sales_call_activities (activity_date DESC, product_1)
    WHERE specialty IS NOT NULL AND BTRIM(specialty) <> '';

CREATE INDEX IF NOT EXISTS sales_call_activities_date_outcome_idx
    ON sales_call_activities (activity_date DESC, outcome)
    WHERE specialty IS NOT NULL AND BTRIM(specialty) <> '';

CREATE INDEX IF NOT EXISTS sales_call_activities_status_date_idx
    ON sales_call_activities (status, activity_date DESC);

CREATE INDEX IF NOT EXISTS users_department_idx
    ON users (department)
    WHERE department IS NOT NULL AND BTRIM(department) <> '';

CREATE INDEX IF NOT EXISTS billing_records_status_date_idx
    ON billing_records (status, bill_date DESC);

CREATE INDEX IF NOT EXISTS quotations_status_date_idx
    ON quotations (status, qt_date DESC);

CREATE INDEX IF NOT EXISTS credit_requests_status_created_idx
    ON credit_requests (status, created_at DESC);
