-- Persist the latest Admin-entered outstanding balance at customer level.
-- Apply this migration once to an existing PostgreSQL / Supabase database.

ALTER TABLE customer_credit_profiles
    ADD COLUMN IF NOT EXISTS credit_outstanding DECIMAL(14,2) DEFAULT 0;

WITH latest_admin_credit AS (
    SELECT DISTINCT ON (bplus_customer_code)
        bplus_customer_code,
        credit_outstanding
    FROM billing_records
    WHERE NULLIF(BTRIM(bplus_customer_code), '') IS NOT NULL
      AND admin_verified_by IS NOT NULL
      AND credit_outstanding IS NOT NULL
    ORDER BY bplus_customer_code, bill_id DESC
)
UPDATE customer_credit_profiles AS profile
SET credit_outstanding = latest.credit_outstanding,
    updated_at = CURRENT_TIMESTAMP
FROM latest_admin_credit AS latest
WHERE profile.ar_code = latest.bplus_customer_code;

-- Refresh only open workflow records; completed historical bills keep their original snapshot.
UPDATE billing_records AS bill
SET credit_limit = profile.credit_limit,
    credit_outstanding = profile.credit_outstanding,
    credit_remain = GREATEST(profile.credit_limit - profile.credit_outstanding, 0),
    credit_term = profile.credit_term
FROM customer_credit_profiles AS profile
WHERE bill.bplus_customer_code = profile.ar_code
  AND bill.status IN ('ฉบับร่าง', 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอแอดมินส่งออก', 'รอแก้ไข');
