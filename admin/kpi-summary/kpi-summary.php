<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/kpi-summary-data.php";
require_once __DIR__ . "/../../includes/period-picker.php";


/* =========================================================
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}


/* =========================================================
   CHECK ADMIN
========================================================= */

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
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
                                    href="#employee-<?= (int) $summary["employee_id"] ?>"
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

                        <tr class="group-row" id="employee-<?= (int) $summary["employee_id"] ?>">

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
