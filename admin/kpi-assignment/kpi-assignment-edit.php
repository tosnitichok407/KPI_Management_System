<?php

session_start();

require_once "../../config/database.php";


/* =========================
   CHECK LOGIN
========================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


/* =========================
   CHECK ADMIN
========================= */

if (!isset($_SESSION["role_id"]) || $_SESSION["role_id"] != 1) {
    header("Location: ../dashboard.php");
    exit;
}


/* =========================
   GET ASSIGNMENT ID
========================= */

$assignment_id = intval($_GET["id"] ?? 0);

if ($assignment_id <= 0) {
    header("Location: kpi-assignment.php");
    exit;
}


/* =========================
   GET ASSIGNMENT DATA
========================= */

$assignment_sql = "
    SELECT
        assignment_id,
        kpi_id,
        employee_id,
        period_id,
        target_value,
        weight,
        start_date,
        end_date,
        status

    FROM kpi_assignments

    WHERE assignment_id = ?

    LIMIT 1
";

$assignment_stmt = $pdo->prepare($assignment_sql);

$assignment_stmt->execute([
    $assignment_id
]);

$assignment = $assignment_stmt->fetch(PDO::FETCH_ASSOC);


if (!$assignment) {
    header("Location: kpi-assignment.php");
    exit;
}


$message = "";
$error = "";


