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

$message = "";
$error = "";


/* =========================================================
   ADD KPI ASSIGNMENT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

    } else {


        /* =====================================================
           GET KPI TYPE
        ===================================================== */

        $kpi_type_sql = "
            SELECT kpi_type
            FROM kpi_indicators
            WHERE kpi_id = ?
            LIMIT 1
        ";

        $kpi_type_stmt = $pdo->prepare($kpi_type_sql);

        $kpi_type_stmt->execute([
            $kpi_id
        ]);

        $kpi_type = $kpi_type_stmt->fetchColumn();


        if (!$kpi_type) {

            $error = "ไม่พบประเภทของ KPI";

        } else {


            /* =================================================
               CHECK DUPLICATE
            ================================================= */

            $check_sql = "
                SELECT assignment_id
                FROM kpi_assignments
                WHERE kpi_id = ?
                AND employee_id = ?
                AND period_id = ?
                LIMIT 1
            ";

            $check_stmt = $pdo->prepare($check_sql);

            $check_stmt->execute([
                $kpi_id,
                $employee_id,
                $period_id
            ]);

            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);


            if ($existing) {

                $error =
                    "พนักงานคนนี้ถูก Assign KPI นี้ในรอบการประเมินนี้แล้ว";

            } else {


                /* =================================================
                   CHECK TOTAL WEIGHT BY KPI TYPE
                   
                   Competency แยกจาก Performance
                ================================================= */

                $weight_sql = "
                    SELECT COALESCE(SUM(a.weight), 0)
                    FROM kpi_assignments a

                    INNER JOIN kpi_indicators k
                        ON a.kpi_id = k.kpi_id

                    WHERE a.employee_id = ?
                    AND a.period_id = ?
                    AND k.kpi_type = ?
                    AND a.status = 'Active'
                ";

                $weight_stmt = $pdo->prepare($weight_sql);

                $weight_stmt->execute([
                    $employee_id,
                    $period_id,
                    $kpi_type
                ]);

                $current_weight = floatval(
                    $weight_stmt->fetchColumn()
                );


                $new_total_weight =
                    $current_weight + floatval($weight);


                /* =================================================
                   CHECK MAX 100% PER KPI TYPE
                ================================================= */

                if ($new_total_weight > 100) {

                    $error =
                        "ไม่สามารถ Assign ได้ เพราะ Weight รวมของ "
                        . htmlspecialchars($kpi_type)
                        . " จะเป็น "
                        . number_format($new_total_weight, 2)
                        . "% ซึ่งเกิน 100%";

                } else {


                    /* =================================================
                       INSERT KPI ASSIGNMENT
                    ================================================= */

                    $insert_sql = "
                        INSERT INTO kpi_assignments
                        (
                            kpi_id,
                            employee_id,
                            period_id,
                            target_value,
                            weight,
                            start_date,
                            end_date,
                            status
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ";

                    $insert_stmt =
                        $pdo->prepare($insert_sql);

                    $insert_stmt->execute([
                        $kpi_id,
                        $employee_id,
                        $period_id,
                        $target_value,
                        $weight,
                        $start_date,
                        $end_date,
                        $status
                    ]);


                    $message =
                        "เพิ่ม KPI Assignment เรียบร้อยแล้ว";
                }
            }
        }
    }
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


/* =========================================================
   FILTER
========================================================= */

$filter_period =
    intval($_GET["period_id"] ?? 0);

$filter_employee =
    intval($_GET["employee_id"] ?? 0);

$filter_status =
    $_GET["status"] ?? "";


/* =========================================================
   GET ASSIGNMENTS
========================================================= */

$assignment_sql = "
    SELECT
        a.assignment_id,
        a.kpi_id,
        a.employee_id,
        a.period_id,
        a.target_value,
        a.weight,
        a.start_date,
        a.end_date,
        a.status,

        e.employee_code,
        e.first_name,
        e.last_name,

        k.kpi_name,
        k.kpi_type,
        k.unit,

        p.period_name

    FROM kpi_assignments a

    INNER JOIN employees e
        ON a.employee_id = e.employee_id

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    INNER JOIN evaluation_periods p
        ON a.period_id = p.period_id

    WHERE 1 = 1
