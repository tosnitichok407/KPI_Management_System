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


$error = "";

$success = "";

/* KPI 9 มูลค่าไม่ต่อสัญญา: Target = Budget (จำนวนเงิน) ตามที่ Admin กำหนด ไม่บังคับเป็น 100 อีกต่อไป */
$isBudgetKpi = isNonRenewalKpi($kpi);

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
    Validation (ข้อมูลจากฟอร์ม)
    --------------------------------------------------------------
    */

        if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif (mb_strlen($comment) > 5000) {

        $error = "รายละเอียดผลงานยาวเกินกำหนด";
    } elseif (!$selectedPeriod) {

        $error = "ไม่พบรอบประเมินของเดือนที่เลือก";
    } elseif (($selectedPeriod["status"] ?? "") !== "Open") {

        $error = "รอบประเมินของเดือนที่เลือกปิดแล้ว ไม่สามารถบันทึกหรือแก้ไขผลงานได้";
    } elseif ($performanceDate === "") {

        $error =
            "กรุณาเลือกวันที่";
    } elseif ($actual === "") {

        $error =
            "กรุณากรอก Actual";
    } elseif (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', (string) $actual)) {

        $error =
            "Actual ต้องเป็นตัวเลขไม่ติดลบ (ทศนิยมไม่เกิน 2 ตำแหน่ง)";
    } else {

        /*
        ----------------------------------------------------------
        Transaction + ล็อกแถว Assignment จนบันทึกผลงานเสร็จ
        ----------------------------------------------------------
        กัน Race Condition: ข้อมูล Assignment ที่โหลดตอนเปิดหน้า ($kpi) อาจเก่าแล้ว
        (Admin เปลี่ยน KPI / Target / ช่วงวันที่ / สถานะ ระหว่างนั้น)
        จึงอ่านใหม่หลังล็อก และใช้เฉพาะค่าที่อ่านใหม่ในการตรวจและตัดเกรด
        ----------------------------------------------------------
        */

        $isUpdate = false;

        try {

            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("
                SELECT assignment_id
                FROM kpi_assignments
                WHERE assignment_id = :assignment_id
                  AND employee_id = :employee_id
                FOR UPDATE
            ");
            $lockStmt->execute([
                ":assignment_id" => $assignmentId,
                ":employee_id" => $employeeId
            ]);

            /* สถานะรอบประเมิน ณ ตอนบันทึก (กันรอบถูกปิดระหว่างนั้น) */
            $periodLockStmt = $pdo->prepare("
                SELECT status
                FROM evaluation_periods
                WHERE period_id = :period_id
                LOCK IN SHARE MODE
            ");
            $periodLockStmt->execute([":period_id" => $selectedPeriodId]);
            $lockedPeriodStatus = (string) $periodLockStmt->fetchColumn();

            /* query เดียวกับตอนโหลดหน้า แต่เป็น locking read (อ่านค่าล่าสุดที่ commit แล้ว) */
            $lockedStmt = $pdo->prepare($sql . " LOCK IN SHARE MODE");
            $lockedStmt->execute([
                ":assignment_id" => $assignmentId,
                ":employee_id" => $employeeId
            ]);
            $lockedKpi = $lockedStmt->fetch(PDO::FETCH_ASSOC);

            if ($lockedPeriodStatus !== "Open") {

                $error = "รอบประเมินของเดือนที่เลือกปิดแล้ว ไม่สามารถบันทึกหรือแก้ไขผลงานได้";
            } elseif (!$lockedKpi) {

                $error = "ไม่พบ KPI ที่ต้องการบันทึก";
            } elseif (($lockedKpi["assignment_status"] ?? "") !== "Active") {

                $error = "KPI นี้ถูกปิดการใช้งาน ไม่สามารถบันทึกผลงานได้";
            } elseif (
                $selectedPeriod["end_date"] < $lockedKpi["start_date"] ||
                $selectedPeriod["start_date"] > $lockedKpi["end_date"]
            ) {

                $error =
                    "KPI นี้ไม่ได้มีผลในเดือนที่เลือก";
            } else {

                /*
                ------------------------------------------------------
                Calculate Score — ฟังก์ชันกลางเดิม (includes/kpi-score-helper.php)
                KPI 9 ใช้ Total R / Budget · Budget 0 / NULL หรือประเภทผิด → error ไม่บันทึก
                ------------------------------------------------------
                */

                $actualValue = (float) $actual;
                $lockedTarget = (float) $lockedKpi["target_value"];
                $lockedLevels =
                    loadKpiScoreCriteria($pdo, [$lockedKpi])[(int) $lockedKpi["kpi_id"]] ?? [];

                $scoreResult = kpiCalculateScore(
                    $lockedKpi,
                    $lockedLevels,
                    $actual,
                    $lockedKpi["target_value"]
                );
                $score = $scoreResult["grade"];

                if ($scoreResult["error"] !== null || $score === null) {

                    $error = $scoreResult["error"]
                        ?? "KPI นี้ยังไม่ได้กำหนดเกณฑ์ระดับผลงานและเป้าหมาย กรุณาติดต่อผู้ดูแลระบบ";
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
                        ":assignment_id" => $assignmentId,
                        ":employee_id" => $employeeId,
                        ":period_id" => $selectedPeriodId
                    ]);
                    $existingPerformance = $check->fetch(PDO::FETCH_ASSOC);

                    if ($existingPerformance) {

                        $isUpdate = true;

                        $update = $pdo->prepare("
                            UPDATE kpi_performances
                            SET target = :target, actual = :actual, score = :score,
                                comment = :comment, status = 'Submitted'
                            WHERE performance_id = :performance_id
                              AND employee_id = :employee_id
                        ");
                        $update->execute([
                            ":target" => $lockedTarget,
                            ":actual" => $actualValue,
                            ":score" => $score,
                            ":comment" => $comment !== "" ? $comment : null,
                            ":performance_id" => $existingPerformance["performance_id"],
                            ":employee_id" => $employeeId
                        ]);

                        $pdo->commit();
                        $success = "อัปเดตผลงานของเดือนที่เลือกเรียบร้อยแล้ว";
                    } else {

                        /*
                        --------------------------------------------------
                        Insert
                        --------------------------------------------------
                        */

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
                            ":assignment_id" => $assignmentId,
                            ":employee_id" => $employeeId,
                            ":period_id" => $selectedPeriodId,
                            ":performance_date" => $performanceDate,
                            ":target" => $lockedTarget,
                            ":actual" => $actualValue,
                            ":score" => $score,
                            ":comment" => $comment !== "" ? $comment : null
                        ]);

                        $pdo->commit();
                        $success = "บันทึกผลงานเรียบร้อยแล้ว";
                    }
                }
            }

            /* ไม่ผ่านเงื่อนไข → ไม่บันทึกอะไรเลย และปล่อยล็อก */
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(($isUpdate ? "Update" : "Insert") . " KPI performance failed: " . $e->getMessage());

            $success = "";

            /* 1205 = lock wait timeout · 1213 = deadlock (มีการแก้ไขข้อมูลนี้พร้อมกัน) */
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                $error = "มีการแก้ไขข้อมูล KPI นี้พร้อมกัน ระบบยังไม่ได้บันทึก กรุณาลองใหม่อีกครั้ง";
            } else {
                $error = $isUpdate ? "ไม่สามารถอัปเดตผลงานได้" : "ไม่สามารถบันทึกผลงานได้";
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
                            <?= $isBudgetKpi ? "Budget (เป้าหมายจำนวนเงิน)" : "Target" ?>
                        </label>

                        <div class="target-value">
                            <?php if ($isBudgetKpi): ?>
                                <?= kpiBudgetIsValid($kpi["target_value"]) ? kpiFormatMoney($kpi["target_value"]) : "ยังไม่ได้กำหนด Budget" ?>
                            <?php else: ?>
                                <?= number_format($targetValue, 0) ?>
                            <?php endif; ?>
                        </div>

                    </div>


                    <div class="form-group">

                        <label>
                            <?= $isBudgetKpi ? "Total R (ไม่รวม Total N)" : "Actual" ?>
                        </label>

                        <input
                            type="number"
                            name="actual"
                            step="0.01"
                            min="0"
                            placeholder="<?= $isBudgetKpi ? "ยอด Total R (จำนวนเงิน)" : "เช่น 100" ?>"
                            required>

                        <?php if ($isBudgetKpi): ?>
                            <small>Achievement = Total R / Budget × 100 · ห้ามรวม Total N และห้ามใช้ Total R+N</small>
                        <?php endif; ?>

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

                    <?= icon("save") ?>บันทึกผลงาน

                </button>

                <a
                    href="../employee/performance.php?year=<?= $selectedYear ?>&amp;month=<?= $selectedMonth ?>"
                    class="btn btn-secondary back-button">

                    <?= icon("arrow-left") ?>ย้อนกลับ

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

                                        <?= $isBudgetKpi
                                            ? kpiFormatMoney($kpi["target_value"])
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
                                            <?php $historyResult = kpiCalculateScore($kpi, $kpiLevels, $performance["actual"], $kpi["target_value"]); ?>
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