/* =========================================================
   UPDATE KPI ASSIGNMENT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /* =========================
       GET FORM DATA
    ========================= */

    $kpi_id       = intval($_POST["kpi_id"] ?? 0);
    $employee_id  = intval($_POST["employee_id"] ?? 0);
    $period_id    = intval($_POST["period_id"] ?? 0);
    $target_value = trim($_POST["target_value"] ?? "");
    $weight       = trim($_POST["weight"] ?? "");
    $start_date   = $_POST["start_date"] ?? "";
    $end_date     = $_POST["end_date"] ?? "";
    $status       = $_POST["status"] ?? "Active";


    /* =========================
       VALIDATION
    ========================= */

    if (
        $kpi_id <= 0 ||
        $employee_id <= 0 ||
        $period_id <= 0 ||
        $target_value === "" ||
        $weight === "" ||
        $start_date === "" ||
        $end_date === ""
    ) {

        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";

    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {

        $error = "Weight ต้องอยู่ระหว่าง 0 - 100%";

    } elseif (!is_numeric($target_value) || $target_value < 0) {

        $error = "Target Value ต้องเป็นตัวเลขที่มากกว่าหรือเท่ากับ 0";

    } elseif ($start_date > $end_date) {

        $error = "วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด";

    } elseif (
        $status !== "Active" &&
        $status !== "Inactive"
    ) {

        $error = "สถานะไม่ถูกต้อง";

    } else {


        /* =================================================
           GET KPI TYPE
        ================================================= */

        $kpi_type_sql = "
            SELECT kpi_type
            FROM kpi_indicators
            WHERE kpi_id = ?
            LIMIT 1
        ";

        $kpi_type_stmt =
            $pdo->prepare($kpi_type_sql);

        $kpi_type_stmt->execute([
            $kpi_id
        ]);

        $kpi_type =
            $kpi_type_stmt->fetchColumn();


        if (!$kpi_type) {

            $error = "ไม่พบประเภทของ KPI";

        } else {


            /* =================================================
               CHECK DUPLICATE

               ไม่รวม Assignment ปัจจุบัน
            ================================================= */

            $duplicate_sql = "
                SELECT assignment_id
                FROM kpi_assignments

                WHERE kpi_id = ?
                AND employee_id = ?
                AND period_id = ?
                AND assignment_id != ?

                LIMIT 1
            ";

            $duplicate_stmt =
                $pdo->prepare($duplicate_sql);

            $duplicate_stmt->execute([
                $kpi_id,
                $employee_id,
                $period_id,
                $assignment_id
            ]);

            $duplicate =
                $duplicate_stmt->fetch(PDO::FETCH_ASSOC);


            if ($duplicate) {

                $error =
                    "พนักงานคนนี้ถูก Assign KPI นี้ในรอบการประเมินนี้แล้ว";

            } else {


                /* =============================================
                   CHECK TOTAL WEIGHT BY KPI TYPE

                   แยก:
                   - Competency
                   - Performance

                   และไม่รวม Assignment ที่กำลังแก้ไข
                ============================================= */

                $weight_sql = "
                    SELECT
                        COALESCE(SUM(a.weight), 0)

                    FROM kpi_assignments a

                    INNER JOIN kpi_indicators k
                        ON a.kpi_id = k.kpi_id

                    WHERE a.employee_id = ?
                    AND a.period_id = ?
                    AND k.kpi_type = ?
                    AND a.status = 'Active'
                    AND a.assignment_id != ?
                ";

                $weight_stmt =
                    $pdo->prepare($weight_sql);

                $weight_stmt->execute([
                    $employee_id,
                    $period_id,
                    $kpi_type,
                    $assignment_id
                ]);


                $current_weight =
                    floatval(
                        $weight_stmt->fetchColumn()
                    );


                /*
                 * ถ้า Assignment ใหม่เป็น Active
                 * ให้นำ Weight มาตรวจ
                 *
                 * ถ้าเป็น Inactive
                 * ไม่ต้องนำมารวม
                 */

                if ($status === "Active") {

                    $new_total_weight =
                        $current_weight +
                        floatval($weight);

                } else {

                    $new_total_weight =
                        $current_weight;
                }


                /* =========================
                   CHECK MAX 100%
                ========================= */

                if ($new_total_weight > 100) {

                    $error =
                        "ไม่สามารถบันทึกได้ เพราะ Weight รวมของ "
                        . $kpi_type
                        . " จะเป็น "
                        . number_format(
                            $new_total_weight,
                            2
                        )
                        . "% ซึ่งเกิน 100%";

                } else {


                    /* =========================
                       UPDATE
                    ========================= */

                    $update_sql = "
                        UPDATE kpi_assignments

                        SET
                            kpi_id = ?,
                            employee_id = ?,
                            period_id = ?,
                            target_value = ?,
                            weight = ?,
                            start_date = ?,
                            end_date = ?,
                            status = ?

                        WHERE assignment_id = ?
                    ";

                    $update_stmt =
                        $pdo->prepare($update_sql);

                    $update_stmt->execute([
                        $kpi_id,
                        $employee_id,
                        $period_id,
                        $target_value,
                        $weight,
                        $start_date,
                        $end_date,
                        $status,
                        $assignment_id
                    ]);


                    /*
                     * Redirect กลับหน้าหลัก
                     */

                    header(
                        "Location: kpi-assignment.php?success=updated"
                    );

                    exit;
                }
            }
        }
    }


    /*
     * ถ้าเกิด Error
     * เก็บค่าที่กรอกไว้แสดงใน Form
     */

    $assignment["kpi_id"] =
        $kpi_id;

    $assignment["employee_id"] =
        $employee_id;

    $assignment["period_id"] =
        $period_id;

    $assignment["target_value"] =
        $target_value;

    $assignment["weight"] =
        $weight;

    $assignment["start_date"] =
        $start_date;

    $assignment["end_date"] =
        $end_date;

    $assignment["status"] =
        $status;
}


/* =========================================================
   GET EMPLOYEES
========================================================= */

$employees_sql = "
    SELECT
        employee_id,
        employee_code,
        first_name,
        last_name

    FROM employees

    WHERE status = 'Active'

    ORDER BY first_name ASC
";

$employees_result =
    $pdo->query($employees_sql);


/* =========================================================
   GET KPIs
========================================================= */