";


$params = [];


/* =========================
   FILTER PERIOD
========================= */

if ($filter_period > 0) {

    $assignment_sql .= "
        AND a.period_id = ?
    ";

    $params[] = $filter_period;
}


/* =========================
   FILTER EMPLOYEE
========================= */

if ($filter_employee > 0) {

    $assignment_sql .= "
        AND a.employee_id = ?
    ";

    $params[] = $filter_employee;
}


/* =========================
   FILTER STATUS
========================= */

if (
    $filter_status === "Active" ||
    $filter_status === "Inactive"
) {

    $assignment_sql .= "
        AND a.status = ?
    ";

    $params[] = $filter_status;
}


$assignment_sql .= "
    ORDER BY
        a.created_at DESC
";


$assignment_stmt =
    $pdo->prepare($assignment_sql);

$assignment_stmt->execute($params);

$assignments_result =
    $assignment_stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   TOTAL WEIGHT
   แยกตาม Employee + Period + KPI Type
========================================================= */

$weight_sql = "
    SELECT
        a.employee_id,
        a.period_id,
        k.kpi_type,
        SUM(a.weight) AS total_weight

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    WHERE a.status = 'Active'

    GROUP BY
        a.employee_id,
        a.period_id,
        k.kpi_type
";

$weight_result =
    $pdo->query($weight_sql);


$weights = [];


