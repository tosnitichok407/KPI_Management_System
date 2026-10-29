-- KPI12 (kpi_id 12) อัตราค้างสัญญาตัวจริงนำกลับ: บันทึกรายสัญญา + หลักฐาน + การอนุมัติ + ประวัติ (schema only)
--
-- Target: MariaDB 10.4.28 · รันซ้ำได้ (CREATE TABLE IF NOT EXISTS) · ไม่แก้ตารางหรือข้อมูลเดิม (ไม่แตะ kpi_performances)
-- Rollback: 20261028_kpi_contract_returns_rollback.sql
-- ห้ามรันบนฐานข้อมูลจริงจนกว่าเจ้าของระบบอนุมัติ · ก่อนรันให้สำรองฐานข้อมูลด้วย mysqldump
--
-- ขั้นตอน (includes/kpi-contract-return.php)
--   พนักงาน (role 3) บันทึก Draft → ส่ง (Submitted ล็อก) → Manager (role 2) อนุมัติ (Approved) / ส่งกลับ (Rejected + เหตุผล)
--   Rejected แก้ไขแล้วส่งใหม่ได้ · Approved แก้ไม่ได้ ต้องขอแก้ไข (kpi_contract_return_revisions) แล้ว Manager อนุมัติอีกครั้ง
--   ค่าที่อนุมัติแล้วยังใช้คำนวณต่อจนกว่าคำขอแก้ไขจะได้รับอนุมัติ
--
-- สถานะเอกสาร (doc_status) แยกจากสถานะอนุมัติ (review_status)
--   not_sent / sent / returned / cancelled (ต้องมีเหตุผล · ใช้ได้เมื่อ Manager อนุมัติ)
--   due_date = วันที่ 15 ของเดือนถัดจากเดือนที่ส่ง (ระบบคำนวณจาก sent_date · ผู้ใช้กรอกเองไม่ได้)
--   ตรงเวลาหรือไม่ ไม่เก็บเป็นคอลัมน์ · คำนวณจาก returned_date <= due_date ทุกครั้ง
--
-- กันซ้ำระดับฐานข้อมูล
--   contract_no_new (UNIQUE): เลขที่สัญญาใหม่ 1 เลข = 1 รายการ
--   pending_guard (UNIQUE): รายการ 1 รายการมีคำขอแก้ไขสถานะ Pending ได้ครั้งละ 1 คำขอ
--
-- kpi_contract_return_events: ประวัติทุกการดำเนินการ (ค่าก่อน / หลังเป็น JSON) · โค้ดเพิ่มแถวอย่างเดียว
-- ไฟล์เก็บใน storage/contract_returns (ชื่อสุ่ม · ปิดการเข้าถึงตรง) · ดาวน์โหลดผ่าน file-download.php?type=contract_return

