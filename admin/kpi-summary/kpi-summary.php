<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


/* =========================================================
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   CHECK ADMIN
========================================================= */

if (!isset($_SESSION["role_id"]) || $_SESSION["role_id"] != 1) {
    header("Location: ../dashboard.php");
    exit;
}


/* =========================================================
   FILTER
   ปี | เดือน | พนักงาน | ประเภท KPI | สถานะ
========================================================= */

$current_year = (int) date("Y");

$filter_year =
    intval($_GET["year"] ?? $current_year);

$filter_month =
    intval($_GET["month"] ?? 0);

$filter_employee =
    intval($_GET["employee_id"] ?? 0);

$filter_type =
    $_GET["kpi_type"] ?? "";

$filter_status =
    $_GET["status"] ?? "Active";


/* =========================================================
   VALIDATE FILTER
========================================================= */

if ($filter_year < 2000 || $filter_year > 2100) {
    $filter_year = $current_year;
}

if ($filter_month < 1 || $filter_month > 12) {
    $filter_month = 0;
}

if (!in_array($filter_type, ["Competency", "Performance"], true)) {
    $filter_type = "";
}

if (!in_array($filter_status, ["", "Active", "Inactive"], true)) {
    $filter_status = "Active";
}

$filter_has_month = $filter_month > 0;

$month_names = monthlyPeriodMonths();

/* รอบประเมินของเดือนที่เลือก (ถ้ามี) */
$filter_period = $filter_has_month
    ? findEvaluationPeriodByMonth($pdo, $filter_year, $filter_month)
    : null;

$filter_label = $filter_has_month
    ? $month_names[$filter_month] . " " . $filter_year . " (" . getQuarterByMonth($filter_month) . ")"
    : "ปี " . $filter_year . " (ทุกเดือน)";


/* =========================================================
   GET YEARS (สำหรับ dropdown)
========================================================= */

$years_stmt = $pdo->query("
    SELECT DISTINCT assignment_year
    FROM kpi_assignments
    ORDER BY assignment_year DESC
");

$years = array_map("intval", $years_stmt->fetchAll(PDO::FETCH_COLUMN));
$years[] = $current_year;
$years[] = $filter_year;
$years = array_unique($years);
rsort($years);


/* =========================================================
   GET EMPLOYEES (สำหรับ dropdown)
========================================================= */

$employees = $pdo->query("
    SELECT
        employee_id,
        employee_code,
        first_name,
        last_name

    FROM employees

    WHERE status = 'Active'

    ORDER BY first_name ASC, last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET KPI DETAIL + ผลประเมิน
   - Assignment ใช้ได้ทั้งปี (1 แถว = KPI 1 ตัวของพนักงาน 1 คน)
   - ผลประเมิน (kpi_performances) ผูกกับรอบประเมินรายเดือน
     เลือกเดือน  -> ผลของเดือนนั้น (ไม่เกิน 1 รายการต่อ KPI)
     ไม่เลือก    -> รวมทุกเดือนในปี (จำนวนเดือนที่ประเมิน + คะแนนเฉลี่ย)
========================================================= */

$perf_where = " ep.period_year = ? ";
$perf_params = [$filter_year];

if ($filter_has_month) {
    $perf_where .= " AND ep.period_month = ? ";
    $perf_params[] = $filter_month;
}

$detail_sql = "
    SELECT

        a.assignment_id,
        a.employee_id,
        a.kpi_id,
        a.assignment_year,
        a.target_value,
        a.weight,
        COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) AS start_date,
        COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) AS end_date,
        a.status,

        e.employee_code,
        e.first_name,
        e.last_name,
        d.department_name,

        k.kpi_name,
        k.kpi_type,
        k.unit,
        COALESCE(k.max_score, 5) AS max_score,

        COALESCE(perf.eval_count, 0) AS eval_count,
        perf.avg_score,
        perf.last_actual,
        perf.last_status

    FROM kpi_assignments a

    INNER JOIN employees e
        ON a.employee_id = e.employee_id

    LEFT JOIN departments d
        ON e.department_id = d.department_id

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    LEFT JOIN (
        SELECT
            kp.assignment_id,
            COUNT(kp.performance_id) AS eval_count,
            AVG(kp.score) AS avg_score,
            MAX(kp.actual) AS last_actual,
            MAX(kp.status) AS last_status
        FROM kpi_performances kp
        INNER JOIN evaluation_periods ep
            ON ep.period_id = kp.period_id
        WHERE kp.score IS NOT NULL
          AND {$perf_where}
        GROUP BY kp.assignment_id
    ) perf
        ON perf.assignment_id = a.assignment_id

    WHERE a.assignment_year = ?
