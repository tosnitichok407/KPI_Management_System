<?php

session_start();

require_once "../../config/database.php";
require_once "../../includes/monthly-period-helper.php";


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


/*
|--------------------------------------------------------------------------
| Add Evaluation Period
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $year = (int) ($_POST["period_year"] ?? 0);
    $month = (int) ($_POST["period_month"] ?? 0);
    $status = $_POST["status"] ?? "Open";


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if (
        $year < 2000 || $year > 2100 || $month < 1 || $month > 12
    ) {

        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } elseif (!in_array($status, ["Open", "Closed"])) {

        $error = "สถานะไม่ถูกต้อง";
    } else {


        /*
        |--------------------------------------------------------------------------
        | Insert
        |--------------------------------------------------------------------------
        */

        try {
            $period = monthlyPeriodDetails($year, $month);

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

                ":status" => $status

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
        href="../../assets/css/evaluation.css">

    <title>Add Evaluation Period</title>

</head>

<body>

    <div class="page-container">


        <!-- =========================================================
         HEADER
    ========================================================== -->

        <header class="page-header">

            <div>

                <h1>
                    Add Evaluation Period
                </h1>

                <p>
                    เพิ่มรอบการประเมินผลการปฏิบัติงาน
                </p>

            </div>


            <div class="header-actions">

                <a
                    href="../index.php?page=evaluation"
                    class="btn btn-secondary">
                    ← กลับ
                </a>

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


            <form
                method="POST"
                action="">


                <div class="form-group">
                    <label for="period_year">ปี</label>
                    <input
                        type="number" id="period_year" name="period_year"
                        value="<?= (int) ($_POST["period_year"] ?? date("Y")) ?>" min="2000" max="2100" required>

                </div>
                <div class="form-group">
                    <label for="period_month">เดือน</label>
                    <select id="period_month" name="period_month" required>
                        <?php foreach (monthlyPeriodMonths() as $number => $name): ?>
                            <option value="<?= $number ?>" <?= $number === (int) ($_POST["period_month"] ?? date("n")) ? "selected" : "" ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>ระบบจะกำหนดชื่อรอบ, Quarter และช่วงวันของเดือนให้อัตโนมัติ</small>

                </div>


                <!-- Status -->

                <div class="form-group">

                    <label for="status">
                        สถานะ
                    </label>

                    <select
                        id="status"
                        name="status">

                        <option value="Open">
                            Open
                        </option>

                        <option value="Closed">
                            Closed
                        </option>

                    </select>

                </div>


                <!-- Buttons -->

                <div class="form-actions">

                    <a
                        href="../index.php?page=evaluation"
                        class="btn btn-secondary">
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primary">
                        Save Evaluation Period
                    </button>

                </div>


            </form>


        </section>


    </div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
