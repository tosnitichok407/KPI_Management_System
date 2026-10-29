<?php

/*
|--------------------------------------------------------------------------
| Manager อนุมัติ / ส่งกลับ รายการรับคืนสัญญา KPI12 และคำขอแก้ไข
|--------------------------------------------------------------------------
|
| POST: target (record | revision), id, decision (approve | reject), comment
| เฉพาะ Manager (role 2) · ตรวจ CSRF · สิทธิ์ สถานะ และรายการของตัวเอง ตรวจซ้ำใน Transaction (includes/kpi-contract-return.php)
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-contract-return.php";

/** @var PDO $pdo */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== KPI_APPROVAL_ROLE_MANAGER) {
    redirectToRoleHome("../");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php?page=contract-returns");
    exit;
}

$target = (string) ($_POST["target"] ?? "");
$id = (int) ($_POST["id"] ?? 0);
$returnId = (int) ($_POST["return_id"] ?? 0);
$back = "index.php?page=contract-returns&status=all&return_id=" . $returnId;

$flash = function (string $type, string $message) use ($back): void {
    $_SESSION["mgr_contract_return_flash"] = ["type" => $type, "message" => $message];
    header("Location: " . $back);
    exit;
};

if (!csrfVerify()) {
    $flash("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง");
}

$decision = (string) ($_POST["decision"] ?? "");
$args = [$pdo, (int) $_SESSION["user_id"], (int) $_SESSION["role_id"], (int) ($_SESSION["employee_id"] ?? 0), $id, $decision, (string) ($_POST["comment"] ?? "")];

if ($target === "record") {
    $error = kpiContractReturnReview(...$args);
    $done = $decision === "approve" ? "อนุมัติรายการแล้ว" : "ส่งรายการกลับให้พนักงานแก้ไขแล้ว";
} elseif ($target === "revision") {
    $error = kpiContractReturnReviewRevision(...$args);
    $done = $decision === "approve" ? "อนุมัติคำขอแก้ไขแล้ว · ระบบใช้ค่าใหม่และเก็บค่าเดิมไว้ในประวัติ" : "ส่งคำขอแก้ไขกลับให้พนักงานแล้ว · ค่าที่อนุมัติเดิมไม่เปลี่ยน";
} else {
    $error = "คำสั่งไม่ถูกต้อง";
    $done = "";
}

if ($error !== null) {
    $flash("error", $error);
}

$flash("success", $done);
