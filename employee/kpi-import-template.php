<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/kpi-import-helper.php";

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
|--------------------------------------------------------------------------
| ดาวน์โหลด Template นำเข้าผลงาน (เฉพาะพนักงาน · ข้อมูลของตนเองเท่านั้น)
|--------------------------------------------------------------------------
|
| ชีต KPI_Import   : หัวตาราง 7 คอลัมน์ (เลือกเดือนได้ → เติม employee_code / kpi_id / year / month ให้)
| ชีต KPI_ของฉัน   : KPI ที่ได้รับมอบหมายในปีนั้น (kpi_id, ชื่อ, ประเภท, หน่วย, Target) ไว้อ้างอิง
| ชีต คำอธิบาย     : วิธีกรอก + ตัวอย่างสมมติ (ไม่ใช่ข้อมูลจริง)
| ไม่มีสูตรในไฟล์ · ระบบไม่อ่านชีตอื่นนอกจาก KPI_Import
|
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 3) {
    redirectToRoleHome("../");
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}

$year = (int) ($_GET["year"] ?? date("Y"));
$month = (int) ($_GET["month"] ?? 0);

if ($year < 2000 || $year > 2100) {
    $year = (int) date("Y");
}

if ($month < 0 || $month > 12) {
    $month = 0;
}

/* ปิดแล้ว: ระบบไม่ใช้ Template ของระบบในการนำเข้าผล KPI (นำเข้าจากไฟล์ Excel ของบริษัทเท่านั้น) */
$_SESSION["kpi_import_flash"] = ["type" => "info", "messages" => ["ระบบปิดการนำเข้าผ่าน Template แล้ว กรุณานำเข้าจากไฟล์ Excel ของบริษัท"]];
header("Location: kpi-import.php", true, 303);
exit;

$employeeStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_id = :employee_id LIMIT 1");
$employeeStmt->execute([":employee_id" => $employeeId]);
$employeeCode = (string) $employeeStmt->fetchColumn();

$assignmentStmt = $pdo->prepare("
    SELECT
        a.kpi_id, a.target_value, a.start_date, a.end_date, a.assignment_year,
        k.kpi_name, k.kpi_type, k.unit
    FROM kpi_assignments a
    INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
    WHERE a.employee_id = :employee_id
      AND a.assignment_year = :year
      AND a.status = 'Active'
    ORDER BY FIELD(k.kpi_type, 'Performance', 'Competency'), a.kpi_id
");
$assignmentStmt->execute([":employee_id" => $employeeId, ":year" => $year]);
$assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

$thaiMonths = monthlyPeriodMonths();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle("KPI Import Template")->setCreator("KPI Management System");

$headerStyle = [
    "font" => ["bold" => true, "color" => ["rgb" => "FFFFFF"]],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => "3D4D64"]]
];

/* -------- ชีต KPI_Import -------- */
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(KPI_IMPORT_SHEET);

foreach (KPI_IMPORT_COLUMNS as $index => $column) {
    $sheet->setCellValueExplicit([$index + 1, 1], $column, DataType::TYPE_STRING);
}

$sheet->getStyle("A1:G1")->applyFromArray($headerStyle);
$sheet->getStyle("A2:A" . KPI_IMPORT_MAX_SHEET_ROWS)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
$sheet->freezePane("A2");

