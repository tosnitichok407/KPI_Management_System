<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/kpi-score-helper.php";
require_once __DIR__ . "/includes/layout.php";


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
| Assignment ID
|--------------------------------------------------------------------------
*/

$assignmentId = (int) ($_GET["assignment_id"] ?? 0);

if ($assignmentId <= 0) {
    header("Location: performance.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Assigned KPI
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        a.assignment_id,
        a.employee_id,
        a.period_id,
        a.kpi_id,
        a.target_value,
        a.weight,
        a.status AS assignment_status,

        k.kpi_name,
        k.description,
        k.unit,
        k.max_score,
        k.score_5,
        k.score_4,
        k.score_3,
        k.score_2,
        k.score_1,

        a.assignment_year,
        COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) AS start_date,
        COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) AS end_date

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    WHERE a.assignment_id = :assignment_id

    AND a.employee_id = :employee_id

    LIMIT 1
";


$stmt = $pdo->prepare($sql);

$stmt->execute([
    ":assignment_id" => $assignmentId,
    ":employee_id" => $employeeId
]);

$kpi = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
*/

if (!$kpi) {

    header("Location: performance.php");

    exit;
}


$error = "";

$success = "";

$targetValue = (float) $kpi["target_value"];

/* เกณฑ์ระดับผลงาน 5..1 ของ KPI นี้ (ใช้ตัดเกรดแทนสูตรสัดส่วนเดิม) */
$kpiLevels = loadKpiScoreCriteria($pdo, [$kpi])[(int) $kpi["kpi_id"]] ?? [];

$currentYear = (int) date("Y");
$currentMonth = (int) date("n");
$selectedYear = (int) ($_GET["year"] ?? $_POST["year"] ?? $currentYear);
$selectedMonth = (int) ($_GET["month"] ?? $_POST["month"] ?? $currentMonth);
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$performanceDateForMonth = sprintf("%04d-%02d-01", $selectedYear, $selectedMonth);
$monthEnd = date("Y-m-t", strtotime($performanceDateForMonth));
// รอบประเมินหาจากปี + เดือนที่เลือก (Assignment ไม่ผูกกับรอบประเมิน)
$selectedPeriod = findEvaluationPeriodByMonth($pdo, $selectedYear, $selectedMonth);
$selectedPeriodId = $selectedPeriod ? (int) $selectedPeriod["period_id"] : 0;
if ($selectedPeriod) {
    $performanceDateForMonth = $selectedPeriod["start_date"];
}
$kpi["period_name"] = $selectedPeriod
    ? $selectedPeriod["period_name"] . " " . $selectedYear . " (" . $selectedPeriod["quarter"] . ")"
    : "ยังไม่มีรอบประเมินของเดือน" . monthlyPeriodMonths()[$selectedMonth] . " " . $selectedYear;


