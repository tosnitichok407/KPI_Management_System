<?php

/*
|--------------------------------------------------------------------------
| ดาวน์โหลดไฟล์ที่ระบบเก็บไว้ (ตรวจสิทธิ์ทุกครั้ง)
|--------------------------------------------------------------------------
|
| ?type=import&id=<history_id>     ไฟล์ Excel ของบริษัทที่นำเข้า (เก็บ 3 ไฟล์ล่าสุดต่อพนักงาน)
| ?type=evidence&id=<evidence_id>  ไฟล์หลักฐานผลงาน · &view=1 เปิดในเบราว์เซอร์ (เฉพาะรูปภาพ / PDF)
| สิทธิ์: พนักงานเจ้าของไฟล์ · หัวหน้า (Manager) · ผู้ดูแลระบบ (Admin)
| ไม่มีสิทธิ์ / ไม่พบ → 404 เหมือนกัน (ไม่บอกว่าไฟล์มีอยู่หรือเป็นของใคร)
|
*/

require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/kpi-import-history.php";
require_once __DIR__ . "/includes/kpi-evidence.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

function fileDownloadFail(int $status, string $message): void
{
    http_response_code($status);
    header("Content-Type: text/plain; charset=UTF-8");
    header("Cache-Control: no-store");
    echo $message;
    exit;
}

$type = (string) ($_GET["type"] ?? "");
$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0 || !in_array($type, ["import", "evidence"], true)
    || !kpiFileFeatureEnabled($pdo, $type === "import" ? "kpi_import_history" : "kpi_performance_evidence")) {
    fileDownloadFail(404, "ไม่พบไฟล์");
}

if ($type === "import") {

    $stmt = $pdo->prepare("SELECT employee_id, original_name, stored_name FROM kpi_import_history WHERE history_id = :id");
    $stmt->execute([":id" => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !kpiFileViewerAllowed((int) $row["employee_id"])) {
        fileDownloadFail(404, "ไม่พบไฟล์");
    }

    if ($row["stored_name"] === null) {
        fileDownloadFail(410, "ไฟล์นี้ถูกลบแล้ว ระบบเก็บไฟล์นำเข้าไว้เฉพาะ " . KPI_IMPORT_HISTORY_KEEP_FILES . " ไฟล์ล่าสุด");
    }

    $path = kpiStoragePath("imports", (string) $row["stored_name"]);

    if ($path === null || !is_file($path)) {
        fileDownloadFail(404, "ไม่พบไฟล์");
    }

    kpiStorageSend($path, (string) $row["original_name"], KPI_EVIDENCE_TYPES["xlsx"]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.original_name, e.stored_name, e.file_ext, e.mime_type, p.employee_id
    FROM kpi_performance_evidence e
    INNER JOIN kpi_performances p ON p.performance_id = e.performance_id
    WHERE e.evidence_id = :id
");
$stmt->execute([":id" => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !kpiFileViewerAllowed((int) $row["employee_id"])) {
    fileDownloadFail(404, "ไม่พบไฟล์");
}

$path = kpiStoragePath("evidence", (string) $row["stored_name"]);

if ($path === null || !is_file($path) || !isset(KPI_EVIDENCE_TYPES[(string) $row["file_ext"]])) {
    fileDownloadFail(404, "ไม่พบไฟล์");
}

$inline = ($_GET["view"] ?? "") === "1" && in_array($row["file_ext"], ["jpg", "jpeg", "png", "pdf"], true);
kpiStorageSend($path, (string) $row["original_name"], KPI_EVIDENCE_TYPES[(string) $row["file_ext"]], $inline);
