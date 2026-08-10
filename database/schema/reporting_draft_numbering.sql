-- Valor ERP: reporting, draft workflow and safe tax-invoice numbering.
-- Idempotent: this file can be run again without recreating data.

BEGIN;

ALTER TABLE billing_records
    ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMPTZ;

ALTER TABLE billing_records
    ADD COLUMN IF NOT EXISTS issued_at TIMESTAMPTZ;

UPDATE billing_records
SET submitted_at = COALESCE(created_at, CURRENT_TIMESTAMP)
WHERE submitted_at IS NULL
  AND COALESCE(status, '') <> 'ฉบับร่าง';

UPDATE billing_records
SET issued_at = COALESCE(document_printed_at, created_at, CURRENT_TIMESTAMP)
WHERE issued_at IS NULL
  AND NULLIF(BTRIM(tax_invoice_no), '') IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS billing_records_tax_invoice_no_unique
    ON billing_records (tax_invoice_no)
    WHERE tax_invoice_no IS NOT NULL
      AND BTRIM(tax_invoice_no) <> '';

CREATE INDEX IF NOT EXISTS billing_records_report_date_status_idx
    ON billing_records (bill_date, status);

CREATE INDEX IF NOT EXISTS billing_records_created_by_bill_date_idx
    ON billing_records (created_by, bill_date);

CREATE INDEX IF NOT EXISTS bill_items_bill_id_idx
    ON bill_items (bill_id);

COMMIT;
