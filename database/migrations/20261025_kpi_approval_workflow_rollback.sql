-- Rollback ของ 20261025_kpi_approval_workflow.sql
-- ลบประวัติการส่ง/อนุมัติทั้งหมด (สถานะใน kpi_performances.status ไม่ถูกแก้ไข) · สำรองข้อมูลก่อนรัน
-- หลังลบตาราง ระบบปิดขั้นตอนส่ง/อนุมัติอัตโนมัติ

DROP TABLE IF EXISTS kpi_approval_logs;
