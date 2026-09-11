<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/performance-export-data.php";

use Dompdf\Dompdf;
use Dompdf\Options;

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

$escape = static fn ($value): string => htmlspecialchars((string) ($value ?? "-"), ENT_QUOTES, "UTF-8");
$number = static fn ($value, int $decimals = 2): string => $value === null ? "-" : number_format((float) $value, $decimals);

$rows = "";
foreach ($report["kpis"] as $kpi) {
    $rows .= "<tr>"
        . "<td>" . $escape($kpi["kpi_type"]) . "</td>"
        . "<td>" . $escape($kpi["category_name"]) . "</td>"
        . "<td>" . $escape($kpi["kpi_name"]) . "</td>"
        . "<td>" . $number($kpi["target_value"]) . "</td>"
        . "<td>" . $escape($kpi["unit"]) . "</td>"
        . "<td>" . $number($kpi["actual_value"]) . "</td>"
        . "<td>" . $number($kpi["score_value"]) . "</td>"
        . "<td>" . $number($kpi["weight_value"]) . "%</td>"
        . "<td>" . $escape($kpi["status_value"]) . "</td>"
        . "</tr>";
}

$html = "<!doctype html><html lang=\"th\"><head><meta charset=\"UTF-8\"><style>
@page { margin: 24px 20px; }
@font-face { font-family: ThaiFont; font-style: normal; font-weight: normal; src: url('file:///System/Library/Fonts/Supplemental/Arial%20Unicode.ttf') format('truetype'); }
body { font-family: ThaiFont, DejaVu Sans, sans-serif; color: #243047; font-size: 9px; }
h1 { color: #244397; font-size: 20px; margin: 0 0 14px; }
h2 { color: #244397; font-size: 12px; margin: 18px 0 7px; }
.info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.info td { padding: 4px 6px; border: 1px solid #d9deea; }
.info strong { color: #596579; }
table.kpi { width: 100%; border-collapse: collapse; }
table.kpi th { background: #244397; color: white; font-weight: bold; }
table.kpi th, table.kpi td { border: 1px solid #cbd3e1; padding: 4px; vertical-align: top; }
table.kpi tr:nth-child(even) { background: #f4f6fa; }
.summary { width: 100%; border-collapse: collapse; }
.summary td { border: 1px solid #d9deea; padding: 6px; }
</style></head><body>
<h1>รายงานผลการปฏิบัติงาน</h1>
<table class=\"info\"><tr><td><strong>ชื่อพนักงาน</strong><br>" . $escape($employee["first_name"] . " " . $employee["last_name"]) . "</td><td><strong>รหัสพนักงาน</strong><br>" . $escape($employee["employee_code"]) . "</td><td><strong>ตำแหน่ง</strong><br>" . $escape($employee["position_name"]) . "</td><td><strong>แผนก</strong><br>" . $escape($employee["department_name"]) . "</td></tr><tr><td colspan=\"2\"><strong>รอบประเมิน</strong><br>" . $escape($period["period_name"]) . "</td><td colspan=\"2\"><strong>วันที่</strong><br>" . $escape($period["start_date"]) . " ถึง " . $escape($period["end_date"]) . "</td></tr></table>
<h2>รายละเอียด KPI</h2><table class=\"kpi\"><thead><tr><th>ประเภท</th><th>หมวดหมู่</th><th>ตัวชี้วัด</th><th>เป้าหมาย</th><th>หน่วย</th><th>Actual</th><th>คะแนน</th><th>Weight</th><th>สถานะ</th></tr></thead><tbody>" . ($rows ?: "<tr><td colspan=\"9\">ไม่มีข้อมูล KPI</td></tr>") . "</tbody></table>
<h2>สรุปคะแนน</h2><table class=\"summary\"><tr><td>คะแนนรวม Performance</td><td>" . $number($summary["performance_score"]) . "</td><td>คะแนนรวม Competency</td><td>" . $number($summary["competency_score"]) . "</td></tr><tr><td>คะแนนรวมทั้งหมด</td><td>" . $number($summary["total_score"]) . "</td><td>เปอร์เซ็นต์ผลการปฏิบัติงาน</td><td>" . ($summary["percentage"] === null ? "-" : $number($summary["percentage"]) . "%") . "</td></tr></table>
</body></html>";

$options = new Options();
$options->set("isRemoteEnabled", false);
$options->set("fontDir", "/System/Library/Fonts/Supplemental");
$options->set("fontCache", __DIR__ . "/../vendor/dompdf/dompdf/lib/fonts");
$options->set("defaultFont", "Arial Unicode MS");
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "landscape");
$dompdf->render();
$dompdf->stream("performance-report-" . $employee["employee_code"] . "-" . $year . "-" . $month . ".pdf", ["Attachment" => true]);
