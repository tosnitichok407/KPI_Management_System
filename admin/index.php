<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/quarter-helper.php";
require_once __DIR__ . "/../includes/monthly-period-helper.php";


/*
|--------------------------------------------------------------------------
| Authentication Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Admin Access Check
|--------------------------------------------------------------------------
*/

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {

    redirectToRoleHome("../");
}


/*
|--------------------------------------------------------------------------
| User Information
|--------------------------------------------------------------------------
*/

$firstName = $_SESSION["first_name"] ?? "Administrator";
$lastName = $_SESSION["last_name"] ?? "";
$employeeCode = $_SESSION["employee_code"] ?? "-";
$roleName = $_SESSION["role_name"] ?? "admin";
$department = $_SESSION["department"] ?? "-";

$fullName = trim($firstName . " " . $lastName);


/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$page = $_GET["page"] ?? "home";


/*
|--------------------------------------------------------------------------
| Page Routing
|--------------------------------------------------------------------------
|
| หน้าที่แสดงใน Content ด้านขวา
|
*/

$pages = [

    "home" => null,

    "employees" =>
    "employees/employees.php",

    "accounts" =>
    "user-accounts/user-accounts.php",

    "kpi-categories" =>
    "kpi-categories/kpi-categories.php",

    "kpi-management" =>
    "kpi-management/kpi-management.php",

    "evaluation" =>
    "evaluation/evaluation-periods.php",

    "kpi-assignment" =>
    "kpi-assignment/kpi-assignment.php",

    "summary" =>
    "kpi-summary/kpi-summary.php"

];


/*
|--------------------------------------------------------------------------
| Validate Page
|--------------------------------------------------------------------------
*/

if (!array_key_exists($page, $pages)) {

    $page = "home";
}


/*
|--------------------------------------------------------------------------
| Content Page
|--------------------------------------------------------------------------
*/

$contentPage = $pages[$page];


/*
|--------------------------------------------------------------------------
| Dashboard Data
|--------------------------------------------------------------------------
|
| ทำเฉพาะหน้า Home
|
*/

$selectedPeriod = (int) ($_GET["period_id"] ?? 0);

$periods = [];

$totalEmployees = 0;
$totalAssignments = 0;
$evaluatedKpis = 0;
$averageScore = 0;

$employeeLabels = [];
$employeeScores = [];

$typeLabels = [];
$typeScores = [];

$kpiLabels = [];
$kpiScores = [];

/* การ์ดรายไตรมาส: กำหนดค่าเริ่มต้นไว้เสมอ (ใช้ใน HTML ของหน้า home) */
$quarterScores = [];
$selectedDashboardYear = (int) date("Y");
$dashboardError = "";


