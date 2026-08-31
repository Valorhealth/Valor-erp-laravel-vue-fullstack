BEGIN;

-- Stable ownership: usernames are editable labels, while user IDs never change.
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS creator_user_id INTEGER;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS creator_user_id INTEGER;

UPDATE billing_records AS bill
SET creator_user_id = account.id
FROM users AS account
WHERE bill.creator_user_id IS NULL
  AND bill.created_by = account.username;

UPDATE quotations AS quotation
SET creator_user_id = account.id
FROM users AS account
WHERE quotation.creator_user_id IS NULL
  AND quotation.created_by = account.username;

-- Recover records created before a username rename from immutable Audit Log user IDs.
WITH historical_accounts AS (
    SELECT username, MIN(user_id)::INTEGER AS user_id
    FROM audit_logs
    WHERE user_id IS NOT NULL AND username IS NOT NULL
    GROUP BY username
    HAVING COUNT(DISTINCT user_id) = 1
)
UPDATE billing_records AS bill
SET creator_user_id = history.user_id
FROM historical_accounts AS history
WHERE bill.creator_user_id IS NULL
  AND bill.created_by = history.username;

WITH historical_accounts AS (
    SELECT username, MIN(user_id)::INTEGER AS user_id
    FROM audit_logs
    WHERE user_id IS NOT NULL AND username IS NOT NULL
    GROUP BY username
    HAVING COUNT(DISTINCT user_id) = 1
)
UPDATE quotations AS quotation
SET creator_user_id = history.user_id
FROM historical_accounts AS history
WHERE quotation.creator_user_id IS NULL
  AND quotation.created_by = history.username;

UPDATE billing_records AS bill
SET created_by = account.username
FROM users AS account
WHERE bill.creator_user_id = account.id
  AND bill.created_by IS DISTINCT FROM account.username;

UPDATE quotations AS quotation
SET created_by = account.username
FROM users AS account
WHERE quotation.creator_user_id = account.id
  AND quotation.created_by IS DISTINCT FROM account.username;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'billing_records_creator_user_id_fk') THEN
        ALTER TABLE billing_records
            ADD CONSTRAINT billing_records_creator_user_id_fk
            FOREIGN KEY (creator_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'quotations_creator_user_id_fk') THEN
        ALTER TABLE quotations
            ADD CONSTRAINT quotations_creator_user_id_fk
            FOREIGN KEY (creator_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS billing_records_creator_user_id_bill_date_idx
    ON billing_records (creator_user_id, bill_date);
CREATE INDEX IF NOT EXISTS quotations_creator_user_id_qt_date_idx
    ON quotations (creator_user_id, qt_date);

-- Stable task ownership/assignment: keep usernames as display snapshots only.
ALTER TABLE sales_tasks ADD COLUMN IF NOT EXISTS assigned_to_user_id INTEGER;
ALTER TABLE sales_tasks ADD COLUMN IF NOT EXISTS assigned_by_user_id INTEGER;

UPDATE sales_tasks AS task
SET assigned_to_user_id = account.id
FROM users AS account
WHERE task.assigned_to_user_id IS NULL
  AND LOWER(BTRIM(task.assigned_to)) = LOWER(BTRIM(account.username));

UPDATE sales_tasks AS task
SET assigned_by_user_id = account.id
FROM users AS account
WHERE task.assigned_by_user_id IS NULL
  AND LOWER(BTRIM(task.assigned_by)) = LOWER(BTRIM(account.username));

WITH historical_accounts AS (
    SELECT LOWER(BTRIM(username)) AS username, MIN(user_id)::INTEGER AS user_id
    FROM audit_logs
    WHERE user_id IS NOT NULL AND username IS NOT NULL
    GROUP BY LOWER(BTRIM(username))
    HAVING COUNT(DISTINCT user_id) = 1
)
UPDATE sales_tasks AS task
SET assigned_to_user_id = history.user_id
FROM historical_accounts AS history
WHERE task.assigned_to_user_id IS NULL
  AND LOWER(BTRIM(task.assigned_to)) = history.username;

WITH historical_accounts AS (
    SELECT LOWER(BTRIM(username)) AS username, MIN(user_id)::INTEGER AS user_id
    FROM audit_logs
    WHERE user_id IS NOT NULL AND username IS NOT NULL
    GROUP BY LOWER(BTRIM(username))
    HAVING COUNT(DISTINCT user_id) = 1
)
UPDATE sales_tasks AS task
SET assigned_by_user_id = history.user_id
FROM historical_accounts AS history
WHERE task.assigned_by_user_id IS NULL
  AND LOWER(BTRIM(task.assigned_by)) = history.username;

UPDATE sales_tasks AS task
SET assigned_to = account.username
FROM users AS account
WHERE task.assigned_to_user_id = account.id
  AND task.assigned_to IS DISTINCT FROM account.username;

UPDATE sales_tasks AS task
SET assigned_by = account.username
FROM users AS account
WHERE task.assigned_by_user_id = account.id
  AND task.assigned_by IS DISTINCT FROM account.username;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sales_tasks_assigned_to_user_id_fk') THEN
        ALTER TABLE sales_tasks
            ADD CONSTRAINT sales_tasks_assigned_to_user_id_fk
            FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sales_tasks_assigned_by_user_id_fk') THEN
        ALTER TABLE sales_tasks
            ADD CONSTRAINT sales_tasks_assigned_by_user_id_fk
            FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS sales_tasks_assigned_to_user_id_start_date_idx
    ON sales_tasks (assigned_to_user_id, start_date);
CREATE INDEX IF NOT EXISTS sales_tasks_assigned_by_user_id_idx
    ON sales_tasks (assigned_by_user_id);

-- Keep the task timeline in the task row to reduce table count.
ALTER TABLE sales_tasks
    ADD COLUMN IF NOT EXISTS progress_updates JSONB NOT NULL DEFAULT '[]'::jsonb;

DO $$
BEGIN
    IF to_regclass('public.sales_task_updates') IS NOT NULL THEN
        UPDATE sales_tasks AS task
        SET progress_updates = history.entries
        FROM (
            SELECT task_id,
                   jsonb_agg(jsonb_build_object(
                       'update_id', update_id::text,
                       'status', status,
                       'progress_note', progress_note,
                       'updated_by', updated_by,
                       'created_at', created_at
                   ) ORDER BY created_at, update_id) AS entries
            FROM sales_task_updates
            GROUP BY task_id
        ) AS history
        WHERE task.task_id = history.task_id
          AND task.progress_updates = '[]'::jsonb;

        DROP TABLE sales_task_updates;
    END IF;
END $$;

-- Removed workflow state: Admin exports directly from the approved queue.
UPDATE billing_records
SET status = 'รอแอดมินส่งออก'
WHERE status IN ('รอแอดมินตรวจสอบ', 'รอแอดมินอนุมัติ', 'เตรียมส่งออก');

COMMIT;
