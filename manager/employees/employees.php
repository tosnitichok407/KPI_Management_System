<?php

/*
|--------------------------------------------------------------------------
| Manager: ผลงานรายพนักงาน
|--------------------------------------------------------------------------
|
| ตารางรายคน (กรองแผนก/เดือน) + เจาะดูคนเดียว (รายเดือน + KPI + Feedback)
| include จาก manager/index.php
|
*/

if (!isset($yearData)) {
    header("Location: index.php");
    exit;
}

/* ค่าจาก manager/index.php (ประกาศค่าเริ่มต้นให้ editor/static analyzer เห็นว่าตัวแปรถูกกำหนด) */
$filterYear = (int) ($filterYear ?? date("Y"));
$filterMonth = (int) ($filterMonth ?? 0);
$filterDepartment = (int) ($filterDepartment ?? 0);
$filterLabel = (string) ($filterLabel ?? "");
$monthNames = $monthNames ?? monthlyPeriodMonths();
$selectedPeriod = $selectedPeriod ?? null;


require_once __DIR__ . "/../../includes/kpi-evidence.php";
require_once __DIR__ . "/../../includes/kpi-import-history.php";
require_once __DIR__ . "/../../includes/kpi-approval.php";
require_once __DIR__ . "/../includes/table-tools.php";

$filterEmployee = (int) ($_GET["employee_id"] ?? 0);

$selectedEmployee = $yearData["employees"][$filterEmployee] ?? null;

if ($selectedEmployee === null) {
    $filterEmployee = 0;
}

/*
| ขั้นตอนส่งผล / อนุมัติ (ปิดอัตโนมัติเมื่อยังไม่ได้รัน Migration 20261025_kpi_approval_workflow)
| อนุมัติ / ส่งกลับได้เฉพาะ Manager (role 2) · Admin ดูสถานะได้อย่างเดียว
*/
$approvalEnabled = kpiApprovalEnabled($pdo);
$approvalCanReview = (int) ($_SESSION["role_id"] ?? 0) === KPI_APPROVAL_ROLE_MANAGER;
$approvalFlash = $_SESSION["mgr_approval_flash"] ?? null;
unset($_SESSION["mgr_approval_flash"]);
$approvalPeriodId = $filterMonth > 0 ? (int) ($yearData["periods"][$filterMonth]["period_id"] ?? 0) : 0;
$approvalMonthStates = [];
$approvalState = null;
$approvalHistory = [];
$approvalPending = [];
$approvalCheck = null;

if ($approvalEnabled && $selectedEmployee !== null) {
    foreach ($yearData["periods"] as $approvalMonth => $approvalPeriod) {
        $approvalMonthStates[(int) $approvalMonth] = kpiApprovalMonthState($pdo, $filterEmployee, (int) $approvalPeriod["period_id"]);
    }
    if ($approvalPeriodId > 0) {
        $approvalState = $approvalMonthStates[$filterMonth] ?? kpiApprovalMonthState($pdo, $filterEmployee, $approvalPeriodId);
        $approvalHistory = kpiApprovalHistory($pdo, $filterEmployee, $approvalPeriodId);
        /* ตรวจคะแนนก่อนอนุมัติ (อ่านอย่างเดียว) */
        $approvalCheck = kpiApprovalScoreCheck($pdo, $filterEmployee, $approvalPeriodId);
    }
} elseif ($approvalEnabled) {
    $approvalPending = kpiApprovalPending($pdo, $filterYear);
}


/*
|--------------------------------------------------------------------------
| Employee Rows
|--------------------------------------------------------------------------
*/

$employeeRows = [];

foreach (managerEmployeeIds($yearData, $filterDepartment) as $employeeId) {

    $employee = $yearData["employees"][$employeeId];

    // แสดงเฉพาะคนที่มี KPI มอบหมายในปีนี้
    if (empty($yearData["assignments_by_employee"][$employeeId])) {
        continue;
    }

    $employeeRows[] = array_merge(
        managerStats($yearData, [$employeeId], $filterMonth),
        [
            "employee" => $employee,
            "feedback" => $filterMonth > 0 ? ($yearData["feedback"][$employeeId][$filterMonth] ?? null) : null
        ]
    );
}

/* ประวัติพนักงานที่ปิดใช้งานซึ่งมีผลงานในช่วงที่เลือก (แยกจากรายชื่อ/อันดับหลัก) */
$historyRows = managerHistoryRows($yearData, $filterDepartment, $filterMonth);

usort($employeeRows, function ($a, $b) {
    if ($a["avg_score"] === $b["avg_score"]) {
        return strcmp($a["employee"]["first_name"], $b["employee"]["first_name"]);
    }
    if ($a["avg_score"] === null) return 1;
    if ($b["avg_score"] === null) return -1;
    return $b["avg_score"] <=> $a["avg_score"];
});


/*
|--------------------------------------------------------------------------
| Drill-down: พนักงานที่เลือก
|--------------------------------------------------------------------------
*/

$employeeMonthRows = [];
$employeeYearStats = null;

if ($selectedEmployee !== null) {

    $employeeYearStats = managerStats($yearData, [$filterEmployee]);

    for ($month = 1; $month <= 12; $month++) {
        $employeeMonthRows[$month] = array_merge(
            managerStats($yearData, [$filterEmployee], $month),
            [
                "period" => $yearData["periods"][$month] ?? null,
                "feedback" => $yearData["feedback"][$filterEmployee][$month] ?? null
            ]
        );
    }

}

/*
| KPI ที่ได้รับมอบหมายของพนักงาน 1 คน · $month = 0 → ทั้งปี · ใช้ทั้งตารางหลักและหน้าต่าง "ดูเดือนนี้"
| คืน [["assignment", "performances", "avg_score", "latest", "score_pending"], ...]
*/
function managerEmployeeKpis(array $yearData, int $employeeId, int $year, int $month): array
{
    $items = [];

    foreach ($yearData["assignments_by_employee"][$employeeId] ?? [] as $assignmentId) {

        $assignment = $yearData["assignments"][$assignmentId];

        if ($month > 0 && !managerAssignmentInMonth($assignment, $year, $month)) {
            continue;
        }

        $performances = $yearData["performances_by_assignment"][$assignmentId] ?? [];

        /* การมอบหมายที่ปิดใช้งาน: แสดงเป็นประวัติเฉพาะช่วงที่มีแถวผลงานจริง (รวมแถวที่ score เป็น NULL) */
        if (($assignment["status"] ?? "Active") !== "Active"
            && ($month > 0 ? !isset($performances[$month]) : empty($performances))) {
            continue;
        }

        if ($month > 0) {
            $performances = isset($performances[$month]) ? [$month => $performances[$month]] : [];
        }

        /* คะแนน / ล่าสุด / จำนวนเดือนที่ประเมิน: ใช้เฉพาะแถวที่มีคะแนน (score NULL = ยังไม่ประเมิน ไม่ใช่ 0) */
        /* มีผลการดำเนินงานแต่ไม่มีคะแนน (KPI 14 ในช่องว่างของเกณฑ์) → แสดงแยกว่า "รอยืนยันคะแนน" */
        $scorePending = array_values(array_filter($performances, fn($performance) => $performance["score"] === null && ($performance["actual"] ?? null) !== null));
        $performances = array_filter($performances, fn($performance) => $performance["score"] !== null);

        $scores = array_map(fn($p) => (float) $p["score"], $performances);

        $items[] = [
            "assignment" => $assignment,
            "performances" => $performances,
            "avg_score" => $scores ? array_sum($scores) / count($scores) : null,
            "latest" => $performances ? end($performances) : null,
            "score_pending" => $scorePending
        ];
    }

    return $items;
}

