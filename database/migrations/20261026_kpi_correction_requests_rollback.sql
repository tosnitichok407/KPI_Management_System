-- Rollback ของ 20261026_kpi_correction_requests.sql
-- ลบคำขอแก้ไข เอกสาร และประวัติทั้งหมด (ค่าที่อนุมัติไปแล้วใน kpi_performances ไม่ถูกย้อนกลับ)
-- สำรองข้อมูลและโฟลเดอร์ storage/corrections ก่อนรัน · หลังลบตาราง ระบบปิดฟีเจอร์คำขอแก้ไขอัตโนมัติ

DROP TABLE IF EXISTS kpi_correction_events;
DROP TABLE IF EXISTS kpi_correction_files;
DROP TABLE IF EXISTS kpi_correction_requests;
