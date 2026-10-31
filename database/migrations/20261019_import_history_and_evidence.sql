-- ประวัติการนำเข้าไฟล์ Excel ของบริษัท + ไฟล์หลักฐานของผลงานรายเดือน (schema only)
--
-- Target: MariaDB 10.4.28 · รันซ้ำได้ (CREATE TABLE IF NOT EXISTS) · ไม่แก้ตารางเดิม
-- Rollback: 20261019_import_history_and_evidence_rollback.sql
--
-- ตัวไฟล์ไม่อยู่ในฐานข้อมูล: เก็บในโฟลเดอร์ storage/ ของระบบ (ปิดการเข้าถึงตรงด้วย .htaccess)
-- ชื่อไฟล์บนดิสก์ = ชื่อสุ่ม (stored_name) · ชื่อไฟล์เดิมเก็บไว้แสดงผล/ตอนดาวน์โหลดเท่านั้น
--
-- kpi_import_history
--   1 แถวต่อการกดยืนยันนำเข้าที่สำเร็จ · เก็บประวัติทุกครั้ง
--   เก็บตัวไฟล์ไว้เฉพาะ 3 ครั้งล่าสุดต่อพนักงาน (KPI_IMPORT_HISTORY_KEEP_FILES)
--   ไฟล์ที่เก่ากว่าถูกลบจากดิสก์ · stored_name = NULL · file_removed_at = เวลาที่ลบ
--   ลบพนักงาน → ลบประวัติตาม (ON DELETE CASCADE) · หน้าลบพนักงานปฏิเสธอยู่แล้วเมื่อมีผลงาน
--
-- kpi_performance_evidence
--   ไฟล์หลักฐานของผลงาน 1 รายการ (KPI × เดือน) · สูงสุด 5 ไฟล์ · ไฟล์ละไม่เกิน 10 MB (ตรวจในโค้ด)
--   ON DELETE RESTRICT: ระบบไม่ลบผลงาน (หน้าลบ Assignment / พนักงานปฏิเสธเมื่อมีผลงาน)
--   จึงไม่ควรมีหลักฐานที่ค้างโดยไม่มีผลงาน

CREATE TABLE IF NOT EXISTS kpi_import_history (
    history_id      INT(11)          NOT NULL AUTO_INCREMENT,
    employee_id     INT(11)          NOT NULL,
    user_id         INT(11)          NOT NULL,
    data_year       SMALLINT         NOT NULL,
    data_month      TINYINT          NOT NULL,
    original_name   VARCHAR(255)     NOT NULL,
    stored_name     VARCHAR(64)      NULL,
    file_size       INT(10) UNSIGNED NOT NULL,
    file_sha256     CHAR(64)         NOT NULL,
    rows_inserted   INT(11)          NOT NULL DEFAULT 0,
    rows_updated    INT(11)          NOT NULL DEFAULT 0,
    rows_unchanged  INT(11)          NOT NULL DEFAULT 0,
    rows_skipped    INT(11)          NOT NULL DEFAULT 0,
    rows_declined   INT(11)          NOT NULL DEFAULT 0,
    imported_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    file_removed_at DATETIME         NULL,
    PRIMARY KEY (history_id),
    UNIQUE KEY uq_import_history_stored (stored_name),
    KEY idx_import_history_employee (employee_id, imported_at),
    CONSTRAINT fk_import_history_employee FOREIGN KEY (employee_id)
        REFERENCES employees (employee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_import_history_month CHECK (data_month BETWEEN 1 AND 12),
    CONSTRAINT chk_import_history_file CHECK ((stored_name IS NULL) = (file_removed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS kpi_performance_evidence (
    evidence_id     INT(11)          NOT NULL AUTO_INCREMENT,
    performance_id  INT(11)          NOT NULL,
    employee_id     INT(11)          NOT NULL,
    user_id         INT(11)          NOT NULL,
    original_name   VARCHAR(255)     NOT NULL,
    stored_name     VARCHAR(64)      NOT NULL,
    file_ext        VARCHAR(10)      NOT NULL,
    mime_type       VARCHAR(100)     NOT NULL,
    file_size       INT(10) UNSIGNED NOT NULL,
    file_sha256     CHAR(64)         NOT NULL,
    uploaded_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evidence_id),
    UNIQUE KEY uq_evidence_stored (stored_name),
    KEY idx_evidence_performance (performance_id),
    KEY idx_evidence_employee (employee_id),
    CONSTRAINT fk_evidence_performance FOREIGN KEY (performance_id)
        REFERENCES kpi_performances (performance_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_evidence_ext CHECK (file_ext IN ('xlsx', 'xls', 'jpg', 'jpeg', 'png', 'pdf')),
    CONSTRAINT chk_evidence_size CHECK (file_size > 0 AND file_size <= 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
