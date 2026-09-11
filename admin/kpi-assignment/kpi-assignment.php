<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


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

if (
    !isset($_SESSION["role_id"]) ||
    $_SESSION["role_id"] != 1
){
    header("Location: ../dashboard.php");
    exit;
}

$message = "";
$error = "";


/* =========================================================
   ADD KPI ASSIGNMENT
   Assign KPI ให้พนักงานหลายคน
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $kpi_id = intval(
        $_POST["kpi_id"] ?? 0
    );

    $employee_ids =
        $_POST["employee_ids"] ?? [];

    $assignment_year = intval(
        $_POST["assignment_year"]
        ?? date("Y")
    );

    $assignment_month = 1;
    $period_id = 0;

    $target_value = trim(
        $_POST["target_value"] ?? ""
    );

    $weight = trim(
        $_POST["weight"] ?? ""
    );

    $status =
        $_POST["status"] ?? "Active";


    /* =====================================================
       CALCULATE START / END DATE FROM YEAR + MONTH
       วันที่เริ่ม = วันที่ 1
       วันที่สิ้นสุด = วันสุดท้ายของเดือน
    ===================================================== */

    $start_date = "";
    $end_date = "";

    if (
        $assignment_year >= 2000 &&
        $assignment_year <= 2100 &&
        $assignment_month >= 1 &&
        $assignment_month <= 12
    ) {

        $start_date = sprintf(
            "%04d-%02d-01",
            $assignment_year,
            $assignment_month
        );

        $end_date = sprintf("%04d-12-31", $assignment_year);
    }


    /* =====================================================
       CLEAN EMPLOYEE IDS
    ===================================================== */

    if (!is_array($employee_ids)) {
        $employee_ids = [];
    }

    $employee_ids = array_map(
        "intval",
        $employee_ids
    );

    $employee_ids = array_filter(
        $employee_ids,
        function ($id) {
            return $id > 0;
        }
    );

    $employee_ids = array_values(
        array_unique($employee_ids)
    );


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $kpi_id <= 0 ||
        empty($employee_ids) ||
        $assignment_year < 2000 ||
        $assignment_year > 2100 ||
        $target_value === "" ||
        $weight === "" ||
        $start_date === "" ||
        $end_date === ""
    ) {

        $error =
            "กรุณากรอกข้อมูลให้ครบถ้วน";

    } elseif (
        !is_numeric($weight) ||
        $weight < 0 ||
        $weight > 100
    ) {

        $error =
            "Weight ต้องอยู่ระหว่าง 0 - 100%";

    } elseif (
        !is_numeric($target_value) ||
        $target_value < 0
    ) {

        $error =
            "Target Value ต้องเป็นตัวเลขที่มากกว่าหรือเท่ากับ 0";

    } elseif ($start_date > $end_date) {

        $error =
            "วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด";

    } else {


        /* =====================================================
           CHECK PERIOD
        ===================================================== */

        $period_check_sql = "
            SELECT
                period_id,
                start_date,
                end_date
            FROM evaluation_periods
            WHERE period_id = ?
            LIMIT 1
        ";

        $period_check_stmt =
            $pdo->prepare(
                $period_check_sql
            );

        $period_check_stmt->execute([
            $period_id
        ]);

        $period_data =
            $period_check_stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($period_id <= 0) {
            $period_data = [
                "start_date" => $start_date,
                "end_date" => $end_date
            ];
        }


        if (!$period_data) {

            $error =
                "ไม่พบรอบการประเมินที่เลือก";

        } elseif (
            date("Y", strtotime($period_data["start_date"])) !==
            (string) $assignment_year
        ) {

            $error =
                "เดือน/ปีที่เลือกต้องตรงกับวันที่เริ่มต้นของรอบการประเมิน";

        } else {


            /* =====================================================
               CHECK KPI TYPE
            ===================================================== */

            $kpi_type_sql = "
                SELECT
                    kpi_type
                FROM kpi_indicators
                WHERE kpi_id = ?
                LIMIT 1
            ";

            $kpi_type_stmt =
                $pdo->prepare(
                    $kpi_type_sql
                );

            $kpi_type_stmt->execute([
                $kpi_id
            ]);

            $kpi_type =
                $kpi_type_stmt->fetchColumn();


            if (!$kpi_type) {

                $error =
                    "ไม่พบประเภทของ KPI";

            } else {


                /* =================================================
                   START TRANSACTION
                ================================================= */

                $pdo->beginTransaction();


                try {

                    $success_count = 0;
                    $skip_count = 0;


                    /* =================================================
                       CHECK DUPLICATE
                    ================================================= */

                    $check_sql = "
                        SELECT
                            assignment_id
                        FROM kpi_assignments
                        WHERE kpi_id = ?
                        AND employee_id = ?
                        AND assignment_year = ?
                        LIMIT 1
                    ";

                    $check_stmt =
                        $pdo->prepare(
                            $check_sql
                        );


                    /* =================================================
                       CHECK WEIGHT
                    ================================================= */

                    $weight_sql = "
                        SELECT
                            COALESCE(
                                SUM(a.weight),
                                0
                            )

                        FROM kpi_assignments a

                        INNER JOIN kpi_indicators k
                            ON a.kpi_id = k.kpi_id

                        WHERE a.employee_id = ?
                        AND a.assignment_year = ?
                        AND k.kpi_type = ?
                        AND a.status = 'Active'
                    ";

                    $weight_stmt =
                        $pdo->prepare(
                            $weight_sql
                        );


                    /* =================================================
                       INSERT
                    ================================================= */

                    $insert_sql = "
                        INSERT INTO kpi_assignments
                        (
                            kpi_id,
                            employee_id,
                            assignment_year,
                            period_id,
                            target_value,
                            weight,
                            start_date,
                            end_date,
                            status
                        )

                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ";

                    $insert_stmt =
                        $pdo->prepare(
                            $insert_sql
                        );


                    /* =================================================
                       LOOP EMPLOYEES
                    ================================================= */

                    foreach (
                        $employee_ids
                        as $employee_id
                    ) {


                        /* =============================================
                           CHECK EMPLOYEE EXISTS
                        ============================================= */

                        $employee_check_stmt =
                            $pdo->prepare("
                                SELECT employee_id
                                FROM employees
                                WHERE employee_id = ?
                                AND status = 'Active'
                                LIMIT 1
                            ");

                        $employee_check_stmt->execute([
                            $employee_id
                        ]);

                        if (
                            !$employee_check_stmt->fetchColumn()
                        ) {
                            $skip_count++;
                            continue;
                        }


                        /* =============================================
                           CHECK DUPLICATE
                        ============================================= */

                        $check_stmt->execute([
                            $kpi_id,
                            $employee_id,
                            $assignment_year
                        ]);

                        $existing =
                            $check_stmt->fetch(
                                PDO::FETCH_ASSOC
                            );


                        if ($existing) {

                            $skip_count++;

                            continue;
                        }


                        /* =============================================
                           CHECK TOTAL WEIGHT
                        ============================================= */

                        $weight_stmt->execute([
                            $employee_id,
                            $assignment_year,
                            $kpi_type
                        ]);

                        $current_weight =
                            floatval(
                                $weight_stmt->fetchColumn()
                            );


                        $new_total_weight =
                            $current_weight
                            + floatval($weight);


                        /* =============================================
                           CHECK MAX 100%
                        ============================================= */

                        if (
                            $new_total_weight > 100
                        ) {

                            throw new Exception(
                                "Weight ของพนักงาน ID "
                                . $employee_id
                                . " ในประเภท "
                                . $kpi_type
                                . " จะเกิน 100%"
                            );
                        }


                        /* =============================================
                           INSERT
                        ============================================= */

                        $insert_stmt->execute([
                            $kpi_id,
                            $employee_id,
                            $assignment_year,
                            null,
                            $target_value,
                            $weight,
                            $start_date,
                            $end_date,
                            $status
                        ]);


                        $success_count++;
                    }


                    /* =================================================
                       COMMIT
                    ================================================= */

                    $pdo->commit();


                    /* =================================================
                       RESULT MESSAGE
                    ================================================= */

                    if ($success_count > 0) {

                        $message =
                            "Assign KPI ให้พนักงาน "
                            . $success_count
                            . " คนเรียบร้อยแล้ว";

                        if ($skip_count > 0) {

                            $message .=
                                " (ข้าม "
                                . $skip_count
                                . " คน เนื่องจากมี KPI นี้อยู่แล้ว)";
                        }

                    } else {

                        $error =
                            "ไม่สามารถ Assign ได้ เนื่องจากพนักงานที่เลือกมี KPI นี้อยู่แล้วทั้งหมด";
                    }


                } catch (Exception $e) {

                    if (
                        $pdo->inTransaction()
                    ) {

                        $pdo->rollBack();
                    }

                    $error =
                        "เกิดข้อผิดพลาด: "
                        . $e->getMessage();
                }
            }
        }
    }
}


