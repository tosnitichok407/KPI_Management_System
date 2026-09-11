<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/performance-export-data.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$auth = requireEmployeeExportAccess();
$year = (int) ($_GET["year"] ?? date("Y"));
$month = (int) ($_GET["month"] ?? date("n"));
$report = loadEmployeePerformanceReport($pdo, $auth["employee_id"], $year, $month);
$employee = $report["employee"];
$period = $report["period"];
$summary = $report["summary"];

if (!$period || empty($report["kpis"])) {
    http_response_code(404);
    exit("เดือนนี้ยังไม่มี KPI ที่ได้รับมอบหมาย");
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("ผลการปฏิบัติงาน");

$sheet->mergeCells("A1:I1");
$sheet->setCellValue("A1", "รายงานผลการปฏิบัติงาน");
$sheet->getStyle("A1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB("244397");
$sheet->getStyle("A1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$employeeName = $employee["first_name"] . " " . $employee["last_name"];
$info = [
    ["ชื่อพนักงาน", $employeeName, "รหัสพนักงาน", $employee["employee_code"]],
    ["ตำแหน่ง", $employee["position_name"] ?? "-", "แผนก", $employee["department_name"] ?? "-"],
    ["รอบประเมิน", $period["period_name"], "วันที่", $period["start_date"] . " ถึง " . $period["end_date"]]
];

$row = 3;
foreach ($info as $infoRow) {
    $sheet->fromArray($infoRow, null, "A" . $row);
    $row++;
}
$sheet->getStyle("A3:A5")->getFont()->setBold(true);
$sheet->getStyle("C3:C5")->getFont()->setBold(true);

$headerRow = 7;
$headers = ["ประเภท KPI", "หมวดหมู่ KPI", "ชื่อตัวชี้วัด", "เป้าหมาย", "หน่วย", "Actual", "คะแนน", "Weight", "สถานะ"];
$sheet->fromArray($headers, null, "A" . $headerRow);
$sheet->getStyle("A{$headerRow}:I{$headerRow}")->applyFromArray([
    "font" => ["bold" => true, "color" => ["rgb" => "FFFFFF"]],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => "244397"]],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => ["allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => "CBD3E1"]]]
]);

$row = $headerRow + 1;
foreach ($report["kpis"] as $kpi) {
    $sheet->fromArray([
        $kpi["kpi_type"],
        $kpi["category_name"] ?? "-",
        $kpi["kpi_name"],
        $kpi["target_value"],
        $kpi["unit"] ?? "-",
        $kpi["actual_value"],
        $kpi["score_value"],
        $kpi["weight_value"],
        $kpi["status_value"]
    ], null, "A" . $row);
    $row++;
}

$lastKpiRow = max($headerRow, $row - 1);
$sheet->getStyle("A{$headerRow}:I{$lastKpiRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB("CBD3E1");
$sheet->getStyle("D{$headerRow}:H{$lastKpiRow}")->getNumberFormat()->setFormatCode("0.00");
$sheet->getStyle("A{$headerRow}:I{$lastKpiRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

$summaryRow = $lastKpiRow + 3;
$sheet->fromArray([
    ["สรุปคะแนน", "คะแนน"],
    ["คะแนนรวม Performance", $summary["performance_score"]],
    ["คะแนนรวม Competency", $summary["competency_score"]],
    ["คะแนนรวมทั้งหมด", $summary["total_score"]],
    ["เปอร์เซ็นต์ผลการปฏิบัติงาน", $summary["percentage"] === null ? "-" : $summary["percentage"]]
], null, "A" . $summaryRow);
$sheet->getStyle("A{$summaryRow}:B{$summaryRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$summaryRow}:B" . ($summaryRow + 4))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB("CBD3E1");
$sheet->getStyle("A{$summaryRow}:B{$summaryRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB("E9EEF9");

$widths = [18, 20, 34, 14, 14, 14, 12, 12, 18];
foreach ($widths as $index => $width) {
    $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
}
$sheet->freezePane("A8");

$filename = "performance-report-" . $employee["employee_code"] . "-" . $year . "-" . $month . ".xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
