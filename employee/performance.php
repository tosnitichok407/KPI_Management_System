<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/quarter-helper.php";
require_once __DIR__ . "/../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/period-picker.php";
require_once __DIR__ . "/../includes/kpi-score-helper.php";
require_once __DIR__ . "/../includes/kpi-evidence.php";
require_once __DIR__ . "/../includes/kpi-import-history.php";
require_once __DIR__ . "/../includes/kpi-approval.php";
require_once __DIR__ . "/../includes/kpi-correction.php";
require_once __DIR__ . "/../includes/kpi-import-helper.php";
require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/feedback.php";


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$roleId = (int) ($_SESSION["role_id"] ?? 0);

if ($roleId !== 3) {
    if ($roleId === 1) {
        header("Location: ../admin/index.php");
    } else {
        redirectToRoleHome("../");
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| Employee
|--------------------------------------------------------------------------
*/

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| User Information
|--------------------------------------------------------------------------
*/

$firstName = $_SESSION["first_name"] ?? "";
$lastName = $_SESSION["last_name"] ?? "";
$employeeCode = $_SESSION["employee_code"] ?? "-";

$fullName = trim($firstName . " " . $lastName);

$thaiMonths = [
    1 => "มกราคม", 2 => "กุมภาพันธ์", 3 => "มีนาคม", 4 => "เมษายน",
    5 => "พฤษภาคม", 6 => "มิถุนายน", 7 => "กรกฎาคม", 8 => "สิงหาคม",
    9 => "กันยายน", 10 => "ตุลาคม", 11 => "พฤศจิกายน", 12 => "ธันวาคม"
];
$currentYear = (int) date("Y");
$currentMonth = (int) date("n");
$selectedYear = (int) ($_GET["year"] ?? $currentYear);
$selectedMonth = (int) ($_GET["month"] ?? $currentMonth);
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$selectedQuarter = getQuarterByMonth($selectedMonth);
$selectedMonthStart = sprintf("%04d-%02d-01", $selectedYear, $selectedMonth);
$selectedMonthEnd = date("Y-m-t", strtotime($selectedMonthStart));
$selectedPeriod = findEvaluationPeriodByMonth($pdo, $selectedYear, $selectedMonth);
$selectedPeriodId = $selectedPeriod ? (int) $selectedPeriod["period_id"] : 0;

/* ปุ่มเดือน: รอบประเมิน + จำนวนผลงานของพนักงานคนนี้ในแต่ละเดือน */
$monthStats = periodPickerMonthStats($pdo, $selectedYear, $employeeId);

/* Feedback จากหัวหน้าของเดือนที่เลือก */
$monthFeedback = $selectedPeriodId > 0
    ? employeeFeedbackList($pdo, $employeeId, ["period_id" => $selectedPeriodId])
    : [];

$quarterScoreStmt = $pdo->prepare("
    SELECT
        p.performance_date,
        k.kpi_type,
        p.score
    FROM kpi_performances p
    INNER JOIN kpi_assignments a ON a.assignment_id = p.assignment_id
    INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
    WHERE a.employee_id = :employee_id
    /* ประวัติรายไตรมาส: ทุกผลงานที่มีจริง รวม Assignment ที่ปิดใช้งาน (แถวผลงาน = เดือนที่มีผลงาน) */
    AND p.score IS NOT NULL
    ORDER BY p.performance_date
");
$quarterScoreStmt->execute([":employee_id" => $employeeId]);
$quarterScores = [];

foreach ($quarterScoreStmt->fetchAll(PDO::FETCH_ASSOC) as $quarterRow) {
    $year = (int) date("Y", strtotime($quarterRow["performance_date"]));
    $quarter = getQuarterFromDate($quarterRow["performance_date"]);
    $key = $year . "-" . $quarter;

    if (!isset($quarterScores[$key])) {
        $quarterScores[$key] = [
            "year" => $year,
            "quarter" => $quarter,
            "Performance" => [],
            "Competency" => [],
            "months" => []
        ];
    }

    if (isset($quarterScores[$key][$quarterRow["kpi_type"]])) {
        $quarterScores[$key][$quarterRow["kpi_type"]][] =
            (float) $quarterRow["score"];
    }

    $month = (int) date("n", strtotime($quarterRow["performance_date"]));
    $quarterScores[$key]["months"][$month][] = (float) $quarterRow["score"];
}

$yearStmt = $pdo->prepare("
    SELECT DISTINCT
        a.assignment_year
    FROM kpi_assignments a
    WHERE a.employee_id = :employee_id
      AND (
          a.status = 'Active'
          OR EXISTS (SELECT 1 FROM kpi_performances kx WHERE kx.assignment_id = a.assignment_id)
      )
    ORDER BY a.assignment_year DESC
");
$yearStmt->execute([":employee_id" => $employeeId]);
$availableYears = array_map("intval", array_column($yearStmt->fetchAll(PDO::FETCH_ASSOC), "assignment_year"));
if (!in_array($selectedYear, $availableYears, true) && !empty($availableYears) && !isset($_GET["year"])) {
    $selectedYear = $currentYear;
}


/*
|--------------------------------------------------------------------------
| Get KPI Assignments + Latest Performance
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        a.assignment_id,
        a.employee_id,
        a.period_id,
        a.assignment_year,
        a.kpi_id,
        a.weight,
        a.target_value AS assignment_target,
        a.status AS assignment_status,

        k.kpi_name,
        k.description,
        k.kpi_type,
        k.unit,
        k.max_score,

        a.start_date,
        a.end_date,

        p.performance_id,
        p.performance_date,
        p.target,
        p.actual,
        p.score,
        p.comment,
        p.status AS performance_status

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    LEFT JOIN kpi_performances p
        ON p.performance_id = (

            SELECT kp.performance_id

            FROM kpi_performances kp

            WHERE kp.assignment_id = a.assignment_id
            AND kp.employee_id = :employee_id_sub
            AND kp.period_id = :period_id_sub
            ORDER BY kp.performance_date DESC,
                     kp.performance_id DESC

            LIMIT 1
        )

    WHERE a.employee_id = :employee_id

    /* Inactive แสดงเป็นประวัติเฉพาะเมื่อมีผลงานในเดือนที่เลือก (นับรวมในสรุปของเดือนนั้นด้วย) */
    AND (a.status = 'Active' OR p.performance_id IS NOT NULL)

    -- KPI ที่มีผลในเดือนที่เลือก (Assignment เดียวใช้ได้ทุกเดือนในช่วงเวลา)
    AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
    AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :month_start

    ORDER BY
        a.assignment_id ASC
";


try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":employee_id_sub" => $employeeId,
        ":period_id_sub" => $selectedPeriodId,
        ":employee_id" => $employeeId,
        ":month_start" => $selectedMonthStart,
        ":month_end" => $selectedMonthEnd
    ]);

    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

        error_log("employee/performance: " . $e->getMessage());

    $assignments = [];

    $error = "ไม่สามารถโหลดข้อมูล KPI ได้";
}

/*
|--------------------------------------------------------------------------
| Prepare KPI Data
|--------------------------------------------------------------------------
*/

$totalKpi = count($assignments);

$totalWeight = 0;

$totalScore = 0;

$scoreCount = 0;

$totalPerformanceRecords = 0;


/*
|--------------------------------------------------------------------------
| Group By Evaluation Period
|--------------------------------------------------------------------------
*/

$periods = [];


foreach ($assignments as $assignment) {

    $periodId = $selectedPeriodId;


    /*
    --------------------------------------------------------------
    Create Period
    --------------------------------------------------------------
    */

    if (!isset($periods[$periodId])) {

        $periods[$periodId] = [

            "period_name" =>
            ($selectedPeriod["period_name"] ?? "ประจำเดือน" . $thaiMonths[$selectedMonth])
                . " " . $selectedYear . " (" . $selectedQuarter . ")",

            "start_date" =>
            $selectedMonthStart,

            "end_date" =>
            $selectedMonthEnd,

            "status" =>
            $selectedPeriod["status"] ?? "ยังไม่เปิดรอบประเมิน",

            "kpis" => []

        ];
    }


    /*
    --------------------------------------------------------------
    Weight
    --------------------------------------------------------------
    */

    $totalWeight +=
        (float) $assignment["weight"];


    /*
    --------------------------------------------------------------
    Performance
    --------------------------------------------------------------
    */

    $target = $assignment["assignment_target"] !== null
        ? (float) $assignment["assignment_target"]
        : null;
    $actual = null;
    $score = null;
    $progress = 0;

    $performanceStatus = null;

    $performanceDate = null;

    $comment = null;


    if (
        !empty($assignment["performance_id"])
    ) {

        /* KPI 9: ใช้ Target ที่บันทึกไว้กับผลงานของเดือนนี้ (นำเข้าจากไฟล์บริษัท = 100) แทนค่าใน Assignment */
        if (isNonRenewalKpi($assignment) && $assignment["target"] !== null) {
            $target = (float) $assignment["target"];
        }

        $actual =
            $assignment["actual"] !== null
            ? (float) $assignment["actual"]
            : null;


        $score =
            $assignment["score"] !== null
            ? (float) $assignment["score"]
            : null;


        $performanceDate =
            $assignment["performance_date"];


        $performanceStatus =
            $assignment["performance_status"];


        $comment =
            $assignment["comment"];


        /*
        ----------------------------------------------------------
        Calculate Progress
        ----------------------------------------------------------
        */

        if (
            $target !== null &&
            $target > 0 &&
            $actual !== null
        ) {

            /* KPI 9 ใช้ Achievement จากฟังก์ชันกลาง (Total R / Budget × 100) */
            $progress = isNonRenewalKpi($assignment)
                ? (float) nonRenewalAchievement($actual, $target)
                : ($actual / $target) * 100;


            /*
            Keep Progress maximum at 100%
            */

            if ($progress > 100) {
                $progress = 100;
            }


            if ($progress < 0) {
                $progress = 0;
            }
        }


        /*
        ----------------------------------------------------------
        Average Score
        ----------------------------------------------------------
        */

        if ($score !== null) {

            $totalScore += $score;

            $scoreCount++;
        }


        $totalPerformanceRecords++;
    }

    /*
    --------------------------------------------------------------
    Add KPI
    --------------------------------------------------------------
    */

    $assignment["target_value"] =
        $target;

    $assignment["actual_value"] =
        $actual;

    $assignment["score_value"] =
        $score;

    $assignment["progress"] =
        $progress;

    $assignment["performance_date_value"] =
        $performanceDate;

    $assignment["performance_status_value"] =
        $performanceStatus;

    $assignment["comment_value"] =
        $comment;


    $periods[$periodId]["kpis"][] =
        $assignment;
}


/*
|--------------------------------------------------------------------------
| Average Score
|--------------------------------------------------------------------------
*/

/* ไฟล์หลักฐานของผลงานในเดือนที่เลือก (ดู includes/kpi-evidence.php) */
$evidencePerformanceIds = [];
foreach ($periods as $periodData) {
    foreach ($periodData["kpis"] as $periodKpi) {
        if (!empty($periodKpi["performance_id"])) {
            $evidencePerformanceIds[] = (int) $periodKpi["performance_id"];
        }
    }
}

$evidenceEnabled = kpiFileFeatureEnabled($pdo, "kpi_performance_evidence");
try {
    $evidenceByPerformance = $evidenceEnabled ? kpiEvidenceForPerformances($pdo, $evidencePerformanceIds) : [];
} catch (Throwable $exception) {
    error_log("performance: evidence query failed (" . get_class($exception) . ")");
    $evidenceByPerformance = [];
}

/* ผลการแนบ / ลบหลักฐาน (จาก kpi-evidence-action.php) */
$evidenceFlash = $_SESSION["perf_evidence_flash"] ?? null;
unset($_SESSION["perf_evidence_flash"]);

/* ขั้นตอนส่งผล / อนุมัติของเดือนที่เลือก (ปิดอัตโนมัติเมื่อยังไม่ได้รัน Migration 20261025) */
$approvalFlash = $_SESSION["perf_approval_flash"] ?? null;
unset($_SESSION["perf_approval_flash"]);
$approvalEnabled = kpiApprovalEnabled($pdo) && $selectedPeriodId > 0;
$approvalState = $approvalEnabled ? kpiApprovalMonthState($pdo, $employeeId, $selectedPeriodId) : null;
$approvalHistory = $approvalEnabled ? kpiApprovalHistory($pdo, $employeeId, $selectedPeriodId) : [];
$approvalLastReturn = null;
foreach ($approvalHistory as $approvalLog) {
    if ($approvalLog["action"] === "return") {
        $approvalLastReturn = $approvalLog;
        break;
    }
    if ($approvalLog["action"] === "submit") {
        break;
    }
}

$averageScore = 0;

/* คะแนนรวมถ่วงน้ำหนักของเดือนที่เลือก (สูตรเดียวกับ Dashboard / Manager / Admin / แบบฟอร์ม KPI AM) */
$monthWeightRows = [];
foreach ($periods as $periodData) {
    foreach ($periodData["kpis"] as $periodKpi) {
        $monthWeightRows[] = [
            "score" => $periodKpi["score_value"],
            "weight" => $periodKpi["weight"],
            "max_score" => $periodKpi["max_score"] ?? null
        ];
    }
}
$monthWeighted = kpiWeightedSummary($monthWeightRows);

if ($scoreCount > 0) {

    $averageScore =
        $totalScore / $scoreCount;
}

/*
|--------------------------------------------------------------------------
| Overall Progress
|--------------------------------------------------------------------------
*/

$overallProgress = 0;

if ($totalKpi > 0) {
    $progressSum = 0;
    $progressCount = 0;

    /*
    | ค่าความคืบหน้าคำนวณไว้ใน $periods (เดิมวนจาก $assignments ซึ่งไม่มีค่า progress จึงได้ 0% เสมอ)
    | นับเฉพาะ KPI ที่มีผลงานและมี Actual (Competency ไม่มี Actual จึงไม่นับ)
    */
    foreach ($periods as $periodData) {
        foreach ($periodData["kpis"] as $periodKpi) {
            if (!empty($periodKpi["performance_id"]) && $periodKpi["actual_value"] !== null) {
                $progressSum += (float) $periodKpi["progress"];
                $progressCount++;
            }
        }
    }

    if ($progressCount > 0) {

        $overallProgress =
            $progressSum / $progressCount;
    }
}

/*
|--------------------------------------------------------------------------
| Status Text
|--------------------------------------------------------------------------
*/

if ($totalPerformanceRecords === 0) {

    $overallStatus = "ยังไม่มีผลงาน";
} elseif ($averageScore >= 4) {

    $overallStatus = "ผลการปฏิบัติงานดี";
} elseif ($averageScore >= 3) {

    $overallStatus = "อยู่ในเกณฑ์ปกติ";
} else {

    $overallStatus = "ควรปรับปรุง";
}

/*
|--------------------------------------------------------------------------
| รูปแบบตัวเลขที่แสดง: จำนวนเต็ม ปัดแบบปกติ (.5 ขึ้นไปปัดขึ้น: 107.05 → 107 · 3.5 → 4 · 87.5 → 88 · 100 → 100) เหมือนกันทุกเดือน
| ใช้แสดงผลเท่านั้น · คะแนนคำนวณจากค่าจริงที่บันทึก (ไม่ปัด)
| round() 6 ตำแหน่งก่อน กันค่าคลาดเคลื่อนของ float (เช่น 3.4999999999 จากการคำนวณต้องปัดเป็น 4 เหมือน 3.5)
|--------------------------------------------------------------------------
*/
function perfFormatNumber(float $value): string
{
    return number_format(round(round($value, 6), 0, PHP_ROUND_HALF_UP));
}

/* ค่า Target / Actual / Achievement: แสดงทศนิยมตามที่บันทึก (สูงสุด 2 ตำแหน่ง) ไม่ปัดเป็นจำนวนเต็ม เช่น 3.5 → 3.5 · 107.05 → 107.05 */
function perfFormatDecimal(float $value): string
{
    $value = round($value, 2);

    return floor($value) == $value
        ? number_format($value)
        : rtrim(rtrim(number_format($value, 2), "0"), ".");
}

function perfFormatValue($value, string $unit): string
{
    $text = perfFormatDecimal((float) $value);

    if ($unit === "%") {
        return $text . "%";
    }

    return $unit === ""
        ? $text
        : $text . ' <span class="perf-unit">' . htmlspecialchars($unit, ENT_QUOTES, "UTF-8") . "</span>";
}

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <title>
        Performance | KPI Management System
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/icons.css?v=1">

        <link
        rel="stylesheet"
        href="../assets/css/employee-kpi.css?v=layout-employee-2">

    <link
        rel="stylesheet"
        href="../assets/css/period-picker.css?v=1">

    <link
        rel="stylesheet"
        href="../assets/css/employee-feedback.css?v=1">

    <link
        rel="stylesheet"
        href="../assets/css/employee-performance.css?v=cards-4">

</head>


<body>

    <?php employeeLayoutStart("performance", "../"); ?>

        <!-- === PAGE HEADER === -->
        <section class="page-header">
            <div>
                <h1>
                    ผลการปฏิบัติงาน
                </h1>

                <p>
                    ติดตามผลการประเมิน KPI ของคุณ
                </p>
            </div>

        </section>

        <!-- === FEEDBACK จากหัวหน้า (เดือนที่เลือก) === -->

        <?php
        renderFeedbackCard($monthFeedback, [
            "root" => "../",
            "title" => "Feedback จากหัวหน้า",
            "subtitle" => "ประจำเดือน" . $thaiMonths[$selectedMonth] . " " . $selectedYear,
            "link" => ["href" => "feedback.php?year=" . $selectedYear, "label" => "ดูทั้งหมด"],
            "empty" => $selectedPeriod
                ? "หัวหน้ายังไม่ได้ให้ Feedback ของเดือนนี้"
                : "ยังไม่มีรอบประเมินของเดือนนี้"
        ]);
        ?>

        <?php if (is_array($approvalFlash)): ?>
            <div class="perf-flash perf-flash-<?= $approvalFlash["type"] === "success" ? "success" : "error" ?>" role="status">
                <?= htmlspecialchars((string) $approvalFlash["message"], ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($evidenceFlash)): ?>
            <div class="perf-flash perf-flash-<?= $evidenceFlash["type"] === "success" ? "success" : "error" ?>" role="status">
                <?= htmlspecialchars((string) $evidenceFlash["message"], ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>

            <div class="alert-error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <!-- === SUMMARY === -->
        <section class="summary-grid">

            <div class="summary-card">

                <div class="summary-icon">
                    <?= icon("target") ?>
                </div>

                <div>
                    <span>
                        KPI ทั้งหมด
                    </span>

                    <strong>

                        <?= count($assignments) ?>

                    </strong>

                </div>
            </div>
            <div class="summary-card">
                <div>
                    <span>
                        Weight รวม
                    </span>

                    <strong class="performance-card-value">

                        <?= perfFormatNumber((float) $totalWeight) ?>%

                    </strong>
                </div>
            </div>

            <div class="summary-card">
                <div>

                    <span>
                        คะแนนรวม (ถ่วงน้ำหนัก)
                    </span>

                    <strong class="performance-card-value">
                        <?= $monthWeighted["percent"] === null ? "-" : number_format($monthWeighted["percent"], 1) . "%" ?>
                    </strong>

                    <?php if ($monthWeighted["percent"] !== null): ?>
                        <small class="summary-card-note">
                            คะแนนจริง <?= number_format($monthWeighted["real"]) ?> / <?= number_format($monthWeighted["full"]) ?> · ประเมินแล้ว <?= (int) $monthWeighted["evaluated"] ?> KPI
                        </small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-card">
                <div>

                    <span>
                        คะแนนเฉลี่ย (ไม่ถ่วงน้ำหนัก)
                    </span>

                    <strong class="performance-card-value">
                        <?= $scoreCount > 0 ? number_format($averageScore, 2) . " / 5" : "-" ?>
                    </strong>

                    <?php if ($scoreCount > 0): ?>
                        <small class="summary-card-note">ค่าเฉลี่ยระดับ 1–5 ของ KPI ที่ประเมินแล้ว ไม่ใช่ผลประเมินรวม</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-card">

                <div class="summary-icon">
                    <?= icon("trending-up") ?>
                </div>

                <div>

                    <span>
                        ความคืบหน้าเฉลี่ย
                    </span>

                    <strong class="performance-card-value">

                        <?= perfFormatNumber((float) $overallProgress) ?>%

                    </strong>
                </div>
            </div>
        </section>

        <!-- === คะแนนรายไตรมาส / รายเดือน (ใต้การ์ดสรุป KPI · เหนือกล่องประจำเดือน) === -->
        <section class="quarter-score-grid">
            <?php foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter): ?>
                <?php
                $quarterKey = $selectedYear . "-" . $quarter;
                $quarterData = $quarterScores[$quarterKey] ?? null;
                $allScores = $quarterData
                    ? array_merge($quarterData["Performance"], $quarterData["Competency"])
                    : [];
                $overallAverage = !empty($allScores)
                    ? array_sum($allScores) / count($allScores)
                    : null;
                ?>
                <article class="quarter-score-card">
                    <h3><?= $quarter ?></h3>
                    <p><?= $selectedYear ?></p>
                    <?php if ($overallAverage === null): ?>
                        <strong>ยังไม่มีข้อมูล</strong>
                    <?php else: ?>
                        <strong><?= number_format($overallAverage, 2) ?> / 5</strong>
                        <p class="quarter-score-note">คะแนนเฉลี่ย (ไม่ถ่วงน้ำหนัก)</p>
                        <?php foreach (getQuarterMonths($quarter) as $month): ?>
                            <?php
                            $monthValues = $quarterData["months"][$month] ?? [];
                            $monthAverage = !empty($monthValues)
                                ? array_sum($monthValues) / count($monthValues)
                                : null;
                            ?>
                            <p>
                                <?= date("M", mktime(0, 0, 0, $month, 1)) ?>:
                                <?= $monthAverage === null ? "-" : number_format($monthAverage, 2) ?>
                            </p>
                        <?php endforeach; ?>
                        <p>
                            Performance: <?= !empty($quarterData["Performance"]) ? number_format(array_sum($quarterData["Performance"]) / count($quarterData["Performance"]), 2) : "-" ?><br>
                            Competency: <?= !empty($quarterData["Competency"]) ? number_format(array_sum($quarterData["Competency"]) / count($quarterData["Competency"]), 2) : "-" ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- === ปี + เดือน (คลิกแล้วโหลดทันที) + Export === -->

        <section class="filter-card">

            <?php
            renderPeriodPicker([
                "year" => $selectedYear,
                "month" => $selectedMonth,
                "stats" => $monthStats,
                                "show_all" => false,
                "dot_label" => "มีผลงานที่บันทึกแล้ว",
                "url" => fn(int $year, int $month): string => "performance.php?year={$year}&month={$month}"
            ]);
            ?>

            <div class="filter-footer">

                <div class="filter-note">

                    <?php if ($selectedPeriod): ?>

                        รอบประเมิน:
                        <strong><?= htmlspecialchars($selectedPeriod["period_name"], ENT_QUOTES, "UTF-8") ?> <?= $selectedYear ?></strong>
                        · <?= htmlspecialchars($selectedPeriod["quarter"], ENT_QUOTES, "UTF-8") ?>
                        · <?= date("d/m/Y", strtotime($selectedPeriod["start_date"])) ?>
                        - <?= date("d/m/Y", strtotime($selectedPeriod["end_date"])) ?>
                        · <?= htmlspecialchars($selectedPeriod["status"], ENT_QUOTES, "UTF-8") ?>

                    <?php else: ?>

                        <?= htmlspecialchars($thaiMonths[$selectedMonth], ENT_QUOTES, "UTF-8") ?> <?= $selectedYear ?>
                        (<?= $selectedQuarter ?>) · ยังไม่มีรอบประเมินของเดือนนี้

                    <?php endif; ?>

                </div>

                <?php if ($selectedPeriod && !empty($assignments)): ?>

                    <div class="performance-export-actions">

                        <a
                            href="performance-export-pdf.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                            class="performance-export-button pdf"
                            target="_blank"
                            rel="noopener">
                            <?= icon("file-text") ?> Export PDF
                        </a>

                        <a
                            href="performance-export-excel.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                            class="performance-export-button excel">
                            <?= icon("file-spreadsheet") ?> Export Excel
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- === PERIODS === -->
        <?php if (empty($periods)): ?>

            <section class="empty-card">

                <div class="empty-icon">
                    <?= icon("clipboard-list") ?>
                </div>

                <h2>
                    ยังไม่มี KPI ที่ได้รับมอบหมายในเดือนนี้
                </h2>

                <p>
                    สำหรับเดือน <?= htmlspecialchars($thaiMonths[$selectedMonth], ENT_QUOTES, "UTF-8") ?> <?= $selectedYear ?><br>
                    กรุณาติดต่อผู้ดูแลระบบหากมีข้อสงสัย
                </p>

            </section>

        <?php else: ?>

            <?php foreach ($periods as $period): ?>

                <section class="period-card">

                    <!-- PERIOD HEADER -->
                    <div class="period-header">

                        <div>
                            <h2>
                                <?= htmlspecialchars(
                                    $period["period_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </h2>

                            <p>

                                <?= htmlspecialchars(
                                    $period["start_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                                ถึง

                                <?= htmlspecialchars(
                                    $period["end_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </p>

                        </div>

                        <span class="period-status">

                            <?= htmlspecialchars(
                                $period["status"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>

                    </div>

                    <?php if ($approvalEnabled && $approvalState !== null): ?>
                        <?php
                        $approvalStateKey = $approvalState["state"];
                        $approvalCanSubmit = $approvalState["can_submit"]
                            && ($selectedPeriod["status"] ?? "") === "Open";
                        ?>
                        <div class="perf-approval perf-approval-<?= htmlspecialchars(strtolower($approvalStateKey), ENT_QUOTES, "UTF-8") ?>">
                            <div class="perf-approval-head">
                                <div>
                                    <span class="perf-approval-label">สถานะการส่งผลการประเมิน</span>
                                    <strong class="perf-approval-badge"><?= htmlspecialchars(KPI_STATUS_LABELS[$approvalStateKey] ?? $approvalStateKey, ENT_QUOTES, "UTF-8") ?></strong>
                                </div>
                                <span class="perf-approval-count">มีผลงาน <?= (int) $approvalState["rows"] ?> / <?= (int) $approvalState["assigned"] ?> KPI</span>
                            </div>

                            <?php if ($approvalStateKey === "Rejected" && $approvalLastReturn !== null): ?>
                                <p class="perf-approval-return">
                                    <strong>Manager ส่งกลับให้แก้ไข:</strong>
                                    <?= nl2br(htmlspecialchars((string) $approvalLastReturn["comment"], ENT_QUOTES, "UTF-8")) ?>
                                    <span>· <?= htmlspecialchars(date("d/m/Y H:i", strtotime((string) $approvalLastReturn["created_at"])), ENT_QUOTES, "UTF-8") ?></span>
                                </p>
                            <?php endif; ?>

                            <?php if ($approvalCanSubmit): ?>
                                <form method="post" action="kpi-submit-action.php" class="perf-approval-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                                    <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                                    <button type="submit" class="perf-approval-submit" data-confirm="ส่งผลการประเมินเดือน<?= htmlspecialchars($thaiMonths[$selectedMonth], ENT_QUOTES, "UTF-8") ?> <?= (int) $selectedYear ?> (<?= (int) $approvalState["rows"] ?> KPI) ให้ Manager ตรวจสอบ? หลังส่งแล้วจะนำเข้าทับ เพิ่ม KPI หรือแนบหลักฐานของเดือนนี้ไม่ได้ จนกว่า Manager จะส่งกลับ">
                                        <?= icon("send") ?>ส่งผลการประเมินให้ Manager
                                    </button>
                                    <span class="perf-approval-note">
                                        <?php if ($approvalState["rows"] < $approvalState["assigned"]): ?>
                                            ยังมี KPI ที่ไม่มีผลงาน <?= (int) ($approvalState["assigned"] - $approvalState["rows"]) ?> รายการ · ส่งเฉพาะรายการที่มีผลงาน และเพิ่มภายหลังไม่ได้จนกว่า Manager จะส่งกลับ
                                        <?php else: ?>
                                            ตรวจผลงานและหลักฐานให้ครบก่อนส่ง
                                        <?php endif; ?>
                                    </span>
                                </form>
                            <?php elseif ($approvalStateKey === "Submitted"): ?>
                                <p class="perf-approval-note">ส่งแล้ว รอ Manager ตรวจสอบ · แก้ไข นำเข้าทับ และแนบหลักฐานไม่ได้ระหว่างรออนุมัติ</p>
                            <?php elseif ($approvalStateKey === "Approved"): ?>
                                <p class="perf-approval-note">Manager อนุมัติผลการประเมินของเดือนนี้แล้ว</p>
                            <?php elseif ($approvalStateKey === "None"): ?>
                                <p class="perf-approval-note">ยังไม่มีผลงานของเดือนนี้ · นำเข้าไฟล์ Excel ก่อนส่งผลการประเมิน</p>
                            <?php endif; ?>

                            <?php if ($approvalHistory): ?>
                                <details class="perf-approval-history">
                                    <summary>ประวัติการดำเนินการ (<?= count($approvalHistory) ?>)</summary>
                                    <ul>
                                        <?php foreach ($approvalHistory as $approvalLog): ?>
                                            <li>
                                                <strong><?= htmlspecialchars(KPI_APPROVAL_ACTION_LABELS[$approvalLog["action"]] ?? $approvalLog["action"], ENT_QUOTES, "UTF-8") ?></strong>
                                                · <?= htmlspecialchars(kpiApprovalActorName($approvalLog), ENT_QUOTES, "UTF-8") ?>
                                                · <?= htmlspecialchars(date("d/m/Y H:i", strtotime((string) $approvalLog["created_at"])), ENT_QUOTES, "UTF-8") ?>
                                                · <?= (int) $approvalLog["performance_count"] ?> KPI
                                                <?php if (trim((string) $approvalLog["comment"]) !== ""): ?>
                                                    <div class="perf-approval-comment"><?= nl2br(htmlspecialchars((string) $approvalLog["comment"], ENT_QUOTES, "UTF-8")) ?></div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($evidenceEnabled): ?>
                        <?php
                        $periodEvidenceKpis = array_values(array_filter($period["kpis"], fn($kpi) => !empty($kpi["performance_id"])));
                        $periodEvidenceFiles = [];
                        $periodEvidenceEditable = count($periodEvidenceKpis) > 0;
                        $periodEvidenceAtLimit = false;
                        foreach ($periodEvidenceKpis as $evidenceKpi) {
                            $evidenceKpiFiles = $evidenceByPerformance[(int) $evidenceKpi["performance_id"]] ?? [];
                            foreach ($evidenceKpiFiles as $evidenceFile) {
                                $evidenceFile["kpi_name"] = $evidenceKpi["kpi_name"];
                                $evidenceFile["performance_id"] = (int) $evidenceKpi["performance_id"];
                                $periodEvidenceFiles[] = $evidenceFile;
                            }
                            if (!kpiStatusIsEditable($evidenceKpi["performance_status"] ?? "")) $periodEvidenceEditable = false;
                            if (count($evidenceKpiFiles) >= KPI_EVIDENCE_MAX_FILES) $periodEvidenceAtLimit = true;
                        }
                        ?>
                        <details class="perf-evidence perf-evidence-period"<?= is_array($evidenceFlash) ? " open" : "" ?>>
                            <summary><?= icon("file-text") ?>หลักฐานของเดือนนี้ (<?= count($periodEvidenceFiles) ?>)</summary>
                            <?php if ($periodEvidenceFiles): ?>
                                <ul class="perf-evidence-list">
                                    <?php foreach ($periodEvidenceFiles as $evidence): ?>
                                        <?php $viewable = in_array($evidence["file_ext"], ["jpg", "jpeg", "png", "pdf"], true); ?>
                                        <li>
                                            <strong><?= htmlspecialchars($evidence["kpi_name"], ENT_QUOTES, "UTF-8") ?>:</strong>
                                            <a href="../file-download.php?type=evidence&amp;id=<?= (int) $evidence["evidence_id"] ?><?= $viewable ? "&amp;view=1" : "" ?>"<?= $viewable ? ' target="_blank" rel="noopener"' : "" ?>><?= htmlspecialchars($evidence["original_name"], ENT_QUOTES, "UTF-8") ?></a>
                                            <span class="perf-evidence-meta"><?= strtoupper(htmlspecialchars($evidence["file_ext"], ENT_QUOTES, "UTF-8")) ?> · <?= kpiFormatFileSize((int) $evidence["file_size"]) ?> · <?= htmlspecialchars(date("d/m/Y H:i", strtotime((string) $evidence["uploaded_at"])), ENT_QUOTES, "UTF-8") ?></span>
                                            <?php if ($periodEvidenceEditable): ?>
                                                <form method="post" action="kpi-evidence-action.php" class="perf-evidence-delete">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="evidence_id" value="<?= (int) $evidence["evidence_id"] ?>">
                                                    <input type="hidden" name="performance_id" value="<?= (int) $evidence["performance_id"] ?>">
                                                    <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                                                    <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                                                    <button type="submit" data-confirm="ลบไฟล์ <?= htmlspecialchars($evidence["original_name"], ENT_QUOTES, "UTF-8") ?> ?" aria-label="ลบไฟล์">ลบ</button>
                                                </form>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="perf-evidence-empty">ยังไม่มีไฟล์หลักฐาน</p>
                            <?php endif; ?>
                            <?php if (!$periodEvidenceKpis): ?>
                                <p class="perf-evidence-note">ยังไม่มีรายการผลงาน KPI สำหรับแนบหลักฐาน</p>
                            <?php elseif (!$periodEvidenceEditable): ?>
                                <p class="perf-evidence-note">แนบหรือลบหลักฐานได้เมื่อผลงาน KPI ทุกตัวยังไม่ได้ส่ง (Draft) หรือถูกส่งกลับให้แก้ไข</p>
                            <?php elseif ($periodEvidenceAtLimit): ?>
                                <p class="perf-evidence-note">KPI อย่างน้อยหนึ่งรายการมีไฟล์ครบ <?= KPI_EVIDENCE_MAX_FILES ?> ไฟล์แล้ว</p>
                            <?php else: ?>
                                <form method="post" action="kpi-evidence-action.php" enctype="multipart/form-data" class="perf-evidence-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="upload_all">
                                    <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                                    <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                                    <input type="file" name="evidence_file" accept="<?= KPI_EVIDENCE_ACCEPT ?>" required aria-label="เลือกไฟล์หลักฐานร่วมกันสำหรับ KPI ทุกตัว">
                                    <button type="submit">แนบไฟล์ให้ทุก KPI</button>
                                    <span class="perf-evidence-note">Excel / รูปภาพ (JPG, PNG) / PDF · ไฟล์ละไม่เกิน 10 MB · สูงสุด <?= KPI_EVIDENCE_MAX_FILES ?> ไฟล์ต่อ KPI</span>
                                </form>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>

                    <?php
                    /* แยก KPI ตามประเภท: Performance (Target / Actual / ความสำเร็จ) และ Competency (คะแนน 1-5) */
                    $kpiGroups = [
                        "performance" => [
                            "title" => "KPI ผลงาน (Performance)",
                            "icon" => "target",
                            "kpis" => []
                        ],
                        "competency" => [
                            "title" => "สมรรถนะ (Competency)",
                            "icon" => "award",
                            "kpis" => []
                        ]
                    ];

                    foreach ($period["kpis"] as $kpi) {
                        $groupKey = strtolower(trim((string) $kpi["kpi_type"])) === "competency" ? "competency" : "performance";
                        $kpiGroups[$groupKey]["kpis"][] = $kpi;
                    }
                    ?>

                    <div class="perf-groups">

                        <?php foreach ($kpiGroups as $groupKey => $group): ?>

                            <?php if (empty($group["kpis"])) continue; ?>

                            <?php
                            $groupRecorded = array_values(array_filter($group["kpis"], fn($item) => !empty($item["performance_id"])));
                            $groupScores = array_values(array_filter(array_column($groupRecorded, "score_value"), fn($value) => $value !== null));
                            $groupWeight = array_sum(array_map(fn($item) => (float) $item["weight"], $group["kpis"]));
                            $groupAverage = $groupScores ? array_sum($groupScores) / count($groupScores) : null;
                            ?>

                            <section class="perf-group perf-group-<?= $groupKey ?>">

                                <header class="perf-group-header">

                                    <h3>
                                        <?= icon($group["icon"]) ?><?= htmlspecialchars($group["title"], ENT_QUOTES, "UTF-8") ?>
                                    </h3>

                                    <div class="perf-group-stats">
                                        <span>บันทึกแล้ว <strong><?= count($groupRecorded) ?> / <?= count($group["kpis"]) ?></strong></span>
                                        <span>น้ำหนักรวม <strong><?= perfFormatNumber($groupWeight) ?>%</strong></span>
                                        <span>คะแนนเฉลี่ย <strong><?= $groupAverage === null ? "-" : number_format($groupAverage, 2) ?></strong></span>
                                    </div>

                                </header>

                                <table class="perf-table">

                                    <thead>
                                        <tr>
                                            <th scope="col">KPI</th>
                                            <th scope="col" class="num">น้ำหนัก</th>
                                            <?php if ($groupKey === "performance"): ?>
                                                <th scope="col" class="num">Target</th>
                                                <th scope="col" class="num">Actual</th>
                                                <th scope="col">ความสำเร็จ</th>
                                            <?php endif; ?>
                                            <th scope="col" class="center">คะแนน</th>
                                            <th scope="col" class="center">สถานะ</th>
                                            <th scope="col"><span class="visually-hidden">ดูรายละเอียด</span></th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($group["kpis"] as $kpi): ?>

                                            <?php
                                            $hasResult = !empty($kpi["performance_id"]);
                                            $isBudget = isNonRenewalKpi($kpi);
                                            $unit = kpiDisplayUnit($kpi);
                                            $level = $hasResult && $kpi["score_value"] !== null ? kpiScoreLevel((float) $kpi["score_value"]) : null;
                                            $statusText = (string) ($kpi["performance_status_value"] ?? "");
                                            $detailUrl = "kpi-detail.php?assignment_id=" . (int) $kpi["assignment_id"] . "&year={$selectedYear}&month={$selectedMonth}&period_id={$selectedPeriodId}";
                                            /* KPI 12: กรอก "ทั้งหมด" / "นำกลับ" ในหน้านี้ (employee/kpi12-entry-action.php) · แก้ได้เมื่อรอบ Open และยังไม่ส่ง / ไม่อนุมัติ */
                                            $isFormKpi = $groupKey === "performance" && (int) $kpi["kpi_id"] === KPI12_FORM_KPI_ID;
                                            $formEditable = $isFormKpi && ($selectedPeriod["status"] ?? "") === "Open"
                                                && ($kpi["assignment_status"] ?? "Active") === "Active"
                                                && !in_array($statusText, ["Submitted", "Approved"], true);
                                            $formValues = $isFormKpi && $hasResult ? kpi12FormParseComment($kpi["comment_value"] ?? null) : null;
                                            ?>

                                            <tr class="<?= $hasResult ? "" : "is-empty" ?>">

                                                <th scope="row" class="perf-kpi" data-label="KPI">

                                                    <span class="perf-kpi-name">
                                                        <?= htmlspecialchars($kpi["kpi_name"], ENT_QUOTES, "UTF-8") ?>
                                                        <?php if (($kpi["assignment_status"] ?? "Active") !== "Active"): ?>
                                                            <span class="perf-badge-inactive" title="การมอบหมายนี้ปิดใช้งานแล้ว แสดงเป็นประวัติเพราะมีผลงานในเดือนนี้">ปิดใช้งาน</span>
                                                        <?php endif; ?>
                                                        <?php if (isset(KPI_CRITERIA_PENDING_CONFIRMATION[(int) $kpi["kpi_id"]])): ?>
                                                            <span class="perf-badge-pending" title="<?= htmlspecialchars(KPI_CRITERIA_PENDING_CONFIRMATION[(int) $kpi["kpi_id"]], ENT_QUOTES, "UTF-8") ?>">เกณฑ์รอยืนยัน</span>
                                                        <?php endif; ?>
                                                    </span>

                                                    <?php if (trim((string) ($kpi["description"] ?? "")) !== ""): ?>
                                                        <span class="perf-kpi-desc" title="<?= htmlspecialchars($kpi["description"], ENT_QUOTES, "UTF-8") ?>">
                                                            <?= htmlspecialchars($kpi["description"], ENT_QUOTES, "UTF-8") ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($hasResult && !empty($kpi["comment_value"])): ?>
                                                        <span class="perf-kpi-comment">
                                                            หมายเหตุ: <?= htmlspecialchars($kpi["comment_value"], ENT_QUOTES, "UTF-8") ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($hasResult && !empty($kpi["performance_date_value"])): ?>
                                                        <span class="perf-kpi-date">
                                                            บันทึก <?= htmlspecialchars($kpi["performance_date_value"], ENT_QUOTES, "UTF-8") ?>
                                                        </span>
                                                    <?php endif; ?>

                                                </th>

                                                <td class="num" data-label="น้ำหนัก"><?= perfFormatNumber((float) $kpi["weight"]) ?>%</td>

                                                <?php if ($groupKey === "performance"): ?>

                                                    <?php /* KPI 9 แสดงแบบเดียวกับ KPI อื่น: Target 100% · Actual = ร้อยละ (Actual ÷ Target × 100 · นำเข้าจาก สรุปทั้งปี!U) */ ?>
                                                    <td class="num" data-label="Target">
                                                        <?php if ($isBudget): ?>
                                                            100%
                                                        <?php elseif ($isFormKpi): ?>
                                                            ไม่คงค้าง
                                                        <?php elseif ($kpi["target_value"] === null): ?>
                                                            -
                                                        <?php else: ?>
                                                            <?= perfFormatValue($kpi["target_value"], $unit) ?>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="num" data-label="Actual">
                                                        <?php if (!$hasResult || $kpi["actual_value"] === null): ?>
                                                            <span class="perf-muted">-</span>
                                                        <?php elseif ($isBudget): ?>
                                                            <?php $achievement = nonRenewalAchievement($kpi["actual_value"], $kpi["target_value"]); ?>
                                                            <?= $achievement === null ? "-" : perfFormatDecimal($achievement) . "%" ?>
                                                        <?php else: ?>
                                                            <?= perfFormatValue($kpi["actual_value"], $unit) ?>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="perf-progress-cell" data-label="ความสำเร็จ">
                                                        <?php if ($hasResult && $kpi["actual_value"] !== null): ?>
                                                            <div class="perf-progress">
                                                                <div class="perf-progress-bar" aria-hidden="true">
                                                                    <span style="width: <?= (int) round((float) $kpi["progress"]) ?>%;"></span>
                                                                </div>
                                                                <strong><?= perfFormatNumber((float) $kpi["progress"]) ?>%</strong>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="perf-muted">ยังไม่มีผลงาน</span>
                                                        <?php endif; ?>
                                                    </td>

                                                <?php endif; ?>

                                                <td class="center" data-label="คะแนน">
                                                    <?php if ($level === null && $hasResult && $kpi["actual_value"] !== null): ?>
                                                        <?php /* มีผลการดำเนินงานแต่ไม่มีคะแนน (เช่น KPI 14 อยู่ในช่องว่างของเกณฑ์บริษัท) → ไม่เดาคะแนน */ ?>
                                                        <span class="perf-badge-pending" title="ผลอยู่ในช่วงที่เกณฑ์ไม่กำหนดคะแนน รอเจ้าของระบบยืนยัน">รอยืนยันคะแนน</span>
                                                    <?php elseif ($level === null): ?>
                                                        <span class="perf-muted">-</span>
                                                    <?php elseif ($groupKey === "competency"): ?>
                                                        <span class="perf-dots" role="img" aria-label="คะแนน <?= $level ?> จาก 5">
                                                            <?php for ($dot = 1; $dot <= 5; $dot++): ?><i class="<?= $dot <= $level ? "on" : "" ?>"></i><?php endfor; ?>
                                                        </span>
                                                        <strong class="perf-dots-value"><?= $level ?>/5</strong>
                                                    <?php else: ?>
                                                        <span class="perf-score perf-score-<?= $level ?>"><?= $level ?><small>/5</small></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="center" data-label="สถานะ">
                                                    <?php if ($hasResult): ?>
                                                        <span class="perf-status perf-status-<?= htmlspecialchars(strtolower($statusText), ENT_QUOTES, "UTF-8") ?>">
                                                            <?= htmlspecialchars($statusText, ENT_QUOTES, "UTF-8") ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="perf-status perf-status-none">ยังไม่บันทึก</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="perf-action">
                                                    <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, "UTF-8") ?>" class="perf-link">
                                                        <?= icon("eye") ?>รายละเอียด
                                                    </a>
                                                    <?php if ($hasResult && kpiCorrectionEnabled($pdo)): ?>
                                                        <a href="kpi-correction.php?performance_id=<?= (int) $kpi["performance_id"] ?>" class="perf-link perf-link-correction">
                                                            <?= icon("square-pen") ?>ขอแก้ไข
                                                        </a>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>

                                            <?php if ($isFormKpi && $formEditable): ?>
                                                <tr class="perf-entry-row" id="kpi12-entry">
                                                    <td colspan="8">
                                                        <form method="post" action="kpi12-entry-action.php" class="perf-entry-form">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                                                            <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                                                            <span class="perf-entry-title">กรอกผล <?= htmlspecialchars($kpi["kpi_name"], ENT_QUOTES, "UTF-8") ?></span>
                                                            <label>ทั้งหมด
                                                                <input type="number" name="total" min="1" max="<?= KPI12_FORM_MAX_COUNT ?>" step="1" required inputmode="numeric"
                                                                    value="<?= $formValues !== null ? (int) $formValues["total"] : "" ?>"> ฉบับ
                                                            </label>
                                                            <label>นำกลับ
                                                                <input type="number" name="returned" min="0" max="<?= KPI12_FORM_MAX_COUNT ?>" step="1" required inputmode="numeric"
                                                                    value="<?= $formValues !== null ? (int) $formValues["returned"] : "" ?>"> ฉบับ
                                                            </label>
                                                            <button type="submit" class="perf-approval-submit"><?= $hasResult ? "บันทึกการแก้ไข" : "บันทึก" ?></button>
                                                            <span class="perf-entry-hint">Actual = นำกลับ ÷ ทั้งหมด × 100 · บันทึกเป็น Draft แล้วส่งพร้อมผลงานเดือนนี้</span>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                                <?php
                                /* Competency: กรอกคะแนน 1-5 ครั้งเดียว → บันทึกทุกเดือนที่เปิดอยู่ (employee/kpi-competency-entry-action.php) */
                                $competencyForm = $groupKey === "competency"
                                    ? array_values(array_filter($group["kpis"], fn($item) => in_array((int) $item["kpi_id"], KPI_COMPETENCY_FORM_KPI_IDS, true)
                                        && ($item["assignment_status"] ?? "Active") === "Active"))
                                    : [];
                                $competencyLocked = (bool) array_filter($competencyForm, fn($item) => in_array((string) ($item["performance_status_value"] ?? ""), ["Submitted", "Approved"], true));
                                ?>

                                <?php if ($competencyForm && ($selectedPeriod["status"] ?? "") === "Open" && !$competencyLocked): ?>
                                    <form method="post" action="kpi-competency-entry-action.php" class="perf-competency-form" id="competency-entry">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                                        <input type="hidden" name="month" value="<?= (int) $selectedMonth ?>">
                                        <p class="perf-entry-title">กรอกคะแนน Competency (1-5)</p>
                                        <p class="perf-entry-hint">คะแนนเหมือนกันทุกเดือน: กรอกครั้งเดียว ระบบบันทึกเป็น Draft ให้ทุกเดือนของปี <?= (int) $selectedYear ?> ที่ยังเปิดและยังไม่ได้ส่ง · Manager ตรวจความเหมาะสมและอนุมัติก่อนนำไปคำนวณ</p>
                                        <div class="perf-competency-grid">
                                            <?php foreach ($competencyForm as $item): ?>
                                                <?php $current = !empty($item["performance_id"]) && $item["score_value"] !== null ? kpiScoreLevel((float) $item["score_value"]) : null; ?>
                                                <label class="perf-competency-item">
                                                    <span><?= htmlspecialchars($item["kpi_name"], ENT_QUOTES, "UTF-8") ?></span>
                                                    <select name="score[<?= (int) $item["kpi_id"] ?>]" required>
                                                        <option value="">เลือก</option>
                                                        <?php for ($level = 5; $level >= 1; $level--): ?>
                                                            <option value="<?= $level ?>" <?= $current === $level ? "selected" : "" ?>><?= $level ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <a class="perf-competency-criteria" href="<?= htmlspecialchars("kpi-detail.php?assignment_id=" . (int) $item["assignment_id"] . "&year={$selectedYear}&month={$selectedMonth}&period_id={$selectedPeriodId}#competency-criteria", ENT_QUOTES, "UTF-8") ?>">ดูเกณฑ์</a>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="perf-approval-submit">บันทึกคะแนน Competency</button>
                                    </form>
                                <?php endif; ?>

                            </section>

                        <?php endforeach; ?>

                    </div>


                </section>


            <?php endforeach; ?>


        <?php endif; ?>


    <script>
        document.addEventListener("submit", function (event) {
            var button = event.submitter;
            if (button && button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
                event.preventDefault();
            }
        });
    </script>

    <?php employeeLayoutEnd("../"); ?>


</body>

</html>
