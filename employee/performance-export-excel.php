<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/performance-export-data.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

$employeeName = trim($employee["first_name"] . " " . $employee["last_name"]);

/* คะแนนรายเดือนทั้งปี (ตารางสรุปท้ายแบบฟอร์ม) */
$yearScores = loadEmployeeYearScores($pdo, $auth["employee_id"], $year);


/*
|--------------------------------------------------------------------------
| แบบฟอร์มประเมิน KPI (ตามแบบฟอร์มของบริษัท)
|--------------------------------------------------------------------------
|
| A หัวข้อการประเมินผลงาน | B ตัวชี้วัดผลงาน | C น้ำหนัก(1)
| D-H ระดับผลงาน(2) 5..1 | I คะแนนเต็ม (1)x5
| J-L (เดือน): ที่ทำได้ | เกรด (2) | คะแนนจริง (1)x(2)
|
| คะแนนเต็ม = น้ำหนัก × 5, คะแนนจริง = น้ำหนัก × เกรด (สูตร Excel)
|
*/

const FULL_GRADE = 5;

const COLOR_EMPLOYEE_BAND = "F4B084";
const COLOR_SECTION = "D9D9D9";
const COLOR_MONTH_BAND = "FFFF00";
const COLOR_EMPTY_LEVEL = "D9D9D9";
const COLOR_BORDER = "808080";
const COLOR_MONTH_HEAD = "FFF2CC";
const COLOR_MONTH_BODY = "E2EFDA";
const COLOR_MONTH_VALUE = "0070C0";


/*
| สูตรตัดเกรดใน Excel จากช่อง "ทำได้ (%)" (ค่า 0-1) เทียบฐาน 500 คะแนน
| ตรงกับ evaluationGradeScale(): 451+ A+ · 400+ A · 300+ B · 200+ C · ต่ำกว่า D
*/
function excelGradeFormula(string $percentCell): string
{
    $score = "ROUND({$percentCell}*500,6)";

    return "=IF({$percentCell}=\"\",\"\","
        . "IF({$score}>=451,\"A+\","
        . "IF({$score}>=400,\"A\","
        . "IF({$score}>=300,\"B\","
        . "IF({$score}>=200,\"C\",\"D\")))))";
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName("Tahoma")->setSize(10);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("แบบประเมิน KPI");
$sheet->setShowGridlines(false);

$thinBorder = ["allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => COLOR_BORDER]]];


/*
|--------------------------------------------------------------------------
| Title + Employee Band
|--------------------------------------------------------------------------
*/

$sheet->mergeCells("A1:L1");
$sheet->setCellValue("A1", "แบบประเมินผลการปฏิบัติงาน (KPI) · ประจำเดือน" . $period["month_name"] . " " . $year);
$sheet->getStyle("A1")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(26);

$employeeBand = [
    2 => ["ตำแหน่ง", "แผนก", "รหัสพนักงาน", "ชื่อ-นามสกุล"],
    3 => [
        $employee["position_name"] ?? "-",
        $employee["department_name"] ?? "-",
        $employee["employee_code"],
        $employee["first_name"] . " " . $employee["last_name"]
    ]
];

foreach ($employeeBand as $bandRow => $values) {
    $sheet->setCellValue("A{$bandRow}", $values[0]);
    $sheet->setCellValue("B{$bandRow}", $values[1]);
    $sheet->mergeCells("C{$bandRow}:D{$bandRow}");
    $sheet->setCellValue("C{$bandRow}", $values[2]);
    $sheet->mergeCells("E{$bandRow}:L{$bandRow}");
    $sheet->setCellValue("E{$bandRow}", $values[3]);
}

$sheet->getStyle("A2:L3")->applyFromArray([
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => COLOR_EMPLOYEE_BAND]],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => $thinBorder
]);
$sheet->getStyle("A2:L2")->getFont()->setBold(true)->setSize(9);
$sheet->getStyle("A3:L3")->getFont()->setBold(true)->setSize(11);


/*
|--------------------------------------------------------------------------
| Section Renderer
|--------------------------------------------------------------------------
|
| คืนค่า [แถวถัดไป, ช่องน้ำหนักรวม, ช่องคะแนนเต็มรวม, ช่องคะแนนจริงรวม]
|
*/