/* =========================================================
   GET DEPARTMENTS
========================================================= */

$departments_sql = "
    SELECT
        department_id,
        department_name
    FROM departments
    ORDER BY department_name ASC
";

$departments_result =
    $pdo->query(
        $departments_sql
    );

$departments =
    $departments_result->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   GET EMPLOYEES
========================================================= */

$employees_sql = "
    SELECT
        employee_id,
        employee_code,
        first_name,
        last_name,
        department_id

    FROM employees

    WHERE status = 'Active'

    ORDER BY
        first_name ASC,
        last_name ASC
";

$employees_result =
    $pdo->query(
        $employees_sql
    );

$employees =
    $employees_result->fetchAll(
        PDO::FETCH_ASSOC
    );


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
    $pdo->query(
        $kpis_sql
    );

$kpis =
    $kpis_result->fetchAll(
        PDO::FETCH_ASSOC
    );


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

    ORDER BY
        start_date DESC
";

$periods_result =
    $pdo->query(
        $periods_sql
    );

$periods =
    $periods_result->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   FILTER
========================================================= */

$filter_year =
    intval(
        $_GET["year"] ?? 0
    );

$filter_month = (int) ($_GET["month"] ?? 0);

$filter_department =
    intval(
        $_GET["department_id"] ?? 0
    );

