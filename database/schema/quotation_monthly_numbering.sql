-- Run once in Supabase SQL Editor before deploying the monthly quotation numbering code.
-- The first quotation in each YYMM period starts at 0001 unless a higher floor is configured.

BEGIN;

CREATE TABLE IF NOT EXISTS quotation_number_counters (
    period VARCHAR(4) PRIMARY KEY,
    last_number INTEGER NOT NULL DEFAULT 0 CHECK (last_number >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT quotation_number_counters_period_format CHECK (period ~ '^[0-9]{4}$')
);

WITH parsed_numbers AS (
    SELECT
        SUBSTRING(qt_no FROM '^QT-([0-9]{4})-[0-9]+$') AS period,
        CAST(SUBSTRING(qt_no FROM '^QT-[0-9]{4}-([0-9]+)$') AS INTEGER) AS issued_number
    FROM quotations
    WHERE qt_no ~ '^QT-[0-9]{4}-[0-9]+$'
), latest_by_period AS (
    SELECT period, MAX(issued_number) AS last_number
    FROM parsed_numbers
    WHERE period IS NOT NULL
    GROUP BY period
)
INSERT INTO quotation_number_counters (period, last_number, updated_at)
SELECT period, last_number, CURRENT_TIMESTAMP
FROM latest_by_period
ON CONFLICT (period) DO UPDATE
SET last_number = GREATEST(quotation_number_counters.last_number, EXCLUDED.last_number),
    updated_at = CURRENT_TIMESTAMP;

CREATE UNIQUE INDEX IF NOT EXISTS quotations_qt_no_unique
    ON quotations (qt_no)
    WHERE qt_no IS NOT NULL
      AND BTRIM(qt_no) <> '';

COMMIT;

-- Example for later: if another system last issued 0150 in August 2026,
-- set the floor to 150 and the next web quotation will be QT-2608-0151.
--
-- INSERT INTO quotation_number_counters (period, last_number, updated_at)
-- VALUES ('2608', 150, CURRENT_TIMESTAMP)
-- ON CONFLICT (period) DO UPDATE
-- SET last_number = GREATEST(quotation_number_counters.last_number, EXCLUDED.last_number),
--     updated_at = CURRENT_TIMESTAMP;
