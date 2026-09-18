<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/quarter-helper.php";
require_once __DIR__ . "/../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/period-picker.php";
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
      AND a.status = 'Active'
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
      AND a.status = 'Active'
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

    AND a.status = 'Active'

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

            $progress =
                ($actual / $target) * 100;


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

$averageScore = 0;

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

    foreach ($assignments as $assignment) {
        if (
            isset($assignment["progress"]) &&
            !empty($assignment["performance_id"])
        ) {
            $progressSum +=
                (float) $assignment["progress"];

            $progressCount++;
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
        href="../assets/css/employee-performance.css">

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
                            📄 Export PDF
                        </a>

                        <a
                            href="performance-export-excel.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                            class="performance-export-button excel">
                            📊 Export Excel
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- === FEEDBACK จากหัวหน้า (เดือนที่เลือก) === -->

        <?php
        renderFeedbackCard($monthFeedback, [
            "root" => "../",
            "title" => "💬 Feedback จากหัวหน้า",
            "subtitle" => "ประจำเดือน" . $thaiMonths[$selectedMonth] . " " . $selectedYear,
            "link" => ["href" => "feedback.php?year=" . $selectedYear, "label" => "ดูทั้งหมด →"],
            "empty" => $selectedPeriod
                ? "หัวหน้ายังไม่ได้ให้ Feedback ของเดือนนี้"
                : "ยังไม่มีรอบประเมินของเดือนนี้"
        ]);
        ?>

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
                        <strong><?= number_format($overallAverage) ?> / 5</strong>
                        <?php foreach (getQuarterMonths($quarter) as $month): ?>
                            <?php
                            $monthValues = $quarterData["months"][$month] ?? [];
                            $monthAverage = !empty($monthValues)
                                ? array_sum($monthValues) / count($monthValues)
                                : null;
                            ?>
                            <p>
                                <?= date("M", mktime(0, 0, 0, $month, 1)) ?>:
                                <?= $monthAverage === null ? "-" : number_format($monthAverage) ?>
                            </p>
                        <?php endforeach; ?>
                        <p>
                            Performance: <?= !empty($quarterData["Performance"]) ? number_format(array_sum($quarterData["Performance"]) / count($quarterData["Performance"])) : "-" ?><br>
                            Competency: <?= !empty($quarterData["Competency"]) ? number_format(array_sum($quarterData["Competency"]) / count($quarterData["Competency"])) : "-" ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

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
                    🎯
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

                        <?= number_format(
                            $totalWeight
                        ) ?>%

                    </strong>
                </div>
            </div>

            <div class="summary-card">
                <div>

                    <span>
                        คะแนนเฉลี่ย
                    </span>

                    <strong class="performance-card-value">

                        <?php if ($scoreCount > 0): ?>

                            <?= number_format(
                                $averageScore
                            ) ?>

                            / 5

                        <?php else: ?>

                            -

                        <?php endif; ?>

                    </strong>
                </div>
            </div>

            <div class="summary-card">

                <div class="summary-icon">
                    📈
                </div>

                <div>

                    <span>
                        ความคืบหน้าเฉลี่ย
                    </span>

                    <strong class="performance-card-value">

                        <?= number_format(
                            $overallProgress
                        ) ?>%

                    </strong>
                </div>
            </div>
        </section>

        <!-- === PERIODS === -->
        <?php if (empty($periods)): ?>

            <section class="empty-card">

                <div class="empty-icon">
                    📋
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

                    <!-- KPI List -->
                    <div class="performance-list">

                        <?php foreach (
                            $period["kpis"]
                            as $kpi
                        ): ?>

                            <div class="performance-item">

                                <div
                                    class="performance-item-top">

                                    <div>

                                        <h3>

                                            <?= htmlspecialchars(
                                                $kpi["kpi_name"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </h3>

                                        <div
                                            class="performance-description">

                                            <?= htmlspecialchars(
                                                $kpi["description"]
                                                    ?? "-",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </div>

                                    </div>

                                    <span
                                        class="performance-weight">

                                        Weight

                                        <?= number_format(
                                            $kpi["weight"],
                                            2
                                        ) ?>%

                                    </span>

                                </div>

                                <?php if (
                                    !empty($kpi["performance_id"])
                                ): ?>

                                    <!-- === KPI DATA === -->

                                    <div class="kpi-data-grid">

                                        <div class="kpi-data-box">

                                            <span>
                                                วันที่บันทึก
                                            </span>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $kpi["performance_date_value"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </strong>

                                        </div>

                                        <div class="kpi-data-box">

                                            <span>
                                                Target
                                            </span>

                                            <strong>

                                                <?= number_format(
                                                    (int) $kpi["target_value"]
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    $kpi["unit"] ?? "",
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </strong>

                                        </div>

                                        <div class="kpi-data-box">

                                            <span>
                                                Actual
                                            </span>

                                            <strong>

                                                <?= number_format(
                                                    (int) $kpi["actual_value"]
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    $kpi["unit"] ?? "",
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </strong>

                                        </div>

                                        <div class="kpi-data-box">

                                            <span>
                                                Score
                                            </span>

                                            <strong>

                                                <?= number_format(
                                                    (int) $kpi["score_value"]
                                                ) ?>

                                                / 5

                                            </strong>

                                        </div>

                                    </div>

                                    <!-- === PROGRESS === -->

                                    <div class="progress-area">

                                        <div
                                            class="progress-header">

                                            <span>
                                                ความคืบหน้า
                                            </span>

                                            <strong>

                                                <?= number_format(
                                                    $kpi["progress"]
                                                ) ?>%

                                            </strong>

                                        </div>

                                        <div
                                            class="progress-bar">

                                            <div
                                                class="progress-fill"
                                                style="width: <?= (int) round((float) $kpi["progress"]) ?>%;">
                                            </div>

                                        </div>


                                    </div>


                                    <!-- === STATUS === -->

                                    <span
                                        class="performance-status">

                                        <?= htmlspecialchars(
                                            $kpi["performance_status_value"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </span>


                                    <?php if (
                                        !empty($kpi["comment_value"])
                                    ): ?>

                                        <div
                                            class="performance-description performance-comment">

                                            <strong>
                                                รายละเอียด:
                                            </strong>

                                            <?= htmlspecialchars(
                                                $kpi["comment_value"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </div>

                                    <?php endif; ?>


                                <?php else: ?>


                                    <!-- === NO PERFORMANCE === -->

                                    <div
                                        class="no-performance">

                                        ยังไม่มีการบันทึกผลงานสำหรับ KPI นี้

                                    </div>


                                <?php endif; ?>


                                <!-- === ACTION === -->

                                <div
                                    class="performance-actions">


                                    <a
                                        href="kpi-detail.php?assignment_id=<?= (int) $kpi["assignment_id"] ?>&amp;year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>&amp;period_id=<?= $selectedPeriodId ?>"
                                        class="performance-button">

                                        <?= !empty($kpi["performance_id"])
                                            ? "เพิ่ม/ดูผลงาน"
                                            : "บันทึกผลงาน"
                                        ?>

                                    </a>


                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>


                </section>


            <?php endforeach; ?>


        <?php endif; ?>


    <?php employeeLayoutEnd("../"); ?>


</body>

</html>