$filter_employee =
    intval(
        $_GET["employee_id"] ?? 0
    );

$filter_status =
    $_GET["status"] ?? "";

$filter_has_month =
    $filter_month >= 1 && $filter_month <= 12;

$filter_period =
    ($filter_year > 0 && $filter_has_month)
    ? findEvaluationPeriodByMonth($pdo, $filter_year, $filter_month)
    : null;


/* =========================================================
   GET ASSIGNMENTS
========================================================= */

$assignment_sql = "
    SELECT

        a.assignment_id,
        a.kpi_id,
        a.employee_id,
        a.assignment_year,
        a.period_id,
        a.target_value,
        a.weight,
        a.start_date,
        a.end_date,
        a.status,

        e.employee_code,
        e.first_name,
        e.last_name,

        d.department_id,
        d.department_name,

        k.kpi_name,
        k.kpi_type,
        k.unit

    FROM kpi_assignments a

    INNER JOIN employees e
        ON a.employee_id = e.employee_id

    LEFT JOIN departments d
        ON e.department_id = d.department_id

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    WHERE 1 = 1
";


$params = [];


/* =========================================================
   FILTER YEAR
========================================================= */

if ($filter_year > 0) {

    $assignment_sql .= "
        AND a.assignment_year = ?
    ";

    $params[] =
        $filter_year;
}

/* =========================================================
   FILTER MONTH
   KPI ที่มีผลในเดือนนั้น = ช่วงเวลาของ Assignment ครอบคลุมเดือนที่เลือก
========================================================= */

if ($filter_month >= 1 && $filter_month <= 12) {
    $assignment_sql .= "
        AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= LAST_DAY(CONCAT(a.assignment_year, '-', LPAD(?, 2, '0'), '-01'))
        AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= CONCAT(a.assignment_year, '-', LPAD(?, 2, '0'), '-01')
    ";
    $params[] = $filter_month;
    $params[] = $filter_month;
}


/* =========================================================
   FILTER DEPARTMENT
========================================================= */

if ($filter_department > 0) {

    $assignment_sql .= "
        AND e.department_id = ?
    ";

    $params[] =
        $filter_department;
}


/* =========================================================
   FILTER EMPLOYEE
========================================================= */

if ($filter_employee > 0) {

    $assignment_sql .= "
        AND a.employee_id = ?
    ";

    $params[] =
        $filter_employee;
}


/* =========================================================
   FILTER STATUS
========================================================= */

if (
    $filter_status === "Active" ||
    $filter_status === "Inactive"
) {

    $assignment_sql .= "
        AND a.status = ?
    ";

    $params[] =
        $filter_status;
}


/* เรียงตามพนักงานเพื่อจัดกลุ่มในตาราง: Performance ก่อน Competency แล้วตามชื่อ KPI */
$assignment_sql .= "
    ORDER BY
        e.first_name ASC,
        e.last_name ASC,
        a.employee_id ASC,
        a.assignment_year DESC,
        FIELD(k.kpi_type, 'Performance', 'Competency'),
        k.kpi_name ASC
";


$assignment_stmt =
    $pdo->prepare(
        $assignment_sql
    );

$assignment_stmt->execute(
    $params
);

$assignments_result =
    $assignment_stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   GROUP BY EMPLOYEE (+ ปี)
   ตารางแสดงพนักงาน 1 คนเป็น 1 กลุ่ม แล้วรายการ KPI อยู่ใต้กลุ่ม
========================================================= */

$grouped_assignments = [];

