<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Employee
|--------------------------------------------------------------------------
*/

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: login.php");
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
        a.weight,
        a.status AS assignment_status,

        k.kpi_name,
        k.description,
        k.unit,
        k.max_score,

        ep.period_name,
        ep.start_date,
        ep.end_date,
        ep.status AS period_status

    FROM kpi_assignments a

    INNER JOIN kpi_indicators k
        ON a.kpi_id = k.kpi_id

    INNER JOIN evaluation_periods ep
        ON a.period_id = ep.period_id

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


/*
|--------------------------------------------------------------------------
| Add Performance
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $performanceDate =
        $_POST["performance_date"] ?? "";

    $target =
        $_POST["target"] ?? "";

    $actual =
        $_POST["actual"] ?? "";

    $comment =
        trim($_POST["comment"] ?? "");


    /*
    --------------------------------------------------------------
    Validation
    --------------------------------------------------------------
    */

    if ($performanceDate === "") {

        $error =
            "กรุณาเลือกวันที่";
    } elseif ($target === "") {

        $error =
            "กรุณากรอก Target";
    } elseif ($actual === "") {

        $error =
            "กรุณากรอก Actual";
    } elseif (!is_numeric($target)) {

        $error =
            "Target ต้องเป็นตัวเลข";
    } elseif (!is_numeric($actual)) {

        $error =
            "Actual ต้องเป็นตัวเลข";
    } else {


        /*
        ----------------------------------------------------------
        Calculate Score
        ----------------------------------------------------------
        */

        $targetValue =
            (float) $target;

        $actualValue =
            (float) $actual;


        if ($targetValue <= 0) {

            $error =
                "Target ต้องมากกว่า 0";
        } else {


            /*
            ------------------------------------------------------
            Score Formula
            ------------------------------------------------------

            Actual / Target × 5

            Maximum = 5
            ------------------------------------------------------
            */

            $score =
                ($actualValue / $targetValue) * 5;


            if ($score > 5) {
                $score = 5;
            }


            if ($score < 0) {
                $score = 0;
            }


            $score =
                round($score, 2);


            /*
            ------------------------------------------------------
            Check Duplicate
            ------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT performance_id

                FROM kpi_performances

                WHERE assignment_id = :assignment_id

                AND performance_date = :performance_date

                LIMIT 1
            ");


            $check->execute([

                ":assignment_id" =>
                $assignmentId,

                ":performance_date" =>
                $performanceDate

            ]);


            if ($check->fetch()) {

                $error =
                    "วันที่นี้มีการบันทึกผลงานแล้ว";
            } else {


                /*
                --------------------------------------------------
                Insert
                --------------------------------------------------
                */

                try {

                    $insert = $pdo->prepare("

                        INSERT INTO kpi_performances

                        (
                            assignment_id,
                            employee_id,
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
                            :performance_date,
                            :target,
                            :actual,
                            :score,
                            :comment,
                            'Submitted'
                        )

                    ");


                    $insert->execute([

                        ":assignment_id" =>
                        $assignmentId,

                        ":employee_id" =>
                        $employeeId,

                        ":performance_date" =>
                        $performanceDate,

                        ":target" =>
                        $targetValue,

                        ":actual" =>
                        $actualValue,

                        ":score" =>
                        $score,

                        ":comment" =>
                        $comment !== ""
                            ? $comment
                            : null

                    ]);


                    $success =
                        "บันทึกผลงานเรียบร้อยแล้ว";
                } catch (PDOException $e) {

                    $error =
                        "ไม่สามารถบันทึกผลงานได้";
                }
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

    <link
        rel="stylesheet"
        href="../assets/css/employee-kpi.css">

    <style>
        body {

            margin: 0;

            font-family: Kanit, sans-serif;

            background: #f5f7fb;

            color: #111827;

        }


        .container {

            max-width: 1100px;

            margin: 40px auto;

            padding: 0 20px;

        }


        .back {

            display: inline-block;

            margin-bottom: 20px;

            color: #1e3a8a;

            text-decoration: none;

        }


        .header-card {

            background: white;

            border-radius: 12px;

            padding: 25px;

            box-shadow:
                0 3px 12px rgba(0, 0, 0, .06);

            margin-bottom: 20px;

        }


        .header-top {

            display: flex;

            justify-content: space-between;

            gap: 20px;

        }


        .header-card h1 {

            margin: 0;

            font-size: 28px;

        }


        .description {

            margin-top: 8px;

            color: #6b7280;

        }


        .weight {

            background: #eff6ff;

            color: #1e3a8a;

            padding: 8px 14px;

            border-radius: 20px;

        }


        .info-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 15px;

            margin-top: 25px;

        }


        .info-box {

            background: #f8fafc;

            padding: 15px;

            border-radius: 8px;

        }


        .info-box span {

            display: block;

            color: #6b7280;

            font-size: 13px;

        }


        .info-box strong {

            display: block;

            margin-top: 5px;

        }


        .card {

            background: white;

            border-radius: 12px;

            padding: 25px;

            box-shadow:
                0 3px 12px rgba(0, 0, 0, .06);

            margin-bottom: 20px;

        }


        .card h2 {

            margin-top: 0;

        }


        .form-grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 18px;

        }


        .form-group {

            margin-bottom: 15px;

        }


        label {

            display: block;

            margin-bottom: 6px;

            font-weight: 500;

        }


        input,
        textarea {

            width: 100%;

            box-sizing: border-box;

            padding: 11px;

            border: 1px solid #d1d5db;

            border-radius: 7px;

            font-family: inherit;

            font-size: 15px;

        }


        textarea {

            min-height: 100px;

            resize: vertical;

        }


        .full {

            grid-column: 1 / -1;

        }


        .submit-button {

            background: #1e3a8a;

            color: white;

            border: none;

            padding: 11px 22px;

            border-radius: 7px;

            font-family: inherit;

            cursor: pointer;

        }


        .submit-button:hover {

            background: #172e6d;

        }


        .alert {

            padding: 12px 15px;

            border-radius: 7px;

            margin-bottom: 20px;

        }


        .error {

            background: #fee2e2;

            color: #991b1b;

        }


        .success {

            background: #dcfce7;

            color: #166534;

        }

        .score {

            font-weight: 700;

            color: #1e3a8a;

        }


        .status {

            padding: 5px 10px;

            border-radius: 15px;

            font-size: 12px;

            background: #eff6ff;

            color: #1e3a8a;

        }


        @media (max-width: 700px) {

            .info-grid,
            .form-grid {

                grid-template-columns: 1fr;

            }


            .header-top {

                flex-direction: column;

            }

        }
    </style>

</head>


<body>


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


                <div class="form-grid">


                    <div class="form-group">

                        <label>
                            วันที่
                        </label>

                        <input
                            type="date"
                            name="performance_date"
                            required>

                    </div>


                    <div class="form-group">

                        <label>
                            Target
                        </label>

                        <input
                            type="number"
                            name="target"
                            step="0.01"
                            min="0"
                            placeholder="เช่น 100000"
                            required>

                    </div>


                    <div class="form-group">

                        <label>
                            Actual
                        </label>

                        <input
                            type="number"
                            name="actual"
                            step="0.01"
                            min="0"
                            placeholder="เช่น 95000"
                            required>

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

                    บันทึกผลงาน

                </button>

                <a
                    href="../employee/performance.php"
                    style="margin-left: 5px;"
                    class="btn btn-secondary">

                    ย้อนกลับ

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

                                        <?= number_format(
                                            (float)
                                            $performance["target"],
                                            2
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

                                        <?= number_format(
                                            (float)
                                            $performance["score"],
                                            2
                                        ) ?>

                                        / 5

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


</body>

</html>