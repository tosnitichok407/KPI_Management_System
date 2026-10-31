<?php

/*
|--------------------------------------------------------------------------
| พนักงาน: บันทึก / ส่ง / ลบหลักฐาน / ขอแก้ไข รายการรับคืนสัญญา KPI12 (POST จาก employee/kpi-contract-returns.php)
|--------------------------------------------------------------------------
|
| เฉพาะพนักงาน (role 3) · ตรวจ CSRF · ทำได้เฉพาะรายการของตัวเอง
| ข้อมูล / สถานะ / ไฟล์ / รายการซ้ำ ตรวจซ้ำที่ includes/kpi-contract-return.php (ใน Transaction)
| ไม่เขียน kpi_performances
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-contract-return.php";
require_once __DIR__ . "/../includes/kpi-correction.php";

/** @var PDO $pdo */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);
$userId = (int) $_SESSION["user_id"];

if ((int) ($_SESSION["role_id"] ?? 0) !== 3 || $employeeId <= 0) {
    redirectToRoleHome("../");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: kpi-contract-returns.php");
    exit;
}

$action = (string) ($_POST["action"] ?? "");
$returnId = (int) ($_POST["return_id"] ?? 0);

$finish = function (string $type, $message, string $location, array $old = []): void {
    $_SESSION["contract_return_flash"] = ["type" => $type, "messages" => (array) $message];
    if ($old) {
        $_SESSION["contract_return_old"] = $old;
    }
    header("Location: " . $location);
    exit;
};

/* ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งหมด (รวม CSRF) */
if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
    $finish("error", "ขนาดไฟล์รวมเกินกว่าที่ระบบรับได้ (ไฟล์ละไม่เกิน 10 MB)", "kpi-contract-returns.php");
}

if (!csrfVerify()) {
    $finish("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง", "kpi-contract-returns.php");
}

$input = [];
foreach (["contract_period_id", "contract_no_new", "contract_no_old", "customer_name", "doc_status", "sent_date", "returned_date", "cancel_reason", "note"] as $field) {
    $input[$field] = mb_substr((string) ($_POST[$field] ?? ""), 0, KPI_CONTRACT_RETURN_REASON_MAX);
}
$files = kpiCorrectionNormalizeFiles($_FILES["files"] ?? null);

switch ($action) {
    case "save":
        $back = "kpi-contract-returns.php" . ($returnId > 0 ? "?edit={$returnId}#form" : "#form");
        $result = kpiContractReturnSave($pdo, $employeeId, $userId, $returnId, $input, $files);
        if ($result["errors"]) {
            $finish("error", $result["errors"], $back, $input);
        }
        $finish("success", "บันทึกฉบับร่างแล้ว · ยังไม่ส่งให้ Manager (กด \"ส่งให้ Manager\" เมื่อข้อมูลครบ)", "kpi-contract-returns.php?edit=" . $result["return_id"] . "#form");

    case "submit":
        $error = kpiContractReturnSubmit($pdo, $employeeId, $userId, $returnId);
        if ($error !== null) {
            $finish("error", $error, "kpi-contract-returns.php?edit={$returnId}#form");
        }
        $finish("success", "ส่งรายการให้ Manager อนุมัติแล้ว · ระหว่างรออนุมัติแก้ไขไม่ได้", "kpi-contract-returns.php#return-{$returnId}");

    case "delete_file":
        $error = kpiContractReturnDeleteFile($pdo, $employeeId, $userId, (int) ($_POST["file_id"] ?? 0));
        $finish($error === null ? "success" : "error", $error ?? "ลบหลักฐานแล้ว", "kpi-contract-returns.php?edit={$returnId}#form");

    case "revise":
        $back = "kpi-contract-returns.php?revise={$returnId}#form";
        $reason = (string) ($_POST["revision_reason"] ?? "");
        $result = kpiContractReturnRequestRevision($pdo, $employeeId, $userId, $returnId, $input, $reason, $files);
        if ($result["errors"]) {
            $finish("error", $result["errors"], $back, $input + ["revision_reason" => mb_substr($reason, 0, KPI_CONTRACT_RETURN_REASON_MAX)]);
        }
        $finish("success", "ส่งคำขอแก้ไขให้ Manager แล้ว · ค่าที่อนุมัติเดิมยังใช้อยู่จนกว่าจะอนุมัติ", "kpi-contract-returns.php#return-{$returnId}");
}

$finish("error", "คำสั่งไม่ถูกต้อง", "kpi-contract-returns.php");