foreach ($assignments_result as $assignment) {

    $group_key =
        $assignment["employee_id"]
        . "_"
        . $assignment["assignment_year"];

    if (!isset($grouped_assignments[$group_key])) {

        $grouped_assignments[$group_key] = [
            "employee" => [
                "employee_id" => $assignment["employee_id"],
                "employee_code" => $assignment["employee_code"],
                "first_name" => $assignment["first_name"],
                "last_name" => $assignment["last_name"],
                "department_name" => $assignment["department_name"],
                "assignment_year" => $assignment["assignment_year"]
            ],
            "kpis" => []
        ];
    }

    $grouped_assignments[$group_key]["kpis"][] = $assignment;
}


/* =========================================================
   TOTAL WEIGHT
========================================================= */

$weight_total_sql = "
    SELECT

        a.employee_id,
        a.assignment_year,
        k.kpi_type,

        SUM(a.weight) AS total_weight

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    WHERE a.status = 'Active'

    GROUP BY
        a.employee_id,
        a.assignment_year,
        k.kpi_type
";

$weight_result =
    $pdo->query(
        $weight_total_sql
    );


$weights = [];


while (
    $row =
    $weight_result->fetch(
        PDO::FETCH_ASSOC
    )
) {

    $key =
        $row["employee_id"]
        . "_"
        . $row["assignment_year"]
        . "_"
        . $row["kpi_type"];


    $weights[$key] =
        floatval(
            $row["total_weight"]
        );
}


/* =========================================================
   DEFAULT FORM VALUES
========================================================= */

$form_year =
    intval(
        $_POST["assignment_year"]
        ?? date("Y")
    );


?>

