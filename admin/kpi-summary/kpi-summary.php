<?php

session_start();

require_once "../../config/database.php";


/* =========================================================
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   CHECK ADMIN
========================================================= */

if (!isset($_SESSION["role_id"]) || $_SESSION["role_id"] != 1) {
    header("Location: ../dashboard.php");
    exit;
}


/* =========================================================
   FILTER
========================================================= */

$filter_period =
    intval($_GET["period_id"] ?? 0);

$filter_employee =
    intval($_GET["employee_id"] ?? 0);

$filter_type =
    $_GET["kpi_type"] ?? "";

$filter_status =
    $_GET["status"] ?? "Active";


/* =========================================================
   VALIDATE FILTER
========================================================= */

$allowed_types = [
    "Competency",
    "Performance"
];

$allowed_status = [
    "",
    "Active",
    "Inactive"
];

if (!in_array($filter_type, $allowed_types)) {
    $filter_type = "";
}

if (!in_array($filter_status, $allowed_status)) {
    $filter_status = "Active";
}


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
   GET KPI SUMMARY
========================================================= */

$summary_sql = "
    SELECT

        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,

        p.period_id,
        p.period_name,
        p.start_date AS period_start_date,
        p.end_date AS period_end_date,

        k.kpi_type,

        COUNT(a.assignment_id)
            AS total_kpi,

        COALESCE(
            SUM(a.weight),
            0
        ) AS total_weight,

        COALESCE(
            SUM(a.target_value),
            0
        ) AS total_target

    FROM kpi_assignments a

    INNER JOIN employees e
        ON a.employee_id = e.employee_id

    INNER JOIN evaluation_periods p
        ON a.period_id = p.period_id

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    WHERE 1 = 1
";


$params = [];


/* =========================================================
   FILTER PERIOD
========================================================= */

if ($filter_period > 0) {

    $summary_sql .= "
        AND a.period_id = ?
    ";

    $params[] = $filter_period;
}


/* =========================================================
   FILTER EMPLOYEE
========================================================= */

if ($filter_employee > 0) {

    $summary_sql .= "
        AND a.employee_id = ?
    ";

    $params[] = $filter_employee;
}


/* =========================================================
   FILTER KPI TYPE
========================================================= */

if ($filter_type !== "") {

    $summary_sql .= "
        AND k.kpi_type = ?
    ";

    $params[] = $filter_type;
}


/* =========================================================
   FILTER STATUS
========================================================= */

if ($filter_status !== "") {

    $summary_sql .= "
        AND a.status = ?
    ";

    $params[] = $filter_status;
}


/* =========================================================
   GROUP
========================================================= */

$summary_sql .= "

    GROUP BY

        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,

        p.period_id,
        p.period_name,
        p.start_date,
        p.end_date,

        k.kpi_type

    ORDER BY
        p.start_date DESC,
        e.first_name ASC,
        k.kpi_type ASC
";


$summary_stmt =
    $pdo->prepare($summary_sql);

$summary_stmt->execute($params);

$summary_result =
    $summary_stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET KPI DETAIL
========================================================= */

$detail_sql = "
    SELECT

        a.assignment_id,

        a.employee_id,
        a.period_id,
        a.kpi_id,

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


$detail_params = [];


/* =========================================================
   DETAIL FILTER
========================================================= */

if ($filter_period > 0) {

    $detail_sql .= "
        AND a.period_id = ?
    ";

    $detail_params[] = $filter_period;
}


if ($filter_employee > 0) {

    $detail_sql .= "
        AND a.employee_id = ?
    ";

    $detail_params[] = $filter_employee;
}


if ($filter_type !== "") {

    $detail_sql .= "
        AND k.kpi_type = ?
    ";

    $detail_params[] = $filter_type;
}


if ($filter_status !== "") {

    $detail_sql .= "
        AND a.status = ?
    ";

    $detail_params[] = $filter_status;
}


$detail_sql .= "
    ORDER BY
        e.first_name ASC,
        p.start_date DESC,
        k.kpi_type ASC,
        k.kpi_name ASC
