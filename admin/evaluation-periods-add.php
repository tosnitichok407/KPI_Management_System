<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
    exit;
}


$error = "";


/*
|--------------------------------------------------------------------------
| Add Evaluation Period
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $period_name = trim($_POST["period_name"] ?? "");
    $start_date = $_POST["start_date"] ?? "";
    $end_date = $_POST["end_date"] ?? "";
    $status = $_POST["status"] ?? "Open";


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if (
        $period_name === ""
        || $start_date === ""
        || $end_date === ""
    ) {

        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } elseif ($end_date < $start_date) {

        $error = "วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น";
    } elseif (!in_array($status, ["Open", "Closed"])) {

        $error = "สถานะไม่ถูกต้อง";
    } else {


        /*
        |--------------------------------------------------------------------------
        | Insert
        |--------------------------------------------------------------------------
        */

        try {

            $stmt = $pdo->prepare("
                INSERT INTO evaluation_periods
                (
                    period_name,
                    start_date,
                    end_date,
                    status
                )
                VALUES
                (
                    :period_name,
                    :start_date,
                    :end_date,
                    :status
                )
            ");

            $stmt->execute([

                ":period_name" => $period_name,

                ":start_date" => $start_date,

                ":end_date" => $end_date,

                ":status" => $status

            ]);


            /*
            |--------------------------------------------------------------------------
            | Redirect
            |--------------------------------------------------------------------------
            */

            header(
                "Location: evaluation-periods.php"
            );

            exit;
        } catch (PDOException $e) {

            $error = "ไม่สามารถเพิ่มรอบการประเมินได้";
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
        href="../assets/css/employee.css">

    <title>Add Evaluation Period</title>

</head>

<style>
    .btn-primary {
        background: #1f6b9c;
        color: white;
    }

    .btn-primary:hover {
        background: #18577f;
    }

    .btn-secondary {
        background: #e5e7eb;
        color: #374151;
        margin-top: 10px;
        margin-right: 5px;
    }

    .btn-secondary:hover {
        background: #d1d5db;
    }
</style>

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
                    href="evaluation-periods.php"
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


                <!-- Period Name -->

                <div class="form-group">

                    <label for="period_name">
                        ชื่อรอบการประเมิน
                    </label>

                    <input
                        type="text"
                        id="period_name"
                        name="period_name"
                        placeholder="เช่น ประจำเดือน สิงหาคม 2569"
                        value="<?= htmlspecialchars(
                                    $_POST["period_name"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

                </div>


                <!-- Start Date -->

                <div class="form-group">

                    <label for="start_date">
                        วันที่เริ่มต้น
                    </label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?= htmlspecialchars(
                                    $_POST["start_date"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

                </div>


                <!-- End Date -->

                <div class="form-group">

                    <label for="end_date">
                        วันที่สิ้นสุด
                    </label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?= htmlspecialchars(
                                    $_POST["end_date"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

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
                        href="evaluation-periods.php"
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

</body>

</html>