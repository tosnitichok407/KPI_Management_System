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


$error = "";

$formYear = (int) ($_POST["period_year"] ?? date("Y"));
$formMonth = (int) ($_POST["period_month"] ?? date("n"));
$formStatus = $_POST["status"] ?? "Open";


/*
|--------------------------------------------------------------------------
| Add Evaluation Period
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if (
        $formYear < 2000 || $formYear > 2100 || $formMonth < 1 || $formMonth > 12
    ) {

        $error = "กรุณาเลือกปีและเดือนให้ครบถ้วน";
    } elseif (!in_array($formStatus, ["Open", "Closed"], true)) {

        $error = "สถานะไม่ถูกต้อง";
    } else {


        /*
        |--------------------------------------------------------------------------
        | Insert
        |--------------------------------------------------------------------------
        */

        try {
            $period = monthlyPeriodDetails($formYear, $formMonth);

            $stmt = $pdo->prepare("
                INSERT INTO evaluation_periods
                (
                    period_name,
                    period_year,
                    period_month,
                    quarter,
                    start_date,
                    end_date,
                    status
                )
                VALUES
                (
                    :period_name,
                    :period_year,
                    :period_month,
                    :quarter,
                    :start_date,
                    :end_date,
                    :status
                )
            ");

            $stmt->execute([

                ":period_name" => $period["period_name"],
                ":period_year" => $period["year"],
                ":period_month" => $period["month"],
                ":quarter" => $period["quarter"],
                ":start_date" => $period["start_date"],
                ":end_date" => $period["end_date"],

                ":status" => $formStatus

            ]);


            /*
            |--------------------------------------------------------------------------
            | Redirect
            |--------------------------------------------------------------------------
            */

            header(
                "Location: ../index.php?page=evaluation"
            );

            exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === "23000"
                ? "มีรอบประเมินสำหรับปีและเดือนนี้แล้ว"
                : "ไม่สามารถเพิ่มรอบการประเมินได้";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Form
|--------------------------------------------------------------------------
*/

$formAction = "evaluation-periods-add.php";
$existingMonths = evaluationPeriodMonthsByYear($pdo);
$lockPeriod = false;
$submitLabel = "Save Evaluation Period";

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../../assets/css/evaluation.css?v=period-form-2">

    <title>Add Evaluation Period</title>

</head>

<body>

    <div class="page-container">


        <!-- =========================================================
         HEADER
    ========================================================== -->

        <header class="page-header">

            <div class="page-title-block">

                <h1>
                    Add Evaluation Period
                </h1>

                <p>
                    เพิ่มรอบการประเมินรายเดือน
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