$kpis_sql = "
    SELECT
        k.kpi_id,
        k.kpi_name,
        k.kpi_type,
        k.unit,
        c.category_name

    FROM kpi_indicators k

    LEFT JOIN kpi_categories c
        ON k.category_id = c.category_id

    ORDER BY
        k.kpi_type ASC,
        k.kpi_name ASC
";

$kpis_result =
    $pdo->query($kpis_sql);


/* =========================================================
   GET EVALUATION PERIODS
========================================================= */

$periods_sql = "
    SELECT
        period_id,
        period_name,
        start_date,
        end_date,
        status

    FROM evaluation_periods

    ORDER BY start_date DESC
";

$periods_result =
    $pdo->query($periods_sql);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>แก้ไข KPI Assignment</title>


    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;
            font-family: "Kanit", sans-serif;
            background: #f5f7fb;
            color: #333;
        }


        .page-container {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            padding: 40px;
        }


        .page-header {
            margin-bottom: 30px;
        }


        .page-header h1 {
            margin: 0;
            font-size: 30px;
            font-weight: 600;
        }


        .page-header p {
            margin: 5px 0 0;
            color: #6b7280;
        }


        .card {
            background: #fff;
            border-radius: 12px;
            padding: 30px;
            box-shadow:
                0 2px 10px
                rgba(0, 0, 0, .05);
        }


        .card-title {
            margin: 0 0 25px;
            color: #244397;
            font-size: 20px;
            font-weight: 500;
        }


        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(2, 1fr);
            gap: 20px;
        }


        .form-group {
            display: flex;
            flex-direction: column;
        }


        .form-group.full {
            grid-column: span 2;
        }


        label {
            font-size: 14px;
            margin-bottom: 7px;
            font-weight: 500;
        }


        label span {
            color: #e53935;
        }


        input,
        select {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d8dce5;
            border-radius: 7px;
            font-family: "Kanit", sans-serif;
            font-size: 14px;
            outline: none;
            background: #fff;
        }


        input:focus,
        select:focus {
            border-color: #244397;
        }


        .button-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 25px;
        }


        .btn {
            border: none;
            padding: 10px 22px;
            border-radius: 7px;
            font-family: "Kanit", sans-serif;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }


        .btn-primary {
            background: #244397;
            color: #fff;
        }


        .btn-secondary {
            background: #e9edf5;
            color: #444;
        }


        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }


        .alert-error {
            background: #fdecec;
            color: #b42323;
        }


        @media (max-width: 700px) {

            .page-container {
                padding: 20px;
            }


            .card {
                padding: 20px;
            }


            .form-grid {
                grid-template-columns: 1fr;
            }


            .form-group.full {
                grid-column: span 1;
            }


            .button-row {
                flex-direction: column-reverse;
            }


            .btn {
                width: 100%;
                text-align: center;
            }

        }

    </style>

</head>


<body>


