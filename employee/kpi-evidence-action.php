<?php

/*
|--------------------------------------------------------------------------
| แนบ / ลบ ไฟล์หลักฐานของผลงาน (POST จากหน้า "ผลการปฏิบัติงาน")
|--------------------------------------------------------------------------
|
| action=upload  performance_id + evidence_file
| action=delete  evidence_id
| ตรวจ CSRF · ทำได้เฉพาะผลงานของตัวเองที่ยังเป็น Draft (ตรวจซ้ำใน Transaction ที่ includes/kpi-evidence.php)
| ผลลัพธ์แสดงเป็นข้อความบนหน้าผลการปฏิบัติงาน (เดือนเดิม)
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-evidence.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}

$year = (int) ($_POST["year"] ?? 0);
$month = (int) ($_POST["month"] ?? 0);
$back = "performance.php" . ($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12 ? "?year={$year}&month={$month}" : "");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $back);
    exit;
}

/* performance_id ใช้เปิดกล่องหลักฐานของรายการนั้นบนหน้าเดิมเท่านั้น (ไม่ใช้ตรวจสิทธิ์) */
$openId = (int) ($_POST["performance_id"] ?? 0);
$flash = function (string $type, string $message) use ($back, $openId): void {
    $_SESSION["perf_evidence_flash"] = ["type" => $type, "message" => $message, "performance_id" => $openId];
    header("Location: " . $back);
    exit;
};

/* ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งหมด (รวม CSRF) */
if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
    $flash("error", "ไฟล์มีขนาดเกิน 10 MB");
}

if (!csrfVerify()) {
    $flash("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง");
}

if (!kpiFileFeatureEnabled($pdo, "kpi_performance_evidence")) {
    $flash("error", "ระบบแนบหลักฐานยังไม่เปิดใช้งาน");
}

$action = (string) ($_POST["action"] ?? "");

if ($action === "upload_all") {
    $periodStmt = $pdo->prepare("SELECT period_id FROM evaluation_periods WHERE period_year = :year AND period_month = :month LIMIT 1");
    $periodStmt->execute([":year" => $year, ":month" => $month]);
    $periodId = (int) $periodStmt->fetchColumn();
    $performanceStmt = $pdo->prepare("SELECT performance_id, status FROM kpi_performances WHERE employee_id = :employee_id AND period_id = :period_id ORDER BY performance_id");
    $performanceStmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);
    $performanceRows = $periodId > 0 ? $performanceStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$performanceRows) $flash("error", "ไม่พบผลงาน KPI ของเดือนนี้");
    foreach ($performanceRows as $performance) {
        if (!kpiStatusIsEditable((string) $performance["status"])) $flash("error", "แนบหลักฐานร่วมกันได้เมื่อผลงาน KPI ทุกตัวของเดือนนี้ยังไม่ได้ส่ง (Draft) หรือถูกส่งกลับให้แก้ไข");
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM kpi_performance_evidence WHERE performance_id = :id");
        $countStmt->execute([":id" => (int) $performance["performance_id"]]);
        if ((int) $countStmt->fetchColumn() >= KPI_EVIDENCE_MAX_FILES) $flash("error", "KPI อย่างน้อยหนึ่งรายการมีไฟล์ครบจำนวนสูงสุดแล้ว");
    }
    $file = $_FILES["evidence_file"] ?? [];
    $createdEvidenceIds = [];
    foreach ($performanceRows as $performance) {
        $error = kpiEvidenceAdd($pdo, $employeeId, (int) $_SESSION["user_id"], (int) $performance["performance_id"], is_array($file) ? $file : [], false);
        if ($error !== null) {
            foreach ($createdEvidenceIds as $evidenceId) kpiEvidenceDelete($pdo, $employeeId, $evidenceId);
            $flash("error", $error);
        }
        $idStmt = $pdo->prepare("SELECT evidence_id FROM kpi_performance_evidence WHERE performance_id = :performance_id ORDER BY evidence_id DESC LIMIT 1");
        $idStmt->execute([":performance_id" => (int) $performance["performance_id"]]);
        $createdEvidenceIds[] = (int) $idStmt->fetchColumn();
    }
    $flash("success", "แนบไฟล์หลักฐานให้ KPI ทุกตัวของเดือนนี้เรียบร้อยแล้ว");
}

if ($action === "upload") {
    $file = $_FILES["evidence_file"] ?? [];
    $error = kpiEvidenceAdd($pdo, $employeeId, (int) $_SESSION["user_id"], (int) ($_POST["performance_id"] ?? 0), is_array($file) ? $file : []);
    $flash($error === null ? "success" : "error", $error ?? "แนบไฟล์หลักฐานเรียบร้อยแล้ว");
}

if ($action === "delete") {
    $error = kpiEvidenceDelete($pdo, $employeeId, (int) ($_POST["evidence_id"] ?? 0));
    $flash($error === null ? "success" : "error", $error ?? "ลบไฟล์หลักฐานเรียบร้อยแล้ว");
}

$flash("error", "คำขอไม่ถูกต้อง");
