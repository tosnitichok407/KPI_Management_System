<?php

/*
|--------------------------------------------------------------------------
| พนักงานส่งผลการประเมินรายเดือนให้ Manager ตรวจ (POST จากหน้า "ผลการปฏิบัติงาน")
|--------------------------------------------------------------------------
|
| year + month · ตรวจ CSRF · เฉพาะพนักงาน (role 3) ส่งได้เฉพาะผลงานของตัวเอง
| Draft / Rejected → Submitted ทั้งเดือน (ตรวจซ้ำใน Transaction ที่ includes/kpi-approval.php)
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-approval.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ((int) ($_SESSION["role_id"] ?? 0) !== 3 || $employeeId <= 0) {
    redirectToRoleHome("../");
}

$year = (int) ($_POST["year"] ?? 0);
$month = (int) ($_POST["month"] ?? 0);
$validMonth = $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12;
$back = "performance.php" . ($validMonth ? "?year={$year}&month={$month}" : "");

$flash = function (string $type, string $message) use ($back): void {
    $_SESSION["perf_approval_flash"] = ["type" => $type, "message" => $message];
    header("Location: " . $back);
    exit;
};

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $back);
    exit;
}

if (!csrfVerify()) {
    $flash("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง");
}

if (!$validMonth) {
    $flash("error", "เดือนที่เลือกไม่ถูกต้อง");
}

$periodStmt = $pdo->prepare("SELECT period_id FROM evaluation_periods WHERE period_year = :year AND period_month = :month LIMIT 1");
$periodStmt->execute([":year" => $year, ":month" => $month]);
$periodId = (int) $periodStmt->fetchColumn();

if ($periodId <= 0) {
    $flash("error", "ยังไม่มีรอบประเมินของเดือนนี้");
}

$error = kpiApprovalSubmit($pdo, $employeeId, (int) $_SESSION["user_id"], 3, $periodId);

$error === null
    ? $flash("success", "ส่งผลการประเมินให้ Manager ตรวจสอบแล้ว ผลงานของเดือนนี้จะแก้ไขไม่ได้จนกว่า Manager จะส่งกลับ")
    : $flash("error", $error);
