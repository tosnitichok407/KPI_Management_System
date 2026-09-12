<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../vendor/autoload.php";
require_once __DIR__ . "/../../includes/pdf-helper.php";
require_once __DIR__ . "/kpi-summary-data.php";

set_exception_handler("thaiPdfExceptionHandler");


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Report (filter เดียวกับหน้า Summary)
|--------------------------------------------------------------------------
*/

$filters = kpiSummaryFilters($_GET);
$report = loadKpiSummaryReport($pdo, $filters);
$totals = $report["totals"];
$hasMonth = $filters["month"] > 0;

$e = static fn($value): string => htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
$num = static fn(?float $value, int $decimals = 2): string => $value === null ? "-" : number_format($value, $decimals);


/* คำอธิบาย filter ที่ใช้ */

$filterParts = [];

if ($filters["employee_id"] > 0) {

    $stmt = $pdo->prepare("SELECT employee_code, first_name, last_name FROM employees WHERE employee_id = :id LIMIT 1");
    $stmt->execute([":id" => $filters["employee_id"]]);

    if ($employee = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $filterParts[] = "พนักงาน " . $employee["employee_code"] . " " . $employee["first_name"] . " " . $employee["last_name"];
    }
}

$filterParts[] = "ประเภท " . ($filters["kpi_type"] === "" ? "ทั้งหมด" : $filters["kpi_type"]);
$filterParts[] = "สถานะ " . ($filters["status"] === "" ? "ทั้งหมด" : $filters["status"]);

$periodNote = "";

if ($hasMonth) {
    $periodNote = $report["period"]
        ? "รอบประเมิน " . $report["period"]["period_name"] . " " . $filters["year"]
            . " · " . date("d/m/Y", strtotime($report["period"]["start_date"]))
            . " - " . date("d/m/Y", strtotime($report["period"]["end_date"]))
            . " · " . $report["period"]["status"]
        : "ยังไม่ได้สร้างรอบประเมินของเดือนนี้";
}


/*
|--------------------------------------------------------------------------
| ตารางสรุปรายพนักงาน
|--------------------------------------------------------------------------
*/

$summaryRows = "";
$no = 1;

foreach ($report["employees"] as $summary) {

    $weightClass = summaryWeightClass($summary["total_weight"]);

    $summaryRows .= "<tr>"
        . "<td class='center muted'>" . $no++ . "</td>"
        . "<td><b>" . $e($summary["first_name"] . " " . $summary["last_name"]) . "</b><br><span class='small'>" . $e($summary["employee_code"]) . "</span></td>"
        . "<td>" . $e($summary["department_name"] ?? "ไม่ระบุแผนก") . "</td>"
        . "<td class='num'>" . $summary["count"]["Performance"] . " KPI<br><span class='small'>" . number_format($summary["weight"]["Performance"]) . "%</span></td>"
        . "<td class='num'>" . $summary["count"]["Competency"] . " KPI<br><span class='small'>" . number_format($summary["weight"]["Competency"]) . "%</span></td>"
        . "<td class='num {$weightClass}'><b>" . number_format($summary["total_weight"]) . "%</b></td>"
        . "<td class='num'>" . $summary["evaluated"] . " / " . $summary["kpi_total"] . "</td>"
        . "<td class='num'>" . ($summary["avg_score"] === null ? "<span class='muted'>ยังไม่ประเมิน</span>" : "<b>" . $num($summary["avg_score"]) . "</b> / 5") . "</td>"
        . "<td class='num'>" . ($summary["weighted_percent"] === null ? "-" : $num($summary["weighted_percent"], 1) . "%") . "</td>"
        . "</tr>";
}

if ($summaryRows === "") {
    $summaryRows = "<tr><td colspan='9' class='center muted empty'>ไม่พบ KPI ตามเงื่อนไขที่เลือก</td></tr>";
}


/*
|--------------------------------------------------------------------------
| ตารางรายละเอียด KPI (จัดกลุ่มตามพนักงาน)
|--------------------------------------------------------------------------
*/

$detailRows = "";