CREATE TABLE IF NOT EXISTS kpi_contract_returns (
    return_id          INT(11)        NOT NULL AUTO_INCREMENT,
    employee_id        INT(11)        NOT NULL,
    contract_period_id INT(11)        NOT NULL,
    contract_no_new    VARCHAR(20)    NOT NULL,
    contract_no_old    VARCHAR(20)    NULL,
    customer_name      VARCHAR(255)   NOT NULL,
    doc_status         ENUM('not_sent', 'sent', 'returned', 'cancelled') NOT NULL DEFAULT 'not_sent',
    sent_date          DATE           NULL,
    due_date           DATE           NULL,
    returned_date      DATE           NULL,
    cancel_reason      VARCHAR(1000)  NULL,
    note               VARCHAR(1000)  NULL,
    review_status      ENUM('Draft', 'Submitted', 'Approved', 'Rejected') NOT NULL DEFAULT 'Draft',
    created_by         INT(11)        NOT NULL,
    created_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by         INT(11)        NOT NULL,
    updated_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_by       INT(11)        NULL,
    submitted_at       DATETIME       NULL,
    reviewed_by        INT(11)        NULL,
    reviewed_role_id   INT(11)        NULL,
    reviewed_at        DATETIME       NULL,
    review_comment     VARCHAR(1000)  NULL,
    PRIMARY KEY (return_id),
    UNIQUE KEY uq_contract_return_no (contract_no_new),
    KEY idx_contract_return_employee (employee_id, contract_period_id),
    KEY idx_contract_return_status (review_status, submitted_at),
    CONSTRAINT fk_contract_return_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_return_period FOREIGN KEY (contract_period_id) REFERENCES evaluation_periods (period_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_return_created_by FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_return_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_return_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_return_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_contract_return_dates CHECK (
        (doc_status = 'not_sent' AND sent_date IS NULL AND due_date IS NULL AND returned_date IS NULL)
        OR (doc_status = 'sent' AND sent_date IS NOT NULL AND due_date IS NOT NULL AND returned_date IS NULL)
        OR (doc_status = 'returned' AND sent_date IS NOT NULL AND due_date IS NOT NULL AND returned_date IS NOT NULL AND returned_date >= sent_date)
        OR (doc_status = 'cancelled' AND (returned_date IS NULL OR sent_date IS NOT NULL))
    ),
    CONSTRAINT chk_contract_return_due CHECK (due_date IS NULL OR (sent_date IS NOT NULL AND due_date > sent_date)),
    CONSTRAINT chk_contract_return_cancel CHECK (doc_status <> 'cancelled' OR CHAR_LENGTH(TRIM(COALESCE(cancel_reason, ''))) > 0),
    CONSTRAINT chk_contract_return_review CHECK (
        (review_status IN ('Draft', 'Submitted') AND reviewed_by IS NULL AND reviewed_at IS NULL)
        OR (review_status IN ('Approved', 'Rejected') AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
    ),
    CONSTRAINT chk_contract_return_reject_comment CHECK (review_status <> 'Rejected' OR CHAR_LENGTH(TRIM(COALESCE(review_comment, ''))) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_contract_return_revisions (
    revision_id                 INT(11)        NOT NULL AUTO_INCREMENT,
    return_id                   INT(11)        NOT NULL,
    employee_id                 INT(11)        NOT NULL,
    proposed_contract_period_id INT(11)        NOT NULL,
    proposed_contract_no_new    VARCHAR(20)    NOT NULL,
    proposed_contract_no_old    VARCHAR(20)    NULL,
    proposed_customer_name      VARCHAR(255)   NOT NULL,
    proposed_doc_status         ENUM('not_sent', 'sent', 'returned', 'cancelled') NOT NULL,
    proposed_sent_date          DATE           NULL,
    proposed_due_date           DATE           NULL,
    proposed_returned_date      DATE           NULL,
    proposed_cancel_reason      VARCHAR(1000)  NULL,
    proposed_note               VARCHAR(1000)  NULL,
    before_json                 TEXT           NOT NULL,
    reason                      TEXT           NOT NULL,
    status                      ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    requested_by                INT(11)        NOT NULL,
    requested_at                DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by                 INT(11)        NULL,
    reviewed_role_id            INT(11)        NULL,
    reviewed_at                 DATETIME       NULL,
    review_comment              VARCHAR(1000)  NULL,
    pending_guard               INT(11) AS (IF(status = 'Pending', return_id, NULL)) PERSISTENT,
    PRIMARY KEY (revision_id),
    UNIQUE KEY uq_contract_revision_pending (pending_guard),
    KEY idx_contract_revision_return (return_id, requested_at),
    KEY idx_contract_revision_status (status, requested_at),
    CONSTRAINT fk_contract_revision_return FOREIGN KEY (return_id) REFERENCES kpi_contract_returns (return_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_revision_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_revision_period FOREIGN KEY (proposed_contract_period_id) REFERENCES evaluation_periods (period_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_revision_requested_by FOREIGN KEY (requested_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_revision_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_contract_revision_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT chk_contract_revision_review CHECK (
        (status = 'Pending' AND reviewed_by IS NULL AND reviewed_at IS NULL)
        OR (status <> 'Pending' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
    ),
    CONSTRAINT chk_contract_revision_reject_comment CHECK (status <> 'Rejected' OR CHAR_LENGTH(TRIM(COALESCE(review_comment, ''))) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_contract_return_files (
    file_id       INT(11)          NOT NULL AUTO_INCREMENT,
    return_id     INT(11)          NOT NULL,
    revision_id   INT(11)          NULL,
    original_name VARCHAR(255)     NOT NULL,
    stored_name   VARCHAR(64)      NOT NULL,
    file_ext      VARCHAR(10)      NOT NULL,
    mime_type     VARCHAR(100)     NOT NULL,
    file_size     INT(10) UNSIGNED NOT NULL,
    file_sha256   CHAR(64)         NOT NULL,
    uploaded_by   INT(11)          NOT NULL,
    uploaded_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    UNIQUE KEY uq_contract_file_stored (stored_name),
    KEY idx_contract_file_return (return_id),
    KEY idx_contract_file_revision (revision_id),
    CONSTRAINT fk_contract_file_return FOREIGN KEY (return_id) REFERENCES kpi_contract_returns (return_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_file_revision FOREIGN KEY (revision_id) REFERENCES kpi_contract_return_revisions (revision_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_file_user FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_contract_return_events (
    event_id      INT(11)       NOT NULL AUTO_INCREMENT,
    return_id     INT(11)       NOT NULL,
    revision_id   INT(11)       NULL,
    action        ENUM('create', 'update', 'file_add', 'file_delete', 'submit', 'approve', 'reject',
                       'revision_request', 'revision_approve', 'revision_reject') NOT NULL,
    actor_user_id INT(11)       NOT NULL,
    actor_role_id INT(11)       NOT NULL,
    comment       VARCHAR(1000) NULL,
    before_json   TEXT          NULL,
    after_json    TEXT          NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY idx_contract_event_return (return_id, created_at),
    CONSTRAINT fk_contract_event_return FOREIGN KEY (return_id) REFERENCES kpi_contract_returns (return_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_event_revision FOREIGN KEY (revision_id) REFERENCES kpi_contract_return_revisions (revision_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_contract_event_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
