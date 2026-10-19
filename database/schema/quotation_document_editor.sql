BEGIN;

CREATE TABLE IF NOT EXISTS quotation_document_drafts (
    draft_id BIGSERIAL PRIMARY KEY,
    qt_id INT NOT NULL REFERENCES quotations(qt_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    template_id VARCHAR(64) NOT NULL,
    document_mode VARCHAR(20) NOT NULL DEFAULT 'original'
        CHECK (document_mode IN ('original', 'copy', 'revised')),
    payload JSONB NOT NULL CHECK (JSONB_TYPEOF(payload) = 'object'),
    lock_version INT NOT NULL DEFAULT 1 CHECK (lock_version >= 1),
    updated_by_user_id INT REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    updated_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (qt_id, template_id, document_mode)
);

CREATE INDEX IF NOT EXISTS quotation_document_drafts_updated_idx
    ON quotation_document_drafts (qt_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS quotation_document_versions (
    document_version_id BIGSERIAL PRIMARY KEY,
    qt_id INT NOT NULL REFERENCES quotations(qt_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    bill_id INT REFERENCES billing_records(bill_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    template_id VARCHAR(64) NOT NULL,
    document_mode VARCHAR(20) NOT NULL DEFAULT 'original'
        CHECK (document_mode IN ('original', 'copy', 'revised')),
    revision_no INT NOT NULL CHECK (revision_no >= 1),
    payload JSONB NOT NULL CHECK (JSONB_TYPEOF(payload) = 'object'),
    pdf_base64 TEXT NOT NULL,
    pdf_sha256 VARCHAR(64) NOT NULL,
    filename VARCHAR(500) NOT NULL,
    confirmed_by_user_id INT REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    confirmed_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (qt_id, template_id, document_mode, revision_no)
);

CREATE INDEX IF NOT EXISTS quotation_document_versions_created_idx
    ON quotation_document_versions (qt_id, created_at DESC);

ALTER TABLE billing_records
    ADD COLUMN IF NOT EXISTS quotation_document_version_id BIGINT;

DO $$ BEGIN
    ALTER TABLE billing_records
        ADD CONSTRAINT billing_records_quotation_document_version_fk
        FOREIGN KEY (quotation_document_version_id)
        REFERENCES quotation_document_versions(document_version_id)
        ON UPDATE CASCADE ON DELETE RESTRICT;
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE INDEX IF NOT EXISTS billing_records_quotation_document_version_idx
    ON billing_records (quotation_document_version_id);

UPDATE billing_records AS bill
SET quotation_document_version_id = latest.document_version_id
FROM (
    SELECT DISTINCT ON (bill_id) bill_id, document_version_id
    FROM quotation_document_versions
    WHERE bill_id IS NOT NULL AND document_mode = 'original'
    ORDER BY bill_id, created_at DESC, document_version_id DESC
) AS latest
WHERE bill.bill_id = latest.bill_id
  AND bill.quotation_document_version_id IS NULL;

COMMIT;