function renderKpiSection(Worksheet $sheet, int $row, string $title, string $totalLabel, array $kpis, string $monthName, array $thinBorder): array
{
    /* หัวส่วน */

    $sheet->mergeCells("A{$row}:L{$row}");
    $sheet->setCellValue("A{$row}", $title);
    $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
        "font" => ["bold" => true, "size" => 11],
        "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => COLOR_SECTION]],
        "borders" => $thinBorder
    ]);
    $row++;


    /* แถบเหลือง + ชื่อเดือน */

    $sheet->mergeCells("J{$row}:L{$row}");
    $sheet->setCellValue("J{$row}", $monthName);
    $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
        "font" => ["bold" => true, "size" => 11],
        "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => COLOR_MONTH_BAND]],
        "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER],
        "borders" => $thinBorder
    ]);
    $row++;


    /* หัวตาราง 2 แถว */

    $headRow = $row;
    $levelRow = $row + 1;

    foreach ([
        "A" => "หัวข้อการประเมินผลงาน",
        "B" => "ตัวชี้วัดผลงาน",
        "C" => "น้ำหนัก(1)",
        "I" => "คะแนนเต็ม\n(1)x" . FULL_GRADE,
        "J" => "ที่ทำได้",
        "K" => "เกรด (2)",
        "L" => "คะแนนจริง\n(1)x(2)"
    ] as $column => $label) {
        $sheet->mergeCells("{$column}{$headRow}:{$column}{$levelRow}");
        $sheet->setCellValue("{$column}{$headRow}", $label);
    }

    $sheet->mergeCells("D{$headRow}:H{$headRow}");
    $sheet->setCellValue("D{$headRow}", "ระดับผลงาน(2)");
    $sheet->fromArray([5, 4, 3, 2, 1], null, "D{$levelRow}");

    $sheet->getStyle("A{$headRow}:L{$levelRow}")->applyFromArray([
        "font" => ["bold" => true, "size" => 11],
        "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER, "wrapText" => true],
        "borders" => $thinBorder
    ]);
    $sheet->getStyle("D{$levelRow}:H{$levelRow}")->getFont()->setSize(9);
    $sheet->getRowDimension($headRow)->setRowHeight(22);
    $sheet->getRowDimension($levelRow)->setRowHeight(22);

    $row = $levelRow + 1;
    $firstRow = $row;


    /* แถว KPI */

    if (empty($kpis)) {

        $sheet->mergeCells("A{$row}:L{$row}");
        $sheet->setCellValue("A{$row}", "ไม่มี KPI ในส่วนนี้");
        $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
            "font" => ["italic" => true, "color" => ["rgb" => "808080"]],
            "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER],
            "borders" => $thinBorder
        ]);
        $row++;
    }

    foreach (array_values($kpis) as $index => $kpi) {

        $sheet->setCellValue("A{$row}", ($index + 1) . "." . $kpi["kpi_name"]);
        $sheet->setCellValue("B{$row}", $kpi["description"] ?? "");
        $sheet->setCellValue("C{$row}", (float) $kpi["weight_value"]);

        foreach ([5 => "D", 4 => "E", 3 => "F", 2 => "G", 1 => "H"] as $level => $column) {
            $text = $kpi["criteria"][$level] ?? "";

            if ($text === "") {
                $sheet->getStyle("{$column}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(COLOR_EMPTY_LEVEL);
            } else {
                $sheet->setCellValueExplicit("{$column}{$row}", $text, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        /* คะแนนเต็ม = น้ำหนัก × 5 */
        $sheet->setCellValue("I{$row}", "=C{$row}*" . FULL_GRADE);

        /* ที่ทำได้ (แสดงเป็น % ถ้าหน่วยเป็น %) */
        if ($kpi["actual_value"] !== null) {
            $actual = (float) $kpi["actual_value"];

            if (trim((string) $kpi["unit"]) === "%") {
                $sheet->setCellValue("J{$row}", $actual / 100);
                $sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode(floor($actual) == $actual ? "0%" : "0.00%");
            } else {
                $sheet->setCellValue("J{$row}", $actual);
            }
        }

        /* เกรด (2) */
        if ($kpi["score_value"] !== null) {
            $sheet->setCellValue("K{$row}", (float) $kpi["score_value"]);
        }

        /* คะแนนจริง = น้ำหนัก × เกรด */
        $sheet->setCellValue("L{$row}", "=IF(K{$row}=\"\",\"\",C{$row}*K{$row})");

        $row++;
    }

    $lastRow = $row - 1;

    $sheet->getStyle("A{$firstRow}:L{$lastRow}")->applyFromArray([
        "alignment" => ["vertical" => Alignment::VERTICAL_CENTER, "wrapText" => true],
        "borders" => $thinBorder
    ]);
    $sheet->getStyle("B{$firstRow}:L{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("D{$firstRow}:H{$lastRow}")->getFont()->setSize(9);
    $sheet->getStyle("C{$firstRow}:C{$lastRow}")->getFont()->setBold(true)->setSize(11);


    /* รวมคะแนนส่วนนี้ */

    $totalRow = $row;

    $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
    $sheet->setCellValue("A{$totalRow}", $totalLabel);

    if (empty($kpis)) {
        $sheet->setCellValue("C{$totalRow}", 0);
        $sheet->setCellValue("I{$totalRow}", 0);
        $sheet->setCellValue("L{$totalRow}", 0);
    } else {
        $sheet->setCellValue("C{$totalRow}", "=SUM(C{$firstRow}:C{$lastRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUM(I{$firstRow}:I{$lastRow})");
        $sheet->setCellValue("L{$totalRow}", "=SUM(L{$firstRow}:L{$lastRow})");
    }

    $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
        "font" => ["bold" => true, "size" => 11],
        "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
        "borders" => $thinBorder
    ]);
    $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    foreach (["A{$totalRow}", "D{$totalRow}:H{$totalRow}"] as $range) {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(COLOR_SECTION);
    }
    $sheet->getRowDimension($totalRow)->setRowHeight(22);

    return [$totalRow + 1, "C{$totalRow}", "I{$totalRow}", "L{$totalRow}"];
}


/*
|--------------------------------------------------------------------------
| ส่วนที่ 1 (Performance) / ส่วนที่ 2 (Competency)
|--------------------------------------------------------------------------
*/

$performanceKpis = array_filter($report["kpis"], fn($kpi) => $kpi["kpi_type"] === "Performance");
$competencyKpis = array_filter($report["kpis"], fn($kpi) => $kpi["kpi_type"] === "Competency");

[$nextRow, $part1Weight, $part1Full, $part1Actual] = renderKpiSection(
    $sheet,
    5,
    "ส่วนที่ 1 : การประเมินผลงานที่สามารถวัดเป็นตัวเลขได้",
    "รวมคะแนนส่วนที่ 1",
    $performanceKpis,
    $period["month_name"],
    $thinBorder
);

[$nextRow, $part2Weight, $part2Full, $part2Actual] = renderKpiSection(
    $sheet,
    $nextRow + 1,
    "ส่วนที่ 2 : การประเมินพฤติกรรมและสมรรถนะ (Competency)",
    "รวมคะแนนส่วนที่ 2",
    $competencyKpis,
    $period["month_name"],
    $thinBorder
);


/*
|--------------------------------------------------------------------------
| สรุปผลการประเมิน
|--------------------------------------------------------------------------
*/

$row = $nextRow + 1;
$summaryHead = $row;

$sheet->mergeCells("A{$row}:B{$row}");
$sheet->setCellValue("A{$row}", "สรุปคะแนนประจำเดือน" . $period["month_name"]);
$sheet->setCellValue("C{$row}", "น้ำหนัก");
$sheet->mergeCells("D{$row}:H{$row}");
$sheet->setCellValue("I{$row}", "คะแนนเต็ม");
$sheet->mergeCells("J{$row}:K{$row}");
$sheet->setCellValue("L{$row}", "คะแนนที่ได้");
$row++;

$summaryLines = [
    ["ส่วนที่ 1 : ผลงานที่วัดเป็นตัวเลขได้", "={$part1Weight}", "={$part1Full}", "={$part1Actual}"],
    ["ส่วนที่ 2 : พฤติกรรมและสมรรถนะ", "={$part2Weight}", "={$part2Full}", "={$part2Actual}"]
];

$firstSummaryRow = $row;

foreach ($summaryLines as [$label, $weight, $full, $actual]) {
    $sheet->mergeCells("A{$row}:B{$row}");
    $sheet->setCellValue("A{$row}", $label);
    $sheet->setCellValue("C{$row}", $weight);
    $sheet->mergeCells("D{$row}:H{$row}");
    $sheet->setCellValue("I{$row}", $full);
    $sheet->mergeCells("J{$row}:K{$row}");
    $sheet->setCellValue("L{$row}", $actual);
    $row++;
}

$grandRow = $row;
$sheet->mergeCells("A{$grandRow}:B{$grandRow}");
$sheet->setCellValue("A{$grandRow}", "รวมคะแนนทั้งหมด");
$sheet->setCellValue("C{$grandRow}", "=SUM(C{$firstSummaryRow}:C" . ($grandRow - 1) . ")");
$sheet->mergeCells("D{$grandRow}:H{$grandRow}");
$sheet->setCellValue("I{$grandRow}", "=SUM(I{$firstSummaryRow}:I" . ($grandRow - 1) . ")");
$sheet->mergeCells("J{$grandRow}:K{$grandRow}");
$sheet->setCellValue("L{$grandRow}", "=SUM(L{$firstSummaryRow}:L" . ($grandRow - 1) . ")");
$row++;

$percentRow = $row;
$sheet->mergeCells("A{$percentRow}:K{$percentRow}");
$sheet->setCellValue("A{$percentRow}", "คิดเป็นร้อยละ (คะแนนที่ได้ ÷ คะแนนเต็ม)");
$sheet->setCellValue("L{$percentRow}", "=IF(I{$grandRow}=0,\"\",L{$grandRow}/I{$grandRow})");
$sheet->getStyle("L{$percentRow}")->getNumberFormat()->setFormatCode("0.00%");

$sheet->getStyle("A{$summaryHead}:L{$percentRow}")->applyFromArray([
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => $thinBorder
]);
$sheet->getStyle("A{$firstSummaryRow}:A{$percentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("A{$summaryHead}:L{$summaryHead}")->applyFromArray([
    "font" => ["bold" => true, "size" => 11],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => COLOR_SECTION]]
]);
$sheet->getStyle("A{$grandRow}:L{$percentRow}")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("A{$grandRow}:L{$grandRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(COLOR_MONTH_BAND);



/* เกรดของเดือนนี้ (สูตรตามเกณฑ์ด้านล่าง) */

$gradeRow = $percentRow + 1;

$sheet->mergeCells("A{$gradeRow}:K{$gradeRow}");
$sheet->setCellValue("A{$gradeRow}", "เกรดของผลงาน (ตามเกณฑ์สรุปผลการประเมิน)");
$sheet->setCellValue("L{$gradeRow}", excelGradeFormula("L{$percentRow}"));
$sheet->getStyle("A{$gradeRow}:L{$gradeRow}")->applyFromArray([
    "font" => ["bold" => true, "size" => 12],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => $thinBorder
]);
$sheet->getStyle("A{$gradeRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("L{$gradeRow}")->getFont()->getColor()->setRGB("C00000");

$sheet->setCellValue(
    "A" . ($gradeRow + 1),
    "วิธีคำนวณ: คะแนนเต็ม = น้ำหนัก × " . FULL_GRADE . " · คะแนนจริง = น้ำหนัก × เกรด · KPI ที่ยังไม่ประเมินนับเป็น 0"
);
$sheet->getStyle("A" . ($gradeRow + 1))->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB("667085");


/*
|--------------------------------------------------------------------------
| สรุปผลการประเมิน (เกณฑ์ตัดเกรด)
|--------------------------------------------------------------------------
|
| แถวที่ตรงกับเกรดของเดือนนี้ไฮไลต์อัตโนมัติ (Conditional Formatting)
|
*/

$row = $gradeRow + 3;

$sheet->setCellValue("A{$row}", "สรุปผลการประเมิน");
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;

$scaleHead = $row;
$sheet->setCellValue("A{$row}", "ช่วงคะแนน");
$sheet->setCellValue("B{$row}", "เกรดของผลงาน");
$sheet->mergeCells("C{$row}:H{$row}");
$sheet->setCellValue("C{$row}", "คำอธิบายผลงานประจำปี");
$row++;

$scaleFirst = $row;

foreach (evaluationGradeScale() as $scale) {
    $sheet->setCellValue("A{$row}", $scale["range"]);
    $sheet->setCellValue("B{$row}", $scale["grade"]);
    $sheet->mergeCells("C{$row}:H{$row}");
    $sheet->setCellValue("C{$row}", $scale["description"]);
    $row++;
}

$scaleLast = $row - 1;

$sheet->getStyle("A{$scaleHead}:H{$scaleLast}")->applyFromArray([
    "font" => ["size" => 11],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => [
        "allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => COLOR_BORDER]],
        "outline" => ["borderStyle" => Border::BORDER_MEDIUM, "color" => ["rgb" => "000000"]]
    ]
]);
$sheet->getStyle("A{$scaleHead}:H{$scaleHead}")->getFont()->setBold(true);

$highlight = new Conditional();
$highlight->setConditionType(Conditional::CONDITION_EXPRESSION);
$highlight->addCondition("\$B{$scaleFirst}=\$L\${$gradeRow}");
$highlight->getStyle()->getFont()->setBold(true);
$highlight->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
$highlight->getStyle()->getFill()->getStartColor()->setRGB(COLOR_MONTH_BAND);
$highlight->getStyle()->getFill()->getEndColor()->setRGB(COLOR_MONTH_BAND);
$sheet->getStyle("A{$scaleFirst}:H{$scaleLast}")->setConditionalStyles([$highlight]);


/*
|--------------------------------------------------------------------------
| ลงชื่อ ผู้ประเมิน / ผู้ถูกประเมิน
|--------------------------------------------------------------------------
*/

$row = $scaleLast + 2;
$signTop = $row;
$sheet->getRowDimension($row)->setRowHeight(30); // ที่ว่างสำหรับเซ็นชื่อ
$row++;

$signLines = [
    ["ลงชื่อผู้ประเมิน :", "(                                              )", "ลงชื่อผู้ถูกประเมิน :", "( " . $employeeName . " )"],
    ["ตำแหน่ง :", "", "ตำแหน่ง :", $employee["position_name"] ?? ""],
    ["วันที่ :", "", "วันที่ :", ""]
];

foreach ($signLines as [$leftLabel, $leftValue, $rightLabel, $rightValue]) {

    $sheet->setCellValue("A{$row}", $leftLabel);
    $sheet->mergeCells("B{$row}:E{$row}");
    $sheet->setCellValue("B{$row}", $leftValue);

    $sheet->mergeCells("F{$row}:G{$row}");
    $sheet->setCellValue("F{$row}", $rightLabel);
    $sheet->mergeCells("H{$row}:L{$row}");
    $sheet->setCellValue("H{$row}", $rightValue);

    $sheet->getStyle("B{$row}:E{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("H{$row}:L{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getRowDimension($row)->setRowHeight(20);

    $row++;
}

$signBottom = $row - 1;

$sheet->getStyle("A{$signTop}:L{$signBottom}")->getFont()->setSize(11);
$sheet->getStyle("A{$signTop}:A{$signBottom}")->applyFromArray([
    "font" => ["bold" => true],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_RIGHT]
]);
$sheet->getStyle("F{$signTop}:F{$signBottom}")->applyFromArray([
    "font" => ["bold" => true],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_RIGHT]
]);
$sheet->getStyle("B{$signTop}:B{$signBottom}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("H{$signTop}:H{$signBottom}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$signTop}:L{$signBottom}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM);
$sheet->getStyle("E{$signTop}:E{$signBottom}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM);


/*
|--------------------------------------------------------------------------
| สรุปผลรายเดือนทั้งปี (คะแนนเต็ม / คะแนนที่ทำได้ / ทำได้ % / GRADE)
|--------------------------------------------------------------------------
|
| เดือนที่เลือกอ้างอิงยอดรวมด้านบน (แก้เกรดในไฟล์แล้วอัปเดตตาม)
| เดือนที่ยังไม่มีผลประเมินเว้นว่าง (ไม่แสดง #VALUE!)
|
*/

$row = $signBottom + 2;

$sheet->setCellValue("A{$row}", "สรุปผลรายเดือน ปี " . $year);
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;

$monthHead = $row;
$fullRow = $row + 1;
$actualRow = $row + 2;
$percentMonthRow = $row + 3;
$gradeMonthRow = $row + 4;

/* หัวแถวใช้ A:B (กว้าง) · 12 เดือนอยู่ C-N เพื่อให้ช่องเดือนกว้างใกล้เคียงกัน */
$monthColumns = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N"];
$shortMonths = evaluationShortMonths();

foreach ([
    $monthHead => "เดือน",
    $fullRow => "คะแนนเต็ม",
    $actualRow => "คะแนนที่ทำได้",
    $percentMonthRow => "ทำได้ (%)",
    $gradeMonthRow => "GRADE"
] as $labelRow => $label) {
    $sheet->mergeCells("A{$labelRow}:B{$labelRow}");
    $sheet->setCellValue("A{$labelRow}", $label);
}

foreach ($monthColumns as $index => $column) {

    $monthNumber = $index + 1;
    $data = $yearScores[$monthNumber];

    $sheet->setCellValue("{$column}{$monthHead}", $shortMonths[$monthNumber]);

    if ($data["full"] !== null) {
        $sheet->setCellValue("{$column}{$fullRow}", $data["full"]);
    }

    if ($data["actual"] !== null) {
        $sheet->setCellValue(
            "{$column}{$actualRow}",
            $monthNumber === $month ? "=L{$grandRow}" : $data["actual"]
        );
    }

    $sheet->setCellValue(
        "{$column}{$percentMonthRow}",
        "=IF(OR({$column}{$actualRow}=\"\",{$column}{$fullRow}=\"\",{$column}{$fullRow}=0),\"\",{$column}{$actualRow}/{$column}{$fullRow})"
    );

    $sheet->setCellValue("{$column}{$gradeMonthRow}", excelGradeFormula("{$column}{$percentMonthRow}"));
}

$sheet->getStyle("A{$monthHead}:N{$gradeMonthRow}")->applyFromArray([
    "font" => ["size" => 12],
    "alignment" => ["horizontal" => Alignment::HORIZONTAL_CENTER, "vertical" => Alignment::VERTICAL_CENTER],
    "borders" => [
        "allBorders" => ["borderStyle" => Border::BORDER_THIN, "color" => ["rgb" => COLOR_BORDER]],
        "outline" => ["borderStyle" => Border::BORDER_MEDIUM, "color" => ["rgb" => "000000"]]
    ]
]);
$sheet->getStyle("A{$monthHead}:N{$monthHead}")->applyFromArray([
    "font" => ["bold" => true],
    "fill" => ["fillType" => Fill::FILL_SOLID, "startColor" => ["rgb" => COLOR_MONTH_HEAD]]
]);
$sheet->getStyle("A{$fullRow}:N{$gradeMonthRow}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(COLOR_MONTH_BODY);
$sheet->getStyle("A{$fullRow}:A{$gradeMonthRow}")->getFont()->setBold(true);
$sheet->getStyle("C{$fullRow}:N{$gradeMonthRow}")->getFont()->getColor()->setRGB(COLOR_MONTH_VALUE);
/* General: 375 / 262.5 (รูปแบบ #,##0.## ใน Excel จะแสดง "375." มีจุดท้าย) */
$sheet->getStyle("C{$fullRow}:N{$actualRow}")->getNumberFormat()->setFormatCode("General");
$sheet->getStyle("C{$percentMonthRow}:N{$percentMonthRow}")->getNumberFormat()->setFormatCode("0%");
$sheet->getStyle("C{$gradeMonthRow}:N{$gradeMonthRow}")->getFont()->setBold(true);

for ($r = $monthHead; $r <= $gradeMonthRow; $r++) {
    $sheet->getRowDimension($r)->setRowHeight(22);
}


/*
|--------------------------------------------------------------------------
| Column Widths + Print
|--------------------------------------------------------------------------
*/

/* D-H (เกณฑ์ระดับผลงาน) กว้างพอให้ข้อความ Competency ยาวๆ ไม่สูงเกินไป */
$widths = ["A" => 36, "B" => 34, "C" => 12, "D" => 18, "E" => 18, "F" => 18, "G" => 18, "H" => 18, "I" => 12, "J" => 11, "K" => 9, "L" => 12, "M" => 12, "N" => 12];
foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

$filename = "performance-report-" . $employee["employee_code"] . "-" . $year . "-" . $month . ".xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save("php://output");
exit;