foreach (["A" => 16, "B" => 10, "C" => 8, "D" => 8, "E" => 14, "F" => 18, "G" => 40] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

/* เลือกเดือน → เติมแถวของ KPI ที่ครอบคลุมเดือนนั้น (ผู้ใช้กรอก actual หรือ competency_score เอง) */
if ($month > 0) {

    $monthStart = sprintf("%04d-%02d-01", $year, $month);
    $monthEnd = date("Y-m-t", strtotime($monthStart));
    $row = 2;

    foreach ($assignments as $assignment) {

        $start = $assignment["start_date"] ?: sprintf("%04d-01-01", (int) $assignment["assignment_year"]);
        $end = $assignment["end_date"] ?: sprintf("%04d-12-31", (int) $assignment["assignment_year"]);

        if ($start > $monthEnd || $end < $monthStart) {
            continue;
        }

        /* KPI ที่บันทึกได้จากไฟล์ Excel ของบริษัทเท่านั้น ไม่เติมใน Template (ระบบปฏิเสธฝั่ง Server อยู่แล้ว) */
        if (isset(kpiImportCompanyOnlyKpiIds()[(int) $assignment["kpi_id"]])) {
            continue;
        }

        $sheet->setCellValueExplicit([1, $row], $employeeCode, DataType::TYPE_STRING);
        $sheet->setCellValue([2, $row], (int) $assignment["kpi_id"]);
        $sheet->setCellValue([3, $row], $year);
        $sheet->setCellValue([4, $row], $month);
        $row++;
    }
}

/* -------- ชีต KPI_ของฉัน (อ้างอิง) -------- */
$reference = $spreadsheet->createSheet();
$reference->setTitle("KPI_ของฉัน");
$referenceHeader = ["kpi_id", "ชื่อ KPI", "ประเภท", "หน่วย", "Target", "ช่วงที่ได้รับมอบหมาย", "คอลัมน์ที่ต้องกรอก"];

foreach ($referenceHeader as $index => $label) {
    $reference->setCellValueExplicit([$index + 1, 1], $label, DataType::TYPE_STRING);
}

$reference->getStyle("A1:G1")->applyFromArray($headerStyle);
$row = 2;

foreach ($assignments as $assignment) {

    $isCompetency = strtolower((string) $assignment["kpi_type"]) === "competency";

    $reference->setCellValue([1, $row], (int) $assignment["kpi_id"]);
    $reference->setCellValueExplicit([2, $row], (string) $assignment["kpi_name"], DataType::TYPE_STRING);
    $reference->setCellValueExplicit([3, $row], (string) $assignment["kpi_type"], DataType::TYPE_STRING);
    $reference->setCellValueExplicit([4, $row], (string) ($assignment["unit"] ?? "-"), DataType::TYPE_STRING);
    $reference->setCellValueExplicit(
        [5, $row],
        $isCompetency ? "-" : number_format((float) $assignment["target_value"], 2),
        DataType::TYPE_STRING
    );
    $reference->setCellValueExplicit(
        [6, $row],
        assignmentMonthRangeLabel($assignment["start_date"], $assignment["end_date"]),
        DataType::TYPE_STRING
    );
    $reference->setCellValueExplicit(
        [7, $row],
        isset(kpiImportCompanyOnlyKpiIds()[(int) $assignment["kpi_id"]])
            ? "ไม่รับผ่าน Template (นำเข้าจากไฟล์ Excel ของบริษัท)"
            : ($isCompetency ? "competency_score (1-5)" : "actual"),
        DataType::TYPE_STRING
    );
    $row++;
}

if (!$assignments) {
    $reference->setCellValueExplicit([1, 2], "ยังไม่มี KPI ที่ได้รับมอบหมายในปี {$year}", DataType::TYPE_STRING);
}

foreach (["A" => 8, "B" => 48, "C" => 14, "D" => 10, "E" => 16, "F" => 22, "G" => 24] as $col => $width) {
    $reference->getColumnDimension($col)->setWidth($width);
}

/* -------- ชีต คำอธิบาย -------- */
$guide = $spreadsheet->createSheet();
$guide->setTitle("คำอธิบาย");

$lines = [
    ["วิธีกรอก Template นำเข้าผลการปฏิบัติงาน", ""],
    ["", ""],
    ["1.", "กรอกข้อมูลในชีต " . KPI_IMPORT_SHEET . " ตั้งแต่แถวที่ 2 · ห้ามแก้ไข ลบ หรือเพิ่มหัวตาราง (แถวที่ 1)"],
    ["2.", "หนึ่งแถว = หนึ่ง KPI ของหนึ่งเดือน · KPI และเดือนเดียวกันห้ามซ้ำในไฟล์"],
    ["3.", "employee_code = รหัสพนักงานของคุณเท่านั้น (ระบบไม่รับข้อมูลของพนักงานคนอื่น)"],
    ["4.", "kpi_id = รหัส KPI จากชีต KPI_ของฉัน · year = ปี ค.ศ. (เช่น 2026) · month = 1-12"],
    ["5.", "KPI ประเภท Performance: กรอก actual เป็นตัวเลข (ไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง ไม่ใส่จุลภาค % หรือหน่วย) และเว้น competency_score ว่าง"],
    ["6.", "KPI ประเภท Competency: กรอก competency_score เป็นจำนวนเต็ม 1-5 และเว้น actual ว่าง"],
    ["7.", "note = หมายเหตุ (ไม่บังคับ) ไม่เกิน " . KPI_IMPORT_NOTE_MAX_LENGTH . " ตัวอักษร"],
    ["8.", "ห้ามใช้สูตร ระบบรับเฉพาะค่าที่พิมพ์ลงเซลล์โดยตรง · ห้ามกรอกคะแนนหรือเกรด ระบบคำนวณคะแนนเองตามเกณฑ์ของ KPI"],
    ["9.", "ลบแถวของ KPI ที่ไม่ต้องการนำเข้าออก · แถวที่ไม่กรอกค่าจะถูกแจ้งเป็นข้อผิดพลาด (ระบบไม่ถือว่าเป็น 0)"],
    ["10.", "นำเข้าได้เฉพาะเดือนที่รอบประเมินยังเปิดอยู่ · รายการเดิมแก้ไขได้เฉพาะสถานะ Draft และต้องยืนยันก่อนบันทึก"],
    ["11.", "KPI ที่ระบุในชีต KPI_ของฉัน ว่า \"ไม่รับผ่าน Template\" ให้นำเข้าจากไฟล์ Excel ของบริษัทเท่านั้น (ระบบปฏิเสธถ้ากรอกใน Template)"],
    ["", ""],
    ["ตัวอย่าง (ข้อมูลสมมติ ไม่ใช่ข้อมูลจริง)", ""]
];

foreach ($lines as $index => [$a, $b]) {
    $guide->setCellValueExplicit([1, $index + 1], $a, DataType::TYPE_STRING);
    $guide->setCellValueExplicit([2, $index + 1], $b, DataType::TYPE_STRING);
}

$exampleRow = count($lines) + 1;

foreach (KPI_IMPORT_COLUMNS as $index => $column) {
    $guide->setCellValueExplicit([$index + 1, $exampleRow], $column, DataType::TYPE_STRING);
}

$guide->getStyle("A{$exampleRow}:G{$exampleRow}")->applyFromArray($headerStyle);

$examples = [
    ["EMP-EXAMPLE", "101", "2026", "1", "95.5", "", "ตัวอย่าง KPI ประเภท Performance"],
    ["EMP-EXAMPLE", "201", "2026", "1", "", "4", "ตัวอย่าง KPI ประเภท Competency"]
];

foreach ($examples as $offset => $values) {
    foreach ($values as $index => $value) {
        $guide->setCellValueExplicit([$index + 1, $exampleRow + 1 + $offset], $value, DataType::TYPE_STRING);
    }
}

$guide->getStyle("A1")->getFont()->setBold(true)->setSize(14);
$guide->getColumnDimension("A")->setWidth(16);
$guide->getColumnDimension("B")->setWidth(110);

$spreadsheet->setActiveSheetIndex(0);

$filename = "kpi-import-template-" . $year . ($month > 0 ? "-" . sprintf("%02d", $month) : "") . ".xlsx";

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: no-store, max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
