<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/quarter-helper.php";


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

    header("Location: ../dashboard.php");
    exit;
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


if ($page === "home") {

    $quarterScoreStmt = $pdo->query("
                SELECT kp.performance_date, ki.kpi_type, kp.score
                FROM kpi_performances kp
                INNER JOIN kpi_assignments ka ON ka.assignment_id = kp.assignment_id
                INNER JOIN kpi_indicators ki ON ki.kpi_id = ka.kpi_id
                WHERE ka.status = 'Active'
                    AND kp.score IS NOT NULL
        ORDER BY kp.performance_date
    ");
    $quarterScores = [];

    foreach ($quarterScoreStmt->fetchAll(PDO::FETCH_ASSOC) as $quarterRow) {
        $year = (int) date("Y", strtotime($quarterRow["performance_date"]));
        $quarter = getQuarterFromDate($quarterRow["performance_date"]);
        $key = $year . "-" . $quarter;
        $quarterScores[$key][] = (float) $quarterRow["score"];
    }

    $selectedDashboardYear = !empty($periods)
        ? (int) date("Y", strtotime($periods[0]["start_date"]))
        : (int) date("Y");


    /*
    |--------------------------------------------------------------------------
    | Get Evaluation Periods
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT
            period_id,
            period_name,
            start_date,
            end_date,
            status
        FROM evaluation_periods
        ORDER BY start_date DESC, period_id DESC
    ");

    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Default Period
    |--------------------------------------------------------------------------
    */

    if ($selectedPeriod <= 0 && !empty($periods)) {

        $selectedPeriod =
            (int) $periods[0]["period_id"];
    }


    /*
    |--------------------------------------------------------------------------
    | Total Employees
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM employees
        WHERE status = 'Active'
    ");

    $totalEmployees =
        (int) $stmt->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | KPI Assignments
    |--------------------------------------------------------------------------
    */

    if ($selectedPeriod > 0) {

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM kpi_assignments
            WHERE period_id = :period_id
            AND status = 'Active'
        ");

        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);

        $totalAssignments =
            (int) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | Evaluated KPI
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM employee_kpi
            WHERE period_id = :period_id
            AND score IS NOT NULL
        ");

        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);

        $evaluatedKpis =
            (int) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | Average Score
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT COALESCE(AVG(score), 0)
            FROM employee_kpi
            WHERE period_id = :period_id
            AND score IS NOT NULL
        ");

        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);

        $averageScore =
            (float) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | Employee Performance
        |--------------------------------------------------------------------------
        */

        $sql = "

            SELECT

                e.employee_id,

                CONCAT(
                    e.first_name,
                    ' ',
                    e.last_name
                ) AS employee_name,

                COALESCE(

                    SUM(

                        ek.score *

                        COALESCE(
                            ka.weight,
                            ki.weight,
                            0
                        )

                        / NULLIF(
                            ki.max_score,
                            0
                        )

                    )

                    /

                    NULLIF(

                        SUM(

                            CASE

                                WHEN ek.score IS NOT NULL

                                THEN COALESCE(
                                    ka.weight,
                                    ki.weight,
                                    0
                                )

                                ELSE 0

                            END

                        ),

                        0

                    )

                    * 100,

                    0

                ) AS performance_percentage


            FROM employees e


            INNER JOIN kpi_assignments ka

                ON e.employee_id =
                   ka.employee_id


            INNER JOIN kpi_indicators ki

                ON ka.kpi_id =
                   ki.kpi_id


            LEFT JOIN employee_kpi ek

                ON ek.employee_id =
                   e.employee_id

                AND ek.kpi_id =
                    ka.kpi_id

                AND ek.period_id =
                    ka.period_id


            WHERE

                e.status = 'Active'

                AND ka.status = 'Active'

                AND ka.period_id =
                    :period_id


            GROUP BY

                e.employee_id,
                e.first_name,
                e.last_name


            ORDER BY

                performance_percentage DESC

        ";


        $stmt =
            $pdo->prepare($sql);


        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);


        $employeePerformance =
            $stmt->fetchAll(PDO::FETCH_ASSOC);


        foreach (
            $employeePerformance
            as $row
        ) {

            $employeeLabels[] =
                $row["employee_name"];


            $employeeScores[] =
                round(
                    (float)
                    $row["performance_percentage"],
                    2
                );
        }


        /*
        |--------------------------------------------------------------------------
        | KPI Type Performance
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("

            SELECT

                ki.kpi_type,

                ROUND(
                    AVG(ek.score),
                    2
                ) AS average_score


            FROM employee_kpi ek


            INNER JOIN kpi_indicators ki

                ON ek.kpi_id =
                   ki.kpi_id


            WHERE

                ek.period_id =
                    :period_id

                AND ek.score IS NOT NULL


            GROUP BY

                ki.kpi_type


            ORDER BY

                ki.kpi_type

        ");


        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);


        $typePerformance =
            $stmt->fetchAll(PDO::FETCH_ASSOC);


        foreach (
            $typePerformance
            as $row
        ) {

            $typeLabels[] =
                $row["kpi_type"];


            $typeScores[] =
                (float)
                $row["average_score"];
        }


        /*
        |--------------------------------------------------------------------------
        | KPI Performance
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("

            SELECT

                ki.kpi_name,

                ROUND(
                    AVG(ek.score),
                    2
                ) AS average_score


            FROM employee_kpi ek


            INNER JOIN kpi_indicators ki

                ON ek.kpi_id =
                   ki.kpi_id


            WHERE

                ek.period_id =
                    :period_id

                AND ek.score IS NOT NULL


            GROUP BY

                ki.kpi_id,
                ki.kpi_name


            ORDER BY

                average_score DESC


            LIMIT 10

        ");


        $stmt->execute([
            ":period_id" => $selectedPeriod
        ]);


        $kpiPerformance =
            $stmt->fetchAll(PDO::FETCH_ASSOC);


        foreach (
            $kpiPerformance
            as $row
        ) {

            $kpiLabels[] =
                $row["kpi_name"];


            $kpiScores[] =
                (float)
                $row["average_score"];
        }
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


    <?php if ($page === "home"): ?>

        <!-- Chart.js -->

        <script
            src="https://cdn.jsdelivr.net/npm/chart.js">
        </script>

    <?php endif; ?>


    <style>
    
        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        .dashboard-header {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;
        }


        .dashboard-header h1 {
            margin: 0;

            font-size: 28px;
        }


        .dashboard-header p {
            margin: 5px 0 0;

            color: #6b7280;
        }


        /*
        |--------------------------------------------------------------------------
        | Period
        |--------------------------------------------------------------------------
        */

        .period-filter {
            display: flex;

            align-items: center;

            gap: 10px;
        }


        .period-filter label {
            font-weight: 500;
        }


        .period-filter select {
            min-width: 220px;

            padding: 10px 14px;

            border: 1px solid #d1d5db;

            border-radius: 8px;

            background: white;

            font-family: inherit;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        .stats-grid {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 20px;

            margin-bottom: 25px;
        }


        .stat-card {
            background: white;

            border-radius: 14px;

            padding: 22px;

            box-shadow:
                0 4px 15px rgba(0, 0, 0, .06);

            border: 1px solid #eef0f4;
        }


        .stat-card-header {
            display: flex;

            justify-content: space-between;

            align-items: center;
        }


        .stat-title {
            color: #6b7280;

            font-size: 14px;
        }


        .stat-icon {
            width: 42px;
            height: 42px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 10px;

            background: #eef2ff;

            font-size: 20px;
        }


        .stat-value {
            margin-top: 12px;

            font-size: 30px;

            font-weight: 700;

            color: #111827;
        }


        /*
        |--------------------------------------------------------------------------
        | Charts
        |--------------------------------------------------------------------------
        */

        .chart-grid {
            display: grid;

            grid-template-columns:
                2fr 1fr;

            gap: 20px;

            margin-bottom: 20px;
        }


        .chart-card {
            background: white;

            border-radius: 14px;

            padding: 22px;

            box-shadow:
                0 4px 15px rgba(0, 0, 0, .06);

            border: 1px solid #eef0f4;
        }


        .chart-card h2 {
            margin: 0;

            font-size: 19px;
        }


        .chart-card p {
            margin: 4px 0 20px;

            color: #6b7280;

            font-size: 13px;
        }


        .chart-container {
            position: relative;

            height: 330px;
        }


        .chart-empty {
            height: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            color: #9ca3af;
        }


        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .stats-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 650px) {

            .sidebar {
                transform: translateX(-100%);

                transition:
                    transform .3s ease;
            }


            .sidebar.mobile-open {
                transform: translateX(0);
            }


            .main-content {
                margin-left: 0;

                width: 100%;
            }


            .mobile-menu-button {
                display: block;
            }


            .dashboard-header {
                flex-direction: column;

                align-items: flex-start;
            }


            .period-filter {
                width: 100%;
            }


            .period-filter select {
                flex: 1;

                min-width: 0;
            }


            .stats-grid {
                grid-template-columns: 1fr;
            }


            .chart-container {
                height: 280px;
            }

        }
    </style>

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

                                        <?= htmlspecialchars(
                                            $period["period_name"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>


                            </select>


                        </form>


                    </div>


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

                    <div
                        style="
                        padding:40px;
                        text-align:center;
                    ">

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
                    JSON_UNESCAPED_UNICODE
                ) ?>;


            const employeeScores =
                <?= json_encode(
                    $employeeScores
                ) ?>;


            const typeLabels =
                <?= json_encode(
                    $typeLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>;


            const typeScores =
                <?= json_encode(
                    $typeScores
                ) ?>;


            const kpiLabels =
                <?= json_encode(
                    $kpiLabels,
                    JSON_UNESCAPED_UNICODE
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


    <script src="../assets/js/admin.js?v=scroll-2"></script>

</body>

</html>
