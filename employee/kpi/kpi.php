<?php

session_start();

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| Authentication Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Employee Information
|--------------------------------------------------------------------------
*/

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

$firstName = $_SESSION["first_name"] ?? "";
$lastName = $_SESSION["last_name"] ?? "";

$fullName = trim($firstName . " " . $lastName);


if ($employeeId <= 0) {
    header("Location: ../../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Active Tab
|--------------------------------------------------------------------------
*/

$activeTab = $_GET["tab"] ?? "performance";

if (!in_array($activeTab, ["performance", "competency"])) {
    $activeTab = "performance";
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$success = "";
$error = "";

/*
|--------------------------------------------------------------------------
| Save KPI
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $assignmentId = (int) ($_POST["assignment_id"] ?? 0);

    $actual = trim($_POST["actual"] ?? "");
    if ($actual === "" || !ctype_digit($actual)) {

        $error = "กรุณากรอกผลที่ทำได้เป็นจำนวนเต็ม";
    } else {

        $actual = (int) $actual;
    }

    $score = trim($_POST["score"] ?? "");
    $comment = trim($_POST["comment"] ?? "");


    /*
    |--------------------------------------------------------------------------
    | Validate Assignment
    |--------------------------------------------------------------------------
    */

    if ($assignmentId <= 0) {

        $error = "ไม่พบ KPI ที่ต้องการบันทึก";
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Get Assignment
            |--------------------------------------------------------------------------
            */

            $checkStmt = $pdo->prepare("
                SELECT
                    a.assignment_id,
                    a.employee_id,
                    a.kpi_id,
                    a.target_value,
                    k.kpi_type,
                    k.kpi_name,
                    k.max_score

                FROM kpi_assignments a

                INNER JOIN kpi_indicators k
                    ON a.kpi_id = k.kpi_id

                WHERE a.assignment_id = :assignment_id
                  AND a.employee_id = :employee_id
                  AND a.status = 'Active'

                LIMIT 1
            ");

            $checkStmt->execute([
                ":assignment_id" => $assignmentId,
                ":employee_id" => $employeeId
            ]);

            $assignment = $checkStmt->fetch(PDO::FETCH_ASSOC);


            if (!$assignment) {

                $error = "ไม่สามารถบันทึก KPI นี้ได้";
            } else {

                /*
                |--------------------------------------------------------------------------
                | KPI TYPE
                |--------------------------------------------------------------------------
                */

                $realKpiType = strtolower(
                    trim($assignment["kpi_type"] ?? "")
                );


                /*
                |--------------------------------------------------------------------------
                | PERFORMANCE KPI
                |--------------------------------------------------------------------------
                */

                if ($realKpiType === "performance") {

                    if ($actual === "") {

                        $error = "กรุณากรอก Actual";
                    } elseif (!is_numeric($actual)) {

                        $error = "Actual ต้องเป็นตัวเลข";
                    } elseif (
                        $assignment["target_value"] === null ||
                        $assignment["target_value"] === ""
                    ) {

                        $error = "KPI นี้ยังไม่ได้กำหนด Target โดย Admin";
                    } else {

                        $targetValue = (int) $assignment["target_value"];
                        $actualValue = (int) $actual;

                        if ($targetValue <= 0) {

                            $error = "Target ของ KPI ต้องมากกว่า 0";
                        } else {

                            /*
        |--------------------------------------------------------------------------
        | Score = Actual / Target × 5
        |--------------------------------------------------------------------------
        */

                            $scoreValue =
                                ($actualValue / $targetValue) * 5;

                            if ($scoreValue > 5) {
                                $scoreValue = 5;
                            }

                            if ($scoreValue < 0) {
                                $scoreValue = 0;
                            }

                            $score = number_format(
                                $scoreValue
                            );
                        }
                    }


                    /*
                |--------------------------------------------------------------------------
                | COMPETENCY KPI
                |--------------------------------------------------------------------------
                */
                } elseif ($realKpiType === "competency") {

                    /*
                    |--------------------------------------------------------------------------
                    | ต้องเลือกคะแนน 1 - 5
                    |--------------------------------------------------------------------------
                    */

                    if ($score === "") {

                        $error = "กรุณาเลือกคะแนน";
                    } elseif (!is_numeric($score)) {

                        $error = "คะแนนต้องเป็นตัวเลข";
                    } else {

                        $scoreValue = (int) $score;


                        if (
                            $scoreValue < 1 ||
                            $scoreValue > 5
                        ) {

                            $error =
                                "คะแนนต้องอยู่ระหว่าง 1 - 5";
                        } else {

                            $score = number_format(
                                $scoreValue
                            );
                        }
                    }
                } else {

                    $error =
                        "ไม่พบประเภท KPI ที่ถูกต้อง";
                }


                /*
                |--------------------------------------------------------------------------
                | Save To Database
                |--------------------------------------------------------------------------
                */

                if ($error === "") {

                    /*
                    |--------------------------------------------------------------------------
                    | Check Existing Performance
                    |--------------------------------------------------------------------------
                    */

                    $existingStmt = $pdo->prepare("
                        SELECT
                            performance_id

                        FROM kpi_performances

                        WHERE assignment_id = :assignment_id
                          AND employee_id = :employee_id

                        ORDER BY
                            performance_id DESC

                        LIMIT 1
                    ");

                    $existingStmt->execute([
                        ":assignment_id" => $assignmentId,
                        ":employee_id" => $employeeId
                    ]);

                    $existing =
                        $existingStmt->fetch(PDO::FETCH_ASSOC);


                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE
                    |--------------------------------------------------------------------------
                    */

                    if ($existing) {

                        $updateStmt = $pdo->prepare("
                            UPDATE kpi_performances

                            SET
                                performance_date = CURDATE(),
                                target = :target,
                                actual = :actual,
                                score = :score,
                                comment = :comment,
                                status = 'Draft'

                            WHERE performance_id = :performance_id
                              AND employee_id = :employee_id
                        ");


                        $updateStmt->execute([

                            ":target" =>
                            (int) $assignment["target_value"],

                            ":actual" =>
                            $actual !== ""
                                ? (int) $actual
                                : null,

                            ":score" =>
                            $score !== ""
                                ? (int) $score
                                : null,

                            ":comment" =>
                            $comment !== ""
                                ? $comment
                                : null,

                            ":performance_id" =>
                            $existing["performance_id"],

                            ":employee_id" =>
                            $employeeId

                        ]);


                        /*
                    |--------------------------------------------------------------------------
                    | INSERT
                    |--------------------------------------------------------------------------
                    */
                    } else {

                        $insertStmt = $pdo->prepare("
                            INSERT INTO kpi_performances
                            (
                                assignment_id,
                                employee_id,
                                performance_date,
                                target,
                                actual,
                                score,
                                comment,
                                status
                            )

                            VALUES
                            (
                                :assignment_id,
                                :employee_id,
                                CURDATE(),
                                :target,
                                :actual,
                                :score,
                                :comment,
                                'Draft'
                            )
                        ");


                        $insertStmt->execute([

                            ":assignment_id" =>
                            $assignmentId,

                            ":employee_id" =>
                            $employeeId,

                            ":target" =>
                            (int) $assignment["target_value"],

                            ":actual" =>
                            $actual !== ""
                                ? (int) $actual
                                : null,

                            ":score" =>
                            $score !== ""
                                ? (int) $score
                                : null,

                            ":comment" =>
                            $comment !== ""
                                ? $comment
                                : null

                        ]);
                    }


                    $success =
                        "บันทึกข้อมูล KPI เรียบร้อยแล้ว";
                }
            }
        } catch (PDOException $e) {

            $error =
                "ไม่สามารถบันทึกข้อมูล KPI ได้";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get KPI Assignments
|--------------------------------------------------------------------------
|
| ใช้ Subquery เพื่อเอา Performance ล่าสุดเพียง 1 รายการ
| ป้องกัน KPI ซ้ำหลายแถว
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        a.assignment_id,
        a.employee_id,
        a.period_id,
        a.kpi_id,
        a.weight,
        a.target_value,
        a.status AS assignment_status,

        k.kpi_name,
        k.description,
        k.unit,
        k.max_score,
        k.kpi_type,

        ep.period_name,
        ep.start_date,
        ep.end_date,
        ep.status AS period_status,

        kp.performance_id,
        kp.performance_date,
        kp.target,
        kp.actual,
        kp.score,
        kp.comment,
        kp.status AS performance_status

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    INNER JOIN evaluation_periods ep
        ON a.period_id = ep.period_id

    LEFT JOIN kpi_performances kp
        ON kp.performance_id = (

            SELECT kp2.performance_id

            FROM kpi_performances kp2

            WHERE kp2.assignment_id = a.assignment_id
              AND kp2.employee_id = a.employee_id

            ORDER BY
                kp2.performance_id DESC

            LIMIT 1
        )

    WHERE a.employee_id = :employee_id

      AND a.status = 'Active'

    ORDER BY
        ep.start_date DESC,
        a.assignment_id ASC
";


try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":employee_id" => $employeeId
    ]);

    $assignments =
        $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

    $assignments = [];

    $error =
        "ไม่สามารถโหลดข้อมูล KPI ได้";
}


/*
|--------------------------------------------------------------------------
| Separate KPI Type
|--------------------------------------------------------------------------
*/

$performanceKpis = [];

$competencyKpis = [];


foreach ($assignments as $assignment) {

    $type = strtolower(
        trim($assignment["kpi_type"] ?? "")
    );


    if ($type === "performance") {

        $performanceKpis[] = $assignment;
    } elseif ($type === "competency") {

        $competencyKpis[] = $assignment;
    }
}


/*
|--------------------------------------------------------------------------
| Get Score Levels + Criteria
|--------------------------------------------------------------------------
|
| เก็บตาม kpi_id
|--------------------------------------------------------------------------
*/

$scoreLevels = [];

$scoreCriteria = [];


try {

    /*
    |--------------------------------------------------------------------------
    | kpi_score_levels
    |--------------------------------------------------------------------------
    */

    $levelStmt = $pdo->query("
        SELECT
            score_level_id,
            kpi_id,
            score,
            criteria

        FROM kpi_score_levels

        ORDER BY
            kpi_id ASC,
            score DESC
    ");


    $levelRows =
        $levelStmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($levelRows as $level) {

        $kpiId = (int) $level["kpi_id"];

        if (!isset($scoreLevels[$kpiId])) {
            $scoreLevels[$kpiId] = [];
        }

        $scoreLevels[$kpiId][] = $level;
    }


    /*
    |--------------------------------------------------------------------------
    | kpi_score_criteria
    |--------------------------------------------------------------------------
    */

    $criteriaStmt = $pdo->query("
        SELECT
            criteria_id,
            kpi_id,
            score_level,
            criteria

        FROM kpi_score_criteria

        ORDER BY
            kpi_id ASC,
            score_level DESC
    ");


    $criteriaRows =
        $criteriaStmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($criteriaRows as $criteria) {

        $kpiId = (int) $criteria["kpi_id"];

        if (!isset($scoreCriteria[$kpiId])) {
            $scoreCriteria[$kpiId] = [];
        }

        $scoreCriteria[$kpiId][] = $criteria;
    }
} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | ถ้าตารางเกณฑ์มีปัญหา
    | ไม่ให้ทั้งหน้า KPI พัง
    |--------------------------------------------------------------------------
    */

    $scoreLevels = [];

    $scoreCriteria = [];
}


/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$totalKpi =
    count($assignments);

$totalPerformance =
    count($performanceKpis);

$totalCompetency =
    count($competencyKpis);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        KPI ของฉัน | KPI Management System
    </title>


    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">


    <link
        rel="stylesheet"
        href="../../assets/css/employee-kpi.css">


    <style>
        /*
        |--------------------------------------------------------------------------
        | Tabs
        |--------------------------------------------------------------------------
        */

        .kpi-tabs {

            display: flex;

            gap: 12px;

            margin-bottom: 25px;

        }


        .kpi-tab {

            padding: 14px 28px;

            border-radius: 10px;

            text-decoration: none;

            font-weight: 600;

            background: #e2e4e8;

            color: #475569;

            transition: .2s;

        }


        .kpi-tab:hover {

            background: #dbe4f0;

        }


        .kpi-tab.active {

            background: #244397;

            color: white;

        }


        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        .kpi-input-table {

            width: 100%;

            border-collapse: collapse;

        }


        .kpi-input-table th {

            background: #f1f5f9;

            padding: 15px;

            text-align: left;

            border-bottom: 1px solid #e2e8f0;

        }


        .kpi-input-table td {

            padding: 18px 15px;

            border-bottom: 1px solid #e5e7eb;

            vertical-align: top;

        }


        .kpi-title {

            font-weight: 600;

            font-size: 16px;

            color: #1e293b;

        }


        .kpi-description {

            margin-top: 5px;

            color: #64748b;

            font-size: 14px;

            line-height: 1.6;

        }


        .kpi-meta {

            margin-top: 8px;

            display: flex;

            gap: 8px;

            flex-wrap: wrap;

        }


        .kpi-badge {

            display: inline-block;

            padding: 4px 9px;

            border-radius: 6px;

            background: #eff6ff;

            color: #1d4ed8;

            font-size: 13px;

        }


        /*
        |--------------------------------------------------------------------------
        | Input
        |--------------------------------------------------------------------------
        */

        .input-area {

            display: grid;

            grid-template-columns:
                repeat(2, minmax(120px, 1fr));

            gap: 12px;

        }


        .input-group {

            display: flex;

            flex-direction: column;

            gap: 5px;

        }


        .input-group label {

            font-size: 13px;

            font-weight: 500;

            color: #64748b;

        }


        .input-group input,
        .input-group textarea,
        .score-select {

            width: 100%;

            padding: 9px 10px;

            border: 1px solid #cbd5e1;

            border-radius: 7px;

            font-family: inherit;

            font-size: 14px;

            box-sizing: border-box;

        }


        .input-group textarea {

            min-height: 70px;

            resize: vertical;

        }


        /*
        |--------------------------------------------------------------------------
        | Score
        |--------------------------------------------------------------------------
        */

        .score-display {

            margin-top: 10px;

            padding: 8px 12px;

            background: #eff6ff;

            border-radius: 7px;

            font-weight: 600;

            color: #244397;

        }


        /*
        |--------------------------------------------------------------------------
        | Score Criteria
        |--------------------------------------------------------------------------
        */

        .criteria-box {

            margin-top: 15px;

            padding: 12px;

            background: #f8fafc;

            border: 1px solid #e2e8f0;

            border-radius: 8px;

        }


        .criteria-title {

            font-weight: 600;

            margin-bottom: 8px;

            color: #334155;

        }


        .criteria-item {

            padding: 6px 0;

            border-bottom: 1px solid #e5e7eb;

            font-size: 13px;

            color: #64748b;

        }


        .criteria-item:last-child {

            border-bottom: none;

        }


        .criteria-score {

            font-weight: 600;

            color: #244397;

        }


        /*
        |--------------------------------------------------------------------------
        | Save Button
        |--------------------------------------------------------------------------
        */

        .save-button {

            margin-top: 12px;

            padding: 10px 18px;

            border: none;

            border-radius: 7px;

            background: #244397;

            color: white;

            font-family: inherit;

            cursor: pointer;

            font-weight: 500;

        }


        .save-button:hover {

            background: #1e3a8a;

        }


        /*
        |--------------------------------------------------------------------------
        | Messages
        |--------------------------------------------------------------------------
        */

        .success-message {

            background: #dcfce7;

            color: #166534;

            padding: 13px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

        }


        .error-message {

            background: #fee2e2;

            color: #991b1b;

            padding: 13px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

        }


        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty-kpi {

            text-align: center;

            padding: 50px 20px;

            color: #64748b;

        }


        /*
        |--------------------------------------------------------------------------
        | Period
        |--------------------------------------------------------------------------
        */

        .period-info {

            margin-bottom: 15px;

            color: #64748b;

        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        @media (max-width: 800px) {

            .kpi-tabs {

                flex-direction: column;

            }


            .kpi-tab {

                text-align: center;

            }


            .input-area {

                grid-template-columns: 1fr;

            }


            .table-wrapper {

                overflow-x: auto;

            }


            .kpi-input-table {

                min-width: 850px;

            }

        }
    </style>

</head>


<body>


    <!-- =========================================================
     SIDEBAR
========================================================= -->

    <aside class="sidebar">

        <div class="sidebar-logo">

            <img
                src="../../assets/images/Advance-Logo.png"
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
                href="../index.php"
                class="nav-item">

                <span class="nav-icon">
                    🏠
                </span>

                <span>
                    หน้าแรก
                </span>
            </a>

            <a
                href="kpi.php"
                class="nav-item active">

                <span class="nav-icon">
                    🎯
                </span>

                <span>
                    KPI ของฉัน
                </span>
            </a>

            <a
                href="../../employee/performance.php"
                class="nav-item">

                <span class="nav-icon">
                    📊
                </span>

                <span>
                    ผลการปฏิบัติงาน
                </span>
            </a>

            <a
                href="../profile.php"
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
                href="../../logout.php"
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
                            $_SESSION["employee_code"] ?? "-",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </span>

                </div>

            </div>

        </header>


        <!-- PAGE HEADER -->

        <section class="page-header">

            <div>

                <h1>
                    KPI ของฉัน
                </h1>

                <p>
                    กรอกและบันทึกผลการปฏิบัติงานของคุณ
                </p>

            </div>

        </section>

        <!-- MESSAGE -->

        <?php if ($success !== ""): ?>

            <div class="success-message">

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <?php if ($error !== ""): ?>

            <div class="error-message">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <!-- SUMMARY -->

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
                        <?= $totalKpi ?>
                    </strong>

                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon">
                    📈
                </div>

                <div>

                    <span>
                        Performance KPI
                    </span>

                    <strong>
                        <?= $totalPerformance ?>
                    </strong>

                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon">
                    ⭐
                </div>

                <div>

                    <span>
                        Competency KPI
                    </span>

                    <strong>
                        <?= $totalCompetency ?>
                    </strong>

                </div>

            </div>

        </section>

        <!-- =====================================================
         TABS
    ====================================================== -->

        <div class="kpi-tabs">

            <a
                href="kpi.php?tab=performance"
                class="kpi-tab <?= $activeTab === "performance" ? "active" : "" ?>">

                📈 Performance KPI

                (<?= $totalPerformance ?>)

            </a>

            <a
                href="kpi.php?tab=competency"
                class="kpi-tab <?= $activeTab === "competency" ? "active" : "" ?>">

                ⭐ Competency KPI

                (<?= $totalCompetency ?>)

            </a>

        </div>

        <!-- =====================================================
         PERFORMANCE
    ====================================================== -->

        <?php if ($activeTab === "performance"): ?>

            <section class="period-card">

                <div class="period-header">

                    <div>

                        <h2>
                            Performance KPI
                        </h2>

                        <p>
                            KPI ที่วัดผลจาก Target และ Actual
                        </p>

                    </div>

                </div>

                <?php if (empty($performanceKpis)): ?>

                    <div class="empty-kpi">

                        <div style="font-size:40px;">
                            📈
                        </div>

                        <h3>
                            ยังไม่มี Performance KPI
                        </h3>

                        <p>
                            ยังไม่มี KPI ประเภท Performance ที่ได้รับมอบหมาย
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="kpi-input-table">

                            <thead>

                                <tr>

                                    <th style="width:35%;">
                                        หัวข้อ KPI
                                    </th>

                                    <th>
                                        ข้อมูลผลการปฏิบัติงาน
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($performanceKpis as $kpi): ?>

                                    <tr>

                                        <!-- LEFT -->

                                        <td>

                                            <div class="kpi-title">

                                                <?= htmlspecialchars(
                                                    $kpi["kpi_name"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </div>

                                            <div class="kpi-meta">

                                                <span class="kpi-badge">

                                                    Weight:
                                                    <?= htmlspecialchars(
                                                        (int) ($kpi["weight"] ?? "0"),
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>%

                                                </span>

                                                <span class="kpi-badge">

                                                    Unit:
                                                    <?= htmlspecialchars(
                                                        $kpi["unit"] ?? "-",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>

                                                </span>

                                            </div>

                                            <!-- SCORE CRITERIA -->

                                            <?php

                                            $kpiId =
                                                (int) $kpi["kpi_id"];

                                            ?>

                                            <?php if (!empty($scoreLevels[$kpiId])): ?>

                                                <div class="criteria-box">

                                                    <div class="criteria-title">

                                                        ระดับผลงาน (Criteria)

                                                    </div>


                                                    <?php foreach (
                                                        $scoreLevels[$kpiId]
                                                        as $level
                                                    ): ?>

                                                        <div class="criteria-item">

                                                            <span class="criteria-score">

                                                                <?= htmlspecialchars(
                                                                    (int) $level["score"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8"
                                                                ) ?>

                                                            </span>

                                                            :

                                                            <?= htmlspecialchars(
                                                                $level["criteria"] ?? "-",
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>

                                                        </div>

                                                    <?php endforeach; ?>

                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <!-- RIGHT -->

                                        <td>

                                            <form
                                                method="POST"
                                                action="kpi.php?tab=performance">

                                                <input

                                                    type="hidden"
                                                    name="assignment_id"
                                                    value="<?= (int) $kpi["assignment_id"] ?>">

                                                <div class="input-area">
                                                    <div class="input-group">

                                                        <label>ตัวชี้วัดผลงาน</label>

                                                        <div class="target-display">
                                                            <?= htmlspecialchars(
                                                                $kpi["description"] ?? "",
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>
                                                        </div>

                                                    </div>

                                                    <div class="input-group">

                                                        <label>

                                                            เป้าหมาย % (Target)

                                                        </label>

                                                        <div style="

                                                                padding: 9px 10px;

                                                                background: #f1f5f9;

                                                                border: 1px solid #cbd5e1;

                                                                border-radius: 7px;

                                                            ">

                                                            <?= number_format((int) $kpi["target_value"]) ?>

                                                            <?= htmlspecialchars(

                                                                $kpi["unit"] ?? "",

                                                                ENT_QUOTES,

                                                                "UTF-8"

                                                            ) ?>

                                                        </div>

                                                    </div>

                                                    <div class="input-group">

                                                        <label>
                                                            ที่ทำได้จริง % (Actual)
                                                        </label>

                                                        <input
                                                            type="number"
                                                            step="1"
                                                            name="actual"
                                                            value="<?= htmlspecialchars(
                                                                        (int) $kpi["actual"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8"
                                                                    ) ?>"
                                                            placeholder="กรอกผลที่ทำได้"
                                                            required>

                                                        <div
                                                            style="
                                                                margin-top:8px;
                                                                font-size:13px;
                                                                color:#64748b;
                                                            ">

                                                            คะแนนจริง = Weight × Criteria

                                                        </div>

                                                    </div>

                                                    <?php if (
                                                        $kpi["score"] !== null &&
                                                        $kpi["score"] !== ""
                                                    ): ?>

                                                    <?php endif; ?>

                                                </div>
                                                <button
                                                    type="submit"
                                                    class="save-button">

                                                    บันทึกข้อมูล

                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </section>


            <!-- =====================================================
         COMPETENCY
    ====================================================== -->

        <?php else: ?>


            <section class="period-card">

                <div class="period-header">

                    <div>

                        <h2>
                            Competency KPI
                        </h2>

                        <p>
                            KPI ด้านสมรรถนะและพฤติกรรม
                        </p>

                    </div>

                </div>

                <?php if (empty($competencyKpis)): ?>

                    <div class="empty-kpi">

                        <div style="font-size:40px;">
                            ⭐
                        </div>

                        <h3>
                            ยังไม่มี Competency KPI
                        </h3>

                        <p>
                            ยังไม่มี KPI ประเภท Competency ที่ได้รับมอบหมาย
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="kpi-input-table">

                            <thead>

                                <tr>

                                    <th style="width:35%;">
                                        หัวข้อ KPI
                                    </th>

                                    <th>
                                        ข้อมูลผลการปฏิบัติงาน
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($competencyKpis as $kpi): ?>

                                    <?php

                                    $kpiId =
                                        (int) $kpi["kpi_id"];

                                    ?>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

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