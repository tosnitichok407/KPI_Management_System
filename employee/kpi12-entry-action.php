<?php

/*
|--------------------------------------------------------------------------
| พนักงานกรอกผล KPI 12 "อัตราค้างสัญญาตัวจริงนำกลับ" (POST จากหน้า "ผลการปฏิบัติงาน")
|--------------------------------------------------------------------------
|
| year + month + total (ทั้งหมด) + returned (นำกลับ) · ตรวจ CSRF · เฉพาะพนักงาน (role 3) บันทึกได้เฉพาะผลงานของตัวเอง
| Actual / หมายเหตุคำนวณที่ kpi12FormRow() · ตรวจและบันทึกด้วย kpiImportValidate / kpiImportCommit (origin form)
| → Assignment ต้อง Active · รอบประเมินต้อง Open · แก้ได้เฉพาะ Draft / Rejected · บันทึกเป็น Draft
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-import-helper.php";

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
$back = "performance.php" . ($validMonth ? "?year={$year}&month={$month}" : "") . "#kpi12-entry";

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

$codeStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_id = :employee_id LIMIT 1");
$codeStmt->execute([":employee_id" => $employeeId]);
$employeeCode = $codeStmt->fetchColumn();

if ($employeeCode === false) {
    $flash("error", "ไม่พบข้อมูลพนักงานของผู้ใช้");
}

$form = kpi12FormRow((string) $employeeCode, $year, $month, (string) ($_POST["total"] ?? ""), (string) ($_POST["returned"] ?? ""));

if ($form["error"] !== null) {
    $flash("error", "KPI อัตราค้างสัญญาตัวจริงนำกลับ: " . $form["error"]);
}

$parsedRows = [$form["row"]];
$validation = kpiImportValidate($pdo, $employeeId, $parsedRows, false, KPI_IMPORT_ORIGIN_FORM);
$row = $validation["rows"][0] ?? null;

if ($row === null || $row["action"] === "error") {
    $flash("error", "KPI อัตราค้างสัญญาตัวจริงนำกลับ: " . implode(" · ", $row["errors"] ?? ["ตรวจสอบข้อมูลไม่สำเร็จ"]));
}

if ($row["action"] === "unchanged") {
    $flash("success", "KPI อัตราค้างสัญญาตัวจริงนำกลับ: ค่าเท่าเดิม ไม่มีการเปลี่ยนแปลง");
}

/* ผู้ใช้กดบันทึกเองในฟอร์มนี้ = ยืนยันการแก้ไขรายการเดิม (Draft / Rejected) ของ KPI และเดือนนี้ */
$summary = kpiImportCommit($pdo, $employeeId, $parsedRows, $validation["rows"], [$row["key"]], KPI_IMPORT_ORIGIN_FORM);

if (!$summary["ok"]) {
    $flash("error", "KPI อัตราค้างสัญญาตัวจริงนำกลับ: " . implode(" · ", $summary["errors"] ?: ["บันทึกไม่สำเร็จ"]));
}

$flash(
    "success",
    "บันทึก KPI อัตราค้างสัญญาตัวจริงนำกลับแล้ว (Draft) · " . $form["row"]["values"]["note"]
    . " → Actual " . $row["actual"] . "% · คะแนน " . ($row["score"] !== null ? (int) $row["score"] : "-")
);
