<?php

session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../../dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Period ID
|--------------------------------------------------------------------------
*/

$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: ../index.php?page=evaluation");
    exit;
}


$error = "";


/*
|--------------------------------------------------------------------------
| Get Evaluation Period
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        SELECT
            period_id,
            period_name,
            period_year,
            period_month,
            quarter,
            start_date,
            end_date,
            status

        FROM evaluation_periods

        WHERE period_id = :period_id

        LIMIT 1
    ");

    $stmt->execute([
        ":period_id" => $id
    ]);

    $period = $stmt->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Period Not Found
    |--------------------------------------------------------------------------
    */

    if (!$period) {

        header("Location: ../index.php?page=evaluation");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Performances In This Period
    |--------------------------------------------------------------------------
    |
    | ถ้ามีผลงานบันทึกในเดือนนี้แล้ว ห้ามย้ายปี/เดือน
    | เพื่อไม่ให้ผลงานเดิมถูกย้ายไปอยู่เดือนอื่น
    |
    */

    $performanceCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM kpi_performances
        WHERE period_id = :period_id
    ");

    $performanceCountStmt->execute([
        ":period_id" => $id
    ]);

    $hasPerformances =
        (int) $performanceCountStmt->fetchColumn() > 0;


} catch (PDOException $e) {

    die("Database error.");
}


/*
|--------------------------------------------------------------------------
| Update Evaluation Period
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $periodYear = (int) ($_POST["period_year"] ?? 0);
    $periodMonth = (int) ($_POST["period_month"] ?? 0);

    $status =
        $_POST["status"] ?? "Open";


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if (
        $periodYear < 2000 || $periodYear > 2100 || $periodMonth < 1 || $periodMonth > 12
    ) {

        $error =
            "กรุณาเลือกปีและเดือนให้ครบถ้วน";

    } elseif (
        !in_array(
            $status,
            ["Open", "Closed"],
            true
        )
    ) {

        $error =
            "สถานะไม่ถูกต้อง";

    } elseif (
        $hasPerformances &&
        (
            $periodYear !== (int) $period["period_year"] ||
            $periodMonth !== (int) $period["period_month"]
        )
    ) {

        $error =
            "ไม่สามารถเปลี่ยนปี/เดือนได้ เนื่องจากมีผลงาน KPI บันทึกในรอบนี้แล้ว (แก้ไขได้เฉพาะสถานะ)";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        try {
            $monthlyPeriod = monthlyPeriodDetails($periodYear, $periodMonth);

            $stmt = $pdo->prepare("
                UPDATE evaluation_periods

                SET
                    period_name = :period_name,
                    period_year = :period_year,
                    period_month = :period_month,
                    quarter = :quarter,
                    start_date = :start_date,
                    end_date = :end_date,
                    status = :status

                WHERE period_id = :period_id
            ");

            $stmt->execute([

                ":period_name" =>
                    $monthlyPeriod["period_name"],

                ":period_year" => $monthlyPeriod["year"],
                ":period_month" => $monthlyPeriod["month"],
                ":quarter" => $monthlyPeriod["quarter"],

                ":start_date" =>
                    $monthlyPeriod["start_date"],

                ":end_date" =>
                    $monthlyPeriod["end_date"],

                ":status" =>
                    $status,

                ":period_id" =>
                    $id

            ]);


            /*
            |--------------------------------------------------------------------------
            | Redirect After Success
            |--------------------------------------------------------------------------
            */

            header(
                "Location: ../index.php?page=evaluation"
            );

            exit;


        } catch (PDOException $e) {

            $error =
                $e->getCode() === "23000" ? "มีรอบประเมินสำหรับปีและเดือนนี้แล้ว" : "ไม่สามารถแก้ไขรอบการประเมินได้";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Keep Form Data After Error
    |--------------------------------------------------------------------------
    */

    $period["period_year"] = $periodYear;
    $period["period_month"] = $periodMonth;

    $period["status"] =
        $status;
}


/*
|--------------------------------------------------------------------------
| Form
|--------------------------------------------------------------------------
*/

$formAction = "evaluation-periods-edit.php?id=" . $id;
$formYear = (int) $period["period_year"];
$formMonth = (int) $period["period_month"];
$formStatus = $period["status"];
$existingMonths = evaluationPeriodMonthsByYear($pdo, $id);
$lockPeriod = $hasPerformances;
$submitLabel = "Save Changes";

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/evaluation.css?v=period-form-2"
    >

    <title>Edit Evaluation Period</title>

</head>

<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                Edit Evaluation Period
            </h1>

            <p>
                แก้ไขรอบการประเมิน
                <?= htmlspecialchars($period["period_name"] ?? "", ENT_QUOTES, "UTF-8") ?>
                · ID <?= (int) $id ?>
            </p>

        </div>


    </header>


    <!-- =========================================================
         FORM
    ========================================================== -->

    <section class="form-card">


        <?php if ($error !== ""): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <?php include __DIR__ . "/evaluation-period-form.php"; ?>


    </section>


</div>

<script src="../../assets/js/admin.js?v=scroll-3"></script>

</body>

</html>