while (
    $row =
    $weight_result->fetch(PDO::FETCH_ASSOC)
) {

    $key =
        $row["employee_id"]
        . "_"
        . $row["period_id"]
        . "_"
        . $row["kpi_type"];


    $weights[$key] =
        floatval($row["total_weight"]);
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

    <title>KPI Assignment</title>


    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px;
        }


        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }


        .page-header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 600;
        }


        .page-header p {
            margin: 5px 0 0;
            color: #6b7280;
        }


        .header-actions {
            display: flex;
            gap: 10px;
        }


        .card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        }


        .card-title {
            margin: 0 0 20px;
            color: #244397;
            font-size: 20px;
            font-weight: 500;
        }


        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
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
            margin-top: 20px;
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


        .alert-success {
            background: #e7f7ed;
            color: #237a42;
        }


        .alert-error {
            background: #fdecec;
            color: #b42323;
        }


        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }


        .table-wrapper {
            overflow-x: auto;
        }


        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }


        th {
            background: #f1f4f9;
            color: #444;
            font-size: 14px;
            font-weight: 500;
            padding: 13px 12px;
            text-align: left;
            white-space: nowrap;
        }


        td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
            vertical-align: middle;
        }


        tr:hover td {
            background: #fafbfe;
        }


        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
        }


        .badge-active {
            background: #e5f7eb;
            color: #237a42;
        }


        .badge-inactive {
            background: #f1f1f1;
            color: #777;
        }


        .badge-type {
            background: #edf2ff;
            color: #244397;
        }


        .badge-type.performance {
            background: #fff4df;
            color: #a16207;
        }


        .action-buttons {
            display: flex;
            gap: 7px;
        }


        .btn-small {
            padding: 6px 11px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
        }


        .btn-edit {
            background: #eef3ff;
            color: #244397;
        }


        .btn-delete {
            background: #fff0f0;
            color: #c62828;
        }


        .empty {
            text-align: center;
            padding: 35px;
            color: #888;
        }


        .weight-current {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }


        @media (max-width: 900px) {

            .page-container {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: span 1;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 600px) {

            .page-container {
                padding: 15px;
            }

            .card {
                padding: 18px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .button-row {
                flex-direction: column;
            }

            .button-row .btn {
                width: 100%;
            }

        }

    </style>

</head>


<body>

<div class="page-container">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="page-header">

        <div>

            <h1>
                KPI Assignment
            </h1>

            <p>
                กำหนด KPI ให้กับพนักงานตามรอบการประเมิน
            </p>

        </div>


        <div class="header-actions">

            <a
                href="../index.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

        </div>

    </div>


    <!-- =====================================================
         ALERT
    ====================================================== -->

    <?php if ($message): ?>

        <div class="alert alert-success">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ADD ASSIGNMENT
    ====================================================== -->

    <div class="card">

        <h2 class="card-title">
            Assign KPI
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
                            $periods_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ): ?>

                            <option
                                value="<?= $period["period_id"] ?>"
                            >

                                <?= htmlspecialchars(
                                    $period["period_name"]
                                ) ?>

                                (
                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $period["start_date"]
                                    )
                                ) ?>

                                -

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $period["end_date"]
                                    )
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
                            $employees_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ): ?>

                            <option
                                value="<?= $employee["employee_id"] ?>"
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
                            $kpis_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ): ?>

                            <option
                                value="<?= $kpi["kpi_id"] ?>"
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
                        placeholder="เช่น 100"
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
                        placeholder="เช่น 20"
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
                        required
                    >

                </div>


                <!-- STATUS -->

                <div class="form-group">

                    <label>
                        สถานะ
                    </label>


                    <select name="status">

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                </div>


            </div>


            <div class="button-row">

                <button
                    type="reset"
                    class="btn btn-secondary"
                >
                    ล้างข้อมูล
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    + Assign KPI
                </button>

            </div>


        </form>

    </div>


    <!-- =====================================================
         FILTER
    ====================================================== -->

    <div class="card">

        <h2 class="card-title">
            รายการ KPI Assignment
        </h2>


        <form method="GET">


            <div class="filter-grid">


                <!-- PERIOD FILTER -->

                <div class="form-group">

                    <label>
                        รอบการประเมิน
                    </label>


                    <select name="period_id">

                        <option value="0">
                            ทั้งหมด
                        </option>


                        <?php

                        $filter_period_result =
                            $pdo->query("
                                SELECT
                                    period_id,
                                    period_name

                                FROM evaluation_periods

                                ORDER BY start_date DESC
                            ");

                        while (
                            $period =
                            $filter_period_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ):

                        ?>

                            <option
                                value="<?= $period["period_id"] ?>"

                                <?= $filter_period ==
                                    $period["period_id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $period["period_name"]
                                ) ?>

                            </option>

                        <?php endwhile; ?>


                    </select>

                </div>


                <!-- EMPLOYEE FILTER -->

                <div class="form-group">

                    <label>
                        พนักงาน
                    </label>


                    <select name="employee_id">

                        <option value="0">
                            ทั้งหมด
                        </option>


                        <?php

                        $filter_employee_result =
                            $pdo->query("
                                SELECT
                                    employee_id,
                                    employee_code,
                                    first_name,
                                    last_name

                                FROM employees

                                WHERE status = 'Active'

                                ORDER BY first_name
                            ");

                        while (
                            $employee =
                            $filter_employee_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ):

                        ?>

                            <option
                                value="<?= $employee["employee_id"] ?>"

                                <?= $filter_employee ==
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


                <!-- STATUS FILTER -->

                <div class="form-group">

                    <label>
                        สถานะ
                    </label>


                    <select name="status">

                        <option value="">
                            ทั้งหมด
                        </option>


                        <option
                            value="Active"

                            <?= $filter_status === "Active"
                                ? "selected"
                                : "" ?>
                        >
                            Active
                        </option>


                        <option
                            value="Inactive"

                            <?= $filter_status === "Inactive"
                                ? "selected"
                                : "" ?>
                        >
                            Inactive
                        </option>


                    </select>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    ค้นหา
                </button>


            </div>

        </form>

    </div>


    <!-- =====================================================
         ASSIGNMENT TABLE
    ====================================================== -->

    <div class="card">

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>#</th>

                        <th>
                            พนักงาน
                        </th>

                        <th>
                            KPI
                        </th>

                        <th>
                            ประเภท
                        </th>

                        <th>
                            รอบประเมิน
                        </th>

                        <th>
                            Target
                        </th>

                        <th>
                            Weight
                        </th>

                        <th>
                            ช่วงเวลา
                        </th>

                        <th>
                            สถานะ
                        </th>

                        <th>
                            จัดการ
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (!empty($assignments_result)): ?>


                    <?php $no = 1; ?>


                    <?php foreach (
                        $assignments_result
                        as $assignment
                    ): ?>


                        <?php

                        /* =====================================
                           KEY = EMPLOYEE + PERIOD + KPI TYPE
                        ===================================== */

                        $weight_key =
                            $assignment["employee_id"]
                            . "_"
                            . $assignment["period_id"]
                            . "_"
                            . $assignment["kpi_type"];


                        $total_weight =
                            $weights[$weight_key] ?? 0;

                        ?>


                        <tr>


                            <!-- NUMBER -->

                            <td>
                                <?= $no++ ?>
                            </td>


                            <!-- EMPLOYEE -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $assignment["employee_code"]
                                    ) ?>

                                </strong>

                                <br>

                                <?= htmlspecialchars(
                                    $assignment["first_name"]
                                ) ?>

                                <?= htmlspecialchars(
                                    $assignment["last_name"]
                                ) ?>

                            </td>


                            <!-- KPI -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $assignment["kpi_name"]
                                    ) ?>

                                </strong>


                                <br>


                                <small>

                                    หน่วย:

                                    <?= htmlspecialchars(
                                        $assignment["unit"] ?? "-"
                                    ) ?>

                                </small>

                            </td>


                            <!-- KPI TYPE -->

                            <td>

                                <?php

                                $type_class =
                                    strtolower(
                                        $assignment["kpi_type"]
                                    ) === "performance"
                                        ? "performance"
                                        : "";

                                ?>


                                <span
                                    class="badge badge-type <?= $type_class ?>"
                                >

                                    <?= htmlspecialchars(
                                        $assignment["kpi_type"]
                                    ) ?>

                                </span>

                            </td>


                            <!-- PERIOD -->

                            <td>

                                <?= htmlspecialchars(
                                    $assignment["period_name"]
                                ) ?>

                            </td>


                            <!-- TARGET -->

                            <td>

                                <?= number_format(
                                    $assignment["target_value"],
                                    2
                                ) ?>

                            </td>


                            <!-- WEIGHT -->

                            <td>

                                <strong>

                                    <?= number_format(
                                        $assignment["weight"],
                                        2
                                    ) ?>%

                                </strong>


                                <div class="weight-current">

                                    รวม
                                    <?= htmlspecialchars(
                                        $assignment["kpi_type"]
                                    ) ?>:

                                    <?= number_format(
                                        $total_weight,
                                        2
                                    ) ?>%

                                </div>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $assignment["start_date"]
                                    )
                                ) ?>


                                <br>


                                -


                                <br>


                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $assignment["end_date"]
                                    )
                                ) ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php if (
                                    $assignment["status"]
                                    === "Active"
                                ): ?>


                                    <span
                                        class="badge badge-active"
                                    >
                                        Active
                                    </span>


                                <?php else: ?>


                                    <span
                                        class="badge badge-inactive"
                                    >
                                        Inactive
                                    </span>


                                <?php endif; ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div class="action-buttons">


                                    <a
                                        href="kpi-assignment-edit.php?id=<?= $assignment["assignment_id"] ?>"
                                        class="btn-small btn-edit"
                                    >
                                        แก้ไข
                                    </a>


                                    <a
                                        href="kpi-assignment-delete.php?id=<?= $assignment["assignment_id"] ?>"
                                        class="btn-small btn-delete"

                                        onclick="return confirm('คุณต้องการลบ KPI Assignment นี้หรือไม่?')"
                                    >
                                        ลบ
                                    </a>


                                </div>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="10"
                            class="empty"
                        >
                            ยังไม่มีข้อมูล KPI Assignment
                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>

</body>

</html>