-- Rollback ของ 20261028_kpi_contract_returns.sql
-- ลบข้อมูลรายสัญญา KPI12 คำขอแก้ไข ไฟล์แนบ และประวัติทั้งหมด (ไม่มีผลกับ kpi_performances เพราะฟีเจอร์ไม่ได้เขียนตารางนั้น)
-- สำรองข้อมูลและโฟลเดอร์ storage/contract_returns ก่อนรัน · หลังลบตาราง ระบบปิดฟีเจอร์อัตโนมัติ

DROP TABLE IF EXISTS kpi_contract_return_events;
DROP TABLE IF EXISTS kpi_contract_return_files;
DROP TABLE IF EXISTS kpi_contract_return_revisions;
DROP TABLE IF EXISTS kpi_contract_returns;