";


$detail_stmt =
    $pdo->prepare($detail_sql);

$detail_stmt->execute($detail_params);

$details =
    $detail_stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   CALCULATE DASHBOARD TOTALS
========================================================= */

$total_employees = 0;
$total_kpi = 0;
$total_competency = 0;
$total_performance = 0;


/* =========================================================
   UNIQUE EMPLOYEE
========================================================= */

$employee_ids = [];


foreach ($details as $row) {

    $employee_ids[
        $row["employee_id"]
    ] = true;


    $total_kpi++;


    if ($row["kpi_type"] === "Competency") {

        $total_competency++;

    } elseif ($row["kpi_type"] === "Performance") {

        $total_performance++;
    }
}


$total_employees =
    count($employee_ids);


/* =========================================================
   TOTAL WEIGHT BY EMPLOYEE + PERIOD + TYPE
========================================================= */

$weight_sql = "
    SELECT

        a.employee_id,
        a.period_id,
        k.kpi_type,

        COALESCE(
            SUM(a.weight),
            0
        ) AS total_weight

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
        floatval(
            $row["total_weight"]
        );
}


/* =========================================================
   KPI TYPE CLASS
========================================================= */

function getTypeClass($type)
{
    if ($type === "Competency") {
        return "badge-competency";
    }

    return "badge-performance";
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

    <title>KPI Summary</title>


    <!-- GOOGLE FONT -->
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >

    <link 
        rel="stylesheet"
        href="../../assets/css/summary.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family:
                "Kanit",
                sans-serif;

            background: #f5f7fb;

            color: #333;
        }


        /* =================================================
           CONTAINER
        ================================================= */

        .page-container {

            width: 100%;

            max-width: 1400px;

            margin: 0 auto;

            padding: 40px;
        }

        /* =================================================
           HEADER
        ================================================= */
        .page-header {
            display: flex;

            justify-content:
                space-between;

            align-items: center;

            margin-bottom: 30px;
        }

        .page-header-left h1 {

            margin: 0;

            font-size: 32px;

            font-weight: 600;
        }

        .page-header-left p {

            margin: 5px 0 0;

            color: #6b7280;
        }

        .header-actions {

            display: flex;

            gap: 10px;
        }

        /* =================================================
           CARD
        ================================================= */
        .card {

            background: #fff;

            border-radius: 12px;

            padding: 25px;

            margin-bottom: 25px;

            box-shadow:
                0 2px 10px
                rgba(0, 0, 0, .05);
        }

        .card-title {

            margin:
                0 0 20px;

            color: #244397;

            font-size: 20px;

            font-weight: 500;
        }

        /* =================================================
           SUMMARY CARDS
        ================================================= */

        .summary-cards {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 25px;
        }


        .summary-card {

            background: #fff;

            border-radius: 12px;

            padding: 22px;

            box-shadow:
                0 2px 10px
                rgba(0, 0, 0, .05);
        }


        .summary-card-title {

            font-size: 14px;

            color: #6b7280;

            margin-bottom: 8px;
        }


        .summary-card-value {

            font-size: 30px;

            font-weight: 600;

            color: #244397;
        }


        /* =================================================
           FILTER
        ================================================= */

        .filter-grid {

            display: grid;

            grid-template-columns:
                1fr 1fr 1fr 1fr auto;

            gap: 15px;

            align-items: end;
        }


        .form-group {

            display: flex;

            flex-direction: column;
        }


        label {

            font-size: 14px;

            margin-bottom: 7px;

            font-weight: 500;
        }


        select {

            width: 100%;

            padding:
                11px 13px;

            border:
                1px solid #d8dce5;

            border-radius: 7px;

            font-family:
                "Kanit",
                sans-serif;

            font-size: 14px;

            outline: none;

            background: #fff;
        }


        select:focus {

            border-color:
                #244397;
        }


        /* =================================================
           SUMMARY TABLE
        ================================================= */

        .table-wrapper {

            overflow-x: auto;
        }


        table {

            width: 100%;

            border-collapse:
                collapse;

            min-width: 1000px;
        }


        th {

            background:
                #f1f4f9;

            color: #444;

            font-size: 14px;

            font-weight: 500;

            padding:
                13px 12px;

            text-align: left;

            white-space: nowrap;
        }


        td {

            padding:
                13px 12px;

            border-bottom:
                1px solid #edf0f5;

            font-size: 14px;

            vertical-align:
                middle;
        }


        tr:hover td {

            background:
                #fafbfe;
        }


        /* =================================================
           BADGES
        ================================================= */

        .badge {

            display: inline-block;

            padding:
                4px 10px;

            border-radius: 20px;

            font-size: 12px;
        }


        .badge-competency {

            background:
                #fff4e5;

            color:
                #b76e00;
        }


        .badge-performance {

            background:
                #edf2ff;

            color:
                #244397;
        }


        .badge-active {

            background:
                #e5f7eb;

            color:
                #237a42;
        }


        .badge-inactive {

            background:
                #f1f1f1;

            color:
                #777;
        }

        /* =================================================
           WEIGHT
        ================================================= */

        .weight-box {

            display: flex;

            flex-direction:
                column;

            gap: 3px;
        }

        .weight-main {

            font-weight: 600;

            font-size: 15px;
        }


        .weight-ok {

            color: #237a42;
        }


        .weight-warning {

            color: #d97706;
        }


        .weight-error {

            color: #c62828;
        }


        .weight-label {

            font-size: 11px;

            color: #888;
        }


        /* =================================================
           DETAIL TABLE
        ================================================= */

        .detail-title {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            margin-bottom: 20px;
        }


        .detail-title h2 {

            margin: 0;

            color: #244397;

            font-size: 20px;

            font-weight: 500;
        }


        .count {

            color: #777;

            font-size: 14px;
        }


        .empty {

            text-align: center;

            padding: 40px;

            color: #888;
        }


        .small-text {

            font-size: 12px;

            color: #777;
        }


        /* =================================================
           RESPONSIVE
        ================================================= */

        @media (max-width: 1100px) {

            .summary-cards {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .filter-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

        }


        @media (max-width: 700px) {

            .page-container {

                padding: 20px;
            }


            .page-header {

                flex-direction:
                    column;

                align-items:
                    flex-start;

                gap: 15px;
            }


            .header-actions {

                width: 100%;
            }


            .header-actions .btn {

                width: 100%;

                text-align: center;
            }


            .summary-cards {

                grid-template-columns:
                    1fr;
            }


            .filter-grid {

                grid-template-columns:
                    1fr;
            }


            .card {

                padding: 18px;
            }

        }

    </style>
</head>

<body>

<div class="page-container">

    <!-- =================================================
         HEADER
    ================================================= -->
    <div class="page-header">

        <div class="page-header-left">

            <h1>
                KPI Summary
            </h1>

            <p>
                สรุปข้อมูล KPI ของพนักงานตามรอบการประเมิน
            </p>

        </div>

        <div class="header-actions">

            <a
                href="../index.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

            <a
                href="../kpi-assignment/kpi-assignment.php"
                class="btn btn-primary"
            >
                มอบหมาย KPI
            </a>

        </div>

    </div>

    <!-- =================================================
         SUMMARY CARDS
    ================================================= -->
    <div class="summary-cards">

        <div class="summary-card">

            <div class="summary-card-title">
                พนักงาน
            </div>

            <div class="summary-card-value">
                <?= $total_employees ?>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-card-title">
                KPI ทั้งหมด
            </div>

            <div class="summary-card-value">
                <?= $total_kpi ?>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-card-title">
                Competency KPI
            </div>

            <div class="summary-card-value">
                <?= $total_competency ?>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-card-title">
                Performance KPI
            </div>

            <div class="summary-card-value">
                <?= $total_performance ?>
            </div>

        </div>

    </div>

    <!-- =================================================
         FILTER
    ================================================= -->
    <div class="card">

        <h2 class="card-title">
            Filter
        </h2>

        <form method="GET">

            <div class="filter-grid">

                <!-- PERIOD -->
                <div class="form-group">

                    <label>
                        รอบการประเมิน
                    </label>

                    <select name="period_id">

                        <option value="0">
                            ทั้งหมด
                        </option>

                        <?php while (
                            $period =
                            $periods_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ): ?>

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

                <!-- EMPLOYEE -->
                <div class="form-group">

                    <label>
                        พนักงาน
                    </label>

                    <select name="employee_id">

                        <option value="0">
                            ทั้งหมด
                        </option>

                        <?php while (
                            $employee =
                            $employees_result->fetch(
                                PDO::FETCH_ASSOC
                            )
                        ): ?>

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

                <!-- KPI TYPE -->
                <div class="form-group">

                    <label>
                        ประเภท KPI
                    </label>

                    <select name="kpi_type">

                        <option value="">
                            ทั้งหมด
                        </option>


                        <option
                            value="Competency"

                            <?= $filter_type === "Competency"
                                ? "selected"
                                : "" ?>
                        >
                            Competency
                        </option>


                        <option
                            value="Performance"

                            <?= $filter_type === "Performance"
                                ? "selected"
                                : "" ?>
                        >
                            Performance
                        </option>
                    </select>
                </div>

                <!-- STATUS -->
                <div class="form-group">

                    <label>
                        สถานะ
                    </label>

                    <select name="status">
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


                        <option
                            value=""

                            <?= $filter_status === ""
                                ? "selected"
                                : "" ?>
                        >
                            ทั้งหมด
                        </option>
                    </select>
                </div>

                <!-- SEARCH -->
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    ค้นหา
                </button>
            </div>
        </form>
    </div>

    <!-- =================================================
         SUMMARY TABLE
    ================================================= -->
    <div class="card">

        <div class="detail-title">

            <h2>
                สรุป KPI
            </h2>

            <span class="count">

                <?= count($summary_result) ?>

                รายการ

            </span>

        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>
                            #
                        </th>

                        <th>
                            พนักงาน
                        </th>

                        <th>
                            รอบการประเมิน
                        </th>

                        <th>
                            ประเภท
                        </th>

                        <th>
                            จำนวน KPI
                        </th>

                        <th>
                            Weight รวม
                        </th>

                        <th>
                            Target รวม
                        </th>

                        <th>
                            ช่วงรอบประเมิน
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!empty($summary_result)): ?>

                    <?php $no = 1; ?>

                    <?php foreach (
                        $summary_result
                        as $summary
                    ): ?>

                        <?php

                        $weight_key =
                            $summary["employee_id"]
                            . "_"
                            . $summary["period_id"]
                            . "_"
                            . $summary["kpi_type"];


                        $actual_weight =
                            $weights[$weight_key]
                            ?? 0;


                        if (
                            $actual_weight == 100
                        ) {

                            $weight_class =
                                "weight-ok";

                        } elseif (
                            $actual_weight < 100
                        ) {

                            $weight_class =
                                "weight-warning";

                        } else {

                            $weight_class =
                                "weight-error";
                        }

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
                                        $summary["employee_code"]
                                    ) ?>

                                </strong>

                                <br>

                                <?= htmlspecialchars(
                                    $summary["first_name"]
                                ) ?>

                                <?= htmlspecialchars(
                                    $summary["last_name"]
                                ) ?>

                            </td>

                            <!-- PERIOD -->
                            <td>

                                <?= htmlspecialchars(
                                    $summary["period_name"]
                                ) ?>

                            </td>

                            <!-- TYPE -->
                            <td>

                                <span
                                    class="badge
                                    <?= getTypeClass(
                                        $summary["kpi_type"]
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $summary["kpi_type"]
                                    ) ?>

                                </span>

                            </td>

                            <!-- KPI COUNT -->
                            <td>

                                <strong>

                                    <?= $summary["total_kpi"] ?>

                                </strong>

                                KPI

                            </td>

                            <!-- WEIGHT -->
                            <td>

                                <div class="weight-box">

                                    <span
                                        class="weight-main
                                        <?= $weight_class ?>"
                                    >

                                        <?= number_format(
                                            $actual_weight,
                                            2
                                        ) ?>%

                                    </span>

                                    <span class="weight-label">

                                        <?= $actual_weight == 100
                                            ? "ครบ 100%"
                                            : "ยังไม่ครบ 100%" ?>

                                    </span>
                                </div>
                            </td>

                            <!-- TARGET -->
                            <td>

                                <?= number_format(
                                    $summary["total_target"],
                                    2
                                ) ?>

                            </td>

                            <!-- PERIOD DATE -->
                            <td>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $summary[
                                            "period_start_date"
                                        ]
                                    )
                                ) ?>

                                <br>

                                -

                                <br>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $summary[
                                            "period_end_date"
                                        ]
                                    )
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td
                            colspan="8"
                            class="empty"
                        >
                            ไม่พบข้อมูล KPI Summary
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

    <!-- =================================================
         KPI DETAIL
    ================================================= -->
    <div class="card">
        <div class="detail-title">
            <h2>
                รายละเอียด KPI
            </h2>

            <span class="count">

                <?= count($details) ?>

                KPI

            </span>

        </div>

        <div class="table-wrapper">
            <table>

                <thead>

                    <tr>

                        <th>
                            #
                        </th>

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

                    </tr>

                </thead>

                <tbody>

                <?php if (!empty($details)): ?>

                    <?php $detail_no = 1; ?>

                    <?php foreach (
                        $details
                        as $detail
                    ): ?>

                        <tr>

                            <!-- NUMBER -->
                            <td>

                                <?= $detail_no++ ?>

                            </td>

                            <!-- EMPLOYEE -->
                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $detail[
                                            "employee_code"
                                        ]
                                    ) ?>

                                </strong>

                                <br>

                                <?= htmlspecialchars(
                                    $detail[
                                        "first_name"
                                    ]
                                ) ?>

                                <?= htmlspecialchars(
                                    $detail[
                                        "last_name"
                                    ]
                                ) ?>

                            </td>

                            <!-- KPI -->
                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $detail[
                                            "kpi_name"
                                        ]
                                    ) ?>

                                </strong>

                                <br>

                                <span class="small-text">

                                    หน่วย:
                                    <?= htmlspecialchars(
                                        $detail[
                                            "unit"
                                        ] ?? "-"
                                    ) ?>

                                </span>

                            </td>

                            <!-- TYPE -->
                            <td>
                                <span
                                    class="badge
                                    <?= getTypeClass(
                                        $detail[
                                            "kpi_type"
                                        ]
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $detail[
                                            "kpi_type"
                                        ]
                                    ) ?>

                                </span>

                            </td>

                            <!-- PERIOD -->
                            <td>

                                <?= htmlspecialchars(
                                    $detail[
                                        "period_name"
                                    ]
                                ) ?>

                            </td>

                            <!-- TARGET -->
                            <td>

                                <?= number_format(
                                    $detail[
                                        "target_value"
                                    ],
                                    2
                                ) ?>

                            </td>

                            <!-- WEIGHT -->
                            <td>

                                <strong>

                                    <?= number_format(
                                        $detail[
                                            "weight"
                                        ],
                                        2
                                    ) ?>%

                                </strong>

                            </td>

                            <!-- DATE -->
                            <td>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $detail[
                                            "start_date"
                                        ]
                                    )
                                ) ?>

                                <br>

                                -

                                <br>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $detail[
                                            "end_date"
                                        ]
                                    )
                                ) ?>

                            </td>

                            <!-- STATUS -->

                            <td>

                                <?php if (
                                    $detail["status"]
                                    === "Active"
                                ): ?>

                                    <span
                                        class="
                                        badge
                                        badge-active
                                        "
                                    >

                                        Active

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                        badge
                                        badge-inactive
                                        "
                                    >

                                        Inactive

                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td
                            colspan="9"
                            class="empty"
                        >

                            ไม่พบข้อมูล KPI
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