if ($page === "home") {

    $quarterScoreStmt = $pdo->query("
        SELECT ep.period_year, ep.quarter, kp.score
        FROM kpi_performances kp
        INNER JOIN evaluation_periods ep ON ep.period_id = kp.period_id
        INNER JOIN kpi_assignments ka ON ka.assignment_id = kp.assignment_id
        INNER JOIN employees e ON e.employee_id = ka.employee_id
        WHERE ka.status = 'Active'
          AND e.status = 'Active'
          AND kp.score IS NOT NULL
    ");

    foreach ($quarterScoreStmt->fetchAll(PDO::FETCH_ASSOC) as $quarterRow) {
        $key = (int) $quarterRow["period_year"] . "-" . $quarterRow["quarter"];
        $quarterScores[$key][] = (float) $quarterRow["score"];
    }



    /*
    |--------------------------------------------------------------------------
    | Dashboard Data
    |--------------------------------------------------------------------------
    |
    | แหล่งข้อมูล: kpi_assignments (มอบหมายรายปี) + kpi_performances (ผลงานรายเดือน)
    | ผูกกับรอบประเมินที่เลือกด้วย kpi_performances.period_id
    |
    | เดิมหน้านี้อ่านจาก employee_kpi และ kpi_assignments.period_id ซึ่งเป็นโครงสร้าง
    | ก่อนเปลี่ยนเป็นรอบรายเดือน (ไม่มีโค้ดเขียนข้อมูลลงไปแล้ว) ตัวเลขจึงเป็น 0
    |
    */

    try {

        /* รอบประเมินทั้งหมด (dropdown) */

        $stmt = $pdo->query("
            SELECT
                period_id,
                period_name,
                period_year,
                period_month,
                start_date,
                end_date,
                status
            FROM evaluation_periods
            ORDER BY start_date DESC, period_id DESC
        ");

        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);


        /* รอบที่เลือก (ต้องเป็นรอบที่มีอยู่จริง) */

        $selectedPeriodData = null;

        foreach ($periods as $period) {
            if ((int) $period["period_id"] === $selectedPeriod) {
                $selectedPeriodData = $period;
                break;
            }
        }

        if ($selectedPeriodData === null) {

            $selectedPeriod = defaultEvaluationPeriodId($periods);

            foreach ($periods as $period) {
                if ((int) $period["period_id"] === $selectedPeriod) {
                    $selectedPeriodData = $period;
                    break;
                }
            }
        }

        if ($selectedPeriodData !== null) {
            $selectedDashboardYear = (int) $selectedPeriodData["period_year"];
        }


        /* พนักงาน Active */

        $totalEmployees = (int) $pdo->query("
            SELECT COUNT(*)
            FROM employees
            WHERE status = 'Active'
        ")->fetchColumn();


        if ($selectedPeriodData !== null) {

            $periodParams = [
                ":month_start" => $selectedPeriodData["start_date"],
                ":month_end" => $selectedPeriodData["end_date"]
            ];

            /*
            | เงื่อนไข "KPI ที่มีผลในเดือนนี้" (ชุดเดียวกับหน้าพนักงานและหน้า Summary)
            */
            $activeInMonth = "
                ka.status = 'Active'
                AND e.status = 'Active'
                AND COALESCE(ka.start_date, CONCAT(ka.assignment_year, '-01-01')) <= :month_end
                AND COALESCE(ka.end_date, CONCAT(ka.assignment_year, '-12-31')) >= :month_start
            ";


            /* KPI ที่มอบหมาย (มีผลในเดือนของรอบที่เลือก) */

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM kpi_assignments ka
                INNER JOIN employees e ON e.employee_id = ka.employee_id
                WHERE {$activeInMonth}
            ");
            $stmt->execute($periodParams);

            $totalAssignments = (int) $stmt->fetchColumn();


            /* KPI ที่ประเมินแล้ว + คะแนนเฉลี่ย ของรอบที่เลือก */

            $stmt = $pdo->prepare("
                SELECT
                    COUNT(kp.performance_id) AS evaluated,
                    COALESCE(AVG(kp.score), 0) AS average_score
                FROM kpi_assignments ka
                INNER JOIN employees e ON e.employee_id = ka.employee_id
                INNER JOIN kpi_performances kp
                    ON kp.assignment_id = ka.assignment_id
                   AND kp.period_id = :period_id
                   AND kp.score IS NOT NULL
                WHERE {$activeInMonth}
            ");
            $stmt->execute($periodParams + [":period_id" => $selectedPeriod]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $evaluatedKpis = (int) ($row["evaluated"] ?? 0);
            $averageScore = (float) ($row["average_score"] ?? 0);


            /*
            | ผลรายพนักงาน (% ถ่วงน้ำหนัก)
            | Σ(score ÷ max_score × weight) ÷ Σ(weight ของ KPI ที่ประเมินแล้ว) × 100
            | สูตรเดียวกับ managerStats() และหน้า Summary
            */

            $stmt = $pdo->prepare("
                SELECT
                    e.employee_id,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                    SUM(kp.score / COALESCE(NULLIF(ki.max_score, 0), 5) * ka.weight)
                        / NULLIF(SUM(ka.weight), 0) * 100 AS performance_percentage
                FROM kpi_assignments ka
                INNER JOIN employees e ON e.employee_id = ka.employee_id
                INNER JOIN kpi_indicators ki ON ki.kpi_id = ka.kpi_id
                INNER JOIN kpi_performances kp
                    ON kp.assignment_id = ka.assignment_id
                   AND kp.period_id = :period_id
                   AND kp.score IS NOT NULL
                WHERE {$activeInMonth}
                GROUP BY e.employee_id, e.first_name, e.last_name
                ORDER BY performance_percentage DESC
            ");
            $stmt->execute($periodParams + [":period_id" => $selectedPeriod]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $employeeLabels[] = $row["employee_name"];
                $employeeScores[] = round((float) $row["performance_percentage"], 2);
            }


            /* คะแนนเฉลี่ยตามประเภท KPI */

            $stmt = $pdo->prepare("
                SELECT
                    ki.kpi_type,
                    ROUND(AVG(kp.score), 2) AS average_score
                FROM kpi_assignments ka
                INNER JOIN employees e ON e.employee_id = ka.employee_id
                INNER JOIN kpi_indicators ki ON ki.kpi_id = ka.kpi_id
                INNER JOIN kpi_performances kp
                    ON kp.assignment_id = ka.assignment_id
                   AND kp.period_id = :period_id
                   AND kp.score IS NOT NULL
                WHERE {$activeInMonth}
                GROUP BY ki.kpi_type
                ORDER BY FIELD(ki.kpi_type, 'Performance', 'Competency')
            ");
            $stmt->execute($periodParams + [":period_id" => $selectedPeriod]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $typeLabels[] = $row["kpi_type"];
                $typeScores[] = (float) $row["average_score"];
            }


            /* คะแนนเฉลี่ยราย KPI (สูงสุด 10 รายการ) */

            $stmt = $pdo->prepare("
                SELECT
                    ki.kpi_name,
                    ROUND(AVG(kp.score), 2) AS average_score
                FROM kpi_assignments ka
                INNER JOIN employees e ON e.employee_id = ka.employee_id
                INNER JOIN kpi_indicators ki ON ki.kpi_id = ka.kpi_id
                INNER JOIN kpi_performances kp
                    ON kp.assignment_id = ka.assignment_id
                   AND kp.period_id = :period_id
                   AND kp.score IS NOT NULL
                WHERE {$activeInMonth}
                GROUP BY ki.kpi_id, ki.kpi_name
                ORDER BY average_score DESC
                LIMIT 10
            ");
            $stmt->execute($periodParams + [":period_id" => $selectedPeriod]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $kpiLabels[] = $row["kpi_name"];
                $kpiScores[] = (float) $row["average_score"];
            }
        }

    } catch (PDOException $e) {

        error_log("Admin dashboard query failed: " . $e->getMessage());

        $dashboardError = "ไม่สามารถโหลดข้อมูล Dashboard ได้";
    }
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
        Admin | KPI Management System
    </title>


    <!-- Kanit -->

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">


    <!-- Admin CSS -->

    <link
        rel="stylesheet"
        href="../assets/css/admin.css?v=layout-20260911-2">

    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/forms.css">
    <link rel="stylesheet" href="../assets/css/tables.css">
    <link rel="stylesheet" href="../assets/css/employee.css">
    <link rel="stylesheet" href="../assets/css/evaluation.css?v=period-form-2">
    <link rel="stylesheet" href="../assets/css/kpi.css?v=category-form-1">
    <link rel="stylesheet" href="../assets/css/summary.css">
        <link rel="stylesheet" href="../assets/css/user-account.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">


    <!-- CSS เฉพาะหน้า (เดิมเป็น <style> ในไฟล์ของแต่ละหน้า) -->

    <?php
    $pageStylesheets = [
        "home" => ["admin-dashboard.css"],
        "kpi-assignment" => ["admin-kpi-assignment.css"],
        "summary" => ["period-picker.css?v=1", "admin-kpi-summary.css"]
    ];

    foreach ($pageStylesheets[$page] ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="../assets/css/<?= htmlspecialchars($stylesheet, ENT_QUOTES, "UTF-8") ?>">
    <?php endforeach; ?>



    <?php if ($page === "home"): ?>

        <!-- Chart.js -->

        <script
            src="https://cdn.jsdelivr.net/npm/chart.js">
        </script>

    <?php endif; ?>



</head>


<body>


    <!-- =========================================================
     MOBILE OVERLAY
========================================================= -->

    <div
        class="mobile-menu-overlay"
        id="mobileMenuOverlay">
    </div>


    <!-- =========================================================
     SIDEBAR
========================================================= -->

    <?php include __DIR__ . "/includes/sidebar.php"; ?>


    <!-- =========================================================
     MAIN
========================================================= -->

    <main class="main-content">


        <!-- =====================================================
         TOPBAR
    ====================================================== -->

        <header class="topbar">


            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open navigation menu"
                aria-expanded="false">

                ☰

            </button>


            <div class="user-info">


                <div class="user-avatar">

                    <?= htmlspecialchars(

                        mb_substr(
                            $firstName,
                            0,
                            1,
                            "UTF-8"
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
                            $employeeCode,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </span>

                </div>


            </div>


        </header>


        <!-- =====================================================
         CONTENT
    ====================================================== -->

        <section class="page-container">


            <?php if ($page === "home"): ?>


                <!-- =================================================
                 DASHBOARD
            ================================================= -->

                <div class="page-container dashboard-content">


                    <div class="dashboard-header">


                        <div>

                            <h1>
                                KPI Performance Dashboard
                            </h1>


                            <p>
                                ภาพรวมผลการปฏิบัติงานของพนักงาน
                            </p>

                        </div>


                        <!-- Period -->

                        <form
                            method="GET"
                            class="period-filter">


                            <!-- สำคัญ:
                             ส่ง page=home กลับไปด้วย -->

                            <input
                                type="hidden"
                                name="page"
                                value="home">


                            <label for="period_id">

                                รอบประเมิน

                            </label>


                            <select
                                name="period_id"
                                id="period_id"
                                onchange="this.form.submit()">


                                <?php if (empty($periods)): ?>

                                    <option value="">

                                        ไม่มีรอบประเมิน

                                    </option>

                                <?php endif; ?>


                                <?php foreach (
                                    $periods
                                    as $period
                                ): ?>

                                    <option

                                        value="<?= (int)
                                                $period["period_id"] ?>"

                                        <?= (
                                            (int)
                                            $period["period_id"]
                                            === $selectedPeriod
                                        )
                                            ? "selected"
                                            : ""
                                        ?>>

                                        <?= htmlspecialchars($period["period_name"] . " " . $period["period_year"], ENT_QUOTES, "UTF-8") ?>

                                    </option>

                                <?php endforeach; ?>


                            </select>


                        </form>


                    </div>


                    <?php if ($dashboardError !== ""): ?>

                        <div class="alert alert-error">
                            <?= htmlspecialchars($dashboardError, ENT_QUOTES, "UTF-8") ?>
                        </div>

                    <?php endif; ?>


                    <!-- =================================================
                     STATISTICS
                ================================================= -->

                    <div class="stats-grid">


                        <div class="stat-card">


                            <div class="stat-card-header">


                                <div class="stat-title">

                                    พนักงาน Active

                                </div>


                                <div class="stat-icon">

                                    👥

                                </div>


                            </div>


                            <div class="stat-value">

                                <?= number_format(
                                    $totalEmployees
                                ) ?>

                            </div>


                        </div>


                        <div class="stat-card">


                            <div class="stat-card-header">


                                <div class="stat-title">

                                    KPI ที่มอบหมาย

                                </div>


                                <div class="stat-icon">

                                    🎯

                                </div>


                            </div>


                            <div class="stat-value">

                                <?= number_format(
                                    $totalAssignments
                                ) ?>

                            </div>


                        </div>


                        <div class="stat-card">


                            <div class="stat-card-header">


                                <div class="stat-title">

                                    KPI ที่ประเมินแล้ว

                                </div>


                                <div class="stat-icon">

                                    ✅

                                </div>


                            </div>


                            <div class="stat-value">

                                <?= number_format(
                                    $evaluatedKpis
                                ) ?>

                            </div>


                        </div>


                        <div class="stat-card">


                            <div class="stat-card-header">


                                <div class="stat-title">

                                    คะแนนเฉลี่ย

                                </div>


                                <div class="stat-icon">

                                    ⭐

                                </div>


                            </div>


                            <div class="stat-value">

                                <?= number_format(
                                    $averageScore,
                                    2
                                ) ?>

                                <small>
                                    / 5
                                </small>

                            </div>


                        </div>


                    </div>


                    <div class="quarter-score-grid">
                        <?php foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter): ?>
                            <?php
                            $quarterValues = $quarterScores[$selectedDashboardYear . "-" . $quarter] ?? [];
                            $quarterAverage = !empty($quarterValues)
                                ? array_sum($quarterValues) / count($quarterValues)
                                : null;
                            ?>
                            <div class="quarter-score-card">
                                <h3><?= $quarter ?></h3>
                                <p><?= $selectedDashboardYear ?></p>
                                <strong>
                                    <?= $quarterAverage === null ? "ยังไม่มีข้อมูล" : number_format($quarterAverage, 2) . " / 5" ?>
                                </strong>
                            </div>
                        <?php endforeach; ?>
                    </div>


                    <!-- =================================================
                     CHART 1
                ================================================= -->

                    <div class="chart-grid">


                        <div class="chart-card">


                            <h2>
                                ผลการประเมินรายพนักงาน
                            </h2>


                            <p>
                                แสดงผล KPI เป็นเปอร์เซ็นต์
                            </p>


                            <div class="chart-container">


                                <?php if (
                                    !empty($employeeLabels)
                                ): ?>

                                    <canvas
                                        id="employeeChart">
                                    </canvas>

                                <?php else: ?>

                                    <div class="chart-empty">

                                        ยังไม่มีข้อมูลการประเมิน

                                    </div>

                                <?php endif; ?>


                            </div>


                        </div>


                        <div class="chart-card">


                            <h2>
                                คะแนนตามประเภท KPI
                            </h2>


                            <p>
                                Performance / Competency
                            </p>


                            <div class="chart-container">


                                <?php if (
                                    !empty($typeLabels)
                                ): ?>

                                    <canvas
                                        id="typeChart">
                                    </canvas>

                                <?php else: ?>

                                    <div class="chart-empty">

                                        ยังไม่มีข้อมูลการประเมิน

                                    </div>

                                <?php endif; ?>


                            </div>


                        </div>


                    </div>


                    <!-- =================================================
                     CHART 2
                ================================================= -->

                    <div class="chart-card">


                        <h2>
                            คะแนนเฉลี่ยราย KPI
                        </h2>


                        <p>
                            แสดง KPI ที่มีคะแนนสูงสุด 10 รายการ
                        </p>


                        <div class="chart-container">


                            <?php if (
                                !empty($kpiLabels)
                            ): ?>

                                <canvas
                                    id="kpiChart">
                                </canvas>

                            <?php else: ?>

                                <div class="chart-empty">

                                    ยังไม่มีข้อมูลการประเมิน

                                </div>

                            <?php endif; ?>


                        </div>


                    </div>


                </div>


            <?php else: ?>


                <!-- =================================================
                 OTHER ADMIN PAGES
            ================================================= -->

                <?php

                if (
                    $contentPage &&
                    file_exists(__DIR__ . "/" . $contentPage)
                ) {

                    include __DIR__ . "/" . $contentPage;
                } else {

                ?>

                                        <div class="page-not-found">

                        <h2>
                            ไม่พบหน้าที่ต้องการ
                        </h2>

                        <p>
                            กรุณาตรวจสอบไฟล์ของหน้านี้
                        </p>

                    </div>

                <?php

                }

                ?>


            <?php endif; ?>


        </section>


    </main>


    <!-- =========================================================
     JAVASCRIPT
========================================================= -->

    <?php if ($page === "home"): ?>

        <script>
            /*
|--------------------------------------------------------------------------
| Chart Data
|--------------------------------------------------------------------------
*/

                        const employeeLabels =
                <?= json_encode(
                    $employeeLabels,
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) ?>;


            const employeeScores =
                <?= json_encode(
                    $employeeScores
                ) ?>;


                        const typeLabels =
                <?= json_encode(
                    $typeLabels,
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) ?>;


            const typeScores =
                <?= json_encode(
                    $typeScores
                ) ?>;


                        const kpiLabels =
                <?= json_encode(
                    $kpiLabels,
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) ?>;


            const kpiScores =
                <?= json_encode(
                    $kpiScores
                ) ?>;


            /*
            |--------------------------------------------------------------------------
            | Employee Chart
            |--------------------------------------------------------------------------
            */

            if (
                document.getElementById(
                    "employeeChart"
                )
            ) {

                new Chart(

                    document.getElementById(
                        "employeeChart"
                    ),

                    {

                        type: "bar",

                        data: {

                            labels: employeeLabels,

                            datasets: [

                                {

                                    label: "Performance (%)",

                                    data: employeeScores,

                                    borderWidth: 1

                                }

                            ]

                        },

                        options: {

                            responsive: true,

                            maintainAspectRatio: false,

                            scales: {

                                y: {

                                    beginAtZero: true,

                                    max: 100,

                                    ticks: {

                                        callback: function(value) {

                                            return value + "%";

                                        }

                                    }

                                }

                            },

                            plugins: {

                                legend: {

                                    display: false

                                }

                            }

                        }

                    }

                );

            }


            /*
            |--------------------------------------------------------------------------
            | KPI Type Chart
            |--------------------------------------------------------------------------
            */

            if (
                document.getElementById(
                    "typeChart"
                )
            ) {

                new Chart(

                    document.getElementById(
                        "typeChart"
                    ),

                    {

                        type: "doughnut",

                        data: {

                            labels: typeLabels,

                            datasets: [

                                {

                                    data: typeScores,

                                    borderWidth: 1

                                }

                            ]

                        },

                        options: {

                            responsive: true,

                            maintainAspectRatio: false,

                            plugins: {

                                legend: {

                                    position: "bottom"

                                }

                            }

                        }

                    }

                );

            }


            /*
            |--------------------------------------------------------------------------
            | KPI Chart
            |--------------------------------------------------------------------------
            */

            if (
                document.getElementById(
                    "kpiChart"
                )
            ) {

                new Chart(

                    document.getElementById(
                        "kpiChart"
                    ),

                    {

                        type: "bar",

                        data: {

                            labels: kpiLabels,

                            datasets: [

                                {

                                    label: "Average Score",

                                    data: kpiScores,

                                    borderWidth: 1

                                }

                            ]

                        },

                        options: {

                            indexAxis: "y",

                            responsive: true,

                            maintainAspectRatio: false,

                            scales: {

                                x: {

                                    beginAtZero: true,

                                    max: 5

                                }

                            },

                            plugins: {

                                legend: {

                                    display: false

                                }

                            }

                        }

                    }

                );

            }
        </script>

    <?php endif; ?>


    <!-- =========================================================
     MOBILE MENU
========================================================= -->

    <script>
        const mobileMenuButton =
            document.getElementById(
                "mobileMenuButton"
            );


        const sidebar =
            document.querySelector(
                ".sidebar"
            );


        const mobileMenuOverlay =
            document.getElementById(
                "mobileMenuOverlay"
            );


        function openMobileMenu() {

            sidebar.classList.add(
                "mobile-open"
            );

            mobileMenuOverlay.classList.add(
                "active"
            );

            mobileMenuButton.setAttribute(
                "aria-expanded",
                "true"
            );

            mobileMenuButton.textContent =
                "✕";

        }


        function closeMobileMenu() {

            sidebar.classList.remove(
                "mobile-open"
            );

            mobileMenuOverlay.classList.remove(
                "active"
            );

            mobileMenuButton.setAttribute(
                "aria-expanded",
                "false"
            );

            mobileMenuButton.textContent =
                "☰";

        }


        if (mobileMenuButton) {

            mobileMenuButton.addEventListener(
                "click",
                function() {

                    if (
                        sidebar.classList.contains(
                            "mobile-open"
                        )
                    ) {

                        closeMobileMenu();

                    } else {

                        openMobileMenu();

                    }

                }
            );

        }


        if (mobileMenuOverlay) {

            mobileMenuOverlay.addEventListener(
                "click",
                closeMobileMenu
            );

        }


        window.addEventListener(
            "resize",
            function() {

                if (window.innerWidth > 650) {

                    closeMobileMenu();

                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Close Mobile Menu After Clicking Menu
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll(".nav-item")
            .forEach(function(item) {

                item.addEventListener(
                    "click",
                    function() {

                        if (
                            window.innerWidth <= 650
                        ) {

                            closeMobileMenu();

                        }

                    }
                );

            });
    </script>


    <script src="../assets/js/admin.js?v=scroll-3"></script>

</body>

</html>