<div class="page-container">


    <!-- PAGE HEADER -->

    <div class="page-header">

        <h1>
            แก้ไข KPI Assignment
        </h1>

        <p>
            แก้ไขข้อมูล KPI ที่กำหนดให้พนักงาน
        </p>

    </div>


    <!-- ERROR -->

    <?php if ($error): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- FORM -->

    <div class="card">

        <h2 class="card-title">
            ข้อมูล KPI Assignment
        </h2>


        <form method="POST">


            <div class="form-grid">


                <!-- PERIOD -->

                <div class="form-group">

                    <label>
                        รอบการประเมิน <span>*</span>
                    </label>


                    <select
                        name="period_id"
                        required
                    >

                        <option value="">
                            -- เลือกรอบการประเมิน --
                        </option>


                        <?php while (
                            $period =
                            $periods_result->fetch(PDO::FETCH_ASSOC)
                        ): ?>

                            <option
                                value="<?= $period["period_id"] ?>"

                                <?= $assignment["period_id"] ==
                                    $period["period_id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $period["period_name"]
                                ) ?>

                                (
                                <?= date(
                                    "d/m/Y",
                                    strtotime($period["start_date"])
                                ) ?>

                                -

                                <?= date(
                                    "d/m/Y",
                                    strtotime($period["end_date"])
                                ) ?>
                                )

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>


                <!-- EMPLOYEE -->

                <div class="form-group">

                    <label>
                        พนักงาน <span>*</span>
                    </label>


                    <select
                        name="employee_id"
                        required
                    >

                        <option value="">
                            -- เลือกพนักงาน --
                        </option>


                        <?php while (
                            $employee =
                            $employees_result->fetch(PDO::FETCH_ASSOC)
                        ): ?>

                            <option
                                value="<?= $employee["employee_id"] ?>"

                                <?= $assignment["employee_id"] ==
                                    $employee["employee_id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $employee["employee_code"]
                                ) ?>

                                -

                                <?= htmlspecialchars(
                                    $employee["first_name"]
                                ) ?>

                                <?= htmlspecialchars(
                                    $employee["last_name"]
                                ) ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>


                <!-- KPI -->

                <div class="form-group full">

                    <label>
                        KPI <span>*</span>
                    </label>


                    <select
                        name="kpi_id"
                        required
                    >

                        <option value="">
                            -- เลือก KPI --
                        </option>


                        <?php while (
                            $kpi =
                            $kpis_result->fetch(PDO::FETCH_ASSOC)
                        ): ?>

                            <option
                                value="<?= $kpi["kpi_id"] ?>"

                                <?= $assignment["kpi_id"] ==
                                    $kpi["kpi_id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                [<?= htmlspecialchars(
                                    $kpi["kpi_type"]
                                ) ?>]

                                <?= htmlspecialchars(
                                    $kpi["kpi_name"]
                                ) ?>

                                -

                                <?= htmlspecialchars(
                                    $kpi["category_name"] ?? ""
                                ) ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>


                <!-- TARGET -->

                <div class="form-group">

                    <label>
                        Target Value <span>*</span>
                    </label>


                    <input
                        type="number"
                        name="target_value"
                        step="0.01"
                        min="0"

                        value="<?= htmlspecialchars(
                            $assignment["target_value"]
                        ) ?>"

                        required
                    >

                </div>


                <!-- WEIGHT -->

                <div class="form-group">

                    <label>
                        Weight (%) <span>*</span>
                    </label>


                    <input
                        type="number"
                        name="weight"
                        step="0.01"
                        min="0"
                        max="100"

                        value="<?= htmlspecialchars(
                            $assignment["weight"]
                        ) ?>"

                        required
                    >

                </div>


                <!-- START DATE -->

                <div class="form-group">

                    <label>
                        วันที่เริ่มต้น <span>*</span>
                    </label>


                    <input
                        type="date"
                        name="start_date"

                        value="<?= htmlspecialchars(
                            $assignment["start_date"]
                        ) ?>"

                        required
                    >

                </div>


                <!-- END DATE -->

                <div class="form-group">

                    <label>
                        วันที่สิ้นสุด <span>*</span>
                    </label>


                    <input
                        type="date"
                        name="end_date"

                        value="<?= htmlspecialchars(
                            $assignment["end_date"]
                        ) ?>"

                        required
                    >

                </div>


                <!-- STATUS -->

                <div class="form-group">

                    <label>
                        สถานะ
                    </label>


                    <select name="status">


                        <option
                            value="Active"

                            <?= $assignment["status"] === "Active"
                                ? "selected"
                                : "" ?>
                        >
                            Active
                        </option>


                        <option
                            value="Inactive"

                            <?= $assignment["status"] === "Inactive"
                                ? "selected"
                                : "" ?>
                        >
                            Inactive
                        </option>


                    </select>

                </div>


            </div>


            <!-- BUTTON -->

            <div class="button-row">


                <a
                    href="kpi-assignment.php"
                    class="btn btn-secondary"
                >
                    ยกเลิก
                </a>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    บันทึกการแก้ไข
                </button>


            </div>


        </form>

    </div>


</div>


</body>

</html>