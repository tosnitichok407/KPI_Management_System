<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../includes/pdf-helper.php";
require_once __DIR__ . "/performance-export-data.php";

set_exception_handler("thaiPdfExceptionHandler");


/*
|--------------------------------------------------------------------------
| แบบประเมินผลการปฏิบัติงาน (PDF)
|--------------------------------------------------------------------------
|
| รูปแบบเดียวกับไฟล์ Excel (แบบฟอร์มของบริษัท):
| ส่วนที่ 1 Performance / ส่วนที่ 2 Competency
| น้ำหนัก(1) | ระดับผลงาน(2) 5..1 | คะแนนเต็ม (1)x5 | ที่ทำได้ | เกรด (2) | คะแนนจริง (1)x(2)
|
*/

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

$form = buildEvaluationForm($report["kpis"]);
$fullGrade = $form["full_grade"];
$totals = $form["totals"];

$employeeName = trim($employee["first_name"] . " " . $employee["last_name"]);

$e = static fn($value): string => htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");

/* ตัวเลขแบบฟอร์ม: 30 / 4.5 (ไม่แสดง .00) */
$fmt = static fn(?float $value): string => $value === null
    ? ""
    : (floor($value) == $value ? number_format($value) : number_format($value, 2));


/*
|--------------------------------------------------------------------------
| ตาราง ส่วนที่ 1 / ส่วนที่ 2
|--------------------------------------------------------------------------
*/

/*
| ความกว้างคอลัมน์ (%): ใช้เป็นแถวกำหนดขนาด (table-layout: fixed อ่านความกว้างจากแถวแรก)
| และใช้คำนวณการตัดบรรทัดภาษาไทยในแต่ละช่อง
*/
$columns = [18, 15, 5.5, 7.6, 7.6, 7.6, 7.6, 7.6, 6, 5.5, 5, 7];
$contentWidth = 841.89 - 2 * 24 * 0.75; // A4 แนวนอน (pt) ลบขอบซ้าย-ขวา 24px
$innerWidth = static fn(int $column): float => $columns[$column] / 100 * $contentWidth - 9; // ลบ padding + เส้นขอบ

$sizerRow = "<tr class='sizer'>"
    . implode("", array_map(static fn($width): string => "<td style='width:{$width}%'></td>", $columns))
    . "</tr>";

$pdf = createThaiPdf();

$sectionsHtml = "";

foreach ($form["sections"] as $sectionIndex => $section) {

    $rows = "";

    foreach ($section["rows"] as $index => $row) {

        $kpi = $row["kpi"];
        $levels = "";

        for ($level = 5; $level >= 1; $level--) {
            $text = $kpi["criteria"][$level] ?? "";
            $levels .= $text === ""
                ? "<td class='level empty'></td>"
                : "<td class='level'>" . thaiPdfWrap($pdf, $text, $innerWidth(3), 11.5) . "</td>";
        }

        $rows .= "<tr>"
            . "<td class='topic'>" . thaiPdfWrap($pdf, ($index + 1) . "." . $kpi["kpi_name"], $innerWidth(0), 14) . "</td>"
            . "<td class='indicator'>" . thaiPdfWrap($pdf, (string) ($kpi["description"] ?? ""), $innerWidth(1), 12.5) . "</td>"
            . "<td class='c weight'>" . $fmt($row["weight"]) . "</td>"
            . $levels
            . "<td class='c'>" . $fmt($row["full"]) . "</td>"
            . "<td class='c'>" . $e(evaluationActualLabel($kpi)) . "</td>"
            . "<td class='c'>" . $fmt($row["grade"]) . "</td>"
            . "<td class='c b'>" . $fmt($row["real"]) . "</td>"
            . "</tr>";
    }

    if ($rows === "") {
        $rows = "<tr><td colspan='12' class='c muted'>ไม่มี KPI ในส่วนนี้</td></tr>";
    }

    // ส่วนที่ 2 ขึ้นหน้าใหม่ / หัวตาราง (thead) พิมพ์ซ้ำทุกหน้าที่ตารางต่อไป
    $sectionsHtml .= "<table class='form" . ($sectionIndex > 0 ? " new-page" : "") . "'><thead>{$sizerRow}"
        . "<tr class='section'><td colspan='12'>" . $e($section["title"]) . "</td></tr>"
        . "<tr class='month'><td colspan='9'></td><td colspan='3' class='c'>" . $e($period["month_name"]) . "</td></tr>"
        . "<tr class='head'>"
            . "<th rowspan='2'>หัวข้อการประเมินผลงาน</th>"
            . "<th rowspan='2'>ตัวชี้วัดผลงาน</th>"
            . "<th rowspan='2'>น้ำหนัก(1)</th>"
            . "<th colspan='5'>ระดับผลงาน(2)</th>"
            . "<th rowspan='2'>คะแนนเต็ม<br>(1)x{$fullGrade}</th>"
            . "<th rowspan='2'>ที่ทำได้</th>"
            . "<th rowspan='2'>เกรด (2)</th>"
            . "<th rowspan='2'>คะแนนจริง<br>(1)x(2)</th>"
        . "</tr>"
        . "<tr class='head levels'><th>5</th><th>4</th><th>3</th><th>2</th><th>1</th></tr>"
        . "</thead><tbody>"
        . $rows
        . "<tr class='total'>"
            . "<td colspan='2' class='r shade'>" . $e($section["total_label"]) . "</td>"
            . "<td class='c'>" . $fmt($section["weight"]) . "</td>"
            . "<td colspan='5' class='empty'></td>"
            . "<td class='c'>" . $fmt($section["full"]) . "</td>"
            . "<td></td><td></td>"
            . "<td class='c'>" . $fmt($section["actual"]) . "</td>"
        . "</tr>"
        . "</tbody></table>";
}


