-- Draft quotation workflow: keep the system product name and the hospital-facing name separately.
-- Safe to run more than once in PostgreSQL / Supabase.

ALTER TABLE bill_items
    ADD COLUMN IF NOT EXISTS customer_product_name TEXT;