";

$detail_params = array_merge($perf_params, [$filter_year]);


/* เดือนที่เลือก: เฉพาะ KPI ที่มีผลในเดือนนั้น */

if ($filter_has_month) {

    $month_start = sprintf("%04d-%02d-01", $filter_year, $filter_month);
    $month_end = date("Y-m-t", strtotime($month_start));

    $detail_sql .= "
        AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= ?
        AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= ?
    ";

    $detail_params[] = $month_end;
    $detail_params[] = $month_start;
}


if ($filter_employee > 0) {
    $detail_sql .= " AND a.employee_id = ? ";
    $detail_params[] = $filter_employee;
}


if ($filter_type !== "") {
    $detail_sql .= " AND k.kpi_type = ? ";
    $detail_params[] = $filter_type;
}


if ($filter_status !== "") {
    $detail_sql .= " AND a.status = ? ";
    $detail_params[] = $filter_status;
}


$detail_sql .= "
    ORDER BY
        e.first_name ASC,
        e.last_name ASC,
        a.employee_id ASC,
        FIELD(k.kpi_type, 'Performance', 'Competency'),
        k.kpi_name ASC
";


$detail_stmt = $pdo->prepare($detail_sql);
$detail_stmt->execute($detail_params);

$details = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GROUP BY EMPLOYEE
   สรุป 1 แถวต่อพนักงาน + รายการ KPI ใต้กลุ่ม
========================================================= */

$employee_summaries = [];

foreach ($details as $row) {

    $employee_id = (int) $row["employee_id"];

    if (!isset($employee_summaries[$employee_id])) {

        $employee_summaries[$employee_id] = [
            "employee_id" => $employee_id,
            "employee_code" => $row["employee_code"],
            "first_name" => $row["first_name"],
            "last_name" => $row["last_name"],
            "department_name" => $row["department_name"],
            "kpis" => [],

            "count" => ["Performance" => 0, "Competency" => 0],
            "weight" => ["Performance" => 0.0, "Competency" => 0.0],

            "evaluated" => 0,
            "score_sum" => 0.0,
            "weighted_sum" => 0.0,
            "weighted_weight" => 0.0
        ];
    }

    $summary = &$employee_summaries[$employee_id];

    $type = $row["kpi_type"];
    $weight = (float) $row["weight"];

    $summary["kpis"][] = $row;

    if (isset($summary["count"][$type])) {
        $summary["count"][$type]++;
        $summary["weight"][$type] += $weight;
    }


    /* ผลประเมิน: นับเฉพาะ KPI ที่มีคะแนนแล้ว */

    if ($row["avg_score"] !== null) {

        $score = (float) $row["avg_score"];
        $max_score = (float) $row["max_score"] ?: 5;

        $summary["evaluated"]++;
        $summary["score_sum"] += $score;

        // ถ่วงน้ำหนัก: สูตรเดียวกับ Dashboard (score/max × weight)
        $summary["weighted_sum"] += ($score / $max_score) * $weight;
        $summary["weighted_weight"] += $weight;
    }

    unset($summary);
}


/* =========================================================
   TOTALS (การ์ดด้านบน)
========================================================= */

$total_employees = count($employee_summaries);
$total_kpi = count($details);
$total_performance = 0;
$total_competency = 0;
$total_evaluated = 0;
$overall_score_sum = 0.0;

