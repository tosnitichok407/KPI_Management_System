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
| ปิดการกรอกผล KPI ด้วยตนเอง
|--------------------------------------------------------------------------
|
| หน้านี้แสดงรายละเอียดและประวัติผลงานเท่านั้น · บันทึกผลผ่าน employee/kpi-import.php
| POST ทุกคำขอถูกส่งต่อก่อนอ่านฐานข้อมูล จึงไม่มีการบันทึกจากหน้านี้
|
*/

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    header("Location: kpi-import.php", true, 303);
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
        k.kpi_type,
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


/* KPI 9 มูลค่าไม่ต่อสัญญา: Target = Budget (จำนวนเงิน) ตามที่ Admin กำหนด ไม่บังคับเป็น 100 อีกต่อไป */
$isBudgetKpi = isNonRenewalKpi($kpi);

$targetValue = (float) $kpi["target_value"];

/* เกณฑ์ระดับผลงาน 5..1 ของ KPI นี้ (ใช้ตัดเกรดแทนสูตรสัดส่วนเดิม) */
$kpiLevels = loadKpiScoreCriteria($pdo, [$kpi])[(int) $kpi["kpi_id"]] ?? [];

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
// รอบประเมินหาจากปี + เดือนที่เลือก (Assignment ไม่ผูกกับรอบประเมิน)
$selectedPeriod = findEvaluationPeriodByMonth($pdo, $selectedYear, $selectedMonth);
$kpi["period_name"] = $selectedPeriod
    ? $selectedPeriod["period_name"] . " " . $selectedYear . " (" . $selectedPeriod["quarter"] . ")"
    : "ยังไม่มีรอบประเมินของเดือน" . monthlyPeriodMonths()[$selectedMonth] . " " . $selectedYear;




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

    <link rel="stylesheet" href="../assets/css/icons.css?v=1">

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
         บันทึกผลงาน → นำเข้า Excel
    ====================================================== -->

        <section class="card">


            <h2>
                บันทึกผลงาน
            </h2>


            <p>
                การบันทึกผล KPI ทำผ่านหน้า "บันทึกผล KPI (Excel)" เท่านั้น
                ดาวน์โหลดแบบฟอร์ม กรอกผลงาน แล้วนำเข้าเพื่อตรวจสอบก่อนยืนยันบันทึก
            </p>


            <a
                href="kpi-import.php"
                class="submit-button">

                <?= icon("file-spreadsheet") ?>ไปหน้าบันทึกผล KPI (Excel)

            </a>

            <a
                href="../employee/performance.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                class="btn btn-secondary back-button">

                <?= icon("arrow-left") ?>ย้อนกลับ

            </a>

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

                                        <?= $isBudgetKpi
                                            ? kpiFormatMoney($performance["target"] ?? $kpi["target_value"])
                                            : number_format(
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

                                        <?= kpiScoreLevel(
                                            (float)
                                            $performance["score"]
                                        ) ?>

                                        / 5

                                        <?php if ($isBudgetKpi): ?>
                                            <?php /* KPI 9: เทียบกับ Budget ของเดือนที่บันทึกกับผลงาน (ถ้ามี) */ $historyResult = kpiCalculateScore($kpi, $kpiLevels, $performance["actual"], $performance["target"] ?? $kpi["target_value"]); ?>
                                            <br>
                                            <small>
                                                คะแนนที่บันทึกไว้
                                                <?php if ($historyResult["grade"] !== null): ?>
                                                    · ตามเกณฑ์ปัจจุบัน <?= $historyResult["grade"] ?> / 5
                                                    (<?= kpiFormatAchievement($historyResult["achievement"]) ?>)
                                                <?php elseif ($historyResult["error"] !== null): ?>
                                                    · คำนวณตามเกณฑ์ปัจจุบันไม่ได้ (ยังไม่ได้กำหนด Budget)
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>

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
