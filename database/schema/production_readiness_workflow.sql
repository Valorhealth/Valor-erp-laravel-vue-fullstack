-- Valor ERP: password reset, document cancellation, PO evidence and quotation revisions.
-- Safe to run repeatedly on PostgreSQL/Supabase.

ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS quotation_signing_authorized BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL,
    requested_ip VARCHAR(45),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_expiry ON password_reset_tokens (expires_at);

CREATE TABLE IF NOT EXISTS user_signature_assets (
    signature_asset_id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    version_no INT NOT NULL CHECK (version_no >= 1),
    file_name VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'image/png',
    image_base64 TEXT NOT NULL,
    image_sha256 VARCHAR(64) NOT NULL,
    is_current BOOLEAN NOT NULL DEFAULT TRUE,
    uploaded_by VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    superseded_at TIMESTAMPTZ,
    superseded_by VARCHAR(255),
    UNIQUE (user_id, version_no)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_user_signature_assets_current
    ON user_signature_assets (user_id) WHERE is_current;
CREATE INDEX IF NOT EXISTS idx_user_signature_assets_user
    ON user_signature_assets (user_id, version_no DESC);

ALTER TABLE quotations ADD COLUMN IF NOT EXISTS customer_po_no VARCHAR(255);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS customer_confirmed_at DATE;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS customer_contact_name VARCHAR(500);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS customer_contact_channel VARCHAR(255);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS quotation_valid_until DATE;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS payment_terms TEXT;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS delivery_due_date DATE;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS delivery_terms TEXT;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS revision_no INT NOT NULL DEFAULT 1;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS canceled_at TIMESTAMPTZ;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS canceled_by VARCHAR(255);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS cancellation_reason TEXT;
ALTER TABLE quotation_items ADD COLUMN IF NOT EXISTS customer_product_name TEXT;

ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_po_no VARCHAR(255);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_confirmed_at DATE;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_contact_name VARCHAR(500);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_contact_channel VARCHAR(255);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS quotation_valid_until DATE;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS payment_terms TEXT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS delivery_due_date DATE;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS delivery_terms TEXT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS revision_no INT NOT NULL DEFAULT 1;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS canceled_at TIMESTAMPTZ;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS canceled_by VARCHAR(255);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS cancellation_reason TEXT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_acceptance_token VARCHAR(128);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_acceptance_expires_at TIMESTAMPTZ;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS customer_acceptance_used_at TIMESTAMPTZ;
CREATE UNIQUE INDEX IF NOT EXISTS uq_billing_records_customer_acceptance_token
    ON billing_records (customer_acceptance_token) WHERE customer_acceptance_token IS NOT NULL;

CREATE TABLE IF NOT EXISTS billing_documents (
    doc_id BIGSERIAL PRIMARY KEY,
    bill_id INT NOT NULL REFERENCES billing_records(bill_id) ON DELETE RESTRICT,
    document_type VARCHAR(50) NOT NULL DEFAULT 'customer_po',
    file_name VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(255),
    file_bytes BYTEA NOT NULL,
    uploaded_by VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (bill_id, document_type)
);
CREATE INDEX IF NOT EXISTS idx_billing_documents_bill ON billing_documents (bill_id);

-- Evidence files are append-only. Replacing a PO creates a new version so the
-- original bytes remain available for audit and dispute resolution.
ALTER TABLE billing_documents ADD COLUMN IF NOT EXISTS version_no INT NOT NULL DEFAULT 1;
ALTER TABLE billing_documents ADD COLUMN IF NOT EXISTS sha256 VARCHAR(64);
ALTER TABLE billing_documents ADD COLUMN IF NOT EXISTS is_current BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE billing_documents ADD COLUMN IF NOT EXISTS superseded_at TIMESTAMPTZ;
ALTER TABLE billing_documents ADD COLUMN IF NOT EXISTS superseded_by VARCHAR(255);
ALTER TABLE billing_documents DROP CONSTRAINT IF EXISTS billing_documents_bill_id_document_type_key;
CREATE UNIQUE INDEX IF NOT EXISTS uq_billing_documents_version
    ON billing_documents (bill_id, document_type, version_no);
CREATE UNIQUE INDEX IF NOT EXISTS uq_billing_documents_current
    ON billing_documents (bill_id, document_type) WHERE is_current;

CREATE TABLE IF NOT EXISTS quotation_revisions (
    revision_id BIGSERIAL PRIMARY KEY,
    qt_id INT NOT NULL REFERENCES quotations(qt_id) ON DELETE RESTRICT,
    bill_id INT REFERENCES billing_records(bill_id) ON DELETE RESTRICT,
    revision_no INT NOT NULL CHECK (revision_no >= 1),
    snapshot JSONB NOT NULL,
    changed_by VARCHAR(255) NOT NULL,
    change_note TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (qt_id, revision_no)
);
CREATE INDEX IF NOT EXISTS idx_quotation_revisions_qt ON quotation_revisions (qt_id, revision_no DESC);

CREATE TABLE IF NOT EXISTS document_signatures (
    signature_id BIGSERIAL PRIMARY KEY,
    bill_id INT NOT NULL REFERENCES billing_records(bill_id) ON DELETE RESTRICT,
    qt_id INT REFERENCES quotations(qt_id) ON DELETE RESTRICT,
    revision_no INT NOT NULL CHECK (revision_no >= 1),
    signature_type VARCHAR(80) NOT NULL DEFAULT 'internal_quotation_approval',
    signature_level VARCHAR(80) NOT NULL DEFAULT 'general_electronic_signature',
    signer_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    signer_username VARCHAR(255) NOT NULL,
    signer_name VARCHAR(1000),
    signer_role VARCHAR(100) NOT NULL,
    signer_email VARCHAR(255),
    signature_asset_id BIGINT REFERENCES user_signature_assets(signature_asset_id) ON DELETE RESTRICT,
    signature_image_sha256 VARCHAR(64),
    signature_image_base64 TEXT,
    intent_text TEXT NOT NULL,
    payload_snapshot JSONB NOT NULL,
    payload_sha256 VARCHAR(64) NOT NULL,
    signed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip_address VARCHAR(45),
    user_agent TEXT,
    revoked_at TIMESTAMPTZ,
    revoked_by VARCHAR(255),
    revocation_reason TEXT,
    UNIQUE (bill_id, revision_no, signature_type)
);
ALTER TABLE document_signatures ADD COLUMN IF NOT EXISTS signature_asset_id BIGINT REFERENCES user_signature_assets(signature_asset_id) ON DELETE RESTRICT;
ALTER TABLE document_signatures ADD COLUMN IF NOT EXISTS signature_image_sha256 VARCHAR(64);
ALTER TABLE document_signatures ADD COLUMN IF NOT EXISTS signature_image_base64 TEXT;
CREATE INDEX IF NOT EXISTS idx_document_signatures_bill
    ON document_signatures (bill_id, signed_at DESC);

CREATE TABLE IF NOT EXISTS customer_acceptance_signatures (
    acceptance_id BIGSERIAL PRIMARY KEY,
    bill_id INT NOT NULL REFERENCES billing_records(bill_id) ON DELETE RESTRICT,
    qt_id INT NOT NULL REFERENCES quotations(qt_id) ON DELETE RESTRICT,
    revision_no INT NOT NULL CHECK (revision_no >= 1),
    signer_name VARCHAR(500) NOT NULL,
    signer_position VARCHAR(500) NOT NULL,
    signer_organization VARCHAR(1000) NOT NULL,
    signer_email VARCHAR(255),
    signature_method VARCHAR(40) NOT NULL CHECK (signature_method IN ('drawn', 'uploaded_png')),
    signature_image_base64 TEXT NOT NULL,
    signature_image_sha256 VARCHAR(64) NOT NULL,
    intent_text TEXT NOT NULL,
    payload_snapshot JSONB NOT NULL,
    payload_sha256 VARCHAR(64) NOT NULL,
    accepted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip_address VARCHAR(45),
    user_agent TEXT,
    revoked_at TIMESTAMPTZ,
    revoked_by VARCHAR(255),
    revocation_reason TEXT,
    UNIQUE (bill_id, revision_no)
);
CREATE INDEX IF NOT EXISTS idx_customer_acceptance_bill
    ON customer_acceptance_signatures (bill_id, accepted_at DESC);

CREATE OR REPLACE FUNCTION protect_customer_acceptance_evidence()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'customer acceptance evidence cannot be deleted';
    END IF;
    IF ROW(NEW.bill_id, NEW.qt_id, NEW.revision_no, NEW.signer_name, NEW.signer_position,
           NEW.signer_organization, NEW.signer_email, NEW.signature_method,
           NEW.signature_image_base64, NEW.signature_image_sha256, NEW.intent_text,
           NEW.payload_snapshot, NEW.payload_sha256, NEW.accepted_at, NEW.ip_address, NEW.user_agent)
       IS DISTINCT FROM
       ROW(OLD.bill_id, OLD.qt_id, OLD.revision_no, OLD.signer_name, OLD.signer_position,
           OLD.signer_organization, OLD.signer_email, OLD.signature_method,
           OLD.signature_image_base64, OLD.signature_image_sha256, OLD.intent_text,
           OLD.payload_snapshot, OLD.payload_sha256, OLD.accepted_at, OLD.ip_address, OLD.user_agent) THEN
        RAISE EXCEPTION 'customer acceptance evidence is immutable; only revocation fields may change';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_protect_customer_acceptance_evidence ON customer_acceptance_signatures;
CREATE TRIGGER trg_protect_customer_acceptance_evidence
BEFORE UPDATE OR DELETE ON customer_acceptance_signatures
FOR EACH ROW EXECUTE FUNCTION protect_customer_acceptance_evidence();

CREATE OR REPLACE FUNCTION protect_document_signature_evidence()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'document signature evidence cannot be deleted';
    END IF;
    IF ROW(NEW.bill_id, NEW.qt_id, NEW.revision_no, NEW.signature_type, NEW.signature_level,
           NEW.signer_user_id, NEW.signer_username, NEW.signer_name, NEW.signer_role, NEW.signer_email,
           NEW.signature_asset_id, NEW.signature_image_sha256, NEW.signature_image_base64,
           NEW.intent_text, NEW.payload_snapshot, NEW.payload_sha256, NEW.signed_at, NEW.ip_address, NEW.user_agent)
       IS DISTINCT FROM
       ROW(OLD.bill_id, OLD.qt_id, OLD.revision_no, OLD.signature_type, OLD.signature_level,
           OLD.signer_user_id, OLD.signer_username, OLD.signer_name, OLD.signer_role, OLD.signer_email,
           OLD.signature_asset_id, OLD.signature_image_sha256, OLD.signature_image_base64,
           OLD.intent_text, OLD.payload_snapshot, OLD.payload_sha256, OLD.signed_at, OLD.ip_address, OLD.user_agent) THEN
        RAISE EXCEPTION 'signed evidence is immutable; only revocation fields may change';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_protect_document_signature_evidence ON document_signatures;
CREATE TRIGGER trg_protect_document_signature_evidence
BEFORE UPDATE OR DELETE ON document_signatures
FOR EACH ROW EXECUTE FUNCTION protect_document_signature_evidence();

CREATE OR REPLACE FUNCTION protect_billing_document_bytes()
RETURNS TRIGGER AS $$
BEGIN
    IF ROW(NEW.bill_id, NEW.document_type, NEW.file_name, NEW.mime_type, NEW.file_bytes,
           NEW.version_no, NEW.sha256, NEW.uploaded_by, NEW.uploaded_at)
       IS DISTINCT FROM
       ROW(OLD.bill_id, OLD.document_type, OLD.file_name, OLD.mime_type, OLD.file_bytes,
           OLD.version_no, OLD.sha256, OLD.uploaded_by, OLD.uploaded_at) THEN
        RAISE EXCEPTION 'uploaded evidence bytes and identity are immutable; create a new version';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_protect_billing_document_bytes ON billing_documents;
CREATE TRIGGER trg_protect_billing_document_bytes
BEFORE UPDATE ON billing_documents
FOR EACH ROW EXECUTE FUNCTION protect_billing_document_bytes();

CREATE OR REPLACE FUNCTION protect_user_signature_asset_bytes()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'signature image versions cannot be deleted';
    END IF;
    IF ROW(NEW.user_id, NEW.version_no, NEW.file_name, NEW.mime_type, NEW.image_base64,
           NEW.image_sha256, NEW.uploaded_by, NEW.uploaded_at)
       IS DISTINCT FROM
       ROW(OLD.user_id, OLD.version_no, OLD.file_name, OLD.mime_type, OLD.image_base64,
           OLD.image_sha256, OLD.uploaded_by, OLD.uploaded_at) THEN
        RAISE EXCEPTION 'signature image evidence is immutable; upload a new version';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_protect_user_signature_asset_bytes ON user_signature_assets;
CREATE TRIGGER trg_protect_user_signature_asset_bytes
BEFORE UPDATE OR DELETE ON user_signature_assets
FOR EACH ROW EXECUTE FUNCTION protect_user_signature_asset_bytes();

COMMENT ON TABLE quotation_revisions IS 'Immutable commercial snapshots for every saved quotation draft version';
COMMENT ON COLUMN billing_records.cancellation_reason IS 'Required reason; cancel preserves original bill and items';
COMMENT ON TABLE document_signatures IS 'Evidence record for ETDA-aligned general electronic signatures; not a PKI certificate signature';
COMMENT ON TABLE user_signature_assets IS 'Immutable versioned PNG visual signatures; legal evidence remains account authentication, intent and signed snapshot';
COMMENT ON TABLE customer_acceptance_signatures IS 'Immutable ETDA-aligned type-1 customer acceptance evidence; drawn/uploaded PNG is combined with identity, intent, timestamp, IP and document hash';
