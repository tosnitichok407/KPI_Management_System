<?php

session_start();

require_once "../../config/database.php";


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
    header("Location: evaluation-periods.php");
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

        header("Location: evaluation-periods.php");
        exit;
    }


} catch (PDOException $e) {

    die("Database error.");
}


/*
|--------------------------------------------------------------------------
| Update Evaluation Period
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $period_name = trim(
        $_POST["period_name"] ?? ""
    );

    $start_date =
        $_POST["start_date"] ?? "";

    $end_date =
        $_POST["end_date"] ?? "";

    $status =
        $_POST["status"] ?? "Open";


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

        $error =
            "กรุณากรอกข้อมูลให้ครบถ้วน";

    } elseif ($end_date < $start_date) {

        $error =
            "วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น";

    } elseif (
        !in_array(
            $status,
            ["Open", "Closed"],
            true
        )
    ) {

        $error =
            "สถานะไม่ถูกต้อง";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        try {

            $stmt = $pdo->prepare("
                UPDATE evaluation_periods

                SET
                    period_name = :period_name,
                    start_date = :start_date,
                    end_date = :end_date,
                    status = :status

                WHERE period_id = :period_id
            ");

            $stmt->execute([

                ":period_name" =>
                    $period_name,

                ":start_date" =>
                    $start_date,

                ":end_date" =>
                    $end_date,

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
                "Location: evaluation-periods.php"
            );

            exit;


        } catch (PDOException $e) {

            $error =
                "ไม่สามารถแก้ไขรอบการประเมินได้";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Keep Form Data After Error
    |--------------------------------------------------------------------------
    */

    $period["period_name"] =
        $period_name;

    $period["start_date"] =
        $start_date;

    $period["end_date"] =
        $end_date;

    $period["status"] =
        $status;
}

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
        href="../../assets/css/evaluation.css"
    >

    <title>Edit Evaluation Period</title>

</head>

<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                Edit Evaluation Period
            </h1>

            <p>
                แก้ไขรอบการประเมินผลการปฏิบัติงาน
            </p>

        </div>


        <div class="header-actions">

            <a
                href="evaluation-periods.php"
                class="btn btn-secondary"
            >
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
            action="evaluation-periods-edit.php?id=<?= (int) $id ?>"
        >


            <!-- =====================================================
                 Period ID
            ====================================================== -->

            <div class="form-group">

                <label>
                    Period ID
                </label>

                <input
                    type="text"
                    value="<?= (int) $period["period_id"] ?>"
                    disabled
                >

            </div>


            <!-- =====================================================
                 Period Name
            ====================================================== -->

            <div class="form-group">

                <label for="period_name">
                    ชื่อรอบการประเมิน
                </label>

                <input
                    type="text"
                    id="period_name"
                    name="period_name"
                    value="<?= htmlspecialchars(
                        $period["period_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    placeholder="เช่น ประจำเดือน สิงหาคม 2569"
                    required
                >

            </div>


            <!-- =====================================================
                 Start Date
            ====================================================== -->

            <div class="form-group">

                <label for="start_date">
                    วันที่เริ่มต้น
                </label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= htmlspecialchars(
                        $period["start_date"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>


            <!-- =====================================================
                 End Date
            ====================================================== -->

            <div class="form-group">

                <label for="end_date">
                    วันที่สิ้นสุด
                </label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= htmlspecialchars(
                        $period["end_date"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>


            <!-- =====================================================
                 Status
            ====================================================== -->

            <div class="form-group">

                <label for="status">
                    สถานะ
                </label>

                <select
                    id="status"
                    name="status"
                >

                    <option
                        value="Open"
                        <?= $period["status"] === "Open"
                            ? "selected"
                            : "" ?>
                    >
                        Open
                    </option>

                    <option
                        value="Closed"
                        <?= $period["status"] === "Closed"
                            ? "selected"
                            : "" ?>
                    >
                        Closed
                    </option>

                </select>

            </div>


            <!-- =====================================================
                 Buttons
            ====================================================== -->

            <div class="form-actions">

                <a
                    href="evaluation-periods.php"
                    class="btn btn-secondary"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Changes
                </button>

            </div>


        </form>


    </section>


</div>

</body>

</html>