foreach ($report["employees"] as $summary) {

    $detailRows .= "<tr class='group'><td colspan='8'>"
        . $e($summary["first_name"] . " " . $summary["last_name"])
        . " <span class='group-meta'>· " . $e($summary["employee_code"])
        . " · " . $e($summary["department_name"] ?? "ไม่ระบุแผนก")
        . " · " . $summary["kpi_total"] . " KPI"
        . " · ประเมินแล้ว " . $summary["evaluated"]
        . " · Weight รวม " . number_format($summary["total_weight"]) . "%</span>"
        . "</td></tr>";

    foreach ($summary["kpis"] as $index => $kpi) {

        if ($kpi["avg_score"] === null) {
            $result = "<span class='muted'>ยังไม่ประเมิน</span>";
        } elseif ($hasMonth) {
            $result = "<b>" . $num((float) $kpi["avg_score"]) . "</b> / " . number_format((float) $kpi["max_score"])
                . "<br><span class='small'>"
                . ($kpi["last_actual"] !== null ? "Actual " . number_format((float) $kpi["last_actual"]) . " · " : "")
                . $e($kpi["last_status"]) . "</span>";
        } else {
            $result = "<b>" . $num((float) $kpi["avg_score"]) . "</b> / " . number_format((float) $kpi["max_score"]) . " เฉลี่ย"
                . "<br><span class='small'>ประเมินแล้ว " . (int) $kpi["eval_count"] . " เดือน</span>";
        }

        $typeClass = strtolower($kpi["kpi_type"]) === "performance" ? "type-p" : "type-c";

        $detailRows .= "<tr" . ($kpi["status"] === "Active" ? "" : " class='inactive'") . ">"
            . "<td class='center muted'>" . ($index + 1) . "</td>"
            . "<td>" . $e($kpi["kpi_name"]) . "<br><span class='small'>หน่วย: " . $e($kpi["unit"] ?? "-") . "</span></td>"
            . "<td class='{$typeClass}'>" . $e($kpi["kpi_type"]) . "</td>"
            . "<td class='num'>" . number_format((float) $kpi["target_value"]) . "%</td>"
            . "<td class='num'><b>" . number_format((float) $kpi["weight"]) . "%</b></td>"
            . "<td>" . $e($kpi["period_label"]) . " " . (int) $kpi["assignment_year"] . " (" . $e($kpi["quarter_label"]) . ")"
                . "<br><span class='small'>" . date("d/m/Y", strtotime($kpi["start_date"])) . " – " . date("d/m/Y", strtotime($kpi["end_date"])) . "</span></td>"
            . "<td>" . $result . "</td>"
            . "<td>" . $e($kpi["status"]) . "</td>"
            . "</tr>";
    }
}

if ($detailRows === "") {
    $detailRows = "<tr><td colspan='8' class='center muted empty'>ไม่พบ KPI ตามเงื่อนไขที่เลือก</td></tr>";
}


/*
|--------------------------------------------------------------------------
| HTML → PDF
|--------------------------------------------------------------------------
*/

$fontCss = thaiPdfFontCss();
$printedAt = date("d/m/Y H:i");

