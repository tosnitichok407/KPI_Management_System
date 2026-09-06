<?php

session_start();

require_once "../config/database.php";


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
| Employee Access Check
|--------------------------------------------------------------------------
*/

$roleId = (int) ($_SESSION["role_id"] ?? 0);

if ($roleId !== 3) {

    if ($roleId === 1) {
        header("Location: ../admin/index.php");
    } else {
        header("Location: ../dashboard.php");
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| User Information
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION["user_id"];

$firstName = $_SESSION["first_name"] ?? "";
$lastName = $_SESSION["last_name"] ?? "";

$fullName = trim(
    $firstName . " " . $lastName
);


/*
|--------------------------------------------------------------------------
| Get Employee Information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.email,
        e.phone,
        e.hire_date,
        e.status,

        d.department_name,

        p.position_name

    FROM users u

    INNER JOIN employees e
        ON u.employee_id = e.employee_id

    LEFT JOIN departments d
        ON e.department_id = d.department_id

    LEFT JOIN positions p
        ON e.position_id = p.position_id

    WHERE u.user_id = :user_id

    LIMIT 1
");

$stmt->execute([
    ":user_id" => $userId
]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Employee Not Found
|--------------------------------------------------------------------------
*/

if (!$employee) {

    session_destroy();

    header("Location: ../login.php");
    exit;
}


$employeeId = (int) $employee["employee_id"];


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
| Selected Period
|--------------------------------------------------------------------------
*/

$selectedPeriod = (int) ($_GET["period_id"] ?? 0);


/*
|--------------------------------------------------------------------------
| Default = Latest Period
|--------------------------------------------------------------------------
*/

if ($selectedPeriod <= 0 && !empty($periods)) {

    $selectedPeriod =
        (int) $periods[0]["period_id"];
}


/*
|--------------------------------------------------------------------------
| Selected Period Data
|--------------------------------------------------------------------------
*/

$selectedPeriodData = null;

foreach ($periods as $period) {

    if (
        (int) $period["period_id"]
        === $selectedPeriod
    ) {

        $selectedPeriodData = $period;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| Dashboard Variables
|--------------------------------------------------------------------------
*/

$totalKpi = 0;

$evaluatedKpi = 0;

$pendingKpi = 0;

$averageScore = 0;

$totalPercentage = 0;

$performanceLevel = "-";

$kpiData = [];

$typeData = [];


/*
|--------------------------------------------------------------------------
| KPI Data
|--------------------------------------------------------------------------
*/

if ($selectedPeriod > 0) {


    /*
    |--------------------------------------------------------------------------
    | KPI Summary
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT

            COUNT(*) AS total_kpi,

            COUNT(
                CASE
                    WHEN ek.score IS NOT NULL
                    THEN 1
                END
            ) AS evaluated_kpi,

            COALESCE(
                AVG(ek.score),
                0
            ) AS average_score

        FROM kpi_assignments ka

        INNER JOIN kpi_indicators ki
            ON ka.kpi_id = ki.kpi_id

        LEFT JOIN employee_kpi ek
            ON ek.employee_id = ka.employee_id
            AND ek.kpi_id = ka.kpi_id
            AND ek.period_id = ka.period_id

        WHERE
            ka.employee_id = :employee_id

            AND ka.period_id = :period_id

            AND ka.status = 'Active'
    ");

    $stmt->execute([

        ":employee_id" => $employeeId,

        ":period_id" => $selectedPeriod

    ]);

    $summary = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($summary) {

        $totalKpi =
            (int) $summary["total_kpi"];

        $evaluatedKpi =
            (int) $summary["evaluated_kpi"];

        $averageScore =
            (float) $summary["average_score"];

        $pendingKpi =
            $totalKpi - $evaluatedKpi;
    }


    /*
    |--------------------------------------------------------------------------
    | Performance Summary
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT

            percentage,

            performance_level,

            total_score,

            max_score,

            evaluation_status

        FROM performance_summary

        WHERE
            employee_id = :employee_id

            AND period_id = :period_id

        LIMIT 1
    ");

    $stmt->execute([

        ":employee_id" => $employeeId,

        ":period_id" => $selectedPeriod

    ]);

    $performanceSummary =
        $stmt->fetch(PDO::FETCH_ASSOC);


    if ($performanceSummary) {

        $totalPercentage =
            (float) (
                $performanceSummary["percentage"]
                ?? 0
            );

        $performanceLevel =
            $performanceSummary["performance_level"] ?? "-";
    }


    /*
    |--------------------------------------------------------------------------
    | KPI List
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT

            ka.assignment_id,

            ka.target_value AS assignment_target,

            ka.weight AS assignment_weight,

            ki.kpi_id,

            ki.kpi_name,

            ki.kpi_type,

            ki.unit,

            ki.max_score,

            ek.actual_value,

            ek.score,

            ek.remark

        FROM kpi_assignments ka

        INNER JOIN kpi_indicators ki
            ON ka.kpi_id = ki.kpi_id

        LEFT JOIN employee_kpi ek
            ON ek.employee_id = ka.employee_id

            AND ek.kpi_id = ka.kpi_id

            AND ek.period_id = ka.period_id

        WHERE

            ka.employee_id = :employee_id

            AND ka.period_id = :period_id

            AND ka.status = 'Active'

        ORDER BY
            ki.kpi_type,
            ka.assignment_id
    ");

    $stmt->execute([

        ":employee_id" => $employeeId,

        ":period_id" => $selectedPeriod

    ]);

    $kpiData =
        $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | KPI Type
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT

            ki.kpi_type,

            COUNT(*) AS total,

            COALESCE(
                AVG(ek.score),
                0
            ) AS average_score

        FROM kpi_assignments ka

        INNER JOIN kpi_indicators ki
            ON ka.kpi_id = ki.kpi_id

        LEFT JOIN employee_kpi ek
            ON ek.employee_id = ka.employee_id

            AND ek.kpi_id = ka.kpi_id

            AND ek.period_id = ka.period_id

        WHERE

            ka.employee_id = :employee_id

            AND ka.period_id = :period_id

            AND ka.status = 'Active'

        GROUP BY
            ki.kpi_type

        ORDER BY
            ki.kpi_type
    ");

    $stmt->execute([

        ":employee_id" => $employeeId,

        ":period_id" => $selectedPeriod

    ]);

    $typeData =
        $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| Chart Data
|--------------------------------------------------------------------------
*/

$chartLabels = [];

$chartScores = [];


foreach ($kpiData as $row) {

    $chartLabels[] =
        $row["kpi_name"];

    $chartScores[] =
        $row["score"] !== null
        ? (float) $row["score"]
        : 0;
}


$typeLabels = [];

$typeScores = [];


foreach ($typeData as $row) {

    $typeLabels[] =
        $row["kpi_type"];

    $typeScores[] =
        (float) $row["average_score"];
}


/*
|--------------------------------------------------------------------------
| Initial
|--------------------------------------------------------------------------
*/

$avatar =
    strtoupper(
        substr(
            $employee["first_name"],
            0,
            1
        )
    );

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Employee Dashboard | KPI Management System
    </title>


    <!-- Google Font -->

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">


    <link
        rel="stylesheet"
        href="../assets/css/employee-kpi.css">

    <style>
        .page-header {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;

        }


        .page-header h2 {

            margin: 0;

            font-size: 27px;

        }


        .page-header p {

            margin: 5px 0 0;

            color: #6b7280;

        }


        /*
        |--------------------------------------------------------------------------
        | Period
        |--------------------------------------------------------------------------
        */

        .period-form select {

            padding: 10px 14px;

            border:
                1px solid #d1d5db;

            border-radius: 8px;

            background: white;

            font-family: inherit;

            min-width: 210px;

        }


        /*
        |--------------------------------------------------------------------------
        | Profile Card
        |--------------------------------------------------------------------------
        */

        .profile-card {

            background:
                linear-gradient(135deg,
                    #1e3a8a,
                    #244397);

            color: white;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 22px;

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 20px;

        }


        .profile-left {

            display: flex;

            align-items: center;

            gap: 18px;

        }


        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
        }
        .profile-name {
            font-size: 23px;
            font-weight: 600;
        }

        .profile-code {

            opacity: .85;

            font-size: 14px;

        }


        .profile-meta {

            display: flex;

            gap: 25px;

            text-align: right;

        }


        .profile-meta span {

            display: block;

            opacity: .75;

            font-size: 12px;

        }


        .profile-meta strong {

            display: block;

            font-size: 15px;

        }


        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 22px;

        }


        .stat-card {

            background: white;

            padding: 20px;

            border-radius: 13px;

            border:
                1px solid #eef0f4;

            box-shadow:
                0 4px 15px rgba(0, 0, 0, .05);

        }


        .stat-title {

            color: #6b7280;

            font-size: 13px;

        }


        .stat-value {

            margin-top: 8px;

            font-size: 28px;

            font-weight: 700;

            color: #111827;

        }


        .stat-value small {

            font-size: 14px;

            font-weight: 400;

            color: #6b7280;

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

            border-radius: 13px;

            padding: 22px;

            border:
                1px solid #eef0f4;

            box-shadow:
                0 4px 15px rgba(0, 0, 0, .05);

        }


        .chart-card h3 {

            margin: 0;

            font-size: 18px;

        }


        .chart-card p {

            margin: 4px 0 18px;

            color: #6b7280;

            font-size: 13px;

        }


        .chart-container {

            position: relative;

            height: 310px;

        }


        /*
        |--------------------------------------------------------------------------
        | KPI Table
        |--------------------------------------------------------------------------
        */

        .table-card {

            background: white;

            border-radius: 13px;

            padding: 22px;

            border:
                1px solid #eef0f4;

            box-shadow:
                0 4px 15px rgba(0, 0, 0, .05);

        }


        .table-header {

            margin-bottom: 15px;

        }


        .table-header h3 {

            margin: 0;

        }


        .table-wrapper {

            overflow-x: auto;

        }


        table {

            width: 100%;

            border-collapse:
                collapse;

        }


        th {

            background: #f8fafc;

            color: #475569;

            font-size: 13px;

            font-weight: 500;

            padding: 13px;

            text-align: left;

            white-space: nowrap;

        }


        td {

            padding: 13px;

            border-top:
                1px solid #f1f5f9;

            font-size: 14px;

        }


        .badge {

            display: inline-block;

            padding: 4px 9px;

            border-radius: 20px;

            font-size: 11px;

        }


        .badge-performance {

            background: #dbeafe;

            color: #1d4ed8;

        }


        .badge-competency {

            background: #ede9fe;

            color: #6d28d9;

        }


        .score {

            font-weight: 700;

        }


        .score-empty {

            color: #9ca3af;

        }
        @media (max-width: 1100px) {

            .stats-grid {

                grid-template-columns:
                    repeat(2, 1fr);

            }


            .chart-grid {

                grid-template-columns: 1fr;

            }

        }


        @media (max-width: 700px) {

            .sidebar {

                transform:
                    translateX(-100%);

                transition:
                    transform .25s;

            }


            .sidebar.mobile-open {

                transform:
                    translateX(0);

            }


            .main-content {

                margin-left: 0;

            }


            .mobile-menu-button {

                display: flex;

            }


            .mobile-menu-overlay.active {

                display: block;

                position: fixed;

                inset: 0;

                background:
                    rgba(0, 0, 0, .35);

                z-index: 900;

            }


            .topbar {

                padding: 0 18px;

            }


            .dashboard-content {

                padding: 18px;

            }


            .page-header {

                flex-direction: column;

                align-items: flex-start;

            }


            .period-form {

                width: 100%;

            }


            .period-form select {

                width: 100%;

            }


            .profile-card {

                flex-direction: column;

                align-items: flex-start;

            }


            .profile-meta {

                text-align: left;

                flex-wrap: wrap;

            }


            .stats-grid {

                grid-template-columns: 1fr;

            }

        }
    </style>

</head>

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
                class="nav-item active">

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
                class="nav-item">

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

    <div
        class="mobile-menu-overlay"
        id="mobileMenuOverlay"></div>

    <!-- === MAIN === -->

    <main class="main-content">

        <!-- TOPBAR -->

        <header class="topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton">
                ☰

            </button>

        </header>

            <!-- PAGE HEADER -->

            <div class="page-header">

                <div>

                    <h1>
                        ภาพรวม KPI
                    </h1>

                    <p>
                        ภาพรวมผลการปฏิบัติงานของคุณ
                    </p>

                </div>

                <!-- Period -->

                <form
                    method="GET"
                    class="period-form">

                    <select
                        name="period_id"
                        onchange="this.form.submit()">


                        <?php foreach (
                            $periods
                            as $period
                        ): ?>

                            <option
                                value="<?= (int) $period["period_id"] ?>"
                                <?= (
                                    (int) $period["period_id"]
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

            <!-- === PROFILE === -->

            <section class="profile-card">

                <div class="profile-left">

                    <div class="profile-avatar">

                        <?= htmlspecialchars(
                            $avatar,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>


                    <div>

                        <div class="profile-name">

                            <?= htmlspecialchars(
                                $employee["first_name"]
                                    . " "
                                    . $employee["last_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>


                        <div class="profile-code">

                            <?= htmlspecialchars(
                                $employee["employee_code"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>

                    </div>

                </div>

                <div class="profile-meta">

                    <div>

                        <span>
                            แผนก
                        </span>

                        <strong>

                            <?= htmlspecialchars(
                                $employee["department_name"] ?? "-",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </strong>

                    </div>


                    <div>

                        <span>
                            ตำแหน่ง
                        </span>

                        <strong>

                            <?= htmlspecialchars(
                                $employee["position_name"] ?? "-",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </strong>

                    </div>


                </div>


            </section>


            <!-- === STATS === -->

            <section class="stats-grid">

                <!-- Total KPI -->

                <div class="stat-card">

                    <div class="stat-title">
                        KPI ที่ได้รับ
                    </div>

                    <div class="stat-value">

                        <?= number_format(
                            $totalKpi
                        ) ?>

                    </div>

                </div>

                <!-- Evaluated -->

                <div class="stat-card">

                    <div class="stat-title">
                        ประเมินแล้ว
                    </div>

                    <div class="stat-value">

                        <?= number_format(
                            $evaluatedKpi
                        ) ?>

                        <small>
                            / <?= number_format(
                                    $totalKpi
                                ) ?>
                        </small>

                    </div>

                </div>


                <!-- Pending -->

                <div class="stat-card">

                    <div class="stat-title">
                        รอประเมิน
                    </div>

                    <div class="stat-value">

                        <?= number_format(
                            $pendingKpi
                        ) ?>

                    </div>

                </div>


                <!-- Score -->

                <div class="stat-card">

                    <div class="stat-title">
                        คะแนนเฉลี่ย
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


            </section>


            <!-- === CHARTS === -->

            <section class="chart-grid">


                <!-- KPI Score -->

                <div class="chart-card">


                    <h3>
                        คะแนน KPI ของฉัน
                    </h3>

                    <p>
                        คะแนนของ KPI แต่ละตัวในรอบประเมิน
                    </p>


                    <div class="chart-container">


                        <?php if (
                            !empty($chartLabels)
                        ): ?>

                            <canvas
                                id="kpiChart">
                            </canvas>

                        <?php else: ?>

                            <div
                                style="
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                height:100%;
                                color:#9ca3af;
                            ">

                                ยังไม่มีข้อมูล KPI

                            </div>

                        <?php endif; ?>


                    </div>


                </div>


                <!-- KPI Type -->

                <div class="chart-card">


                    <h3>
                        คะแนนตามประเภท KPI
                    </h3>

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

                            <div
                                style="
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                height:100%;
                                color:#9ca3af;
                            ">

                                ยังไม่มีข้อมูล

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </section>

            <!-- === KPI TABLE === -->

            <section class="table-card">


                <div class="table-header">

                    <h3>
                        KPI ที่ได้รับมอบหมาย
                    </h3>

                </div>


                <div class="table-wrapper">


                    <table>


                        <thead>

                            <tr>

                                <th>
                                    KPI
                                </th>

                                <th>
                                    ประเภท
                                </th>

                                <th>
                                    Weight
                                </th>

                                <th>
                                    Target
                                </th>

                                <th>
                                    Actual
                                </th>

                                <th>
                                    Score
                                </th>

                                <th>
                                    สถานะ
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php if (
                                empty($kpiData)
                            ): ?>


                                <tr>

                                    <td
                                        colspan="7"
                                        style="
                                        text-align:center;
                                        padding:35px;
                                        color:#9ca3af;
                                    ">

                                        ยังไม่มี KPI ที่ได้รับมอบหมาย

                                    </td>

                                </tr>


                            <?php else: ?>


                                <?php foreach (
                                    $kpiData
                                    as $kpi
                                ): ?>


                                    <tr>


                                        <!-- KPI -->

                                        <td>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $kpi["kpi_name"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </strong>

                                        </td>


                                        <!-- Type -->

                                        <td>


                                            <?php if (
                                                $kpi["kpi_type"]
                                                ===
                                                "Performance"
                                            ): ?>


                                                <span
                                                    class="
                                                    badge
                                                    badge-performance
                                                ">

                                                    Performance

                                                </span>


                                            <?php else: ?>


                                                <span
                                                    class="
                                                    badge
                                                    badge-competency
                                                ">

                                                    Competency

                                                </span>


                                            <?php endif; ?>


                                        </td>


                                        <!-- Weight -->

                                        <td>

                                            <?= number_format(
                                                (float)
                                                $kpi["assignment_weight"],
                                                2
                                            ) ?>

                                            %

                                        </td>


                                        <!-- Target -->

                                        <td>

                                            <?= $kpi["assignment_target"] !== null

                                                ? htmlspecialchars(
                                                    $kpi["assignment_target"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                )
                                                : "-"
                                            ?>


                                            <?php if (
                                                !empty($kpi["unit"])
                                            ): ?>

                                                <?= htmlspecialchars(
                                                    $kpi["unit"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            <?php endif; ?>


                                        </td>


                                        <!-- Actual -->

                                        <td>

                                            <?= $kpi["actual_value"] !== null

                                                ? htmlspecialchars(
                                                    $kpi["actual_value"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                )
                                                : "-"
                                            ?>


                                        </td>


                                        <!-- Score -->

                                        <td>


                                            <?php if (
                                                $kpi["score"] !== null
                                            ): ?>


                                                <span
                                                    class="score">

                                                    <?= number_format(
                                                        (float)
                                                        $kpi["score"],
                                                        2
                                                    ) ?>

                                                    / 5

                                                </span>


                                            <?php else: ?>


                                                <span
                                                    class="
                                                    score-empty
                                                ">

                                                    ยังไม่มีคะแนน

                                                </span>


                                            <?php endif; ?>


                                        </td>


                                        <!-- Status -->

                                        <td>


                                            <?php if (
                                                $kpi["score"] !== null
                                            ): ?>

                                                <span
                                                    class="
                                                    badge
                                                    badge-performance
                                                ">

                                                    ประเมินแล้ว

                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="
                                                    badge
                                                    badge-competency
                                                ">

                                                    รอดำเนินการ

                                                </span>

                                            <?php endif; ?>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </section>

    </main>


    <!-- =========================================================
     CHART.JS
========================================================= -->

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js">
    </script>


    <script>
        /*
|--------------------------------------------------------------------------
| KPI Chart
|--------------------------------------------------------------------------
*/

        const kpiLabels =
            <?= json_encode(
                $chartLabels,
                JSON_UNESCAPED_UNICODE
            ) ?>;


        const kpiScores =
            <?= json_encode(
                $chartScores
            ) ?>;


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

                                label: "คะแนน",

                                data: kpiScores,

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


        /*
        |--------------------------------------------------------------------------
        | KPI Type Chart
        |--------------------------------------------------------------------------
        */

        const typeLabels =
            <?= json_encode(
                $typeLabels,
                JSON_UNESCAPED_UNICODE
            ) ?>;


        const typeScores =
            <?= json_encode(
                $typeScores
            ) ?>;


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
        | Mobile Menu
        |--------------------------------------------------------------------------
        */

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

        }


        function closeMobileMenu() {

            sidebar.classList.remove(
                "mobile-open"
            );

            mobileMenuOverlay.classList.remove(
                "active"
            );

        }


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


        mobileMenuOverlay.addEventListener(
            "click",
            closeMobileMenu
        );


        window.addEventListener(
            "resize",
            function() {

                if (
                    window.innerWidth > 700
                ) {

                    closeMobileMenu();

                }

            }
        );
    </script>


</body>

</html>