/*
|--------------------------------------------------------------------------
| Add Performance
|--------------------------------------------------------------------------
*/

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {

    $performanceDate = $performanceDateForMonth;

    $actual =
        $_POST["actual"] ?? "";

    $comment =
        trim($_POST["comment"] ?? "");


    /*
    --------------------------------------------------------------
    Validation
    --------------------------------------------------------------
    */

        if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif (mb_strlen($comment) > 5000) {

        $error = "รายละเอียดผลงานยาวเกินกำหนด";
    } elseif (!$selectedPeriod) {

        $error = "ไม่พบรอบประเมินของเดือนที่เลือก";
    } elseif ($performanceDate === "") {

        $error =
            "กรุณาเลือกวันที่";
    } elseif (
        $selectedPeriod["end_date"] < $kpi["start_date"] ||
        $selectedPeriod["start_date"] > $kpi["end_date"]
    ) {

        $error =
            "KPI นี้ไม่ได้มีผลในเดือนที่เลือก";
    } elseif ($actual === "") {

        $error =
            "กรุณากรอก Actual";
    } elseif (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', (string) $actual)) {

        $error =
            "Actual ต้องเป็นตัวเลขไม่ติดลบ (ทศนิยมไม่เกิน 2 ตำแหน่ง)";
    } else {


        /*
        ----------------------------------------------------------
        Calculate Score
        ----------------------------------------------------------
        */

        $actualValue =
            (float) $actual;


        /*
        ------------------------------------------------------
        เกรด = ระดับผลงานตามเกณฑ์ (Criteria) ที่ Admin กำหนด

        เดิมใช้สูตรสัดส่วน Actual / Target × 5 ซึ่งไม่ตรงกับเกณฑ์
        ที่แสดงไว้ เช่น "5 : >100%" กับ "4 : 100%" แต่สูตรเดิม
        ให้ Actual เท่ากับ Target (100%) ได้ 5 เต็ม

        รายละเอียดอยู่ใน includes/kpi-score-helper.php
        ------------------------------------------------------
        */

        $score = kpiPerformanceGrade(
            $kpiLevels,
            $actualValue,
            $targetValue > 0 ? $targetValue : null
        );


        if ($score === null) {

            $error =
                "KPI นี้ยังไม่ได้กำหนดเกณฑ์ระดับผลงานและเป้าหมาย กรุณาติดต่อผู้ดูแลระบบ";
        } else {


            /*
            ------------------------------------------------------
            Check Duplicate
            ------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT performance_id

                FROM kpi_performances

                WHERE assignment_id = :assignment_id

                AND employee_id = :employee_id

                AND period_id = :period_id

                LIMIT 1
            ");


            $check->execute([

                ":assignment_id" =>
                $assignmentId,

                ":employee_id" => $employeeId,

                ":period_id" => $selectedPeriodId

            ]);


            $existingPerformance = $check->fetch(PDO::FETCH_ASSOC);

            if ($existingPerformance) {
                try {
                    $update = $pdo->prepare("
                        UPDATE kpi_performances
                        SET target = :target, actual = :actual, score = :score,
                            comment = :comment, status = 'Submitted'
                        WHERE performance_id = :performance_id
                          AND employee_id = :employee_id
                    ");
                    $update->execute([
                        ":target" => $targetValue,
                        ":actual" => $actualValue,
                        ":score" => $score,
                        ":comment" => $comment !== "" ? $comment : null,
                        ":performance_id" => $existingPerformance["performance_id"],
                        ":employee_id" => $employeeId
                    ]);
                    $success = "อัปเดตผลงานของเดือนที่เลือกเรียบร้อยแล้ว";
                                } catch (PDOException $e) {
                    error_log("Update KPI performance failed: " . $e->getMessage());
                    $error = "ไม่สามารถอัปเดตผลงานได้";
                }
            } else {


                /*
                --------------------------------------------------
                Insert
                --------------------------------------------------
                */

                try {

                    $insert = $pdo->prepare("

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
                            'Submitted'
                        )

                    ");


                    $insert->execute([

                        ":assignment_id" =>
                        $assignmentId,

                        ":employee_id" =>
                        $employeeId,

                        ":period_id" => $selectedPeriodId,

                        ":performance_date" =>
                        $performanceDate,

                        ":target" =>
                        $targetValue,

                        ":actual" =>
                        $actualValue,

                        ":score" =>
                        $score,

                        ":comment" =>
                        $comment !== ""
                            ? $comment
                            : null

                    ]);


                    $success =
                        "บันทึกผลงานเรียบร้อยแล้ว";
                                } catch (PDOException $e) {

                    error_log("Insert KPI performance failed: " . $e->getMessage());

                    $error =
                        "ไม่สามารถบันทึกผลงานได้";
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Performance History
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare("

    SELECT

        performance_id,
        performance_date,
        target,
        actual,
        score,
        comment,
        status

    FROM kpi_performances

    WHERE assignment_id = :assignment_id

    AND employee_id = :employee_id

    ORDER BY performance_date DESC

");


$historyStmt->execute([

    ":assignment_id" =>
    $assignmentId,

    ":employee_id" =>
    $employeeId

]);


$performances =
    $historyStmt->fetchAll(
        PDO::FETCH_ASSOC
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
        KPI Detail
    </title>


    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../assets/css/employee-kpi.css?v=layout-employee-2">

    <link
        rel="stylesheet"
        href="../assets/css/employee-kpi-detail.css">

</head>


<body>

    <?php employeeLayoutStart("performance", "../"); ?>

    <div class="container">
        <!-- =====================================================
         KPI INFORMATION
    ====================================================== -->

        <section class="header-card">


            <div class="header-top">


                <div>

                    <h1>

                        <?= htmlspecialchars(
                            $kpi["kpi_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </h1>


                    <div class="description">

                        <?= htmlspecialchars(
                            $kpi["description"] ?? "-",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>

                </div>


                <span class="weight">

                    Weight
                    <?= htmlspecialchars(
                        $kpi["weight"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>%

                </span>


            </div>


            <div class="info-grid">


                <div class="info-box">

                    <span>
                        รอบประเมิน
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $kpi["period_name"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>
                        หน่วย
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $kpi["unit"] ?? "-",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>
                        คะแนนสูงสุด
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $kpi["max_score"] ?? 5,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </strong>

                </div>


                <div class="info-box">

                    <span>
                        ระยะเวลา
                    </span>

                    <strong>

                        <?= htmlspecialchars(
                            $kpi["start_date"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                        -

                        <?= htmlspecialchars(
                            $kpi["end_date"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </strong>

                </div>


            </div>

        </section>


        <!-- =====================================================
         ALERT
    ====================================================== -->

        <?php if ($error !== ""): ?>

            <div class="alert error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ""): ?>

            <div class="alert success">

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
         ADD PERFORMANCE
    ====================================================== -->

        <section class="card">


            <h2>
                บันทึกผลงาน
            </h2>


                        <form
                method="POST">

                <?= csrfField() ?>

                <div class="form-grid">


                    <div class="form-group">

                        <label>
                            เดือนที่บันทึก
                        </label>
                        <div class="target-value">
                            <?= htmlspecialchars(date("F Y", strtotime($performanceDateForMonth)), ENT_QUOTES, "UTF-8") ?>
                        </div>
                        <input type="hidden" name="year" value="<?= $selectedYear ?>">
                        <input type="hidden" name="month" value="<?= $selectedMonth ?>">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriodId ?>">

                    </div>


                    <div class="form-group">

                        <label>
                            Target
                        </label>

                        <div class="target-value">
                            <?= number_format($targetValue, 0) ?>
                        </div>

                    </div>


                    <div class="form-group">

                        <label>
                            Actual
                        </label>

                        <input
                            type="number"
                            name="actual"
                            step="0.01"
                            min="0"
                            placeholder="เช่น 100"
                            required>

                    </div>


                    <div class="form-group full">

                        <label>
                            รายละเอียดผลงาน
                        </label>

                        <textarea
                            name="comment"
                            placeholder="อธิบายรายละเอียดผลงานของคุณ"></textarea>

                    </div>


                </div>


                <button
                    type="submit"
                    class="submit-button">

                    บันทึกผลงาน

                </button>

                <a
                    href="../employee/performance.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                    class="btn btn-secondary back-button">

                    ย้อนกลับ

                </a>

            </form>

        </section>


        <!-- =====================================================
         HISTORY
    ====================================================== -->

        <section class="card">


            <h2>
                ประวัติผลงาน
            </h2>


            <?php if (empty($performances)): ?>


                <p>
                    ยังไม่มีการบันทึกผลงาน
                </p>


            <?php else: ?>


                <div class="table-wrapper">


                    <table>


                        <thead>

                            <tr>

                                <th>
                                    วันที่
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
                                    รายละเอียด
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach (
                                $performances
                                as $performance
                            ): ?>


                                <tr>

                                    <td>

                                        <?= htmlspecialchars(
                                            $performance["performance_date"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= number_format(
                                            $targetValue,
                                            0
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= number_format(
                                            (float)
                                            $performance["actual"],
                                            2
                                        ) ?>

                                    </td>


                                    <td class="score">

                                        <?= number_format(
                                            (float)
                                            $performance["score"],
                                            2
                                        ) ?>

                                        / 5

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $performance["comment"] ?? "-",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </td>


                                    <td>

                                        <span
                                            class="status">

                                            <?= htmlspecialchars(
                                                $performance["status"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </span>

                                    </td>

                                </tr>


                            <?php endforeach; ?>


                        </tbody>


                    </table>


                </div>


            <?php endif; ?>


        </section>


    </div>


    <?php employeeLayoutEnd("../"); ?>

</body>

</html>
