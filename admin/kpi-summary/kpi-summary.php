<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/kpi-summary-data.php";
require_once __DIR__ . "/../../includes/period-picker.php";


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
   FILTER + REPORT
   ปี | เดือน | พนักงาน | ประเภท KPI | สถานะ
========================================================= */

$filters = kpiSummaryFilters($_GET);

$filter_year = $filters["year"];
$filter_month = $filters["month"];
$filter_employee = $filters["employee_id"];
$filter_type = $filters["kpi_type"];
$filter_status = $filters["status"];
$filter_has_month = $filter_month > 0;

$report = loadKpiSummaryReport($pdo, $filters);

$employee_summaries = $report["employees"];
$totals = $report["totals"];
$filter_period = $report["period"];
$filter_label = $report["label"];

$month_names = monthlyPeriodMonths();
$month_stats = periodPickerMonthStats($pdo, $filter_year);

$current_year = (int) date("Y");
$current_month = (int) date("n");

$has_filter =
    $filter_year !== $current_year ||
    $filter_has_month ||
    $filter_employee > 0 ||
    $filter_type !== "" ||
    $filter_status !== "Active";


/* ลิงก์ของหน้านี้ (คง filter เดิม) */
function summaryPageUrl(array $filters, array $overrides = []): string
{
    return "index.php?page=summary&" . kpiSummaryQuery($filters, $overrides);
}


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

    .btn-export {
        gap: 6px;
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
       CARD
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


    /* =================================================
       OTHER FILTERS (เปลี่ยนแล้วโหลดใหม่ทันที)
    ================================================= */

    .filter-row {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
        gap: 15px;
        align-items: end;
    }

    .filter-row .form-group {
        display: flex;
        flex-direction: column;
    }

    .filter-row label {
        margin-bottom: 7px;
        font-size: 14px;
        font-weight: 500;
    }

    .filter-row select {
        width: 100%;
        height: 42px;
        padding: 0 12px;
        border: 1px solid #d8dce5;
        border-radius: 8px;
        background: #fff;
        font-family: inherit;
        font-size: 14px;
        outline: none;
    }

    .filter-row select:focus {
        border-color: #244397;
    }

    .filter-actions {
        display: flex;
        gap: 8px;
        white-space: nowrap;
    }

    .filter-note {
        margin-top: 14px;
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


    /* พนักงาน */

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

        .filter-row {
            grid-template-columns: 1fr 1fr;
        }

        .picker-legend {
            margin-left: 0;
        }

    }

    @media (max-width: 650px) {

        .summary-cards,
        .filter-row {
            grid-template-columns: 1fr;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .card {
            padding: 18px;
        }

        .month-track {
            flex-wrap: wrap;
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
                href="kpi-summary/kpi-summary-export-pdf.php?<?= htmlspecialchars(kpiSummaryQuery($filters)) ?>"
                class="btn btn-secondary btn-export"
                target="_blank"
                rel="noopener"
                data-no-scroll-save>
                📄 Export PDF
            </a>

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
                <?= $totals["employees"] ?>
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
                <?= $totals["kpi"] ?>
            </div>

            <div class="summary-card-note">
                Performance <?= $totals["performance"] ?> · Competency <?= $totals["competency"] ?>
            </div>

        </div>


        <div class="summary-card">

            <div class="summary-card-title">
                ประเมินแล้ว
            </div>

            <div class="summary-card-value">
                <?= $totals["evaluated"] ?>
                <small>/ <?= $totals["kpi"] ?> KPI</small>
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

                <?php if ($totals["avg_score"] === null): ?>
                    -
                <?php else: ?>
                    <?= number_format($totals["avg_score"], 2) ?>
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


        <!-- ปี + เดือน (คลิกแล้วโหลดทันที) -->

        <?php
        renderPeriodPicker([
            "year" => $filter_year,
            "month" => $filter_month,
            "stats" => $month_stats,
            "show_all" => true,
            "css" => "../assets/css/period-picker.css?v=1",
            "url" => fn(int $year, int $month): string => summaryPageUrl($filters, ["year" => $year, "month" => $month])
        ]);
        ?>
        <!-- พนักงาน / ประเภท / สถานะ (เปลี่ยนแล้วโหลดทันที) -->

        <form method="GET" action="index.php" class="filter-row">

            <!-- ต้องส่ง page กลับไปด้วย ไม่งั้น index.php จะเด้งไปหน้า home -->
            <input type="hidden" name="page" value="summary">
            <input type="hidden" name="year" value="<?= $filter_year ?>">

            <?php if ($filter_has_month): ?>
                <input type="hidden" name="month" value="<?= $filter_month ?>">
            <?php endif; ?>


            <div class="form-group">

                <label for="summary_employee">พนักงาน</label>

                <select name="employee_id" id="summary_employee" onchange="this.form.submit()">

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

                <label for="summary_type">ประเภท KPI</label>

                <select name="kpi_type" id="summary_type" onchange="this.form.submit()">
                    <option value="">ทั้งหมด</option>
                    <option value="Performance" <?= $filter_type === "Performance" ? "selected" : "" ?>>Performance</option>
                    <option value="Competency" <?= $filter_type === "Competency" ? "selected" : "" ?>>Competency</option>
                </select>

            </div>


            <div class="form-group">

                <label for="summary_status">สถานะ</label>

                <select name="status" id="summary_status" onchange="this.form.submit()">
                    <option value="Active" <?= $filter_status === "Active" ? "selected" : "" ?>>Active</option>
                    <option value="Inactive" <?= $filter_status === "Inactive" ? "selected" : "" ?>>Inactive</option>
                    <option value="" <?= $filter_status === "" ? "selected" : "" ?>>ทั้งหมด</option>
                </select>

            </div>


            <div class="filter-actions">

                <noscript>
                    <button type="submit" class="btn btn-primary">ค้นหา</button>
                </noscript>

                <?php if ($has_filter): ?>

                    <a
                        href="index.php?page=summary"
                        class="btn btn-secondary">
                        ล้างตัวกรอง
                    </a>

                <?php endif; ?>

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
                <?= $totals["employees"] ?> คน
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

                                <span class="weight-chip <?= summaryWeightClass($summary["total_weight"]) ?>">
                                    <strong><?= number_format($summary["total_weight"]) ?>%</strong>
                                    <small>/ 100%</small>
                                </span>

                            </td>


                            <td>

                                <div class="stat-main">
                                    <?= $summary["evaluated"] ?> / <?= $summary["kpi_total"] ?> KPI
                                </div>

                                <div class="progress <?= $summary["evaluated_ratio"] >= 1 ? "done" : "" ?>">
                                    <span style="width: <?= round($summary["evaluated_ratio"] * 100) ?>%"></span>
                                </div>

                            </td>


                            <td>

                                <?php if ($summary["avg_score"] === null): ?>

                                    <span class="result-none">ยังไม่ประเมิน</span>

                                <?php else: ?>

                                    <div class="stat-main">
                                        <?= number_format($summary["avg_score"], 2) ?> / 5
                                    </div>

                                    <div class="stat-sub">
                                        ถ่วงน้ำหนัก <?= number_format($summary["weighted_percent"], 1) ?>%
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
                <?= $totals["kpi"] ?> KPI
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
                                                · <?= $summary["kpi_total"] ?> KPI
                                                · ประเมินแล้ว <?= $summary["evaluated"] ?>
                                            </div>

                                        </div>

                                    </div>


                                    <div class="group-summary">

                                        <span class="weight-chip <?= summaryWeightClass($summary["total_weight"]) ?>">
                                            Weight รวม
                                            <strong><?= number_format($summary["total_weight"]) ?>%</strong>
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

                            <?php $result_status = strtolower((string) ($kpi["last_status"] ?? "")); ?>

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

                                        <?= htmlspecialchars($kpi["period_label"]) ?>
                                        <?= (int) $kpi["assignment_year"] ?>

                                        <span class="badge badge-quarter">
                                            <?= htmlspecialchars($kpi["quarter_label"]) ?>
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