<style>

        .quarter-preview {
            margin-top: 8px;
            color: #667085;
            font-size: 13px;
        }

        .quarter-preview strong {
            color: #244397;
        }


        /* .page-container ใช้ขนาดเดียวกับหน้าอื่นจาก assets/css/admin.css */

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
            font-family: var(--font-family, "Kanit", sans-serif);
            font-size: 14px;
            outline: none;
            background: #fff;
        }


        input:focus,
        select:focus {
            border-color: #244397;
        }


        .employee-select {
            min-height: 220px;
        }


        .department-select {
            margin-bottom: 8px;
        }


        .select-hint {
            margin-top: 6px;
            font-size: 12px;
            color: #777;
        }


        .selected-count {
            margin-top: 7px;
            font-size: 13px;
            color: #244397;
            font-weight: 500;
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
            font-family: var(--font-family, "Kanit", sans-serif);
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }


        .btn-primary {
            background: #244397;
            color: #fff;
        }


        .btn-primary:hover {
            background: #244397;
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
            grid-template-columns:
                repeat(5, 1fr)
                auto;

            gap: 15px;
            align-items: end;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            white-space: nowrap;
        }


        .table-wrapper {
            overflow-x: auto;
        }


        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }


        /* =================================================
           ASSIGNMENT TABLE (จัดกลุ่มตามพนักงาน)
        ================================================= */

        .table-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 22px;
            margin-bottom: 16px;
            color: #667085;
            font-size: 14px;
        }

        .table-summary strong {
            color: #244397;
        }

        .assignment-table .col-index {
            width: 44px;
            color: #98a2b3;
            text-align: center;
        }

        .assignment-table .col-number {
            text-align: right;
            white-space: nowrap;
        }

        .assignment-table .group-row td {
            padding: 0;
            border-top: 1px solid #dfe4ee;
            border-bottom: 1px solid #dfe4ee;
            background: #f4f6fb;
        }

        .assignment-table .group-row:first-child td {
            border-top: 0;
        }

        .assignment-table .group-row:hover td {
            background: #f4f6fb;
        }

        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 12px 14px;
        }

        .group-employee {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .group-employee strong {
            font-size: 15px;
            color: #1f2937;
        }

        .group-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #244397;
            color: #fff;
            font-weight: 600;
            flex-shrink: 0;
        }

        .group-meta {
            margin-top: 2px;
            color: #667085;
            font-size: 13px;
        }

        .group-weights {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .weight-chip {
            display: inline-flex;
            align-items: baseline;
            gap: 5px;
            padding: 5px 11px;
            border: 1px solid #dfe4ee;
            border-radius: 20px;
            background: #fff;
            color: #667085;
            font-size: 12px;
            white-space: nowrap;
        }

        .weight-chip strong {
            color: #1f2937;
            font-size: 14px;
        }

        .weight-chip small {
            color: #98a2b3;
        }

        .weight-chip.full {
            border-color: #bfe3cb;
            background: #eefaf2;
        }

        .weight-chip.full strong {
            color: #237a42;
        }

        .weight-chip.over {
            border-color: #f5c2c2;
            background: #fff0f0;
        }

        .weight-chip.over strong {
            color: #c62828;
        }

        .kpi-name {
            font-weight: 500;
            color: #1f2937;
        }

        .kpi-unit {
            margin-top: 2px;
            color: #98a2b3;
            font-size: 12px;
        }

        .assignment-table .col-period {
            min-width: 250px;
        }

        .period-main {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            white-space: nowrap;
        }

        .period-dates {
            margin-top: 3px;
            color: #98a2b3;
            font-size: 12px;
            white-space: nowrap;
        }

        .badge-quarter {
            background: #eef2ff;
            color: #244397;
            font-weight: 500;
        }

        .row-inactive td {
            color: #98a2b3;
        }

        .row-inactive .kpi-name {
            color: #98a2b3;
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


        .badge-department {
            background: #f0f4ff;
            color: #244397;
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


        @media (max-width: 1100px) {

            .filter-grid {
                grid-template-columns:
                    1fr
                    1fr;
            }

        }


        @media (max-width: 900px) {

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
                กำหนด KPI ประจำปีให้กับพนักงาน
            </p>

        </div>


    </div>


    <!-- =====================================================
         ALERT
    ====================================================== -->

    <?php if ($message): ?>

        <div class="alert alert-success">

            <?= htmlspecialchars(
                $message
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars(
                $error
            ) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ADD ASSIGNMENT
    ====================================================== -->

    <div class="card">

        <h2 class="card-title">
            Assign KPI ประจำปี
        </h2>


        <form
            method="POST"
            id="assignmentForm">


            <div class="form-grid">


                <!-- =================================================
                     YEAR
                ================================================= -->

                <div class="form-group">

                    <label>
                        ปี <span>*</span>
                    </label>


                    <input
                        type="number"
                        name="assignment_year"
                        id="assignment_year"
                        value="<?= $form_year ?>"
                        min="2000"
                        max="2100"
                        required>

                    <div class="select-hint">

                        Assign ครั้งเดียวใช้ได้ทั้งปี (มกราคม - ธันวาคม)
                        และประเมินผลได้ทุกเดือน

                    </div>

                </div>


                <!-- =================================================
                     DEPARTMENT
                ================================================= -->

                <div class="form-group">

                    <label>
                        แผนก <span>*</span>
                    </label>


                    <select
                        id="department_select"
                        class="department-select">

                        <option value="">
                            -- เลือกแผนก --
                        </option>


                        <?php foreach (
                            $departments
                            as $department
                        ): ?>

                            <option
                                value="<?= $department["department_id"] ?>">

                                <?= htmlspecialchars(
                                    $department["department_name"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>


                    <div class="select-hint">

                        เลือกแผนกเพื่อแสดงรายชื่อพนักงานในแผนกนั้น

                    </div>

                </div>


                <!-- =================================================
                     EMPLOYEES
                ================================================= -->

                <div class="form-group">

                    <label>
                        พนักงาน <span>*</span>
                    </label>


                    <select
                        name="employee_ids[]"
                        id="employee_select"
                        class="employee-select"
                        multiple
                        required>


                        <?php foreach (
                            $employees
                            as $employee
                        ): ?>

                            <option
                                value="<?= $employee["employee_id"] ?>"
                                data-department="<?= $employee["department_id"] ?>">

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

                        <?php endforeach; ?>


                    </select>


                    <div
                        class="selected-count"
                        id="selected_count">

                        เลือกพนักงานแล้ว 0 คน

                    </div>


                    <div class="select-hint">

                        กด Ctrl (Windows) หรือ Command (Mac)
                        เพื่อเลือกหลายคน

                    </div>

                </div>


                <!-- =================================================
                     KPI
                ================================================= -->

                <div class="form-group full">

                    <label>
                        KPI <span>*</span>
                    </label>


                    <select
                        name="kpi_id"
                        required>

                        <option value="">
                            -- เลือก KPI --
                        </option>


                        <?php foreach (
                            $kpis
                            as $kpi
                        ): ?>

                            <option
                                value="<?= $kpi["kpi_id"] ?>">

                                [<?= htmlspecialchars(
                                    $kpi["kpi_type"]
                                ) ?>]

                                <?= htmlspecialchars(
                                    $kpi["kpi_name"]
                                ) ?>

                                -

                                <?= htmlspecialchars(
                                    $kpi["category_name"]
                                    ?? ""
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- =================================================
                     TARGET
                ================================================= -->

                <div class="form-group">

                    <label>
                        เป้าหมาย (%) <span>*</span>
                    </label>


                    <input
                        type="number"
                        name="target_value"
                        step="1"
                        min="0"
                        max="100"
                        placeholder="เช่น 100"
                        required>

                </div>


                <!-- =================================================
                     WEIGHT
                ================================================= -->

                <div class="form-group">

                    <label>
                        น้ำหนัก (%) <span>*</span>
                    </label>


                    <input
                        type="number"
                        name="weight"
                        step="1"
                        min="0"
                        max="100"
                        placeholder="เช่น 20"
                        required>

                </div>


                <!-- =================================================
                     STATUS
                ================================================= -->

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


            <!-- =================================================
                 BUTTON
            ================================================= -->

            <div class="button-row">

                <button
                    type="reset"
                    class="btn btn-secondary">

                    ล้างข้อมูล

                </button>


                <button
                    type="submit"
                    class="btn btn-primary">

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


        <form method="GET" action="index.php">

            <!-- ต้องส่ง page กลับไปด้วย ไม่งั้น index.php จะเด้งไปหน้า home -->
            <input type="hidden" name="page" value="kpi-assignment">

            <div class="filter-grid">


                <div class="form-group">

                    <label>
                        ปี
                    </label>


                    <select name="year">

                        <option value="0">
                            ทั้งหมด
                        </option>


                        <?php

                        $current_year =
                            intval(date("Y"));

                        for (
                            $year =
                                $current_year + 2;

                            $year >=
                                $current_year - 5;

                            $year--
                        ):

                        ?>

                            <option
                                value="<?= $year ?>"

                                <?= $filter_year ==
                                    $year
                                    ? "selected"
                                    : "" ?>>

                                <?= $year ?>

                            </option>

                        <?php endfor; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>เดือน</label>

                    <select name="month">
                        <option value="0">ทั้งหมด</option>
                        <?php foreach ([1 => "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"] as $monthNumber => $monthName): ?>
                            <option value="<?= $monthNumber ?>" <?= $filter_month === $monthNumber ? "selected" : "" ?>><?= $monthName ?></option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div class="form-group">

                    <label>
                        แผนก
                    </label>

                    <select
                        name="department_id"
                        id="filter_department">

                        <option value="0">
                            ทั้งหมด
                        </option>


                        <?php foreach (
                            $departments
                            as $department
                        ): ?>

                            <option
                                value="<?= $department["department_id"] ?>"

                                <?= $filter_department ==
                                    $department["department_id"]
                                    ? "selected"
                                    : "" ?>>

                                <?= htmlspecialchars(
                                    $department["department_name"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        พนักงาน
                    </label>


                    <select
                        name="employee_id"
                        id="filter_employee">

                        <option value="0">
                            ทั้งหมด
                        </option>


                        <?php foreach (
                            $employees
                            as $employee
                        ): ?>

                            <option
                                value="<?= $employee["employee_id"] ?>"

                                data-department="<?= $employee["department_id"] ?>"

                                <?= $filter_employee ==
                                    $employee["employee_id"]
                                    ? "selected"
                                    : "" ?>>

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

                        <?php endforeach; ?>

                    </select>

                </div>


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
                                : "" ?>>

                            Active

                        </option>

                        <option
                            value="Inactive"
                            <?= $filter_status === "Inactive"
                                ? "selected"
                                : "" ?>>

                            Inactive

                        </option>

                    </select>

                </div>


                <div class="filter-actions">

                    <button
                        type="submit"
                        class="btn btn-primary">

                        ค้นหา

                    </button>

                    <?php if ($filter_year || $filter_has_month || $filter_department || $filter_employee || $filter_status !== ""): ?>

                        <a
                            href="index.php?page=kpi-assignment"
                            class="btn btn-secondary">
                            ล้างตัวกรอง
                        </a>

                    <?php endif; ?>

                </div>

            </div>

        </form>

        <?php if ($filter_year > 0 && $filter_has_month): ?>

            <div class="select-hint">

                <?php if ($filter_period): ?>

                    รอบประเมิน:
                    <strong><?= htmlspecialchars($filter_period["period_name"]) ?> <?= (int) $filter_period["period_year"] ?></strong>
                    · <?= htmlspecialchars($filter_period["quarter"]) ?>
                    · <?= date("d/m/Y", strtotime($filter_period["start_date"])) ?>
                    - <?= date("d/m/Y", strtotime($filter_period["end_date"])) ?>
                    · <?= htmlspecialchars($filter_period["status"]) ?>

                <?php else: ?>

                    ยังไม่ได้สร้างรอบประเมินของเดือน<?= monthlyPeriodMonths()[$filter_month] ?> <?= $filter_year ?>
                    (<?= getQuarterByMonth($filter_month) ?>) พนักงานจะยังบันทึกผลงานเดือนนี้ไม่ได้

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         ASSIGNMENT TABLE
    ====================================================== -->

    <div class="card">

        <div class="table-summary">

            <span>
                <strong><?= count($grouped_assignments) ?></strong> พนักงาน
            </span>

            <span>
                <strong><?= count($assignments_result) ?></strong> รายการ KPI
            </span>

            <?php if ($filter_has_month && $filter_year > 0): ?>

                <span>
                    แสดง KPI ที่มีผลในเดือน
                    <strong><?= monthlyPeriodMonths()[$filter_month] ?> <?= $filter_year ?></strong>
                    (<?= getQuarterByMonth($filter_month) ?>)
                </span>

            <?php endif; ?>

        </div>


        <div class="table-wrapper">

            <table class="assignment-table">

                <thead>

                    <tr>

                        <th class="col-index">#</th>
                        <th>KPI</th>
                        <th>ประเภท</th>
                        <th class="col-number">Target</th>
                        <th class="col-number">Weight</th>
                        <th class="col-period">ช่วงเวลาที่มีผล</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>

                    </tr>

                </thead>

                <tbody>


                <?php if (empty($grouped_assignments)): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="empty">

                            ยังไม่มีข้อมูล KPI Assignment

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($grouped_assignments as $group): ?>

                        <?php
                        $employee = $group["employee"];
                        $kpi_count = count($group["kpis"]);
                        ?>


                        <!-- =========================================
                             EMPLOYEE GROUP HEADER
                        ========================================== -->

                        <tr class="group-row">

                            <td colspan="8">

                                <div class="group-header">

                                    <div class="group-employee">

                                        <span class="group-avatar">
                                            <?= htmlspecialchars(
                                                mb_substr($employee["first_name"], 0, 1, "UTF-8")
                                            ) ?>
                                        </span>

                                        <div>

                                            <strong>
                                                <?= htmlspecialchars($employee["first_name"]) ?>
                                                <?= htmlspecialchars($employee["last_name"]) ?>
                                            </strong>

                                            <div class="group-meta">

                                                <?= htmlspecialchars($employee["employee_code"]) ?>

                                                ·

                                                <?= htmlspecialchars($employee["department_name"] ?? "ไม่ระบุแผนก") ?>

                                                ·

                                                ปี <?= (int) $employee["assignment_year"] ?>

                                                ·

                                                <?= $kpi_count ?> KPI

                                            </div>

                                        </div>

                                    </div>


                                    <!-- Weight รวมทุกประเภท (Performance + Competency = 100%) -->

                                    <?php
                                    $weight_key_prefix =
                                        $employee["employee_id"]
                                        . "_" . $employee["assignment_year"] . "_";

                                    $total_weight =
                                        ($weights[$weight_key_prefix . "Performance"] ?? 0)
                                        + ($weights[$weight_key_prefix . "Competency"] ?? 0);

                                    $chip_class = $total_weight > 100
                                        ? "over"
                                        : ($total_weight == 100 ? "full" : "");
                                    ?>

                                    <div class="group-weights">

                                        <span class="weight-chip <?= $chip_class ?>">

                                            Weight รวม

                                            <strong><?= number_format($total_weight) ?>%</strong>

                                            <small>/ 100%</small>

                                        </span>

                                    </div>

                                </div>

                            </td>

                        </tr>


                        <!-- =========================================
                             KPI ROWS
                        ========================================== -->

                        <?php foreach ($group["kpis"] as $index => $assignment): ?>

                            <?php
                            $is_performance =
                                strtolower($assignment["kpi_type"]) === "performance";

                            $period_label = $filter_has_month
                                ? monthlyPeriodMonths()[$filter_month]
                                : assignmentMonthRangeLabel(
                                    $assignment["start_date"],
                                    $assignment["end_date"]
                                );

                            $quarter_label = $filter_has_month
                                ? getQuarterByMonth($filter_month)
                                : assignmentQuarterRangeLabel(
                                    $assignment["start_date"],
                                    $assignment["end_date"]
                                );
                            ?>

                            <tr class="<?= $assignment["status"] === "Active" ? "" : "row-inactive" ?>">

                                <td class="col-index">
                                    <?= $index + 1 ?>
                                </td>


                                <td>

                                    <div class="kpi-name">
                                        <?= htmlspecialchars($assignment["kpi_name"]) ?>
                                    </div>

                                    <div class="kpi-unit">
                                        หน่วย: <?= htmlspecialchars($assignment["unit"] ?? "-") ?>
                                    </div>

                                </td>


                                <td>

                                    <span class="badge badge-type <?= $is_performance ? "performance" : "" ?>">
                                        <?= htmlspecialchars($assignment["kpi_type"]) ?>
                                    </span>

                                </td>


                                <td class="col-number">

                                    <?= number_format($assignment["target_value"]) ?>%

                                </td>


                                <td class="col-number">

                                    <strong><?= number_format($assignment["weight"]) ?>%</strong>

                                </td>


                                <td>

                                    <div class="period-main">

                                        <?= htmlspecialchars($period_label) ?>
                                        <?= (int) $assignment["assignment_year"] ?>

                                        <span class="badge badge-quarter">
                                            <?= htmlspecialchars($quarter_label) ?>
                                        </span>

                                    </div>

                                    <div class="period-dates">

                                        <?= date("d/m/Y", strtotime($assignment["start_date"])) ?>
                                        –
                                        <?= date("d/m/Y", strtotime($assignment["end_date"])) ?>

                                    </div>

                                </td>


                                <td>

                                    <?php if ($assignment["status"] === "Active"): ?>

                                        <span class="badge badge-active">Active</span>

                                    <?php else: ?>

                                        <span class="badge badge-inactive">Inactive</span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <div class="action-buttons">

                                        <a
                                            href="kpi-assignment/kpi-assignment-edit.php?id=<?= (int) $assignment["assignment_id"] ?>"
                                            class="btn-small btn-edit">
                                            แก้ไข
                                        </a>

                                        <a
                                            href="kpi-assignment/kpi-assignment-delete.php?id=<?= (int) $assignment["assignment_id"] ?>"
                                            class="btn-small btn-delete"
                                            onclick="return confirm('คุณต้องการลบ KPI Assignment นี้หรือไม่?')">
                                            ลบ
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


</div>


<script>


/* =========================================================
   FORM ELEMENTS
========================================================= */

const yearInput =
    document.getElementById(
        "assignment_year"
    );

const departmentSelect =
    document.getElementById(
        "department_select"
    );

const employeeSelect =
    document.getElementById(
        "employee_select"
    );

const selectedCount =
    document.getElementById(
        "selected_count"
    );

/* =========================================================
   DEPARTMENT -> EMPLOYEE FILTER
========================================================= */

function filterEmployees() {

    const departmentId =
        departmentSelect.value;


    const options =
        employeeSelect.querySelectorAll(
            "option"
        );


    options.forEach(
        function (option) {

            option.selected = false;

        }
    );


    options.forEach(
        function (option) {

            const employeeDepartment =
                option.dataset.department;


            if (
                departmentId === "" ||
                employeeDepartment ===
                    departmentId
            ) {

                option.hidden = false;

                option.style.display = "";

            } else {

                option.hidden = true;

                option.style.display = "none";
            }

        }
    );


    updateSelectedCount();
}


departmentSelect.addEventListener(
    "change",
    filterEmployees
);


/* =========================================================
   COUNT SELECTED EMPLOYEES
========================================================= */

function updateSelectedCount() {

    const selected =
        employeeSelect.querySelectorAll(
            "option:checked"
        );


    selectedCount.textContent =
        "เลือกพนักงานแล้ว "
        + selected.length
        + " คน";
}


employeeSelect.addEventListener(
    "change",
    updateSelectedCount
);


/* =========================================================
   RESET FORM
========================================================= */

document
    .getElementById(
        "assignmentForm"
    )
    .addEventListener(
        "reset",
        function () {

            setTimeout(
                function () {

                    departmentSelect.value =
                        "";

                    filterEmployees();

                    updateSelectedCount();

                },
                0
            );

        }
    );


/* =========================================================
   FILTER PAGE EMPLOYEE
========================================================= */

const filterDepartment =
    document.getElementById(
        "filter_department"
    );

const filterEmployee =
    document.getElementById(
        "filter_employee"
    );


function filterEmployeeList() {

    const departmentId =
        filterDepartment.value;


    const options =
        filterEmployee.querySelectorAll(
            "option"
        );


    options.forEach(
        function (option) {

            if (
                option.value === "0"
            ) {

                option.hidden = false;

                return;
            }


            const employeeDepartment =
                option.dataset.department;


            if (
                departmentId === "0" ||
                employeeDepartment ===
                    departmentId
            ) {

                option.hidden = false;

            } else {

                option.hidden = true;

                if (
                    option.selected
                ) {

                    filterEmployee.value =
                        "0";
                }

            }

        }
    );
}


filterDepartment.addEventListener(
    "change",
    filterEmployeeList
);


/* =========================================================
   INITIAL
========================================================= */


updateSelectedCount();

filterEmployeeList();

</script>
