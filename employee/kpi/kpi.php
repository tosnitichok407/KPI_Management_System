<?php

/*
|--------------------------------------------------------------------------
| KPI ของฉัน (ปิดการกรอกผล KPI ด้วยตนเองแล้ว)
|--------------------------------------------------------------------------
|
| บันทึกผล KPI ผ่านหน้านำเข้า Excel (employee/kpi-import.php) เท่านั้น
| ไฟล์นี้เหลือไว้รับ URL เดิม (bookmark / ลิงก์เก่า) ทุก Method:
|   ยังไม่ login       → login.php
|   ไม่ใช่พนักงาน     → หน้าหลักของบทบาทนั้น
|   พนักงาน           → employee/kpi-import.php (303 · POST เดิมไม่ถูกบันทึก)
|
*/

require_once __DIR__ . "/../../includes/security.php";

/* ตรวจบัญชีที่ login อยู่ซ้ำ (securityRecheckAccount) เหมือนหน้าอื่นของพนักงาน */
require_once __DIR__ . "/../../config/database.php";


if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 3) {
    redirectToRoleHome("../../");
}

if ((int) ($_SESSION["employee_id"] ?? 0) <= 0) {
    header("Location: ../../login.php");
    exit;
}

header("Location: ../kpi-import.php", true, 303);
exit;