/* หลักฐานของผลงานในปีที่เลือก (ใช้กรองตามเดือนในตารางและหน้าต่าง) */
$evidenceByPerformance = [];
if (!empty($yearData["performances_by_assignment"]) && kpiFileFeatureEnabled($pdo, "kpi_performance_evidence")) {
    try {
        $performanceIds = [];
        foreach ($yearData["performances_by_assignment"] as $months) {
            foreach ($months as $performance) $performanceIds[] = (int) $performance["performance_id"];
        }
        $evidenceByPerformance = kpiEvidenceForPerformances($pdo, $performanceIds);
    } catch (Throwable $exception) {
        error_log("manager employees: evidence query failed (" . get_class($exception) . ")");
    }
}

/* เตรียมชุดข้อมูลไฟล์พร้อมบริบท KPI/เดือนสำหรับหน้าต่างหลักฐาน */
$evidenceByEmployee = [];
$evidenceRows = [];
foreach ($yearData["performances_by_assignment"] as $assignmentId => $months) {
    $assignment = $yearData["assignments"][$assignmentId] ?? null;
    if (!$assignment) continue;
    foreach ($months as $month => $performance) {
        foreach ($evidenceByPerformance[(int) $performance["performance_id"]] ?? [] as $file) {
            $file["employee_id"] = (int) $assignment["employee_id"];
            $file["assignment_id"] = (int) $assignmentId;
            $file["kpi_name"] = (string) $assignment["kpi_name"];
            $file["month"] = (int) $month;
            $evidenceRows[] = $file;
            $evidenceByEmployee[(int) $assignment["employee_id"]][] = $file;
        }
    }
}
$evidenceJson = json_encode($evidenceRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$evidenceFeatureEnabled = kpiFileFeatureEnabled($pdo, "kpi_performance_evidence");

/*
| หลักฐานในหน้าต่าง "ดูเดือนนี้" ของพนักงานที่เลือก (แยกตามเดือนของปีที่เลือก)
|   ไฟล์หลักฐานที่แนบกับผลงาน · ไฟล์ Excel ต้นฉบับที่พนักงานนำเข้า (ประวัติการนำเข้า · ล่าสุดก่อน)
|   ให้หัวหน้าเปิดไฟล์เทียบกับผลที่บันทึก · ดาวน์โหลดผ่าน file-download.php (ตรวจสิทธิ์ทุกครั้ง)
*/
$importHistoryEnabled = $selectedEmployee !== null && kpiFileFeatureEnabled($pdo, "kpi_import_history");
$managerImportFilesByMonth = [];
if ($importHistoryEnabled) {
    try {
        foreach (kpiImportHistoryList($pdo, $filterEmployee, null)["rows"] as $historyRow) {
            if ((int) $historyRow["data_year"] === $filterYear) {
                $managerImportFilesByMonth[(int) $historyRow["data_month"]][] = $historyRow;
            }
        }
    } catch (Throwable $exception) {
        error_log("manager employees: import history query failed (" . get_class($exception) . ")");
    }
}
$managerEvidenceByMonth = [];
foreach ($evidenceByEmployee[$filterEmployee] ?? [] as $file) {
    $managerEvidenceByMonth[(int) $file["month"]][] = $file;
}

/*
| Feedback รายเดือน: ปุ่มแสดงทุกครั้งที่ปีนี้มีรอบประเมิน และเลือกเดือนในฟอร์มได้
| เดือนเริ่มต้น = เดือนที่กรอง > เดือนปัจจุบัน > เดือนล่าสุดที่มีรอบ
*/
$feedbackPeriods = $yearData["periods"];
$canFeedback = !empty($feedbackPeriods);

if ($filterMonth > 0 && isset($feedbackPeriods[$filterMonth])) {
    $feedbackDefaultMonth = $filterMonth;
} elseif ($filterYear === (int) date("Y") && isset($feedbackPeriods[(int) date("n")])) {
    $feedbackDefaultMonth = (int) date("n");
} else {
    $feedbackDefaultMonth = $feedbackPeriods ? max(array_keys($feedbackPeriods)) : 0;
}

/* Feedback ที่ให้แล้วของพนักงาน {เดือน: {score, feedback}} ใช้เติมฟอร์มเมื่อเปลี่ยนเดือน */
$feedbackMap = static function (int $employeeId) use ($yearData): string {
    $map = [];

    foreach ($yearData["feedback"][$employeeId] ?? [] as $month => $row) {
        $map[$month] = ["score" => (float) $row["evaluation_score"], "feedback" => $row["feedback"]];
    }

    return htmlspecialchars(json_encode((object) $map, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8");
};

?>


<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                ผลงานรายพนักงาน
            </h1>

            <p>
                ผลการประเมิน KPI และ Feedback ของหัวหน้า · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?>
            </p>

        </div>

    </header>


    <!-- =========================================================
         FILTER
    ========================================================== -->

    <div class="card">

        <?php
        $showMonth = true;
        $showDepartment = true;
        $extraHidden = $filterEmployee > 0 ? ["employee_id" => $filterEmployee] : [];
        include __DIR__ . "/../includes/filter-bar.php";
        ?>

        <div class="filter-note">

            <?php if ($filterMonth > 0 && !$selectedPeriod): ?>
                ยังไม่ได้สร้างรอบประเมินของเดือน<?= $monthNames[$filterMonth] ?> <?= $filterYear ?> จึงยังไม่มีผลประเมินของเดือนนี้
            <?php elseif ($filterMonth === 0): ?>
                เลือก <strong>เดือน</strong> เพื่อดูผลของเดือนนั้น · กดปุ่ม <strong>Feedback</strong> เพื่อส่ง Feedback รายเดือนถึงพนักงาน
            <?php else: ?>
                รอบประเมิน: <strong><?= htmlspecialchars($selectedPeriod["period_name"], ENT_QUOTES, "UTF-8") ?> <?= $filterYear ?></strong>
                · <?= htmlspecialchars($selectedPeriod["quarter"], ENT_QUOTES, "UTF-8") ?>
                · <?= date("d/m/Y", strtotime($selectedPeriod["start_date"])) ?> - <?= date("d/m/Y", strtotime($selectedPeriod["end_date"])) ?>
            <?php endif; ?>

        </div>

    </div>


    <?php if (is_array($approvalFlash)): ?>
        <div class="alert <?= $approvalFlash["type"] === "success" ? "alert-success" : "alert-error" ?>" role="status">
            <?= htmlspecialchars((string) $approvalFlash["message"], ENT_QUOTES, "UTF-8") ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedEmployee !== null): ?>


        <!-- =====================================================
             DRILL-DOWN: พนักงานที่เลือก
        ====================================================== -->

        <?php
        [$levelText, $levelClass] = managerScoreLevel($employeeYearStats["avg_score"], $employeeYearStats["evaluated"], $employeeYearStats["total_kpi"]);
        ?>

        <div class="card employee-profile">

            <div class="person person-large">

                <span class="person-avatar"><?= htmlspecialchars(mb_substr($selectedEmployee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>

                <div>
                    <h2><?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?></h2>
                    <div class="cell-sub">
                        <?= htmlspecialchars($selectedEmployee["employee_code"], ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($selectedEmployee["department_name"] ?? "ไม่ระบุแผนก", ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($selectedEmployee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?>
                    </div>
                </div>

            </div>

            <?php /* ชื่อตัวเลขตรงกับหน้าผลการปฏิบัติงานของพนักงาน: คะแนนรวม (ถ่วงน้ำหนัก) / คะแนนเฉลี่ย (ไม่ถ่วงน้ำหนัก) */ ?>
            <div class="employee-profile-stats">

                <div>
                    <span>KPI ที่ได้รับมอบหมาย</span>
                    <strong><?= $employeeYearStats["total_kpi"] ?></strong>
                    <em>ปี <?= $filterYear ?></em>
                </div>

                <div>
                    <span>คะแนนรวม (ถ่วงน้ำหนัก)</span>
                    <strong><?= managerFormat($employeeYearStats["weighted_percent"], 1, "%") ?></strong>
                    <em>ทุกเดือนที่มีผลในปีนี้</em>
                </div>

                <div>
                    <span>คะแนนเฉลี่ย (ไม่ถ่วงน้ำหนัก)</span>
                    <strong><?= managerFormat($employeeYearStats["avg_score"]) ?> <small>/ 5</small></strong>
                    <em><span class="level level-<?= $levelClass ?>"><?= $levelText ?></span></em>
                </div>

            </div>

            <div class="employee-profile-actions">

                <?php if ($canFeedback): ?>
                    <button
                        type="button"
                        class="btn btn-primary feedback-btn"
                        data-id="<?= $filterEmployee ?>"
                        data-name="<?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?>"
                        data-month="<?= $feedbackDefaultMonth ?>"
                        data-feedback-map="<?= $feedbackMap($filterEmployee) ?>">
                        <?= icon("message-square") ?> ให้ Feedback
                    </button>
                <?php endif; ?>

                <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment]) ?>" class="btn btn-secondary">
                    <?= icon("users") ?>ดูทุกคน
                </a>

            </div>

        </div>


        <!-- รายเดือน -->

        <?php $visibleMonths = array_filter($employeeMonthRows, fn($row, $month) => managerMonthVisible($row, $filterYear, $month), ARRAY_FILTER_USE_BOTH); ?>

        <div class="table-card">

            <div class="table-header">
                <h2>ผลรายเดือน ปี <?= $filterYear ?></h2>
                <span>เฉพาะเดือนที่ถึงรอบประเมินแล้ว</span>
            </div>

            <p class="month-table-legend">
                <strong>คะแนนเฉลี่ย</strong> = ค่าเฉลี่ยคะแนน 1–5 ของ KPI ที่ประเมินแล้วในเดือนนั้น (ไม่ถ่วงน้ำหนัก) ·
                <strong>คะแนนรวม</strong> = Σ(คะแนน ÷ 5 × น้ำหนัก) ÷ Σน้ำหนัก ของ KPI ที่ประเมินแล้ว ·
                เดือนที่ <span class="incomplete-mark">ประเมินยังไม่ครบ</span> คะแนนคิดจากเฉพาะ KPI ที่มีผลแล้ว
            </p>

            <div class="table-wrapper">

                <table class="manager-table manager-table-compact month-table">

                    <thead>
                        <tr>
                            <th>เดือน</th>
                            <th>KPI ที่ประเมินแล้ว</th>
                            <th>คะแนนเฉลี่ย <small>(ไม่ถ่วงน้ำหนัก)</small></th>
                            <th class="col-number">Performance</th>
                            <th class="col-number">Competency</th>
                            <th class="col-number">คะแนนรวม <small>(ถ่วงน้ำหนัก)</small></th>
                            <?php if ($approvalEnabled): ?><th>สถานะการอนุมัติ</th><?php endif; ?>
                            <th>Feedback หัวหน้า</th>
                            <th class="col-actions"><span class="visually-hidden">จัดการ</span></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (!$visibleMonths): ?>
                        <tr><td colspan="<?= $approvalEnabled ? 9 : 8 ?>" class="empty-state">ยังไม่มีรอบประเมินที่ถึงกำหนดในปีนี้</td></tr>
                    <?php endif; ?>

                    <?php foreach ($visibleMonths as $month => $row): ?>

                        <?php
                        [$rowLevelText, $rowLevelClass] = managerScoreLevel($row["avg_score"], (int) $row["evaluated"], (int) $row["total_kpi"]);
                        $rowIncomplete = (int) $row["evaluated"] > 0 && (int) $row["evaluated"] < (int) $row["total_kpi"];
                        ?>

                        <tr class="<?= $month === $filterMonth ? "row-selected" : "" ?>">

                            <td>
                                <strong><?= $monthNames[$month] ?></strong>
                                <div class="cell-sub"><?= getQuarterByMonth($month) ?></div>
                            </td>

                            <td>
                                <?php managerProgressCell($row); ?>
                                <?php if ($rowIncomplete): ?>
                                    <div class="incomplete-mark">ประเมินยังไม่ครบ</div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row["avg_score"] === null): ?>
                                    <span class="level level-<?= $rowLevelClass ?>"><?= $rowLevelText ?></span>
                                <?php else: ?>
                                    <div class="score-line">
                                        <strong><?= number_format((float) $row["avg_score"], 2) ?></strong><small>/ 5</small>
                                        <span class="level level-<?= $rowLevelClass ?>"><?= $rowLevelText ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="col-number"><?= managerFormat($row["avg_performance"]) ?></td>

                            <td class="col-number"><?= managerFormat($row["avg_competency"]) ?></td>

                            <td class="col-number"><strong><?= managerFormat($row["weighted_percent"], 1, "%") ?></strong></td>

                            <?php if ($approvalEnabled): ?>
                                <td>
                                    <?php $monthState = $approvalMonthStates[$month]["state"] ?? "None"; ?>
                                    <span class="approval-badge approval-<?= htmlspecialchars(strtolower($monthState), ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars(KPI_STATUS_LABELS[$monthState] ?? $monthState, ENT_QUOTES, "UTF-8") ?></span>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?php if ($row["feedback"]): ?>
                                    <div class="stat-main"><?= number_format((float) $row["feedback"]["evaluation_score"], 1) ?> <small>/ 100</small></div>
                                    <div class="cell-sub feedback-text" title="<?= htmlspecialchars($row["feedback"]["feedback"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($row["feedback"]["feedback"], ENT_QUOTES, "UTF-8") ?></div>
                                <?php else: ?>
                                    <span class="cell-muted">ยังไม่ให้ Feedback</span>
                                <?php endif; ?>
                            </td>

                            <td class="col-actions">
                                <div class="action-buttons">
                                    <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $month, "department_id" => $filterDepartment, "employee_id" => $filterEmployee]) ?>" class="btn-small edit"
                                        data-kpi-month="<?= (int) $month ?>" data-kpi-month-label="<?= htmlspecialchars($monthNames[$month] . " " . $filterYear, ENT_QUOTES, "UTF-8") ?>"><?= icon("eye") ?>ดูเดือนนี้</a>
                                    <button
                                        type="button"
                                        class="btn-small feedback-btn"
                                        data-id="<?= $filterEmployee ?>"
                                        data-name="<?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?>"
                                        data-month="<?= $month ?>"
                                        data-feedback-map="<?= $feedbackMap($filterEmployee) ?>">
                                        <?= icon("message-square") ?>Feedback
                                    </button>
                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- การส่งผลและการอนุมัติ (เดือนที่เลือก) -->

        <?php if ($approvalEnabled && $approvalState !== null): ?>
            <?php $approvalKey = $approvalState["state"]; ?>
            <div class="table-card approval-card approval-card-<?= htmlspecialchars(strtolower($approvalKey), ENT_QUOTES, "UTF-8") ?>" id="approval">

                <div class="table-header">
                    <h2>การส่งผลและการอนุมัติ</h2>
                    <span><?= htmlspecialchars($monthNames[$filterMonth] ?? "", ENT_QUOTES, "UTF-8") ?> <?= $filterYear ?></span>
                </div>

                <div class="approval-body">
                    <p class="approval-status">
                        สถานะ: <span class="approval-badge approval-<?= htmlspecialchars(strtolower($approvalKey), ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars(KPI_STATUS_LABELS[$approvalKey] ?? $approvalKey, ENT_QUOTES, "UTF-8") ?></span>
                        <span class="cell-muted">มีผลงาน <?= (int) $approvalState["rows"] ?> / <?= (int) $approvalState["assigned"] ?> KPI</span>
                    </p>

                    <?php if ($approvalCheck !== null && $approvalCheck["rows"]): ?>
                        <?php $checkIssues = !$approvalCheck["verified"]; ?>
                        <div class="score-check <?= $checkIssues ? "score-check-warning" : "score-check-ok" ?>">
                            <strong>
                                <?= $checkIssues ? icon("x") . "ตรวจคะแนนก่อนอนุมัติ: ยังยืนยันความถูกต้องไม่ได้ทั้งหมด" : icon("clipboard-check") . "ตรวจคะแนนก่อนอนุมัติ: คะแนนทุกรายการตรงกับเกณฑ์ที่ใช้ประเมิน" ?>
                            </strong>
                            <span class="score-check-summary">
                                <?php foreach (KPI_SCORE_CHECK_LABELS as $checkKey => $checkLabel): ?>
                                    <span class="check-pill check-<?= $checkKey ?>"><?= htmlspecialchars($checkLabel, ENT_QUOTES, "UTF-8") ?> <?= (int) $approvalCheck["counts"][$checkKey] ?></span>
                                <?php endforeach; ?>
                            </span>
                            <?php if (($approvalCheck["pending_corrections"] ?? 0) > 0): ?>
                                <p class="score-check-correction">มีคำขอแก้ไขข้อมูลของเดือนนี้ที่รอพิจารณา <?= (int) $approvalCheck["pending_corrections"] ?> รายการ · <a href="index.php?page=corrections&amp;status=Pending">ไปที่คำขอแก้ไขข้อมูล</a></p>
                            <?php endif; ?>
                            <?php if ($checkIssues): ?>
                                <p class="cell-muted">ระบบไม่แก้คะแนนให้อัตโนมัติ · ถ้าคะแนนไม่ถูกต้องให้ส่งกลับให้พนักงานนำเข้าไฟล์ใหม่ · KPI ที่เกณฑ์รอยืนยันจะถือว่ายังไม่ผ่านการตรวจจนกว่าเจ้าของระบบยืนยันเกณฑ์</p>
                            <?php endif; ?>
                            <div class="table-wrapper">
                                <table class="manager-table manager-table-compact score-check-table">
                                    <thead>
                                        <tr><th>KPI</th><th class="col-number">Actual</th><th class="col-number">Target</th><th class="col-number">คะแนนที่บันทึก</th><th class="col-number">ตามเกณฑ์ปัจจุบัน</th><th>ผลตรวจ</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approvalCheck["rows"] as $checkRow): ?>
                                            <tr class="check-row-<?= htmlspecialchars($checkRow["result"], ENT_QUOTES, "UTF-8") ?>">
                                                <td><?= htmlspecialchars($checkRow["kpi_name"], ENT_QUOTES, "UTF-8") ?><div class="cell-sub"><?= htmlspecialchars($checkRow["kpi_type"], ENT_QUOTES, "UTF-8") ?></div></td>
                                                <td class="col-number"><?= $checkRow["actual"] === null ? "-" : htmlspecialchars(rtrim(rtrim(number_format((float) $checkRow["actual"], 2, ".", ","), "0"), "."), ENT_QUOTES, "UTF-8") ?></td>
                                                <td class="col-number"><?= $checkRow["target"] === null ? "-" : htmlspecialchars(rtrim(rtrim(number_format((float) $checkRow["target"], 2, ".", ","), "0"), "."), ENT_QUOTES, "UTF-8") ?></td>
                                                <td class="col-number"><?= $checkRow["stored"] === null ? "-" : htmlspecialchars(rtrim(rtrim(number_format($checkRow["stored"], 2, ".", ""), "0"), "."), ENT_QUOTES, "UTF-8") ?></td>
                                                <td class="col-number"><?= $checkRow["expected"] === null ? "-" : (int) $checkRow["expected"] ?></td>
                                                <td>
                                                    <span class="check-pill check-<?= htmlspecialchars($checkRow["result"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars(KPI_SCORE_CHECK_LABELS[$checkRow["result"]], ENT_QUOTES, "UTF-8") ?></span>
                                                    <?php if ($checkRow["criteria_pending"] && $checkRow["result"] !== "pending"): ?>
                                                        <span class="check-pill check-pending"><?= htmlspecialchars(KPI_SCORE_CHECK_LABELS["pending"], ENT_QUOTES, "UTF-8") ?></span>
                                                    <?php endif; ?>
                                                    <?php foreach ($checkRow["reasons"] as $checkReason): ?>
                                                        <div class="cell-sub"><?= htmlspecialchars($checkReason, ENT_QUOTES, "UTF-8") ?></div>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($approvalKey === "Submitted" && $approvalCanReview): ?>
                        <form method="post" action="kpi-approval-action.php" class="approval-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="employee_id" value="<?= (int) $filterEmployee ?>">
                            <input type="hidden" name="period_id" value="<?= (int) $approvalPeriodId ?>">
                            <input type="hidden" name="year" value="<?= (int) $filterYear ?>">
                            <input type="hidden" name="month" value="<?= (int) $filterMonth ?>">
                            <input type="hidden" name="department_id" value="<?= (int) $filterDepartment ?>">
                            <label for="approvalComment">หมายเหตุ <span class="cell-muted">(บังคับเมื่อส่งกลับให้แก้ไข)</span></label>
                            <textarea name="comment" id="approvalComment" rows="3" maxlength="<?= KPI_APPROVAL_COMMENT_MAX ?>" placeholder="เช่น ตรวจกับไฟล์ต้นฉบับแล้วถูกต้อง หรือ เหตุผลที่ต้องแก้ไข"></textarea>
                            <?php if ($approvalCheck !== null && !$approvalCheck["verified"]): ?>
                                <label class="approval-ack">
                                    <input type="checkbox" name="acknowledge_warnings" value="1" id="approvalAck">
                                    รับทราบคำเตือนการตรวจคะแนนข้างต้น และยืนยันอนุมัติตามคะแนนที่บันทึก (ระบบบันทึกการรับทราบนี้ไว้ในประวัติ)
                                </label>
                            <?php endif; ?>
                            <div class="approval-actions">
                                <button type="submit" name="decision" value="approve" data-needs-ack="<?= $approvalCheck !== null && !$approvalCheck["verified"] ? "1" : "0" ?>" class="approval-btn approve" onclick="var a=document.getElementById('approvalAck'); if(this.dataset.needsAck==='1' && !(a && a.checked)){alert('มีคะแนนที่ยังยืนยันความถูกต้องไม่ได้ กรุณาตรวจและติ๊กรับทราบก่อนอนุมัติ'); if(a){a.focus();} return false;} return confirm('อนุมัติผลการประเมินของเดือนนี้? หลังอนุมัติแล้วพนักงานจะแก้ไขไม่ได้');"><?= icon("clipboard-check") ?>อนุมัติ</button>
                                <button type="submit" name="decision" value="return" class="approval-btn return" onclick="var c=document.getElementById('approvalComment'); if(!c.value.trim()){alert('กรุณาระบุเหตุผลที่ส่งกลับให้แก้ไข'); c.focus(); return false;} return confirm('ส่งกลับให้พนักงานแก้ไข?');"><?= icon("rotate-ccw") ?>ส่งกลับให้แก้ไข</button>
                            </div>
                            <p class="cell-muted">ตรวจผลงานกับไฟล์ Excel ต้นฉบับและหลักฐานในปุ่ม "ดูเดือนนี้" ของตารางผลรายเดือนก่อนอนุมัติ</p>
                        </form>
                    <?php elseif ($approvalKey === "Submitted"): ?>
                        <p class="cell-muted">รอ Manager อนุมัติ · การอนุมัติทำได้เฉพาะบัญชี Manager</p>
                    <?php elseif ($approvalKey === "Approved"): ?>
                        <p class="cell-muted">อนุมัติแล้ว · ผลงานของเดือนนี้ถูกล็อก</p>
                    <?php elseif ($approvalKey === "Rejected"): ?>
                        <p class="cell-muted">ส่งกลับให้แก้ไขแล้ว · รอพนักงานแก้ไขและส่งใหม่</p>
                    <?php elseif ($approvalKey === "None"): ?>
                        <p class="cell-muted">พนักงานยังไม่มีผลงานของเดือนนี้</p>
                    <?php else: ?>
                        <p class="cell-muted">พนักงานยังไม่ได้ส่งผลการประเมินของเดือนนี้</p>
                    <?php endif; ?>

                    <?php if ($approvalHistory): ?>
                        <h3 class="approval-history-title">ประวัติการดำเนินการ</h3>
                        <ul class="approval-history">
                            <?php foreach ($approvalHistory as $approvalLog): ?>
                                <li>
                                    <strong><?= htmlspecialchars(KPI_APPROVAL_ACTION_LABELS[$approvalLog["action"]] ?? $approvalLog["action"], ENT_QUOTES, "UTF-8") ?></strong>
                                    · <?= htmlspecialchars(kpiApprovalActorName($approvalLog), ENT_QUOTES, "UTF-8") ?>
                                    · <?= htmlspecialchars(date("d/m/Y H:i", strtotime((string) $approvalLog["created_at"])), ENT_QUOTES, "UTF-8") ?>
                                    · <?= (int) $approvalLog["performance_count"] ?> KPI
                                    <?php if (trim((string) $approvalLog["comment"]) !== ""): ?>
                                        <div class="cell-sub"><?= nl2br(htmlspecialchars((string) $approvalLog["comment"], ENT_QUOTES, "UTF-8")) ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>


                    <?php
                    /* แถวของตาราง "KPI ที่ได้รับมอบหมาย" ในหน้าต่าง "ดูเดือนนี้" ($month = เดือนนั้น) */
                    $renderKpiRows = function (array $items, int $month): void {
                    ?>

                    <?php if (empty($items)): ?>

                        <tr>
                            <td colspan="4" class="empty-state">ไม่มี KPI ที่มีผลในช่วงเวลาที่เลือก</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($items as $item): ?>

                            <?php
                            $assignment = $item["assignment"];
                            $isPerformance = strtolower((string) $assignment["kpi_type"]) === "performance";
                            $isBudgetKpi = isNonRenewalKpi($assignment);
                            $unit = kpiDisplayUnit($assignment);
                            ?>

                            <tr data-search="<?= managerSearchText($assignment["kpi_name"]) ?>" data-type="<?= $isPerformance ? "performance" : "competency" ?>">

                                <td>
                                    <strong><?= htmlspecialchars($assignment["kpi_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                    <div class="cell-sub">
                                        <span class="badge badge-type <?= $isPerformance ? "performance" : "" ?>"><?= htmlspecialchars($assignment["kpi_type"], ENT_QUOTES, "UTF-8") ?></span>
                                        <?php if (($assignment["status"] ?? "Active") !== "Active"): ?>
                                            <span class="badge badge-inactive" title="ปิดใช้งาน · แสดงเป็นประวัติ">ปิดใช้งาน</span>
                                        <?php endif; ?>
                                        <?php if (isset(KPI_CRITERIA_PENDING_CONFIRMATION[(int) $assignment["kpi_id"]])): ?>
                                            <span class="approval-badge approval-submitted" title="<?= htmlspecialchars(KPI_CRITERIA_PENDING_CONFIRMATION[(int) $assignment["kpi_id"]], ENT_QUOTES, "UTF-8") ?>">เกณฑ์รอยืนยัน</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="col-number">
                                    <?php if (!$isPerformance): ?>
                                        <span class="cell-muted">-</span>
                                    <?php elseif ($isBudgetKpi): ?>
                                        100%
                                    <?php else: ?>
                                        <?= number_format((float) $assignment["target_value"]) ?><?= $unit === "%" ? "%" : ($unit !== "" ? " <small class=\"cell-muted\">" . htmlspecialchars($unit, ENT_QUOTES, "UTF-8") . "</small>" : "") ?>
                                    <?php endif; ?>
                                </td>

                                <td class="col-number"><?= number_format((float) $assignment["weight"]) ?>%</td>

                                <td>
                                    <?php if ($item["avg_score"] === null && !empty($item["score_pending"])): ?>
                                        <?php $pendingPerf = end($item["score_pending"]); ?>
                                        <span class="approval-badge approval-submitted" title="ผลอยู่ในช่วงที่เกณฑ์ไม่กำหนดคะแนน รอเจ้าของระบบยืนยัน">รอยืนยันคะแนน</span>
                                        <div class="cell-sub">Actual <?= htmlspecialchars(rtrim(rtrim(number_format((float) $pendingPerf["actual"], 2, ".", ","), "0"), "."), ENT_QUOTES, "UTF-8") ?>%</div>
                                    <?php elseif ($item["avg_score"] === null): ?>
                                        <span class="cell-muted">ยังไม่ประเมิน</span>
                                    <?php elseif ($month > 0): ?>
                                        <div class="stat-main"><?= kpiScoreLevel((float) $item["avg_score"]) ?> <small>/ <?= number_format((float) $assignment["max_score"]) ?></small></div>
                                        <div class="cell-sub">
                                            <?php if ($item["latest"]["actual"] !== null): ?>
                                                Actual <?= $isBudgetKpi
                                                    ? htmlspecialchars(kpiFormatAchievement(nonRenewalAchievement($item["latest"]["actual"], $item["latest"]["target"] ?? $assignment["target_value"])), ENT_QUOTES, "UTF-8")
                                                    : number_format((float) $item["latest"]["actual"], floor((float) $item["latest"]["actual"]) == (float) $item["latest"]["actual"] ? 0 : 2) . ($unit === "%" ? "%" : "") ?> ·
                                            <?php endif; ?>
                                            <?= htmlspecialchars($item["latest"]["status"], ENT_QUOTES, "UTF-8") ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="stat-main"><?= number_format($item["avg_score"], 2) ?> <small>/ <?= number_format((float) $assignment["max_score"]) ?> เฉลี่ย</small></div>
                                        <div class="cell-sub">ประเมินแล้ว <?= count($item["performances"]) ?> เดือน</div>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <?php
                    };
                    ?>


        <!-- หน้าต่าง "ดูเดือนนี้": ตาราง KPI ที่ได้รับมอบหมายของเดือนที่กด (render ไว้ล่วงหน้า · ไม่โหลดหน้าใหม่) -->

        <?php foreach ($visibleMonths as $month => $row): ?>
            <template id="kpiMonth<?= (int) $month ?>">
                <div class="kpi-month-summary">
                    <span>KPI ที่ประเมินแล้ว <strong><?= (int) $row["evaluated"] ?> / <?= (int) $row["total_kpi"] ?></strong></span>
                    <span>คะแนนเฉลี่ย (ไม่ถ่วงน้ำหนัก) <strong><?= managerFormat($row["avg_score"]) ?></strong> / 5</span>
                    <span>คะแนนรวม (ถ่วงน้ำหนัก) <strong><?= managerFormat($row["weighted_percent"], 1, "%") ?></strong></span>
                    <?php if ((int) $row["evaluated"] > 0 && (int) $row["evaluated"] < (int) $row["total_kpi"]): ?>
                        <span class="incomplete-mark">ประเมินยังไม่ครบ</span>
                    <?php endif; ?>
                </div>
                <div class="table-wrapper">
                    <table class="manager-table manager-table-compact">
                        <thead>
                            <tr>
                                <th>KPI</th>
                                <th class="col-number">Target</th>
                                <th class="col-number">น้ำหนัก</th>
                                <th>ผลประเมิน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $renderKpiRows(managerEmployeeKpis($yearData, $filterEmployee, $filterYear, (int) $month), (int) $month); ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($evidenceFeatureEnabled): ?>
                    <?php
                    /* ไฟล์เดียวกันแนบได้หลาย KPI → แสดงไฟล์ละ 1 แถว พร้อมรายชื่อ KPI (นับแบบเดียวกับปุ่มหลักฐานเดิม: file_sha256) */
                    $monthEvidence = [];
                    foreach ($managerEvidenceByMonth[(int) $month] ?? [] as $file) {
                        $fileKey = (string) ($file["file_sha256"] ?? $file["evidence_id"]);
                        $monthEvidence[$fileKey] ??= ["file" => $file, "kpis" => []];
                        $monthEvidence[$fileKey]["kpis"][(string) $file["kpi_name"]] = true;
                    }
                    ?>
                    <h3 class="kpi-month-section"><?= icon("file-text") ?>หลักฐานที่แนบ (<?= count($monthEvidence) ?> ไฟล์)</h3>
                    <?php if (!$monthEvidence): ?>
                        <p class="cell-muted">ยังไม่มีไฟล์หลักฐานในเดือนนี้</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="manager-table manager-table-compact">
                                <thead><tr><th>ชื่อไฟล์</th><th>KPI</th><th class="col-number">ขนาด</th><th>วันที่แนบ</th><th>ไฟล์</th></tr></thead>
                                <tbody>
                                    <?php foreach ($monthEvidence as ["file" => $file, "kpis" => $fileKpis]): ?>
                                        <?php $fileUrl = "../file-download.php?type=evidence&id=" . (int) $file["evidence_id"]; ?>
                                        <tr>
                                            <td class="evidence-name"><?= htmlspecialchars((string) $file["original_name"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td><?= htmlspecialchars(implode(", ", array_keys($fileKpis)), ENT_QUOTES, "UTF-8") ?></td>
                                            <td class="col-number"><?= number_format((int) $file["file_size"] / 1024, 1) ?> KB</td>
                                            <td><?= htmlspecialchars((string) $file["uploaded_at"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td>
                                                <?php if (in_array(strtolower((string) $file["file_ext"]), ["jpg", "jpeg", "png", "pdf"], true)): ?>
                                                    <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, "UTF-8") ?>&amp;view=1" target="_blank" rel="noopener">เปิดดู</a> ·
                                                <?php endif; ?>
                                                <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, "UTF-8") ?>">ดาวน์โหลด</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($importHistoryEnabled): ?>
                    <?php $monthImports = $managerImportFilesByMonth[(int) $month] ?? []; ?>
                    <h3 class="kpi-month-section"><?= icon("file-spreadsheet") ?>ไฟล์ Excel ต้นฉบับที่พนักงานนำเข้า (<?= count($monthImports) ?> ไฟล์)</h3>
                    <?php if (!$monthImports): ?>
                        <p class="cell-muted">ไม่มีประวัติการนำเข้าไฟล์ของเดือนนี้</p>
                    <?php else: ?>
                        <ul class="kpi-month-imports">
                            <?php foreach ($monthImports as $importFile): ?>
                                <?php
                                $importName = rawurldecode((string) $importFile["original_name"]);
                                $importName = mb_check_encoding($importName, "UTF-8") ? $importName : (string) $importFile["original_name"];
                                ?>
                                <li>
                                    <?php if ($importFile["stored_name"] !== null): ?>
                                        <a href="../file-download.php?type=import&amp;id=<?= (int) $importFile["history_id"] ?>"><?= htmlspecialchars($importName, ENT_QUOTES, "UTF-8") ?></a>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($importName, ENT_QUOTES, "UTF-8") ?></span>
                                        <span class="cell-muted">(<?= htmlspecialchars(KPI_IMPORT_HISTORY_REMOVED_TEXT, ENT_QUOTES, "UTF-8") ?>)</span>
                                    <?php endif; ?>
                                    <span class="cell-muted">
                                        · นำเข้า <?= htmlspecialchars(date("d/m/Y H:i", strtotime((string) $importFile["imported_at"])), ENT_QUOTES, "UTF-8") ?>
                                        · เพิ่ม <?= (int) $importFile["rows_inserted"] ?> / อัปเดต <?= (int) $importFile["rows_updated"] ?> / ไม่เปลี่ยน <?= (int) $importFile["rows_unchanged"] ?> รายการ
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </template>
        <?php endforeach; ?>

        <dialog id="kpiMonthDialog" class="feedback-dialog kpi-month-dialog">
            <div class="kpi-month-dialog-content">
                <button type="button" class="dialog-close" id="kpiMonthClose" aria-label="ปิด"><?= icon("x", "icon-only") ?></button>
                <h2 id="kpiMonthTitle">KPI ที่ได้รับมอบหมาย</h2>
                <p class="dialog-person"><?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?></p>
                <div id="kpiMonthBody"></div>
            </div>
        </dialog>

        <script>
            (function () {
                const dialog = document.getElementById("kpiMonthDialog");
                const body = document.getElementById("kpiMonthBody");
                const title = document.getElementById("kpiMonthTitle");
                document.getElementById("kpiMonthClose").addEventListener("click", function () { dialog.close(); });
                dialog.addEventListener("click", function (event) { if (event.target === dialog) dialog.close(); });

                document.querySelectorAll("[data-kpi-month]").forEach(function (link) {
                    link.addEventListener("click", function (event) {
                        const template = document.getElementById("kpiMonth" + link.dataset.kpiMonth);
                        if (!template || typeof dialog.showModal !== "function") {
                            return;   /* ไม่มีข้อมูล / เบราว์เซอร์ไม่รองรับ dialog → เปิดลิงก์เดิม */
                        }
                        event.preventDefault();
                        title.textContent = "KPI ที่ได้รับมอบหมาย · " + link.dataset.kpiMonthLabel;
                        body.replaceChildren(template.content.cloneNode(true));
                        dialog.showModal();
                    });
                });
            })();
        </script>


    <?php else: ?>


        <!-- =====================================================
             EMPLOYEE TABLE
        ====================================================== -->

        <?php if ($approvalEnabled): ?>
            <div class="table-card approval-pending">
                <div class="table-header">
                    <h2>รอการอนุมัติ</h2>
                    <span>ปี <?= $filterYear ?> · <?= count($approvalPending) ?> รายการ</span>
                </div>
                <?php if (!$approvalPending): ?>
                    <p class="empty-state">ไม่มีผลการประเมินที่รออนุมัติ</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="manager-table manager-table-compact">
                            <thead>
                                <tr><th>พนักงาน</th><th>แผนก</th><th>เดือน</th><th>ส่งเมื่อ</th><th class="col-actions"><span class="visually-hidden">จัดการ</span></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvalPending as $pending): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($pending["first_name"] . " " . $pending["last_name"], ENT_QUOTES, "UTF-8") ?></strong><div class="cell-sub"><?= htmlspecialchars($pending["employee_code"], ENT_QUOTES, "UTF-8") ?></div></td>
                                        <td><?= htmlspecialchars($pending["department_name"] ?? "-", ENT_QUOTES, "UTF-8") ?></td>
                                        <td><?= htmlspecialchars($monthNames[(int) $pending["period_month"]] ?? "", ENT_QUOTES, "UTF-8") ?> <?= (int) $pending["period_year"] ?> · <?= (int) $pending["performance_count"] ?> KPI</td>
                                        <td><?= $pending["submitted_at"] ? htmlspecialchars(date("d/m/Y H:i", strtotime((string) $pending["submitted_at"])), ENT_QUOTES, "UTF-8") : '<span class="cell-muted">-</span>' ?></td>
                                        <td class="col-actions"><a class="btn-small edit" href="<?= managerUrl("employees", ["year" => (int) $pending["period_year"], "month" => (int) $pending["period_month"], "employee_id" => (int) $pending["employee_id"]]) ?>#approval"><?= icon("eye") ?>ตรวจสอบ</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-card">

            <div class="table-header">
                <h2>รายชื่อพนักงาน</h2>
                <span>เรียงตามคะแนนเฉลี่ย · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?></span>
            </div>

            <?php
            $employeeFilters = ["level" => ["ระดับ", managerLevelOptions()]];
            if ($filterMonth > 0) {
                $employeeFilters["feedback"] = ["Feedback", ["" => "Feedback ทั้งหมด", "none" => "ยังไม่ให้ Feedback", "given" => "ให้ Feedback แล้ว"]];
            }
            managerTableTools("employeeTable", "ค้นหาชื่อ รหัส แผนก หรือตำแหน่ง", $employeeFilters);
            ?>

            <div class="table-wrapper">

                <table class="manager-table manager-table-compact" id="employeeTable">

                    <thead>
                        <tr>
                            <th class="col-index">#</th>
                            <th>พนักงาน</th>
                            <th>ประเมินแล้ว</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>Feedback</th>
                            <th class="col-actions"><span class="visually-hidden">จัดการ</span></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($employeeRows)): ?>

                        <tr>
                            <td colspan="6" class="empty-state">ไม่พบพนักงานที่มี KPI ในตัวกรองนี้</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employeeRows as $index => $row): ?>

                            <?php
                            $employee = $row["employee"];
                            [, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]);
                            $fullName = $employee["first_name"] . " " . $employee["last_name"];
                            ?>

                            <tr data-search="<?= managerSearchText($fullName, $employee["employee_code"], $employee["department_name"] ?? "", $employee["position_name"] ?? "") ?>"
                                data-level="<?= $levelClass ?>"
                                data-feedback="<?= $filterMonth > 0 && $row["feedback"] ? "given" : "none" ?>">

                                <td class="col-index"><?= $index + 1 ?></td>

                                <td>
                                    <div class="person">
                                        <span class="person-avatar"><?= htmlspecialchars(mb_substr($employee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>
                                        <div>
                                            <strong><?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?></strong>
                                            <div class="cell-sub">
                                                <?= htmlspecialchars($employee["employee_code"], ENT_QUOTES, "UTF-8") ?>
                                                · <?= htmlspecialchars($employee["department_name"] ?? "ไม่ระบุแผนก", ENT_QUOTES, "UTF-8") ?>
                                                · <?= htmlspecialchars($employee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td><?php managerProgressCell($row); ?></td>

                                <td><?php managerScoreCell($row); ?></td>

                                <td>
                                    <?php if ($filterMonth > 0): ?>
                                        <?php if ($row["feedback"]): ?>
                                            <div class="stat-main"><?= number_format((float) $row["feedback"]["evaluation_score"], 1) ?> <small>/ 100</small></div>
                                        <?php else: ?>
                                            <span class="cell-muted">ยังไม่ให้</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="stat-main"><?= $row["feedback_count"] ?> <small>เดือน</small></div>
                                    <?php endif; ?>
                                </td>

                                <td class="col-actions">
                                    <div class="action-buttons">
                                        <?php
                                        $employeeFileKeys = [];
                                        $employeeFiles = [];
                                        foreach ($evidenceByEmployee[(int) $employee["employee_id"]] ?? [] as $file) {
                                            if ($filterMonth > 0 && (int) $file["month"] !== $filterMonth) continue;
                                            $fileKey = (string) ($file["file_sha256"] ?? $file["evidence_id"]) . ":" . (int) $file["month"];
                                            if (isset($employeeFileKeys[$fileKey])) continue;
                                            $employeeFileKeys[$fileKey] = true;
                                            $employeeFiles[] = $file;
                                        }
                                        ?>
                                        <?php if ($evidenceFeatureEnabled): ?>
                                            <button type="button" class="btn-small evidence-btn" data-evidence-kind="employee" data-evidence-id="<?= (int) $employee["employee_id"] ?>" data-evidence-name="<?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?>" <?= !$employeeFiles ? "disabled" : "" ?>><?= icon("file-text") ?>หลักฐาน (<?= count($employeeFiles) ?>)</button>
                                        <?php endif; ?>
                                        <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment, "employee_id" => $employee["employee_id"]]) ?>" class="btn-small edit"><?= icon("eye") ?>รายละเอียด</a>
                                        <?php if ($canFeedback): ?>
                                            <button
                                                type="button"
                                                class="btn-small feedback-btn"
                                                data-id="<?= (int) $employee["employee_id"] ?>"
                                                data-name="<?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?>"
                                                data-month="<?= $feedbackDefaultMonth ?>"
                                                data-feedback-map="<?= $feedbackMap((int) $employee["employee_id"]) ?>">
                                                <?= icon("message-square") ?>Feedback
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <?php if (!empty($historyRows)): ?>

            <!-- =====================================================
                 ประวัติพนักงานที่ปิดใช้งาน (ไม่รวมในรายชื่อ/อันดับด้านบน)
            ====================================================== -->

            <div class="table-card">

                <div class="table-header">
                    <h2>ประวัติพนักงานที่ปิดใช้งาน</h2>
                    <span><?= count($historyRows) ?> คน · ไม่นับรวมจำนวนพนักงานและอันดับด้านบน</span>
                </div>

                <div class="table-wrapper">
                    <table class="manager-table">
                        <thead>
                            <tr>
                                <th class="col-index">#</th>
                                <th>พนักงาน</th>
                                <th>แผนก / ตำแหน่ง</th>
                                <th>ประเมินแล้ว</th>
                                <th class="col-number">Performance</th>
                                <th class="col-number">Competency</th>
                                <th>คะแนนเฉลี่ย</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyRows as $historyIndex => $historyRow): ?>
                                <?php $historyEmployee = $historyRow["employee"]; ?>
                                <tr>
                                    <td class="col-index"><?= $historyIndex + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($historyEmployee["first_name"] . " " . $historyEmployee["last_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                        <span class="badge badge-inactive" title="ปิดใช้งาน · แสดงเป็นประวัติ">ปิดใช้งาน</span>
                                        <div class="cell-sub"><?= htmlspecialchars($historyEmployee["employee_code"], ENT_QUOTES, "UTF-8") ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($historyEmployee["department_name"] ?? "-", ENT_QUOTES, "UTF-8") ?>
                                        <div class="cell-sub"><?= htmlspecialchars($historyEmployee["position_name"] ?? "-", ENT_QUOTES, "UTF-8") ?></div>
                                    </td>
                                    <td><?= $historyRow["evaluated"] ?> / <?= $historyRow["total_kpi"] ?> KPI</td>
                                    <td class="col-number"><?= managerFormat($historyRow["avg_performance"]) ?></td>
                                    <td class="col-number"><?= managerFormat($historyRow["avg_competency"]) ?></td>
                                    <td>
                                        <?= managerFormat($historyRow["avg_score"]) ?>
                                        <div class="cell-sub">ถ่วงน้ำหนัก <?= managerFormat($historyRow["weighted_percent"], 1, "%") ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        <?php endif; ?>

    <?php endif; ?>


</div>


<?php if ($evidenceFeatureEnabled): ?>
    <dialog id="evidenceDialog" class="feedback-dialog evidence-dialog">
        <div class="evidence-dialog-content">
            <button type="button" class="dialog-close" id="evidenceClose" aria-label="ปิด"><?= icon("x", "icon-only") ?></button>
            <h2>ไฟล์หลักฐาน</h2>
            <p class="dialog-person" id="evidencePerson"></p>
            <div class="table-wrapper evidence-table-wrap">
                <table class="manager-table manager-table-compact">
                    <thead><tr><th>KPI</th><th>เดือน</th><th>ชื่อไฟล์</th><th>ขนาด</th><th>วันที่แนบ</th><th>ไฟล์</th></tr></thead>
                    <tbody id="evidenceTableBody"></tbody>
                </table>
            </div>
        </div>
    </dialog>
    <script>
        (function () {
            const rows = <?= $evidenceJson ?>;
            const months = <?= json_encode($monthNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const dialog = document.getElementById("evidenceDialog");
            const body = document.getElementById("evidenceTableBody");
            const title = document.getElementById("evidencePerson");
            const esc = function (value) { return String(value ?? ""); };
            document.getElementById("evidenceClose").addEventListener("click", function () { dialog.close(); });

            document.querySelectorAll(".evidence-btn:not(:disabled)").forEach(function (button) {
                button.addEventListener("click", function () {
                    const id = Number(button.dataset.evidenceId);
                    const selectedMonth = button.dataset.evidenceMonth ? Number(button.dataset.evidenceMonth) : <?= $filterMonth ?>;
                    const files = rows.filter(function (file) {
                        const same = button.dataset.evidenceKind === "employee"
                            ? Number(file.employee_id) === id
                            : Number(file.assignment_id) === id;
                        return same && (selectedMonth === 0 || Number(file.month) === selectedMonth);
                    });
                    title.textContent = button.dataset.evidenceName || "";
                    body.replaceChildren();
                    files.forEach(function (file) {
                        const tr = document.createElement("tr");
                        [file.kpi_name, (months[file.month] || file.month) + " <?= $filterYear ?>", file.original_name,
                         (Number(file.file_size) / 1024).toFixed(1) + " KB", file.uploaded_at].forEach(function (value) {
                            const td = document.createElement("td"); td.textContent = esc(value); tr.appendChild(td);
                        });
                        const actions = document.createElement("td");
                        const base = "../file-download.php?type=evidence&id=" + encodeURIComponent(file.evidence_id);
                        if (["jpg", "jpeg", "png", "pdf"].includes(String(file.file_ext).toLowerCase())) {
                            const view = document.createElement("a"); view.href = base + "&view=1"; view.target = "_blank"; view.rel = "noopener"; view.textContent = "เปิดดู"; actions.appendChild(view);
                            actions.appendChild(document.createTextNode(" · "));
                        }
                        const download = document.createElement("a"); download.href = base; download.textContent = "ดาวน์โหลด"; actions.appendChild(download);
                        tr.appendChild(actions); body.appendChild(tr);
                    });
                    if (!files.length) {
                        const tr = document.createElement("tr");
                        const td = document.createElement("td");
                        td.colSpan = 6; td.className = "empty-state"; td.textContent = "ยังไม่มีไฟล์หลักฐานในเดือนนี้";
                        tr.appendChild(td); body.appendChild(tr);
                    }
                    dialog.showModal();
                });
            });
        })();
    </script>
<?php endif; ?>


<?php if ($canFeedback): ?>

    <!-- =========================================================
         FEEDBACK DIALOG
    ========================================================== -->

    <dialog id="feedbackDialog" class="feedback-dialog">

                <form method="POST" action="feedback.php">

            <?= csrfField() ?>

            <button type="button" class="dialog-close" onclick="feedbackDialog.close()" aria-label="ปิด"><?= icon("x", "icon-only") ?></button>

            <h2>Feedback ถึงพนักงาน</h2>

            <p class="dialog-person" id="dialogPerson"></p>

            <input type="hidden" name="employee_id" id="feedbackEmployeeId">
            <input type="hidden" name="year" value="<?= $filterYear ?>">
            <input type="hidden" name="month" value="<?= $filterMonth ?>">
            <input type="hidden" name="department_id" value="<?= $filterDepartment ?>">
            <input type="hidden" name="return_employee_id" value="<?= $filterEmployee ?>">

            <label>
                รอบประเมิน (เดือน)
                <select name="period_id" id="feedbackPeriod" required>
                    <?php foreach ($feedbackPeriods as $periodMonth => $period): ?>
                        <option value="<?= (int) $period["period_id"] ?>" data-month="<?= (int) $periodMonth ?>">
                            <?= $monthNames[$periodMonth] ?> <?= $filterYear ?> (<?= htmlspecialchars($period["quarter"], ENT_QUOTES, "UTF-8") ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <p class="dialog-status" id="feedbackStatus"></p>

            <label>
                คะแนนประเมินจากหัวหน้า (0-100)
                <input type="number" name="evaluation_score" id="feedbackScore" min="0" max="100" step="0.1" required>
            </label>

            <label>
                Feedback ถึงพนักงาน
                <textarea name="feedback" id="feedbackText" rows="5" placeholder="จุดแข็ง สิ่งที่ควรพัฒนา และข้อเสนอแนะ" required></textarea>
            </label>

            <div class="dialog-actions">
                <button type="button" class="btn btn-secondary" onclick="feedbackDialog.close()"><?= icon("x") ?>ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><?= icon("send") ?>ส่ง Feedback</button>
            </div>

        </form>

    </dialog>

    <script>
        (function () {

            const dialog = document.getElementById("feedbackDialog");
            const periodSelect = document.getElementById("feedbackPeriod");
            const scoreInput = document.getElementById("feedbackScore");
            const textInput = document.getElementById("feedbackText");
            const status = document.getElementById("feedbackStatus");

            let feedbackMap = {};

            /* เติมคะแนน/ข้อความเดิมของเดือนที่เลือก (ถ้าเคยให้แล้ว) */
            function fillForm() {
                const option = periodSelect.options[periodSelect.selectedIndex];
                const saved = option ? feedbackMap[option.dataset.month] : null;

                scoreInput.value = saved ? saved.score : "";
                textInput.value = saved ? saved.feedback : "";
                status.textContent = saved
                    ? "เดือนนี้ให้ Feedback แล้ว · ส่งอีกครั้งเพื่อแก้ไข"
                    : "เดือนนี้ยังไม่ได้ให้ Feedback";
                status.classList.toggle("is-saved", Boolean(saved));
            }

            periodSelect.addEventListener("change", fillForm);

            document.querySelectorAll(".feedback-btn").forEach(function (button) {
                button.addEventListener("click", function () {

                    document.getElementById("feedbackEmployeeId").value = button.dataset.id;
                    document.getElementById("dialogPerson").textContent = button.dataset.name;

                    try {
                        feedbackMap = JSON.parse(button.dataset.feedbackMap || "{}");
                    } catch (error) {
                        feedbackMap = {};
                    }

                    const option = periodSelect.querySelector('option[data-month="' + button.dataset.month + '"]');

                    if (option) {
                        periodSelect.value = option.value;
                    }

                    fillForm();
                    dialog.showModal();
                    textInput.focus();
                });
            });

        })();
    </script>

<?php endif; ?>
