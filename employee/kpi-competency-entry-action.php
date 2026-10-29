<?php

/*
|--------------------------------------------------------------------------
| พนักงานกรอกคะแนน Competency (POST จากหน้า "ผลการปฏิบัติงาน")
|--------------------------------------------------------------------------
|
| year + month (เดือนที่เปิดหน้าอยู่ · ใช้กลับหน้าเดิม) + score[kpi_id] = 1-5 · ตรวจ CSRF · เฉพาะพนักงาน (role 3)
| คะแนนเหมือนกันทุกเดือน: บันทึกเป็น Draft ทุกเดือนของปีที่บันทึกได้ (kpiCompetencyFormSave)
| เดือนที่ส่งแล้ว / อนุมัติแล้ว / รอบปิด → ไม่แตะ · Manager ตรวจและอนุมัติรายเดือนตามขั้นตอนเดิม
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
$back = "performance.php" . ($validMonth ? "?year={$year}&month={$month}" : "") . "#competency-entry";

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

$input = kpiCompetencyFormScores(is_array($_POST["score"] ?? null) ? $_POST["score"] : []);

if ($input["error"] !== null) {
    $flash("error", "Competency: " . $input["error"]);
}

$codeStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_id = :employee_id LIMIT 1");
$codeStmt->execute([":employee_id" => $employeeId]);
$employeeCode = $codeStmt->fetchColumn();

if ($employeeCode === false) {
    $flash("error", "ไม่พบข้อมูลพนักงานของผู้ใช้");
}

$result = kpiCompetencyFormSave($pdo, $employeeId, (string) $employeeCode, $year, $input["scores"]);

if (!$result["ok"]) {
    $flash("error", "Competency: " . implode(" · ", $result["errors"] ?: ["บันทึกไม่สำเร็จ"]));
}

$monthNames = monthlyPeriodMonths();
$label = fn(array $months): string => implode(", ", array_map(fn($m) => $monthNames[$m], $months));
$summary = $result["summary"];

$flash(
    "success",
    "บันทึกคะแนน Competency แล้ว (Draft) " . count($result["saved_months"]) . " เดือน: " . $label($result["saved_months"])
    . " · เพิ่มใหม่ {$summary["inserted"]} · แก้ไข {$summary["updated"]} · เท่าเดิม {$summary["unchanged"]}"
    . ($result["skipped"] ? " · ไม่แตะเดือนที่บันทึกไม่ได้: " . $label(array_keys($result["skipped"])) : "")
);
