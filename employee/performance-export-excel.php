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

if (!$period || empty($report["kpis"])) {
    http_response_code(404);
    exit("เดือนนี้ยังไม่มี KPI ที่ได้รับมอบหมาย");
}

/* คะแนนเต็มต่อ KPI (เกรด 1-5) */
const MAX_SCORE = 5;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("ผลการปฏิบัติงาน");

$sheet->mergeCells("A1:J1");
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


/*
|--------------------------------------------------------------------------
| KPI Table
|--------------------------------------------------------------------------
|
| G = คะแนน (เต็ม 5), H = น้ำหนัก (%), I = คะแนน × น้ำหนัก (สูตร Excel)
| เช่น คะแนน 5 × น้ำหนัก 30% = 1.50  → รวมทุกข้อได้คะแนนเต็ม 5.00 เมื่อน้ำหนักรวม 100%
|
*/

$headerRow = 7;
$headers = ["ประเภท KPI", "หมวดหมู่ KPI", "ชื่อตัวชี้วัด", "เป้าหมาย", "หน่วย", "Actual", "คะแนน (เต็ม 5)", "น้ำหนัก", "คะแนน × น้ำหนัก", "สถานะ"];
$sheet->fromArray($headers, null, "A" . $headerRow);
$sheet->getStyle("A{$headerRow}:J{$headerRow}")->applyFromArray([
    "font" => ["bold" => true, "color" => ["rgb" => "FFFFFF"]],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => "244397"]],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER, "wrapText" => true],
    "borders" => ["allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => "CBD3E1"]]]
]);

$firstKpiRow = $headerRow + 1;
$row = $firstKpiRow;

foreach ($report["kpis"] as $kpi) {
    $sheet->fromArray([
        $kpi["kpi_type"],
        $kpi["category_name"] ?? "-",
        $kpi["kpi_name"],
        $kpi["target_value"],
        $kpi["unit"] ?? "-",
        $kpi["actual_value"],
        $kpi["score_value"] !== null ? (float) $kpi["score_value"] : null,
        $kpi["weight_value"] / 100,
        "=IF(G{$row}=\"\",\"\",G{$row}*H{$row})",
        $kpi["status_value"]
    ], null, "A" . $row, true);
    $row++;
}

$lastKpiRow = $row - 1;
$kpiRange = "{$firstKpiRow}:{$lastKpiRow}";

$sheet->getStyle("A{$headerRow}:J{$lastKpiRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB("CBD3E1");
$sheet->getStyle("D{$firstKpiRow}:G{$lastKpiRow}")->getNumberFormat()->setFormatCode("0.00");
$sheet->getStyle("H{$firstKpiRow}:H{$lastKpiRow}")->getNumberFormat()->setFormatCode("0.00%");
$sheet->getStyle("I{$firstKpiRow}:I{$lastKpiRow}")->getNumberFormat()->setFormatCode("0.00");
$sheet->getStyle("A{$headerRow}:J{$lastKpiRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
$sheet->getStyle("G{$firstKpiRow}:I{$lastKpiRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);


/*
|--------------------------------------------------------------------------
| Total Row (รวมยอดใต้ตาราง)
|--------------------------------------------------------------------------
*/

$totalRow = $lastKpiRow + 1;

$sheet->mergeCells("A{$totalRow}:G{$totalRow}");
$sheet->setCellValue("A{$totalRow}", "รวม");
$sheet->setCellValue("H{$totalRow}", "=SUM(H{$firstKpiRow}:H{$lastKpiRow})");
$sheet->setCellValue("I{$totalRow}", "=SUM(I{$firstKpiRow}:I{$lastKpiRow})");
$sheet->setCellValue("J{$totalRow}", "เต็ม " . number_format(MAX_SCORE, 2));

$sheet->getStyle("A{$totalRow}:J{$totalRow}")->applyFromArray([
    "font" => ["bold" => true],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => "E9EEF9"]],
    "borders" => ["allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => "CBD3E1"]]]
]);
$sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("H{$totalRow}")->getNumberFormat()->setFormatCode("0.00%");
$sheet->getStyle("I{$totalRow}")->getNumberFormat()->setFormatCode("0.00");


/*
|--------------------------------------------------------------------------
| Summary (สูตรอ้างอิงคอลัมน์ I)
|--------------------------------------------------------------------------
*/

$summaryRow = $totalRow + 3;

$sheet->fromArray([
    ["สรุปคะแนน", "คะแนน"],
    ["คะแนนถ่วงน้ำหนัก Performance", "=SUMIF(A{$firstKpiRow}:A{$lastKpiRow},\"Performance\",I{$firstKpiRow}:I{$lastKpiRow})"],
    ["คะแนนถ่วงน้ำหนัก Competency", "=SUMIF(A{$firstKpiRow}:A{$lastKpiRow},\"Competency\",I{$firstKpiRow}:I{$lastKpiRow})"],
    ["คะแนนรวมทั้งหมด (เต็ม " . MAX_SCORE . ")", "=I{$totalRow}"],
    ["น้ำหนักรวม", "=H{$totalRow}"],
    ["เปอร์เซ็นต์ผลการปฏิบัติงาน", "=I{$totalRow}/" . MAX_SCORE]
], null, "A" . $summaryRow);

$summaryLast = $summaryRow + 5;

$sheet->getStyle("A{$summaryRow}:B{$summaryRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$summaryRow}:B{$summaryRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB("E9EEF9");
$sheet->getStyle("A{$summaryRow}:B{$summaryLast}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB("CBD3E1");
$sheet->getStyle("B" . ($summaryRow + 1) . ":B" . ($summaryRow + 3))->getNumberFormat()->setFormatCode("0.00");
$sheet->getStyle("B" . ($summaryRow + 4) . ":B{$summaryLast}")->getNumberFormat()->setFormatCode("0.00%");
$sheet->getStyle("A" . ($summaryRow + 3) . ":B" . ($summaryRow + 3))->getFont()->setBold(true);
$sheet->getStyle("B" . ($summaryRow + 1) . ":B{$summaryLast}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet->setCellValue(
    "A" . ($summaryLast + 2),
    "วิธีคำนวณ: คะแนน (เต็ม " . MAX_SCORE . ") × น้ำหนัก ของแต่ละ KPI แล้วรวมทุกข้อ · KPI ที่ยังไม่ประเมินนับเป็น 0"
);
$sheet->getStyle("A" . ($summaryLast + 2))->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB("667085");

$widths = [18, 20, 34, 12, 10, 12, 14, 12, 16, 18];
foreach ($widths as $index => $width) {
    $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
}
$sheet->freezePane("A" . $firstKpiRow);

$filename = "performance-report-" . $employee["employee_code"] . "-" . $year . "-" . $month . ".xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
