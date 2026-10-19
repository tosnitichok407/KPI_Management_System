-- Rollback ของ 20261019_import_history_and_evidence.sql (MariaDB 10.4.28)
--
-- ลบเฉพาะ 2 ตารางที่ migration สร้าง · ปฏิเสธ (SIGNAL ก่อนลบใด ๆ) ถ้ายังมีข้อมูลในตารางใดตารางหนึ่ง
-- เพื่อไม่ให้ประวัติ/หลักฐานหายโดยไม่ตั้งใจ — ต้องสำรองและล้างข้อมูลเองก่อน
-- ไฟล์ใน storage/imports และ storage/evidence ไม่ถูกลบโดยไฟล์นี้

DELIMITER //
BEGIN NOT ATOMIC
    DECLARE history_rows INT DEFAULT 0;
    DECLARE evidence_rows INT DEFAULT 0;

    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_import_history') THEN
        SELECT COUNT(*) INTO history_rows FROM kpi_import_history;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performance_evidence') THEN
        SELECT COUNT(*) INTO evidence_rows FROM kpi_performance_evidence;
    END IF;

    IF history_rows > 0 OR evidence_rows > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback refused: kpi_import_history / kpi_performance_evidence still contain rows';
    END IF;

    DROP TABLE IF EXISTS kpi_performance_evidence;
    DROP TABLE IF EXISTS kpi_import_history;
END //
DELIMITER ;
