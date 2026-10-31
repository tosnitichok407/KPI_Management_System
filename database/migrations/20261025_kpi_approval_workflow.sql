-- ขั้นตอนส่งผลและอนุมัติผล KPI รายเดือน: ตารางประวัติการดำเนินการ (schema only)
--
-- Target: MariaDB 10.4.28 · รันซ้ำได้ (CREATE TABLE IF NOT EXISTS) · ไม่แก้ตารางหรือข้อมูลเดิม
-- Rollback: 20261025_kpi_approval_workflow_rollback.sql
-- ห้ามรันบนฐานข้อมูลจริงจนกว่าเจ้าของระบบอนุมัติ · ก่อนรันให้สำรองฐานข้อมูลด้วย mysqldump
--
-- สถานะของผลงานใช้คอลัมน์เดิม kpi_performances.status (Draft / Submitted / Approved / Rejected)
--   Draft     → พนักงานนำเข้าแล้ว ยังไม่ส่ง (แก้ไข / นำเข้าทับ / แนบหลักฐานได้)
--   Submitted → พนักงานส่งให้ Manager ตรวจ (ล็อก แก้ไขไม่ได้)
--   Approved  → Manager อนุมัติ (ล็อกถาวร)
--   Rejected  → Manager ส่งกลับให้แก้ไข พร้อมเหตุผล (แก้ไข / นำเข้าทับได้เหมือน Draft แล้วส่งใหม่)
--
-- kpi_approval_logs: 1 แถวต่อ 1 การดำเนินการ (ส่ง / อนุมัติ / ส่งกลับ) ของพนักงาน 1 คน × 1 เดือน
--   โค้ดของระบบเพิ่มแถวอย่างเดียว ไม่แก้และไม่ลบ (append-only ระดับแอป)
--   performance_ids = รหัสผลงานที่เปลี่ยนสถานะในการดำเนินการนั้น (คั่นด้วย ,)
--   ไม่มีตารางนี้ → ระบบปิดขั้นตอนส่ง/อนุมัติ หน้าเว็บทำงานแบบเดิม
--
-- อิสระจาก 20261016_kpi_approval_audit.sql (ยังไม่ได้รัน · ใช้บัญชี kpi_dba + trigger) ชื่อตาราง/คอลัมน์ไม่ซ้ำกัน

CREATE TABLE IF NOT EXISTS kpi_approval_logs (
    log_id            INT(11)       NOT NULL AUTO_INCREMENT,
    employee_id       INT(11)       NOT NULL,
    period_id         INT(11)       NOT NULL,
    action            ENUM('submit', 'approve', 'return') NOT NULL,
    from_status       VARCHAR(20)   NOT NULL,
    to_status         ENUM('Submitted', 'Approved', 'Rejected') NOT NULL,
    performance_count INT(11)       NOT NULL,
    performance_ids   TEXT          NOT NULL,
    comment           VARCHAR(1000) NULL,
    actor_user_id     INT(11)       NOT NULL,
    actor_role_id     INT(11)       NOT NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_approval_logs_employee_period (employee_id, period_id, created_at),
    KEY idx_approval_logs_period (period_id),
    KEY idx_approval_logs_actor (actor_user_id),
    CONSTRAINT fk_approval_logs_employee FOREIGN KEY (employee_id)
        REFERENCES employees (employee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_approval_logs_period FOREIGN KEY (period_id)
        REFERENCES evaluation_periods (period_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_approval_logs_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_approval_logs_transition CHECK (
        (action = 'submit'  AND to_status = 'Submitted')
        OR (action = 'approve' AND to_status = 'Approved')
        OR (action = 'return'  AND to_status = 'Rejected')
    ),
    CONSTRAINT chk_approval_logs_return_comment CHECK (action <> 'return' OR CHAR_LENGTH(TRIM(COALESCE(comment, ''))) > 0),
    CONSTRAINT chk_approval_logs_count CHECK (performance_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