/*
|--------------------------------------------------------------------------
| สรุปผล + ลงชื่อ
|--------------------------------------------------------------------------
*/

$summaryRows = "";

foreach ($form["sections"] as $section) {
    $summaryRows .= "<tr>"
        . "<td>" . $e($section["summary_label"]) . "</td>"
        . "<td class='c'>" . $fmt($section["weight"]) . "</td>"
        . "<td class='c'>" . $fmt($section["full"]) . "</td>"
        . "<td class='c'>" . $fmt($section["actual"]) . "</td>"
        . "</tr>";
}

$percentText = $totals["percent"] === null ? "-" : number_format($totals["percent"], 2) . "%";


/*
|--------------------------------------------------------------------------
| สรุปผลการประเมิน (เกณฑ์ตัดเกรด) + ลงชื่อ + สรุปผลรายเดือนทั้งปี
|--------------------------------------------------------------------------
*/

$yearScores = loadEmployeeYearScores($pdo, $auth["employee_id"], $year, (int) $fullGrade);
$monthGrade = $yearScores[$month]["grade"] ?? null;

/* ตารางเกณฑ์: ไฮไลต์แถวของเกรดที่ได้ในเดือนนี้ */
$scaleWidth = $contentWidth * 0.5 * 0.58 - 18;
$scaleRows = "";

foreach (evaluationGradeScale() as $scale) {
    $scaleRows .= "<tr" . ($scale["grade"] === $monthGrade ? " class='current'" : "") . ">"
        . "<td class='c'>" . $e($scale["range"]) . "</td>"
        . "<td class='c b'>" . $e($scale["grade"]) . "</td>"
        . "<td>" . thaiPdfWrap($pdf, $scale["description"], $scaleWidth, 14) . "</td>"
        . "</tr>";
}

/* ลงชื่อ: ลงชื่อ / ( ชื่อ ) / ตำแหน่ง / วันที่ */
$signBlock = static function (string $title, string $name, string $position) use ($e): string {
    $nameText = $name === "" ? str_repeat("&nbsp;", 56) : "&nbsp;" . $e($name) . "&nbsp;";

    return "<table class='sign-lines'>"
        . "<tr><td class='lbl'>" . $e($title) . "</td><td class='fill sign-space'></td></tr>"
        . "<tr><td></td><td class='c'>( {$nameText} )</td></tr>"
        . "<tr><td class='lbl'>ตำแหน่ง :</td><td class='fill'>" . $e($position) . "</td></tr>"
        . "<tr><td class='lbl'>วันที่ :</td><td class='fill'></td></tr>"
        . "</table>";
};

/* ตารางรายเดือน: เดือนที่ยังไม่มีผลประเมินแสดง - */
$dash = "<span class='muted'>-</span>";
$monthCells = ["head" => "", "full" => "", "actual" => "", "percent" => "", "grade" => ""];

