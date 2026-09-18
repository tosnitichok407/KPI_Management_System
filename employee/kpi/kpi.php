<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/layout.php";
require_once __DIR__ . "/../performance-export-data.php";


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
| Selected Month (ปี + เดือน)
|--------------------------------------------------------------------------
|
| Assignment ใช้ได้ทั้งปี / Performance ผูกกับรอบประเมินของเดือนที่เลือก
|
*/

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

$thaiMonths = monthlyPeriodMonths();
$selectedQuarter = getQuarterByMonth($selectedMonth);
$selectedMonthStart = sprintf("%04d-%02d-01", $selectedYear, $selectedMonth);
$selectedMonthEnd = date("Y-m-t", strtotime($selectedMonthStart));

$selectedPeriod = findEvaluationPeriodByMonth($pdo, $selectedYear, $selectedMonth);
$selectedPeriodId = $selectedPeriod ? (int) $selectedPeriod["period_id"] : 0;

$monthQuery = "year=" . $selectedYear . "&month=" . $selectedMonth;

$yearStmt = $pdo->prepare("
    SELECT DISTINCT assignment_year
    FROM kpi_assignments
    WHERE employee_id = :employee_id
      AND status = 'Active'
");
$yearStmt->execute([":employee_id" => $employeeId]);

$availableYears = array_map("intval", $yearStmt->fetchAll(PDO::FETCH_COLUMN));
$availableYears[] = $currentYear;
$availableYears[] = $selectedYear;
$availableYears = array_unique($availableYears);
rsort($availableYears);

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

    // Actual ใช้เฉพาะ Performance KPI (Competency เลือกคะแนน 1-5 แทน)
    $actual = trim($_POST["actual"] ?? "");

    $score = trim($_POST["score"] ?? "");
    $comment = trim($_POST["comment"] ?? "");

    /*
    |--------------------------------------------------------------------------
    | Validate Assignment
    |--------------------------------------------------------------------------
    */

    if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($assignmentId <= 0) {

        $error = "ไม่พบ KPI ที่ต้องการบันทึก";
    } elseif (mb_strlen($comment) > 5000) {

        $error = "ความคิดเห็นยาวเกินกำหนด";
    } elseif (!$selectedPeriod) {

        $error = "ยังไม่มีรอบประเมินของเดือน"
            . $thaiMonths[$selectedMonth] . " " . $selectedYear
            . " กรุณาติดต่อผู้ดูแลระบบ";
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
                  AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
                  AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :month_start

                LIMIT 1
            ");

            $checkStmt->execute([
                ":assignment_id" => $assignmentId,
                ":employee_id" => $employeeId,
                ":month_start" => $selectedPeriod["start_date"],
                ":month_end" => $selectedPeriod["end_date"]
            ]);

            $assignment = $checkStmt->fetch(PDO::FETCH_ASSOC);


            if (!$assignment) {

                $error = "ไม่สามารถบันทึก KPI นี้ในเดือนที่เลือกได้";
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
                    } elseif (!ctype_digit($actual)) {

                        $error = "กรุณากรอกผลที่ทำได้เป็นจำนวนเต็ม";
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
                    | ต้องเลือกคะแนน 1 - 5 (ไม่มี Actual)
                    |--------------------------------------------------------------------------
                    */

                    $actual = "";

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
                          AND period_id = :period_id

                        LIMIT 1
                    ");

                    $existingStmt->execute([
                        ":assignment_id" => $assignmentId,
                        ":employee_id" => $employeeId,
                        ":period_id" => $selectedPeriodId
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
                                performance_date = :performance_date,
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

                            ":performance_date" =>
                            $selectedPeriod["start_date"],

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
                                period_id,
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
                                :period_id,
                                :performance_date,
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

                            ":period_id" =>
                            $selectedPeriodId,

                            ":performance_date" =>
                            $selectedPeriod["start_date"],

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

            error_log("Save KPI performance failed: " . $e->getMessage());

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
        k.score_5,
        k.score_4,
        k.score_3,
        k.score_2,
        k.score_1,

        a.assignment_year,
        a.start_date,
        a.end_date,

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

    LEFT JOIN kpi_performances kp
        ON kp.performance_id = (

            SELECT kp2.performance_id

            FROM kpi_performances kp2

            WHERE kp2.assignment_id = a.assignment_id
              AND kp2.employee_id = a.employee_id
              AND kp2.period_id = :period_id

            ORDER BY
                kp2.performance_id DESC

            LIMIT 1
        )

    WHERE a.employee_id = :employee_id

      AND a.status = 'Active'

      AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
      AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :month_start

    ORDER BY
        a.assignment_id ASC
";


try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":period_id" => $selectedPeriodId,
        ":employee_id" => $employeeId,
        ":month_start" => $selectedMonthStart,
        ":month_end" => $selectedMonthEnd
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
| เกณฑ์ระดับผลงาน 5..1 ของทุก KPI (Performance + Competency)
|--------------------------------------------------------------------------
|
| แหล่งเดียวกับแบบฟอร์ม Excel/PDF:
| kpi_score_criteria > kpi_score_levels > kpi_indicators.score_5..score_1
|
*/

try {
    $kpiCriteria = loadKpiScoreCriteria($pdo, $assignments);
} catch (PDOException $e) {
    // ถ้าตารางเกณฑ์มีปัญหา ไม่ให้ทั้งหน้า KPI พัง
    $kpiCriteria = [];
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
        href="../../assets/css/employee-kpi.css?v=layout-employee-2">


    <link
        rel="stylesheet"
        href="../../assets/css/employee-my-kpi.css">

</head>


<body>

    <?php employeeLayoutStart("kpi", "../../"); ?>


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

        <!-- MONTH FILTER -->

        <form method="get" class="period-filter">

            <input
                type="hidden"
                name="tab"
                value="<?= htmlspecialchars($activeTab, ENT_QUOTES, "UTF-8") ?>">

            <label for="year">ปี</label>

            <select name="year" id="year" onchange="this.form.submit()">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?= $year ?>" <?= $year === $selectedYear ? "selected" : "" ?>><?= $year ?></option>
                <?php endforeach; ?>
            </select>

            <label for="month">เดือน</label>

            <select name="month" id="month" onchange="this.form.submit()">
                <?php foreach ($thaiMonths as $monthNumber => $monthName): ?>
                    <option value="<?= $monthNumber ?>" <?= $monthNumber === $selectedMonth ? "selected" : "" ?>><?= $monthName ?></option>
                <?php endforeach; ?>
            </select>

            <span class="period-filter-status">

                <?php if ($selectedPeriod): ?>

                    <?= htmlspecialchars($selectedPeriod["period_name"], ENT_QUOTES, "UTF-8") ?>
                    <?= $selectedYear ?>
                    · <?= htmlspecialchars($selectedPeriod["quarter"], ENT_QUOTES, "UTF-8") ?>
                    · <?= date("d/m/Y", strtotime($selectedPeriod["start_date"])) ?>
                    - <?= date("d/m/Y", strtotime($selectedPeriod["end_date"])) ?>

                <?php else: ?>

                    <?= $thaiMonths[$selectedMonth] ?> <?= $selectedYear ?>
                    · <?= $selectedQuarter ?>
                    · ยังไม่มีรอบประเมินของเดือนนี้

                <?php endif; ?>

            </span>

        </form>

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
                href="kpi.php?tab=performance&amp;<?= htmlspecialchars($monthQuery, ENT_QUOTES, "UTF-8") ?>"
                class="kpi-tab <?= $activeTab === "performance" ? "active" : "" ?>">

                📈 Performance KPI

                (<?= $totalPerformance ?>)

            </a>

            <a
                href="kpi.php?tab=competency&amp;<?= htmlspecialchars($monthQuery, ENT_QUOTES, "UTF-8") ?>"
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

                        <div class="empty-kpi-icon">
                            📈
                        </div>

                        <h3>
                            ยังไม่มี KPI ที่ได้รับมอบหมายในเดือนนี้
                        </h3>

                        <p>
                            Performance KPI · <?= $thaiMonths[$selectedMonth] ?> <?= $selectedYear ?> (<?= $selectedQuarter ?>)
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="kpi-input-table">

                            <thead>

                                <tr>

                                    <th class="col-kpi-topic">
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

                                            <!-- ระดับผลงาน 5..1 (แสดงทุก KPI) · ไฮไลต์ระดับของคะแนนที่บันทึก -->

                                            <?php

                                            $kpiId =
                                                (int) $kpi["kpi_id"];

                                            $levels = $kpiCriteria[$kpiId] ?? [];

                                            $currentLevel = ($kpi["score"] !== null && $kpi["score"] !== "")
                                                ? (int) round((float) $kpi["score"])
                                                : 0;

                                            ?>

                                            <div class="criteria-box">

                                                <div class="criteria-title">
                                                    ระดับผลงาน (Criteria)
                                                </div>

                                                <?php if (empty($levels)): ?>

                                                    <div class="criteria-note">
                                                        ยังไม่ได้กำหนดเกณฑ์ระดับผลงานของ KPI นี้ · ติดต่อผู้ดูแลระบบ
                                                    </div>

                                                <?php endif; ?>

                                                <?php for ($level = 5; $level >= 1; $level--): ?>

                                                    <?php $levelText = $levels[$level] ?? ""; ?>

                                                    <div class="criteria-item<?= $level === $currentLevel ? " is-current" : "" ?><?= $levelText === "" ? " is-empty" : "" ?>">

                                                        <span class="criteria-score"><?= $level ?></span>
                                                        :
                                                        <?= $levelText !== "" ? htmlspecialchars($levelText, ENT_QUOTES, "UTF-8") : "-" ?>

                                                        <?php if ($level === $currentLevel): ?>
                                                            <span class="criteria-current">ระดับที่ได้</span>
                                                        <?php endif; ?>

                                                    </div>

                                                <?php endfor; ?>

                                            </div>

                                        </td>

                                        <!-- RIGHT -->

                                        <td>

                                            <form
                                                method="POST"
                                                action="kpi.php?tab=performance&amp;<?= htmlspecialchars($monthQuery, ENT_QUOTES, "UTF-8") ?>">

                                                <?= csrfField() ?>

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

                                                        <div class="target-readonly">

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
                                                                                                                        value="<?= $kpi["actual"] !== null
                                                                        ? (int) $kpi["actual"]
                                                                        : "" ?>"
                                                            placeholder="กรอกผลที่ทำได้"
                                                            required>

                                                        <div class="input-hint">

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

                        <div class="empty-kpi-icon">
                            ⭐
                        </div>

                        <h3>
                            ยังไม่มี KPI ที่ได้รับมอบหมายในเดือนนี้
                        </h3>

                        <p>
                            Competency KPI · <?= $thaiMonths[$selectedMonth] ?> <?= $selectedYear ?> (<?= $selectedQuarter ?>)
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="kpi-input-table">

                            <thead>

                                <tr>

                                    <th class="col-kpi-topic">
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

                                    $levels = $kpiCriteria[$kpiId] ?? [];

                                    $currentScore = ($kpi["score"] !== null && $kpi["score"] !== "")
                                        ? (int) round((float) $kpi["score"])
                                        : 0;

                                    $realScore = (float) ($kpi["weight"] ?? 0) * $currentScore;

                                    ?>

                                    <tr>

                                        <!-- LEFT -->

                                        <td>

                                            <div class="kpi-title">
                                                <?= htmlspecialchars($kpi["kpi_name"], ENT_QUOTES, "UTF-8") ?>
                                            </div>

                                            <div class="kpi-meta">

                                                <span class="kpi-badge">
                                                    Weight: <?= (int) ($kpi["weight"] ?? 0) ?>%
                                                </span>

                                                <span class="kpi-badge">
                                                    คะแนน 1 - 5
                                                </span>

                                            </div>

                                            <?php if ($currentScore > 0): ?>

                                                <div class="score-display">
                                                    คะแนนที่บันทึก: <?= $currentScore ?> / 5
                                                    · คะแนนจริง <?= number_format($realScore, floor($realScore) == $realScore ? 0 : 2) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <!-- RIGHT -->

                                        <td>

                                            <form
                                                method="POST"
                                                class="competency-form"
                                                action="kpi.php?tab=competency&amp;<?= htmlspecialchars($monthQuery, ENT_QUOTES, "UTF-8") ?>">

                                                <?= csrfField() ?>

                                                <input
                                                    type="hidden"
                                                    name="assignment_id"
                                                    value="<?= (int) $kpi["assignment_id"] ?>">

                                                <div class="input-group">

                                                    <label>ตัวชี้วัดผลงาน</label>

                                                    <div class="target-display">
                                                        <?= htmlspecialchars($kpi["description"] ?? "", ENT_QUOTES, "UTF-8") ?>
                                                    </div>

                                                </div>

                                                <div class="input-group">

                                                    <label>ระดับผลงาน (เลือกคะแนน 1 - 5)</label>

                                                    <div class="level-options">

                                                        <?php for ($level = 5; $level >= 1; $level--): ?>

                                                            <?php $levelText = $levels[$level] ?? ""; ?>

                                                            <label class="level-option<?= $levelText === "" ? " is-empty" : "" ?>">

                                                                <input
                                                                    type="radio"
                                                                    name="score"
                                                                    value="<?= $level ?>"
                                                                    <?= $currentScore === $level ? "checked" : "" ?>
                                                                    required>

                                                                <span class="level-number"><?= $level ?></span>

                                                                <span class="level-text">
                                                                    <?= $levelText !== ""
                                                                        ? htmlspecialchars($levelText, ENT_QUOTES, "UTF-8")
                                                                        : "ไม่มีเกณฑ์กำหนดในระดับนี้" ?>
                                                                </span>

                                                            </label>

                                                        <?php endfor; ?>

                                                    </div>

                                                    <div class="input-hint">

                                                        คะแนนจริง = Weight × คะแนนที่เลือก

                                                    </div>

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

        <?php endif; ?>

    <?php employeeLayoutEnd("../../"); ?>


</body>

</html>
