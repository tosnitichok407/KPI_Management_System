-- คำขอแก้ไขข้อมูลผลงาน KPI + เอกสารประกอบ + ประวัติการพิจารณา (schema only)
--
-- Target: MariaDB 10.4.28 · รันซ้ำได้ (CREATE TABLE IF NOT EXISTS) · ไม่แก้ตารางหรือข้อมูลเดิม
-- Rollback: 20261026_kpi_correction_requests_rollback.sql
-- ห้ามรันบนฐานข้อมูลจริงจนกว่าเจ้าของระบบอนุมัติ · ก่อนรันให้สำรองฐานข้อมูลด้วย mysqldump
--
-- ขั้นตอน (includes/kpi-correction.php)
--   พนักงานส่งคำขอ (Pending) ── Manager อนุมัติ (Approved) → ระบบแก้ค่าในผลงานใน Transaction เดียวกัน
--                            └─ Manager ส่งกลับ (Returned) → พนักงานส่งคำขอใหม่ = แถวใหม่ (previous_request_id)
--   คำขอไม่เปลี่ยนค่าจริงจนกว่าจะอนุมัติ · request_type = evidence_only ไม่เปลี่ยนค่า (ส่งหลักฐานอย่างเดียว)
--   ค่าเดิม (original_*) เก็บ ณ เวลาส่งคำขอ · applied_* เก็บค่าก่อน / หลังที่เปลี่ยนจริงตอนอนุมัติ
--   ผลพิจารณาเขียนครั้งเดียว (Pending → Approved / Returned) · ส่งใหม่สร้างแถวใหม่ ไม่เขียนทับคำขอเดิม
--
-- กันซ้ำระดับฐานข้อมูล
--   pending_guard (UNIQUE): ผลงาน 1 แถวมีคำขอสถานะ Pending ได้ครั้งละ 1 คำขอ
--   previous_request_id (UNIQUE): คำขอที่ถูกส่งกลับ ส่งใหม่ได้ 1 ครั้ง (ส่งกลับอีกรอบ → ส่งใหม่ต่อจากคำขอล่าสุด)
--
-- kpi_correction_events: ประวัติทุกการดำเนินการ (ส่ง / ส่งใหม่ / อนุมัติ / ส่งกลับ) · โค้ดเพิ่มแถวอย่างเดียว
-- ไฟล์เอกสารเก็บใน storage/corrections (ชื่อสุ่ม · ปิดการเข้าถึงตรง) · ดาวน์โหลดผ่าน file-download.php?type=correction

CREATE TABLE IF NOT EXISTS kpi_correction_requests (
    request_id          INT(11)        NOT NULL AUTO_INCREMENT,
    employee_id         INT(11)        NOT NULL,
    performance_id      INT(11)        NOT NULL,
    period_id           INT(11)        NOT NULL,
    kpi_id              INT(11)        NOT NULL,
    request_type        ENUM('value_change', 'evidence_only') NOT NULL,
    value_field         ENUM('actual', 'score') NULL,
    original_target     DECIMAL(15,2)  NULL,
    original_actual     DECIMAL(15,2)  NULL,
    original_score      DECIMAL(5,2)   NULL,
    original_status     VARCHAR(20)    NOT NULL,
    proposed_value      DECIMAL(15,2)  NULL,
    reason              TEXT           NOT NULL,
    status              ENUM('Pending', 'Approved', 'Returned') NOT NULL DEFAULT 'Pending',
    previous_request_id INT(11)        NULL,
    submitted_by        INT(11)        NOT NULL,
    submitted_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by         INT(11)        NULL,
    reviewed_role_id    INT(11)        NULL,
    reviewed_at         DATETIME       NULL,
    review_comment      VARCHAR(1000)  NULL,
    applied_old_actual  DECIMAL(15,2)  NULL,
    applied_old_score   DECIMAL(5,2)   NULL,
    applied_new_actual  DECIMAL(15,2)  NULL,
    applied_new_score   DECIMAL(5,2)   NULL,
    applied_at          DATETIME       NULL,
    pending_guard       INT(11) AS (IF(status = 'Pending', performance_id, NULL)) PERSISTENT,
    PRIMARY KEY (request_id),
    UNIQUE KEY uq_correction_pending (pending_guard),
    UNIQUE KEY uq_correction_previous (previous_request_id),
    KEY idx_correction_employee (employee_id, period_id),
    KEY idx_correction_status (status, submitted_at),
    KEY idx_correction_performance (performance_id),
    CONSTRAINT fk_correction_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_performance FOREIGN KEY (performance_id) REFERENCES kpi_performances (performance_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_period FOREIGN KEY (period_id) REFERENCES evaluation_periods (period_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_kpi FOREIGN KEY (kpi_id) REFERENCES kpi_indicators (kpi_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_previous FOREIGN KEY (previous_request_id) REFERENCES kpi_correction_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_correction_type CHECK (
        (request_type = 'evidence_only' AND value_field IS NULL AND proposed_value IS NULL)
        OR (request_type = 'value_change' AND value_field IS NOT NULL AND proposed_value IS NOT NULL)
    ),
    CONSTRAINT chk_correction_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT chk_correction_review CHECK (
        (status = 'Pending' AND reviewed_by IS NULL AND reviewed_at IS NULL)
        OR (status <> 'Pending' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
    ),
    CONSTRAINT chk_correction_return_comment CHECK (status <> 'Returned' OR CHAR_LENGTH(TRIM(COALESCE(review_comment, ''))) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_correction_files (
    file_id             INT(11)          NOT NULL AUTO_INCREMENT,
    request_id          INT(11)          NOT NULL,
    original_name       VARCHAR(255)     NOT NULL,
    stored_name         VARCHAR(64)      NOT NULL,
    file_ext            VARCHAR(10)      NOT NULL,
    mime_type           VARCHAR(100)     NOT NULL,
    file_size           INT(10) UNSIGNED NOT NULL,
    file_sha256         CHAR(64)         NOT NULL,
    copied_from_file_id INT(11)          NULL,
    uploaded_by         INT(11)          NOT NULL,
    uploaded_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    UNIQUE KEY uq_correction_file_stored (stored_name),
    KEY idx_correction_file_request (request_id),
    CONSTRAINT fk_correction_file_request FOREIGN KEY (request_id) REFERENCES kpi_correction_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_file_copied FOREIGN KEY (copied_from_file_id) REFERENCES kpi_correction_files (file_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_file_user FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_correction_events (
    event_id      INT(11)       NOT NULL AUTO_INCREMENT,
    request_id    INT(11)       NOT NULL,
    action        ENUM('submit', 'resubmit', 'approve', 'return') NOT NULL,
    actor_user_id INT(11)       NOT NULL,
    actor_role_id INT(11)       NOT NULL,
    comment       VARCHAR(1000) NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY idx_correction_event_request (request_id, created_at),
    CONSTRAINT fk_correction_event_request FOREIGN KEY (request_id) REFERENCES kpi_correction_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_correction_event_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
