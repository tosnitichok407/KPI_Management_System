<?php

/*
|--------------------------------------------------------------------------
| Manager อนุมัติ / ส่งกลับให้แก้ไข ผลการประเมินรายเดือนของพนักงาน
|--------------------------------------------------------------------------
|
| POST: employee_id, period_id, decision (approve | return), comment (บังคับเมื่อส่งกลับ)
| เฉพาะ Manager (role 2) · Manager ดูแลพนักงานทุกแผนกของสาขา (ไม่จำกัดตามแผนก)
| ตรวจ CSRF · สถานะต้องเป็น Submitted ทั้งเดือน (ตรวจซ้ำใน Transaction ที่ includes/kpi-approval.php)
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-approval.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== KPI_APPROVAL_ROLE_MANAGER) {
    redirectToRoleHome("../");
}

$employeeId = (int) ($_POST["employee_id"] ?? 0);
$periodId = (int) ($_POST["period_id"] ?? 0);
$decision = (string) ($_POST["decision"] ?? "");
$comment = (string) ($_POST["comment"] ?? "");

$returnQuery = http_build_query(array_filter([
    "page" => "employees",
    "year" => (int) ($_POST["year"] ?? 0),
    "month" => (int) ($_POST["month"] ?? 0),
    "department_id" => (int) ($_POST["department_id"] ?? 0),
    "employee_id" => $employeeId,
]));

$flash = function (string $type, string $message) use ($returnQuery): void {
    $_SESSION["mgr_approval_flash"] = ["type" => $type, "message" => $message];
    header("Location: index.php?" . $returnQuery);
    exit;
};

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php?" . $returnQuery);
    exit;
}

if (!csrfVerify()) {
    $flash("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง");
}

$employeeStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = :id");
$employeeStmt->execute([":id" => $employeeId]);

if ($employeeId <= 0 || $periodId <= 0 || $employeeStmt->fetchColumn() === false) {
    $flash("error", "ไม่พบพนักงานหรือรอบประเมินที่เลือก");
}

$error = kpiApprovalReview(
    $pdo,
    (int) $_SESSION["user_id"],
    (int) $_SESSION["role_id"],
    (int) ($_SESSION["employee_id"] ?? 0),
    $employeeId,
    $periodId,
    $decision,
    $comment,
    ($_POST["acknowledge_warnings"] ?? "") === "1"
);

if ($error !== null) {
    $flash("error", $error);
}

$flash("success", $decision === "approve"
    ? "อนุมัติผลการประเมินเรียบร้อยแล้ว"
    : "ส่งกลับให้พนักงานแก้ไขเรียบร้อยแล้ว · พนักงานจะเห็นเหตุผลในหน้าผลการปฏิบัติงาน");
