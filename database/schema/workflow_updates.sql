-- Workflow upgrade SQL for Web With Database
-- Run this once in Supabase SQL Editor before using the new workflow.

CREATE TABLE IF NOT EXISTS quotations (
    qt_id SERIAL PRIMARY KEY,
    qt_no VARCHAR(50),
    qt_date DATE DEFAULT CURRENT_DATE,
    buyer_name VARCHAR(255),
    tax_id VARCHAR(50),
    total_amount DECIMAL(12,2) DEFAULT 0,
    province VARCHAR(100),
    district VARCHAR(100),
    sub_district VARCHAR(100),
    zipcode VARCHAR(20),
    address_note TEXT,
    shipping_type VARCHAR(100),
    shipping_text TEXT,
    bill_note TEXT,
    bplus_customer_code VARCHAR(100),
    created_by VARCHAR(255),
    status VARCHAR(50) DEFAULT 'ร่างใบเสนอราคา',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS quotation_items (
    qt_item_id SERIAL PRIMARY KEY,
    qt_id INT REFERENCES quotations(qt_id) ON DELETE CASCADE,
    main_category VARCHAR(255),
    sub_category VARCHAR(255),
    unit_type VARCHAR(100),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0,
    line_total DECIMAL(12,2) DEFAULT 0
);

ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS ref_qt_id INT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS warehouse_code VARCHAR(100);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(12,2);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS credit_outstanding DECIMAL(12,2);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS credit_remain DECIMAL(12,2);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS credit_term INT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS requested_credit_term INT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS admin_verified_by VARCHAR(255);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS reject_reason TEXT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS document_printed_at TIMESTAMP;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS document_printed_by VARCHAR(255);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS excel_exported_at TIMESTAMP;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS excel_exported_by VARCHAR(255);

ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255);

CREATE TABLE IF NOT EXISTS customer_credit_profiles (
    ar_code VARCHAR(100) PRIMARY KEY,
    ar_name TEXT NOT NULL,
    credit_limit DECIMAL(14,2) DEFAULT 0,
    credit_term INT,
    source_file TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE customer_credit_profiles ADD COLUMN IF NOT EXISTS source_file TEXT;
ALTER TABLE customer_credit_profiles ADD COLUMN IF NOT EXISTS credit_outstanding DECIMAL(14,2) DEFAULT 0;

CREATE TABLE IF NOT EXISTS credit_requests (
    request_id SERIAL PRIMARY KEY,
    company_name TEXT NOT NULL,
    tax_id VARCHAR(13) NOT NULL,
    registered_address TEXT NOT NULL,
    authorized_directors TEXT NOT NULL,
    requested_credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
    requested_credit_term INT NOT NULL DEFAULT 0,
    expected_monthly_volume DECIMAL(14,2) DEFAULT 0,
    justification TEXT,
    status VARCHAR(50) DEFAULT 'รออนุมัติวงเงิน',
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by VARCHAR(255),
    approved_at TIMESTAMP,
    opened_by VARCHAR(255),
    opened_at TIMESTAMP,
    reject_reason TEXT,
    ar_code VARCHAR(100),
    request_type VARCHAR(50) DEFAULT 'new_customer',
    current_credit_limit DECIMAL(14,2) DEFAULT 0,
    current_credit_term INT DEFAULT 0,
    ar_name TEXT
);

ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS request_type VARCHAR(50) DEFAULT 'new_customer';
ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS current_credit_limit DECIMAL(14,2) DEFAULT 0;
ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS current_credit_term INT DEFAULT 0;
ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS ar_name TEXT;

CREATE TABLE IF NOT EXISTS credit_request_documents (
    doc_id SERIAL PRIMARY KEY,
    request_id INT REFERENCES credit_requests(request_id) ON DELETE CASCADE,
    doc_type VARCHAR(100) NOT NULL,
    file_name TEXT NOT NULL,
    mime_type TEXT,
    file_bytes BYTEA NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Notice board / team announcement page.
-- Admin and Approve can create announcements or meeting notices; Sale can view them.
CREATE TABLE IF NOT EXISTS notice_board (
    notice_id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT,
    notice_type TEXT DEFAULT 'ประกาศทั่วไป',
    meeting_date DATE,
    meeting_time TIME,
    location TEXT,
    is_pinned BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS notice_type TEXT DEFAULT 'ประกาศทั่วไป';
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS meeting_date DATE;
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS meeting_time TIME;
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS location TEXT;
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS is_pinned BOOLEAN DEFAULT FALSE;
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE;
ALTER TABLE notice_board ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Quotation print tracking.
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS quotation_printed_at TIMESTAMP;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS quotation_printed_by VARCHAR(255);

-- Workflow upgrade: credit-first vs existing-customer billing
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS workflow_type VARCHAR(50);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS sale_discount_total DECIMAL(12,2) DEFAULT 0;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS sale_discount_note TEXT;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(12,2);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_term INT;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS requested_credit_term INT;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_approved_at TIMESTAMP;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_approved_by VARCHAR(255);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_opened_at TIMESTAMP;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS credit_opened_by VARCHAR(255);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS reject_reason TEXT;

ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS workflow_type VARCHAR(50);
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS sale_discount_total DECIMAL(12,2) DEFAULT 0;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS sale_discount_note TEXT;
ALTER TABLE billing_records ADD COLUMN IF NOT EXISTS auto_approved_reason TEXT;

ALTER TABLE quotation_items ADD COLUMN IF NOT EXISTS sale_discount_unit DECIMAL(12,2) DEFAULT 0;
ALTER TABLE quotation_items ADD COLUMN IF NOT EXISTS line_total_before_discount DECIMAL(12,2);
ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS sale_discount_unit DECIMAL(12,2) DEFAULT 0;
ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS line_total_before_discount DECIMAL(12,2);
ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS customer_product_name TEXT;

UPDATE notice_board SET is_active = TRUE WHERE is_active IS NULL;
UPDATE notice_board SET is_pinned = FALSE WHERE is_pinned IS NULL;