$html = "<!doctype html><html lang='th'><head><meta charset='UTF-8'><style>
{$fontCss}
@page { margin: 26px 28px 44px; }
body { font-family: 'Sarabun', sans-serif; font-size: 15px; color: #1f2937; line-height: 1.2; }
h1 { margin: 0; color: #244397; font-size: 28px; }
h2 { margin: 16px 0 6px; color: #244397; font-size: 20px; }
.sub { margin: 0 0 2px; color: #475467; font-size: 17px; }
.filters { margin: 0 0 12px; color: #667085; font-size: 14px; }
.cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 4px; }
.cards td { width: 25%; padding: 7px 12px; background: #f4f6fb; border: 1px solid #e4e8f1; }
.cards .label { color: #667085; font-size: 14px; }
.cards .value { color: #244397; font-size: 26px; font-weight: bold; line-height: 1.05; }
.cards .value span { color: #98a2b3; font-size: 15px; font-weight: normal; }
.cards .note { color: #98a2b3; font-size: 13px; }
table.data { width: 100%; border-collapse: collapse; }
table.data th { padding: 5px 7px; background: #244397; color: #fff; font-weight: bold; font-size: 15px; text-align: left; }
table.data td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 15px; vertical-align: top; }
table.data tr { page-break-inside: avoid; }
table.data tr.group td { padding: 5px 7px; background: #eef2ff; border-top: 1px solid #c7d2fe; font-weight: bold; color: #1f2937; }
table.data tr.inactive td { color: #98a2b3; }
.group-meta { color: #667085; font-weight: normal; font-size: 14px; }
.num { text-align: right; white-space: nowrap; }
.center { text-align: center; }
.muted { color: #98a2b3; }
.small { color: #667085; font-size: 13px; }
.empty { padding: 16px; }
.full { color: #237a42; }
.over { color: #c62828; }
.type-p { color: #a16207; }
.type-c { color: #244397; }
/* รายละเอียด KPI เริ่มหน้าใหม่ (หน้าแรก = ภาพรวม + สรุปรายพนักงาน) */
.detail { page-break-before: always; margin-top: 0; }
</style></head><body>

<h1>รายงานสรุปผล KPI</h1>
<p class='sub'>" . $e($report["label"]) . ($periodNote !== "" ? " · " . $e($periodNote) : "") . "</p>
<p class='filters'>" . $e(implode(" · ", $filterParts)) . " · พิมพ์เมื่อ " . $printedAt . "</p>

<table class='cards'><tr>
    <td><div class='label'>พนักงาน</div><div class='value'>" . $totals["employees"] . "</div><div class='note'>ที่ได้รับมอบหมาย KPI</div></td>
    <td><div class='label'>KPI ทั้งหมด</div><div class='value'>" . $totals["kpi"] . "</div><div class='note'>Performance " . $totals["performance"] . " · Competency " . $totals["competency"] . "</div></td>
    <td><div class='label'>ประเมินแล้ว</div><div class='value'>" . $totals["evaluated"] . " <span>/ " . $totals["kpi"] . " KPI</span></div><div class='note'>" . ($hasMonth ? "ในเดือนที่เลือก" : "อย่างน้อย 1 เดือนในปีนี้") . "</div></td>
    <td><div class='label'>คะแนนเฉลี่ย</div><div class='value'>" . ($totals["avg_score"] === null ? "-" : $num($totals["avg_score"]) . " <span>/ 5</span>") . "</div><div class='note'>เฉลี่ยจาก KPI ที่ประเมินแล้ว</div></td>
</tr></table>

<h2>สรุปรายพนักงาน</h2>
<table class='data'>
    <thead><tr>
        <th style='width:26px' class='center'>#</th><th>พนักงาน</th><th>แผนก</th>
        <th class='num'>Performance</th><th class='num'>Competency</th><th class='num'>Weight รวม</th>
        <th class='num'>ประเมินแล้ว</th><th class='num'>คะแนนเฉลี่ย</th><th class='num'>ถ่วงน้ำหนัก</th>
    </tr></thead>
    <tbody>{$summaryRows}</tbody>
</table>

<h2 class='detail'>รายละเอียด KPI</h2>
<table class='data'>
    <thead><tr>
        <th style='width:26px' class='center'>#</th><th>KPI</th><th>ประเภท</th>
        <th class='num'>Target</th><th class='num'>Weight</th><th>ช่วงเวลาที่มีผล</th><th>ผลประเมิน</th><th>สถานะ</th>
    </tr></thead>
    <tbody>{$detailRows}</tbody>
</table>

</body></html>";

$pdf = createThaiPdf();
$pdf->loadHtml($html, "UTF-8");
$pdf->setPaper("A4", "landscape");
$pdf->render();

thaiPdfPageNumbers($pdf, "KPI Management System · รายงานสรุปผล KPI · " . $report["label"]);

$filename = "kpi-summary-" . $filters["year"] . ($hasMonth ? "-" . str_pad((string) $filters["month"], 2, "0", STR_PAD_LEFT) : "") . ".pdf";

$pdf->stream($filename, ["Attachment" => false]);
exit;