foreach (evaluationShortMonths() as $number => $short) {

    $data = $yearScores[$number];
    $current = $number === $month ? " current" : "";

    $monthCells["head"] .= "<th class='m{$current}'>" . $e($short) . "</th>";
    $monthCells["full"] .= "<td class='v{$current}'>" . ($data["full"] === null ? $dash : $fmt($data["full"])) . "</td>";
    $monthCells["actual"] .= "<td class='v{$current}'>" . ($data["actual"] === null ? $dash : $fmt($data["actual"])) . "</td>";
    $monthCells["percent"] .= "<td class='v{$current}'>" . ($data["percent"] === null ? $dash : number_format($data["percent"]) . "%") . "</td>";
    $monthCells["grade"] .= "<td class='v{$current}'>" . ($data["grade"] === null ? $dash : $e($data["grade"])) . "</td>";
}


/*
|--------------------------------------------------------------------------
| HTML → PDF
|--------------------------------------------------------------------------
*/

$fontCss = thaiPdfFontCss();

$html = "<!doctype html><html lang='th'><head><meta charset='UTF-8'><style>
{$fontCss}
@page { margin: 22px 24px 42px; }
body { font-family: 'Sarabun', sans-serif; font-size: 14px; color: #111827; line-height: 1.15; }
table { width: 100%; border-collapse: collapse; }
h1 { margin: 0 0 6px; font-size: 22px; text-align: center; }

.band td { padding: 2px 6px; border: 1px solid #808080; background: #F4B084; text-align: center; }
.band .label td { font-size: 12px; font-weight: bold; }
.band .value td { font-size: 16px; font-weight: bold; }

.form { margin-top: 10px; table-layout: fixed; }
.form td, .form th { padding: 3px 4px; border: 1px solid #808080; vertical-align: middle; }
.form tr { page-break-inside: avoid; }
.form tr.sizer td { padding: 0; border: 0; height: 0; font-size: 0; line-height: 0; }
.form tr.section td { background: #D9D9D9; font-size: 16px; font-weight: bold; }
.form tr.month td { background: #FFFF00; font-size: 15px; font-weight: bold; }
.form th { background: #fff; font-size: 14px; font-weight: bold; text-align: center; }
.form tr.levels th { font-size: 12px; }
.form td.topic { font-size: 14px; }
.form td.indicator { font-size: 12.5px; text-align: center; }
.form td.level { font-size: 11.5px; text-align: center; }
.form td.weight { font-size: 15px; font-weight: bold; }
.form td.empty { background: #D9D9D9; }
.form tr.total td { font-size: 15px; font-weight: bold; }
.form td.shade { background: #D9D9D9; }

.bottom { margin-top: 14px; page-break-inside: avoid; }
.bottom > tbody > tr > td { vertical-align: top; }
.summary td, .summary th { padding: 3px 8px; border: 1px solid #808080; }
.summary th { background: #D9D9D9; font-size: 14px; }
.summary .grand td { background: #FFFF00; font-weight: bold; font-size: 15px; }
.summary .percent td { font-weight: bold; font-size: 15px; }

.summary .grade td { font-weight: bold; font-size: 16px; }
.summary .grade td.c { color: #C00000; }

.scale td, .scale th { padding: 3px 8px; border: 1px solid #808080; font-size: 14px; }
.scale th { background: #D9D9D9; }
.scale th.title { text-align: left; font-size: 15px; }
.scale tr.current td { background: #FFFF00; font-weight: bold; }

.signs { margin-top: 14px; border: 1.5px solid #111827; page-break-inside: avoid; }
.signs td.half { width: 50%; padding: 6px 18px 10px; vertical-align: top; }
.signs td.half.right { border-left: 1px solid #808080; }
.sign-lines td { padding: 3px 4px; font-size: 15px; }
.sign-lines td.lbl { width: 32%; text-align: right; font-weight: bold; }
.sign-lines td.fill { border-bottom: 1px dotted #111827; text-align: center; }
.sign-lines td.sign-space { height: 26px; }

.months { margin-top: 14px; table-layout: fixed; page-break-inside: avoid; }
.months th, .months td { padding: 4px 2px; border: 1px solid #808080; text-align: center; font-size: 15px; }
.months th { background: #FFF2CC; font-weight: bold; }
.months td { background: #E2EFDA; }
.months td.v { color: #0070C0; }
.months .label { font-weight: bold; }
.months th.current { background: #FFFF00; }
.months tr.grade td { font-weight: bold; }
.months-title { margin-top: 14px; font-size: 16px; font-weight: bold; }
/* หัวข้อ + ตารางรายเดือนอยู่หน้าเดียวกันเสมอ */
.months-block { page-break-inside: avoid; }

/* ไม่ใช้ตัวเอียง: ไม่มีฟอนต์ไทยแบบเอียง Dompdf จะแสดงเป็น ??? */
.note { margin-top: 8px; color: #667085; font-size: 12px; }
.form.new-page { page-break-before: always; margin-top: 0; }
.c { text-align: center; }
.r { text-align: right; }
.b { font-weight: bold; }
.muted { color: #888; }
</style></head><body>

<h1>แบบประเมินผลการปฏิบัติงาน (KPI) · ประจำเดือน" . $e($period["month_name"]) . " " . $year . "</h1>

<table class='band'>
    <tr class='label'><td>ตำแหน่ง</td><td>แผนก</td><td>รหัสพนักงาน</td><td>ชื่อ-นามสกุล</td></tr>
    <tr class='value'>
        <td>" . $e($employee["position_name"] ?? "-") . "</td>
        <td>" . $e($employee["department_name"] ?? "-") . "</td>
        <td>" . $e($employee["employee_code"]) . "</td>
        <td>" . $e($employeeName) . "</td>
    </tr>
</table>

{$sectionsHtml}

<table class='bottom'><tr>
    <td style='width:50%; padding-right:14px'>
        <table class='summary'>
            <tr><th style='text-align:left'>สรุปคะแนนประจำเดือน" . $e($period["month_name"]) . "</th><th>น้ำหนัก</th><th>คะแนนเต็ม</th><th>คะแนนที่ได้</th></tr>
            {$summaryRows}
            <tr class='grand'>
                <td>รวมคะแนนทั้งหมด</td>
                <td class='c'>" . $fmt($totals["weight"]) . "</td>
                <td class='c'>" . $fmt($totals["full"]) . "</td>
                <td class='c'>" . $fmt($totals["actual"]) . "</td>
            </tr>
            <tr class='percent'><td colspan='3' class='r'>คิดเป็นร้อยละ (คะแนนที่ได้ ÷ คะแนนเต็ม)</td><td class='c'>{$percentText}</td></tr>
            <tr class='grade'><td colspan='3' class='r'>เกรดของผลงาน (ตามเกณฑ์สรุปผลการประเมิน)</td><td class='c'>" . $e($monthGrade ?? "-") . "</td></tr>
        </table>
        <div class='note'>วิธีคำนวณ: คะแนนเต็ม = น้ำหนัก × {$fullGrade} · คะแนนจริง = น้ำหนัก × เกรด · KPI ที่ยังไม่ประเมินนับเป็น 0</div>
    </td>
    <td style='width:50%'>
        <table class='scale'>
            <tr><th colspan='3' class='title'>สรุปผลการประเมิน</th></tr>
            <tr><th style='width:26%'>ช่วงคะแนน</th><th style='width:16%'>เกรดของผลงาน</th><th>คำอธิบายผลงานประจำปี</th></tr>
            {$scaleRows}
        </table>
    </td>
</tr></table>

<table class='signs'><tr>
    <td class='half'>" . $signBlock("ลงชื่อผู้ประเมิน :", "", "") . "</td>
    <td class='half right'>" . $signBlock("ลงชื่อผู้ถูกประเมิน :", $employeeName, (string) ($employee["position_name"] ?? "")) . "</td>
</tr></table>

<div class='months-block'>
<div class='months-title'>สรุปผลรายเดือน ปี {$year}</div>
<table class='months' style='margin-top:4px'>
    <tr><th class='label' style='width:16%'>เดือน</th>" . str_replace("<th class='m", "<th style='width:7%' class='m", $monthCells["head"]) . "</tr>
    <tr><td class='label'>คะแนนเต็ม</td>{$monthCells["full"]}</tr>
    <tr><td class='label'>คะแนนที่ทำได้</td>{$monthCells["actual"]}</tr>
    <tr><td class='label'>ทำได้ (%)</td>{$monthCells["percent"]}</tr>
    <tr class='grade'><td class='label'>GRADE</td>{$monthCells["grade"]}</tr>
</table>
</div>

</body></html>";

$pdf->loadHtml($html, "UTF-8");
$pdf->setPaper("A4", "landscape");
$pdf->render();

thaiPdfPageNumbers(
    $pdf,
    "แบบประเมินผลการปฏิบัติงาน · " . $employee["employee_code"] . " " . $employeeName . " · " . $period["month_name"] . " " . $year
);

$filename = "performance-report-" . $employee["employee_code"] . "-" . $year . "-" . str_pad((string) $month, 2, "0", STR_PAD_LEFT) . ".pdf";

$pdf->stream($filename, ["Attachment" => false]);
exit;