foreach ($employee_summaries as $summary) {

    $total_performance += $summary["count"]["Performance"];
    $total_competency += $summary["count"]["Competency"];
    $total_evaluated += $summary["evaluated"];
    $overall_score_sum += $summary["score_sum"];
}

$overall_avg_score = $total_evaluated > 0
    ? $overall_score_sum / $total_evaluated
    : null;


/* =========================================================
   HELPERS
========================================================= */

function summaryTypeClass(string $type): string
{
    return strtolower($type) === "performance" ? "performance" : "";
}

function summaryWeightClass(float $total): string
{
    if ($total > 100) {
        return "over";
    }

    return $total == 100 ? "full" : "";
}

$has_filter =
    $filter_year !== $current_year ||
    $filter_has_month ||
    $filter_employee > 0 ||
    $filter_type !== "" ||
    $filter_status !== "Active";

?>

<style>

    /* =================================================
       HEADER
    ================================================= */

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0;
        font-size: 32px;
        font-weight: 600;
    }

    .page-header p {
        margin: 5px 0 0;
        color: #6b7280;
    }


    /* =================================================
       SUMMARY CARDS
    ================================================= */

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 25px;
    }

    .summary-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px 22px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
    }

    .summary-card-title {
        color: #6b7280;
        font-size: 14px;
    }

    .summary-card-value {
        margin-top: 8px;
        color: #244397;
        font-size: 30px;
        font-weight: 700;
        line-height: 1.1;
    }

    .summary-card-value small {
        color: #98a2b3;
        font-size: 14px;
        font-weight: 400;
    }

    .summary-card-note {
        margin-top: 6px;
        color: #98a2b3;
        font-size: 12px;
    }


    /* =================================================
       CARD / FILTER
    ================================================= */

    .card {
        background: #fff;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
    }

    .card-title {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 12px;
        margin: 0 0 18px;
        color: #244397;
        font-size: 20px;
        font-weight: 500;
    }

    .card-title .count {
        color: #6b7280;
        font-size: 14px;
        font-weight: 400;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr) auto;
        gap: 15px;
        align-items: end;
    }

    .filter-grid .form-group {
        display: flex;
        flex-direction: column;
    }

    .filter-grid label {
        margin-bottom: 7px;
        font-size: 14px;
        font-weight: 500;
    }

    .filter-grid select {
        width: 100%;
        padding: 11px 13px;
        border: 1px solid #d8dce5;
        border-radius: 7px;
        background: #fff;
        font-family: inherit;
        font-size: 14px;
        outline: none;
    }

    .filter-grid select:focus {
        border-color: #244397;
    }

    .filter-actions {
        display: flex;
        gap: 8px;
        white-space: nowrap;
    }

    .filter-note {
        margin-top: 12px;
        color: #667085;
        font-size: 13px;
    }

    .filter-note strong {
        color: #244397;
    }


    /* =================================================
       TABLES
    ================================================= */

    .table-wrapper {
        overflow-x: auto;
    }

    .summary-table,
    .detail-table {
        width: 100%;
        border-collapse: collapse;
    }

    .summary-table {
        min-width: 860px;
    }

    .detail-table {
        min-width: 900px;
    }

    th {
        background: #f1f4f9;
        color: #444;
        font-size: 14px;
        font-weight: 500;
        padding: 13px 12px;
        text-align: left;
        white-space: nowrap;
    }

    td {
        padding: 13px 12px;
        border-bottom: 1px solid #edf0f5;
        font-size: 14px;
        vertical-align: middle;
    }

    tbody tr:hover td {
        background: #fafbfe;
    }

    .col-index {
        width: 44px;
        color: #98a2b3;
        text-align: center;
    }

    .col-number {
        text-align: right;
        white-space: nowrap;
    }

    .empty {
        padding: 35px;
        color: #888;
        text-align: center;
    }


    /* พนักงาน (ใช้ทั้ง 2 ตาราง) */

    .employee-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .employee-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #244397;
        color: #fff;
        font-weight: 600;
        flex-shrink: 0;
    }

    .employee-cell strong {
        display: block;
        color: #1f2937;
    }

    .employee-meta {
        margin-top: 2px;
        color: #667085;
        font-size: 13px;
    }


    /* ตัวเลขในตารางสรุป */

    .stat-main {
        font-weight: 600;
        color: #1f2937;
        white-space: nowrap;
    }

    .stat-sub {
        margin-top: 2px;
        color: #98a2b3;
        font-size: 12px;
        white-space: nowrap;
    }

    .progress {
        width: 110px;
        height: 6px;
        margin-top: 6px;
        border-radius: 6px;
        background: #edf0f5;
        overflow: hidden;
    }

    .progress span {
        display: block;
        height: 100%;
        background: #244397;
    }

    .progress.done span {
        background: #237a42;
    }


    /* ป้าย */

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        white-space: nowrap;
    }

    .badge-type {
        background: #edf2ff;
        color: #244397;
    }

    .badge-type.performance {
        background: #fff4df;
        color: #a16207;
    }

    .badge-quarter {
        background: #eef2ff;
        color: #244397;
        font-weight: 500;
    }

    .badge-active {
        background: #e5f7eb;
        color: #237a42;
    }

    .badge-inactive {
        background: #f1f1f1;
        color: #777;
    }

    .badge-submitted {
        background: #e5f7eb;
        color: #237a42;
    }

    .badge-draft {
        background: #fff4df;
        color: #a16207;
    }

    .badge-pending {
        background: #f1f1f1;
        color: #777;
    }

    .weight-chip {
        display: inline-flex;
        align-items: baseline;
        gap: 5px;
        padding: 5px 11px;
        border: 1px solid #dfe4ee;
        border-radius: 20px;
        background: #fff;
        color: #667085;
        font-size: 12px;
        white-space: nowrap;
    }

    .weight-chip strong {
        color: #1f2937;
        font-size: 14px;
    }

    .weight-chip small {
        color: #98a2b3;
    }

    .weight-chip.full {
        border-color: #bfe3cb;
        background: #eefaf2;
    }

    .weight-chip.full strong {
        color: #237a42;
    }

    .weight-chip.over {
        border-color: #f5c2c2;
        background: #fff0f0;
    }

    .weight-chip.over strong {
        color: #c62828;
    }

    .btn-small {
        display: inline-block;
        padding: 6px 11px;
        border-radius: 6px;
        background: #eef3ff;
        color: #244397;
        font-size: 12px;
        text-decoration: none;
        white-space: nowrap;
    }


    /* ตารางรายละเอียด: กลุ่มพนักงาน */

    .detail-table .group-row td {
        padding: 0;
        border-top: 1px solid #dfe4ee;
        border-bottom: 1px solid #dfe4ee;
        background: #f4f6fb;
    }

    .detail-table .group-row:first-child td {
        border-top: 0;
    }

    .detail-table .group-row:hover td {
        background: #f4f6fb;
    }

    .group-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        padding: 12px 14px;
    }

    .group-summary {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        color: #667085;
        font-size: 13px;
    }

    .kpi-name {
        font-weight: 500;
        color: #1f2937;
    }

    .kpi-unit {
        margin-top: 2px;
        color: #98a2b3;
        font-size: 12px;
    }

    .col-period {
        min-width: 230px;
    }

    .period-main {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
        white-space: nowrap;
    }

    .period-dates {
        margin-top: 3px;
        color: #98a2b3;
        font-size: 12px;
        white-space: nowrap;
    }

    .result-score {
        font-weight: 600;
        color: #1f2937;
        white-space: nowrap;
    }

    .result-score small {
        color: #98a2b3;
        font-weight: 400;
    }

    .result-meta {
        margin-top: 2px;
        color: #667085;
        font-size: 12px;
        white-space: nowrap;
    }

    .result-none {
        color: #98a2b3;
        font-size: 13px;
    }

    .row-inactive td,
    .row-inactive .kpi-name {
        color: #98a2b3;
    }


    /* =================================================
       RESPONSIVE
    ================================================= */

    @media (max-width: 1100px) {

        .summary-cards {
            grid-template-columns: repeat(2, 1fr);
        }

        .filter-grid {
            grid-template-columns: 1fr 1fr;
        }

    }

    @media (max-width: 650px) {

        .summary-cards,
        .filter-grid {
            grid-template-columns: 1fr;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .card {
            padding: 18px;
        }

    }

</style>


<div class="page-container">


    <!-- =================================================
         HEADER
    ================================================= -->

    <div class="page-header">

        <div>

            <h1>
                KPI Summary
            </h1>

            <p>
                สรุป KPI และผลการประเมินของพนักงาน · <?= htmlspecialchars($filter_label) ?>
            </p>

        </div>

        <div class="header-actions">

            <a
                href="index.php?page=kpi-assignment"
                class="btn btn-primary">
                มอบหมาย KPI
            </a>

        </div>

    </div>


    <!-- =================================================
         SUMMARY CARDS
    ================================================= -->

    <div class="summary-cards">

        <div class="summary-card">

            <div class="summary-card-title">
                พนักงาน
            </div>

            <div class="summary-card-value">
                <?= $total_employees ?>
            </div>

            <div class="summary-card-note">
                ที่ได้รับมอบหมาย KPI
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-card-title">
                KPI ทั้งหมด
            </div>

            <div class="summary-card-value">
                <?= $total_kpi ?>
            </div>

            <div class="summary-card-note">
                Performance <?= $total_performance ?> · Competency <?= $total_competency ?>
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-card-title">
                ประเมินแล้ว
            </div>

            <div class="summary-card-value">
                <?= $total_evaluated ?>
                <small>/ <?= $total_kpi ?> KPI</small>
            </div>

            <div class="summary-card-note">
                <?= $filter_has_month ? "ในเดือนที่เลือก" : "อย่างน้อย 1 เดือนในปีนี้" ?>
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-card-title">
                คะแนนเฉลี่ย
            </div>

            <div class="summary-card-value">

                <?php if ($overall_avg_score === null): ?>
                    -
                <?php else: ?>
                    <?= number_format($overall_avg_score, 2) ?>
                    <small>/ 5</small>
                <?php endif; ?>

            </div>

            <div class="summary-card-note">
                เฉลี่ยจาก KPI ที่ประเมินแล้ว
            </div>

        </div>

    </div>


    <!-- =================================================
         FILTER
    ================================================= -->

    <div class="card">

        <h2 class="card-title">
            Filter
        </h2>

        <form method="GET" action="index.php">

            <!-- ต้องส่ง page กลับไปด้วย ไม่งั้น index.php จะเด้งไปหน้า home -->
            <input type="hidden" name="page" value="summary">

            <div class="filter-grid">


                <div class="form-group">

                    <label>ปี</label>

                    <select name="year">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $year === $filter_year ? "selected" : "" ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>


                <div class="form-group">

                    <label>เดือน</label>

                    <select name="month">

                        <option value="0">ทั้งปี</option>

                        <?php foreach ($month_names as $month_number => $month_name): ?>
                            <option value="<?= $month_number ?>" <?= $month_number === $filter_month ? "selected" : "" ?>>
                                <?= $month_name ?> (<?= getQuarterByMonth($month_number) ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>พนักงาน</label>

                    <select name="employee_id">

                        <option value="0">ทั้งหมด</option>

                        <?php foreach ($employees as $employee): ?>

                            <option
                                value="<?= (int) $employee["employee_id"] ?>"
                                <?= $filter_employee === (int) $employee["employee_id"] ? "selected" : "" ?>>

                                <?= htmlspecialchars($employee["employee_code"]) ?>
                                -
                                <?= htmlspecialchars($employee["first_name"]) ?>
                                <?= htmlspecialchars($employee["last_name"]) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>ประเภท KPI</label>

                    <select name="kpi_type">
                        <option value="">ทั้งหมด</option>
                        <option value="Performance" <?= $filter_type === "Performance" ? "selected" : "" ?>>Performance</option>
                        <option value="Competency" <?= $filter_type === "Competency" ? "selected" : "" ?>>Competency</option>
                    </select>

                </div>


                <div class="form-group">

                    <label>สถานะ</label>

                    <select name="status">
                        <option value="Active" <?= $filter_status === "Active" ? "selected" : "" ?>>Active</option>
                        <option value="Inactive" <?= $filter_status === "Inactive" ? "selected" : "" ?>>Inactive</option>
                        <option value="" <?= $filter_status === "" ? "selected" : "" ?>>ทั้งหมด</option>
                    </select>

                </div>


                <div class="filter-actions">

                    <button
                        type="submit"
                        class="btn btn-primary">
                        ค้นหา
                    </button>

                    <?php if ($has_filter): ?>

                        <a
                            href="index.php?page=summary"
                            class="btn btn-secondary">
                            ล้างตัวกรอง
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        </form>


        <?php if ($filter_has_month): ?>

            <div class="filter-note">

                <?php if ($filter_period): ?>

                    รอบประเมิน:
                    <strong><?= htmlspecialchars($filter_period["period_name"]) ?> <?= (int) $filter_period["period_year"] ?></strong>
                    · <?= htmlspecialchars($filter_period["quarter"]) ?>
                    · <?= date("d/m/Y", strtotime($filter_period["start_date"])) ?>
                    - <?= date("d/m/Y", strtotime($filter_period["end_date"])) ?>
                    · <?= htmlspecialchars($filter_period["status"]) ?>

                <?php else: ?>

                    ยังไม่ได้สร้างรอบประเมินของเดือน<?= $month_names[$filter_month] ?> <?= $filter_year ?>
                    จึงยังไม่มีผลประเมินของเดือนนี้

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>


    <!-- =================================================
         SUMMARY TABLE (1 แถว = พนักงาน 1 คน)
    ================================================= -->

    <div class="card">

        <h2 class="card-title">

            สรุปรายพนักงาน

            <span class="count">
                <?= $total_employees ?> คน
            </span>

        </h2>

        <div class="table-wrapper">

            <table class="summary-table">

                <thead>

                    <tr>

                        <th class="col-index">#</th>
                        <th>พนักงาน</th>
                        <th>Performance</th>
                        <th>Competency</th>
                        <th>Weight รวม</th>
                        <th>ประเมินแล้ว</th>
                        <th>คะแนนเฉลี่ย</th>
                        <th></th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($employee_summaries)): ?>

                    <tr>

                        <td colspan="8" class="empty">
                            ไม่พบ KPI ตามเงื่อนไขที่เลือก
                        </td>

                    </tr>

                <?php else: ?>

                    <?php $no = 1; ?>

                    <?php foreach ($employee_summaries as $summary): ?>

                        <?php
                        $kpi_total = count($summary["kpis"]);
                        $total_weight = $summary["weight"]["Performance"] + $summary["weight"]["Competency"];

                        $evaluated_ratio = $kpi_total > 0
                            ? $summary["evaluated"] / $kpi_total
                            : 0;

                        $avg_score = $summary["evaluated"] > 0
                            ? $summary["score_sum"] / $summary["evaluated"]
                            : null;

                        $weighted_percent = $summary["weighted_weight"] > 0
                            ? $summary["weighted_sum"] / $summary["weighted_weight"] * 100
                            : null;
                        ?>

                        <tr>

                            <td class="col-index">
                                <?= $no++ ?>
                            </td>


                            <td>

                                <div class="employee-cell">

                                    <span class="employee-avatar">
                                        <?= htmlspecialchars(mb_substr($summary["first_name"], 0, 1, "UTF-8")) ?>
                                    </span>

                                    <div>

                                        <strong>
                                            <?= htmlspecialchars($summary["first_name"]) ?>
                                            <?= htmlspecialchars($summary["last_name"]) ?>
                                        </strong>

                                        <div class="employee-meta">
                                            <?= htmlspecialchars($summary["employee_code"]) ?>
                                            · <?= htmlspecialchars($summary["department_name"] ?? "ไม่ระบุแผนก") ?>
                                        </div>

                                    </div>

                                </div>

                            </td>


                            <td>

                                <div class="stat-main">
                                    <?= $summary["count"]["Performance"] ?> KPI
                                </div>

                                <div class="stat-sub">
                                    Weight <?= number_format($summary["weight"]["Performance"]) ?>%
                                </div>

                            </td>


                            <td>

                                <div class="stat-main">
                                    <?= $summary["count"]["Competency"] ?> KPI
                                </div>

                                <div class="stat-sub">
                                    Weight <?= number_format($summary["weight"]["Competency"]) ?>%
                                </div>

                            </td>


                            <td>

                                <span class="weight-chip <?= summaryWeightClass($total_weight) ?>">
                                    <strong><?= number_format($total_weight) ?>%</strong>
                                    <small>/ 100%</small>
                                </span>

                            </td>


                            <td>

                                <div class="stat-main">
                                    <?= $summary["evaluated"] ?> / <?= $kpi_total ?> KPI
                                </div>

                                <div class="progress <?= $evaluated_ratio >= 1 ? "done" : "" ?>">
                                    <span style="width: <?= round($evaluated_ratio * 100) ?>%"></span>
                                </div>

                            </td>


                            <td>

                                <?php if ($avg_score === null): ?>

                                    <span class="result-none">ยังไม่ประเมิน</span>

                                <?php else: ?>

                                    <div class="stat-main">
                                        <?= number_format($avg_score, 2) ?> / 5
                                    </div>

                                    <div class="stat-sub">
                                        ถ่วงน้ำหนัก <?= number_format($weighted_percent, 1) ?>%
                                    </div>

                                <?php endif; ?>

                            </td>


                            <td>

                                <a
                                    href="#employee-<?= $summary["employee_id"] ?>"
                                    class="btn-small">
                                    ดูรายละเอียด
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =================================================
         KPI DETAIL (จัดกลุ่มตามพนักงาน)
    ================================================= -->

    <div class="card">

        <h2 class="card-title">

            รายละเอียด KPI

            <span class="count">
                <?= $total_kpi ?> KPI
            </span>

        </h2>

        <div class="table-wrapper">

            <table class="detail-table">

                <thead>

                    <tr>

                        <th class="col-index">#</th>
                        <th>KPI</th>
                        <th>ประเภท</th>
                        <th class="col-number">Target</th>
                        <th class="col-number">Weight</th>
                        <th class="col-period">ช่วงเวลาที่มีผล</th>
                        <th>ผลประเมิน</th>
                        <th>สถานะ</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($employee_summaries)): ?>

                    <tr>

                        <td colspan="8" class="empty">
                            ไม่พบ KPI ตามเงื่อนไขที่เลือก
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($employee_summaries as $summary): ?>

                        <?php
                        $kpi_total = count($summary["kpis"]);
                        $total_weight = $summary["weight"]["Performance"] + $summary["weight"]["Competency"];
                        ?>


                        <!-- =========================================
                             EMPLOYEE GROUP HEADER
                        ========================================== -->

                        <tr class="group-row" id="employee-<?= $summary["employee_id"] ?>">

                            <td colspan="8">

                                <div class="group-header">

                                    <div class="employee-cell">

                                        <span class="employee-avatar">
                                            <?= htmlspecialchars(mb_substr($summary["first_name"], 0, 1, "UTF-8")) ?>
                                        </span>

                                        <div>

                                            <strong>
                                                <?= htmlspecialchars($summary["first_name"]) ?>
                                                <?= htmlspecialchars($summary["last_name"]) ?>
                                            </strong>

                                            <div class="employee-meta">
                                                <?= htmlspecialchars($summary["employee_code"]) ?>
                                                · <?= htmlspecialchars($summary["department_name"] ?? "ไม่ระบุแผนก") ?>
                                                · <?= $kpi_total ?> KPI
                                                · ประเมินแล้ว <?= $summary["evaluated"] ?>
                                            </div>

                                        </div>

                                    </div>


                                    <div class="group-summary">

                                        <span class="weight-chip <?= summaryWeightClass($total_weight) ?>">
                                            Weight รวม
                                            <strong><?= number_format($total_weight) ?>%</strong>
                                            <small>/ 100%</small>
                                        </span>

                                    </div>

                                </div>

                            </td>

                        </tr>


                        <!-- =========================================
                             KPI ROWS
                        ========================================== -->

                        <?php foreach ($summary["kpis"] as $index => $kpi): ?>

                            <?php
                            $period_label = $filter_has_month
                                ? $month_names[$filter_month]
                                : assignmentMonthRangeLabel($kpi["start_date"], $kpi["end_date"]);

                            $quarter_label = $filter_has_month
                                ? getQuarterByMonth($filter_month)
                                : assignmentQuarterRangeLabel($kpi["start_date"], $kpi["end_date"]);

                            $result_status = strtolower((string) ($kpi["last_status"] ?? ""));
                            ?>

                            <tr class="<?= $kpi["status"] === "Active" ? "" : "row-inactive" ?>">

                                <td class="col-index">
                                    <?= $index + 1 ?>
                                </td>


                                <td>

                                    <div class="kpi-name">
                                        <?= htmlspecialchars($kpi["kpi_name"]) ?>
                                    </div>

                                    <div class="kpi-unit">
                                        หน่วย: <?= htmlspecialchars($kpi["unit"] ?? "-") ?>
                                    </div>

                                </td>


                                <td>

                                    <span class="badge badge-type <?= summaryTypeClass($kpi["kpi_type"]) ?>">
                                        <?= htmlspecialchars($kpi["kpi_type"]) ?>
                                    </span>

                                </td>


                                <td class="col-number">
                                    <?= number_format($kpi["target_value"]) ?>%
                                </td>


                                <td class="col-number">
                                    <strong><?= number_format($kpi["weight"]) ?>%</strong>
                                </td>


                                <td>

                                    <div class="period-main">

                                        <?= htmlspecialchars($period_label) ?>
                                        <?= (int) $kpi["assignment_year"] ?>

                                        <span class="badge badge-quarter">
                                            <?= htmlspecialchars($quarter_label) ?>
                                        </span>

                                    </div>

                                    <div class="period-dates">
                                        <?= date("d/m/Y", strtotime($kpi["start_date"])) ?>
                                        –
                                        <?= date("d/m/Y", strtotime($kpi["end_date"])) ?>
                                    </div>

                                </td>


                                <td>

                                    <?php if ($kpi["avg_score"] === null): ?>

                                        <span class="result-none">ยังไม่ประเมิน</span>

                                    <?php elseif ($filter_has_month): ?>

                                        <!-- เดือนเดียว: ผลของเดือนนั้น -->

                                        <div class="result-score">
                                            <?= number_format((float) $kpi["avg_score"], 2) ?>
                                            <small>/ <?= number_format((float) $kpi["max_score"]) ?></small>
                                        </div>

                                        <div class="result-meta">

                                            <?php if ($kpi["last_actual"] !== null): ?>
                                                Actual <?= number_format((float) $kpi["last_actual"]) ?>
                                                ·
                                            <?php endif; ?>

                                            <span class="badge badge-<?= in_array($result_status, ["submitted", "approved"], true) ? "submitted" : "draft" ?>">
                                                <?= htmlspecialchars($kpi["last_status"]) ?>
                                            </span>

                                        </div>

                                    <?php else: ?>

                                        <!-- ทั้งปี: เฉลี่ยทุกเดือนที่ประเมิน -->

                                        <div class="result-score">
                                            <?= number_format((float) $kpi["avg_score"], 2) ?>
                                            <small>/ <?= number_format((float) $kpi["max_score"]) ?> เฉลี่ย</small>
                                        </div>

                                        <div class="result-meta">
                                            ประเมินแล้ว <?= (int) $kpi["eval_count"] ?> เดือน
                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if ($kpi["status"] === "Active"): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


</div>
