<?php

/*
|--------------------------------------------------------------------------
| Manager อนุมัติ / ส่งกลับ คำขอแก้ไขข้อมูล KPI
|--------------------------------------------------------------------------
|
| POST: request_id, decision (approve | return), comment, acknowledge_warnings
| เฉพาะ Manager (role 2) · ตรวจ CSRF · สิทธิ์ สถานะ คำขอของตัวเอง และค่าในผลงาน ตรวจซ้ำใน Transaction
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-correction.php";

/** @var PDO $pdo */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== KPI_APPROVAL_ROLE_MANAGER) {
    redirectToRoleHome("../");
}

$requestId = (int) ($_POST["request_id"] ?? 0);
$back = "index.php?page=corrections&status=all&request_id=" . $requestId;

$flash = function (string $type, string $message) use ($back): void {
    $_SESSION["mgr_correction_flash"] = ["type" => $type, "message" => $message];
    header("Location: " . $back);
    exit;
};

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php?page=corrections");
    exit;
}

if (!csrfVerify()) {
    $flash("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง");
}

$decision = (string) ($_POST["decision"] ?? "");
$error = kpiCorrectionReview(
    $pdo,
    (int) $_SESSION["user_id"],
    (int) $_SESSION["role_id"],
    (int) ($_SESSION["employee_id"] ?? 0),
    $requestId,
    $decision,
    (string) ($_POST["comment"] ?? ""),
    ($_POST["acknowledge_warnings"] ?? "") === "1"
);

if ($error !== null) {
    $flash("error", $error);
}

$flash("success", $decision === "approve"
    ? "อนุมัติคำขอ #{$requestId} แล้ว · บันทึกค่าก่อน / หลังการแก้ไขไว้ในประวัติ"
    : "ส่งคำขอ #{$requestId} กลับให้พนักงานแก้ไขแล้ว");
