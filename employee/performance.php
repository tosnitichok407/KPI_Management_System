<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/database.php";


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

$periodStmt = $pdo->prepare("
    SELECT DISTINCT
        ep.period_id,
        ep.period_name,
        ep.start_date,
        ep.end_date,
        ep.status
    FROM evaluation_periods ep
    INNER JOIN kpi_assignments a ON a.period_id = ep.period_id
    WHERE a.employee_id = :employee_id
      AND a.status = 'Active'
    ORDER BY ep.start_date DESC, ep.period_id DESC
");
$periodStmt->execute([":employee_id" => $employeeId]);
$availablePeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedPeriod = (int) ($_GET["period_id"] ?? 0);
if ($selectedPeriod <= 0 && !empty($availablePeriods)) {
    $selectedPeriod = (int) $availablePeriods[0]["period_id"];
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
        a.kpi_id,
        a.weight,
        a.status AS assignment_status,

        k.kpi_name,
        k.description,
        k.kpi_type,
        k.unit,
        k.max_score,

        ep.period_name,
        ep.start_date,
        ep.end_date,
        ep.status AS period_status,

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

    INNER JOIN evaluation_periods ep
        ON a.period_id = ep.period_id

    LEFT JOIN kpi_performances p
        ON p.performance_id = (

            SELECT kp.performance_id

            FROM kpi_performances kp

            WHERE kp.assignment_id = a.assignment_id

            AND kp.employee_id = :employee_id_sub

            ORDER BY kp.performance_date DESC,
                     kp.performance_id DESC

            LIMIT 1
        )

    WHERE a.employee_id = :employee_id

    AND a.status = 'Active'

    AND a.period_id = :period_id

    ORDER BY
        ep.start_date DESC,
        a.assignment_id ASC
";


try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":employee_id_sub" => $employeeId,
        ":employee_id" => $employeeId,
        ":period_id" => $selectedPeriod
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

    $periodId = $assignment["period_id"];


    /*
    --------------------------------------------------------------
    Create Period
    --------------------------------------------------------------
    */

    if (!isset($periods[$periodId])) {

        $periods[$periodId] = [

            "period_name" =>
            $assignment["period_name"],

            "start_date" =>
            $assignment["start_date"],

            "end_date" =>
            $assignment["end_date"],

            "status" =>
            $assignment["period_status"],

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

    $target = null;
    $actual = null;
    $score = null;
    $progress = 0;

    $performanceStatus = null;

    $performanceDate = null;

    $comment = null;


    if (
        !empty($assignment["performance_id"])
    ) {

        $target =
            $assignment["target"] !== null
            ? (float) $assignment["target"]
            : null;


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
        href="../assets/css/employee-kpi.css">

</head>

<style>
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-top: 20px;
    }

    .performance-list {
        padding: 20px;
    }

    .performance-item {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
    }

    .performance-item:last-child {
        margin-bottom: 0;

    }

    .performance-item-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
    }

    .performance-item h3 {
        margin: 0;
        font-size: 17px;
    }

    .performance-description {
        margin-top: 5px;
        color: #6b7280;
        font-size: 13px;
    }

    .performance-weight {
        padding: 6px 12px;
        border-radius: 20px;
        background: #eff6ff;
        color: #244397;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }
    
        /* === KPI DATA=== */
    .kpi-data-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-top: 20px;
    }

    .kpi-data-box {

        background: #f8fafc;

        padding: 13px;

        border-radius: 8px;

    }

    .kpi-data-box span {

        display: block;

        color: #6b7280;

        font-size: 12px;

    }

    .kpi-data-box strong {

        display: block;

        margin-top: 4px;

        font-size: 16px;

    }

    /*

        =========================================================

        PROGRESS

        =========================================================

        */

    .progress-area {

        margin-top: 20px;

    }

    .progress-header {

        display: flex;

        justify-content: space-between;

        margin-bottom: 7px;

        font-size: 13px;

    }

    .progress-bar {

        width: 100%;

        height: 10px;

        background: #e5e7eb;

        border-radius: 20px;

        overflow: hidden;

    }

    .progress-fill {

        height: 100%;

        background: #244397;

        border-radius: 20px;

    }

    /*

        =========================================================

        PERFORMANCE STATUS

        =========================================================

        */

    .performance-status {

        display: inline-block;

        margin-top: 12px;

        padding: 5px 10px;

        border-radius: 15px;

        background: #eff6ff;

        color: #244397;

        font-size: 12px;

    }

    .no-performance {

        color: #9ca3af;

        font-size: 13px;

        margin-top: 15px;

    }

    /*

        =========================================================

        ACTION

        =========================================================

        */

    .performance-actions {

        margin-top: 18px;

    }

    .performance-button {

        display: inline-block;

        padding: 8px 15px;

        background: #244397;

        color: white;

        border-radius: 7px;

        text-decoration: none;

        font-size: 13px;

    }

    .performance-button:hover {

        background: #1b3475;

    }

    .performance-export-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }

    .performance-export-button {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 14px;
        border-radius: 7px;
        color: #ffffff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
    }

    .performance-export-button.pdf {
        background: #b42318;
    }

    .performance-export-button.excel {
        background: #217346;
    }

    /*

        =========================================================

        EMPTY

        =========================================================

        */

    .empty-performance {

        background: white;

        border-radius: 12px;

        padding: 60px 20px;

        text-align: center;

        box-shadow:

            0 3px 12px rgba(0, 0, 0, .06);

    }

    .empty-performance-icon {

        font-size: 45px;

    }

    .empty-performance h2 {

        margin-bottom: 5px;

    }

    .empty-performance p {

        color: #6b7280;

    }

    /*

        =========================================================

        ALERT

        =========================================================

        */

    .alert-error {

        background: #fee2e2;

        color: #991b1b;

        padding: 12px 15px;

        border-radius: 8px;

        margin-bottom: 20px;

    }

    /*

        =========================================================

        RESPONSIVE

        =========================================================

        */

    @media (max-width: 1000px) {

        .performance-summary {

            grid-template-columns:

                repeat(2, 1fr);

        }

        .kpi-data-grid {

            grid-template-columns:

                repeat(2, 1fr);

        }

    }

    @media (max-width: 700px) {

        .sidebar {

            transform: translateX(-100%);

            transition: .25s;

        }

        .sidebar.mobile-open {

            transform: translateX(0);

        }

        .main-content {

            margin-left: 0;

            padding: 20px;

        }

        .mobile-menu-button {

            display: block;

        }

        .topbar {

            justify-content:

                space-between;

        }

        .performance-summary {

            grid-template-columns: 1fr;

        }

        .kpi-data-grid {

            grid-template-columns: 1fr;

        }

        .performance-item-top {

            flex-direction: column;

        }

        .performance-period-header {

            flex-direction: column;

            align-items: flex-start;

            gap: 10px;

        }

        .performance-export-actions {
            justify-content: flex-start;
            flex-wrap: wrap;
        }

    }
</style>

<body>

    <!-- === SIDEBAR === -->
    <aside class="sidebar">
        <div class="sidebar-logo">

            <img
                src="../assets/images/Advance-Logo.png"
                alt="Advance Asia Group Logo">

            <div>

                <h2>
                    KPI System
                </h2>

                <span>
                    Employee
                </span>

            </div>

        </div>

        <nav class="sidebar-nav">

            <a
                href="../employee/index.php"
                class="nav-item">

                <span class="nav-icon">
                    🏠
                </span>

                <span>
                    หน้าแรก
                </span>

            </a>

            <a
                href="../employee/kpi/kpi.php"
                class="nav-item">

                <span class="nav-icon">
                    🎯
                </span>

                <span>
                    KPI ของฉัน
                </span>

            </a>

            <a
                href="../employee/performance.php"
                class="nav-item active">

                <span class="nav-icon">
                    📊
                </span>

                <span>
                    ผลการปฏิบัติงาน
                </span>

            </a>

            <a
                href="../employee/profile.php"
                class="nav-item">

                <span class="nav-icon">
                    👤
                </span>

                <span>
                    ข้อมูลส่วนตัว
                </span>

            </a>
        </nav>

        <div class="sidebar-bottom">

            <a
                href="../logout.php"
                class="logout-button">

                ออกจากระบบ

            </a>
        </div>
    </aside>

    <!-- === MAIN === -->
    <main class="main-content">
        <header class="topbar">
            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton">
                ☰
            </button>

            <div class="user-info">
                <div class="user-avatar">
                    <?= htmlspecialchars(
                            mb_substr(
                                $firstName,
                                0,
                                1,
                                'UTF-8'
                            ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

                <div class="user-detail">
                    <strong>
                        <?= htmlspecialchars(
                            $fullName,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </strong>

                    <span>

                        <?= htmlspecialchars(
                            $_SESSION["employee_code"] ??
                                "-",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </span>
                </div>
            </div>
        </header>

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

        <form method="get" class="period-filter">
            <label for="period_id">รอบประเมิน</label>
            <select name="period_id" id="period_id" onchange="this.form.submit()">
                <?php if (empty($availablePeriods)): ?>
                    <option value="0">ไม่มีรอบประเมิน</option>
                <?php endif; ?>
                <?php foreach ($availablePeriods as $availablePeriod): ?>
                    <option
                        value="<?= (int) $availablePeriod["period_id"] ?>"
                        <?= (int) $availablePeriod["period_id"] === $selectedPeriod ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars($availablePeriod["period_name"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selectedPeriod > 0): ?>
            <div class="performance-export-actions">
                <a
                    href="performance-export-pdf.php?period_id=<?= (int) $selectedPeriod ?>"
                    class="performance-export-button pdf"
                >
                    📄 Export PDF
                </a>
                <a
                    href="performance-export-excel.php?period_id=<?= (int) $selectedPeriod ?>"
                    class="performance-export-button excel"
                >
                    📊 Export Excel
                </a>
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
                    📊
                </div>

                <h2>
                    ยังไม่มีข้อมูล KPI
                </h2>

                <p>
                    ยังไม่มี KPI ที่ได้รับมอบหมายให้คุณ
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
                                                style="width: <?= $kpi["progress"] ?>%;">
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
                                            class="performance-description"
                                            style="margin-top:12px;">

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
                                        href="kpi-detail.php?assignment_id=<?= (int) $kpi["assignment_id"] ?>"
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


    </main>


    <script>
        const mobileMenuButton =
            document.getElementById(
                "mobileMenuButton"
            );


        const sidebar =
            document.querySelector(
                ".sidebar"
            );


        if (
            mobileMenuButton &&
            sidebar
        ) {

            mobileMenuButton.addEventListener(

                "click",

                function() {

                    sidebar.classList.toggle(
                        "mobile-open"
                    );

                }

            );

        }
    </script>


</body>

</html>