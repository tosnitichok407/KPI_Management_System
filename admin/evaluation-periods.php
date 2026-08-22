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


/*
|--------------------------------------------------------------------------
| Get Evaluation Periods
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        period_id,
        period_name,
        start_date,
        end_date,
        status

    FROM evaluation_periods

    ORDER BY start_date DESC, period_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        href="../assets/css/evaluation.css"
    >

    <title>Evaluation Period Management</title>

</head>


<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                Evaluation Period Management
            </h1>

            <p>
                จัดการรอบการประเมินผลการปฏิบัติงาน
            </p>

        </div>


        <div class="header-actions">

            <a
                href="index.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

            <a
                href="evaluation-periods-add.php"
                class="btn btn-primary"
            >
                + เพิ่มรอบการประเมิน
            </a>

        </div>

    </header>


    <!-- =========================================================
         TABLE
    ========================================================== -->

    <section class="table-card">


        <div class="table-header">

            <h2>
                Evaluation Periods
            </h2>

            <span>
                <?= count($periods) ?> periods
            </span>

        </div>


        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>
                            ID
                        </th>

                        <th>
                            รอบการประเมิน
                        </th>

                        <th>
                            วันที่เริ่มต้น
                        </th>

                        <th>
                            วันที่สิ้นสุด
                        </th>

                        <th>
                            สถานะ
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (empty($periods)): ?>

                    <tr>

                        <td
                            colspan="6"
                            class="empty-state"
                        >
                            ยังไม่มีรอบการประเมิน
                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($periods as $period): ?>

                        <tr>


                            <!-- ID -->

                            <td>

                                <?= (int) $period["period_id"] ?>

                            </td>


                            <!-- Period Name -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $period["period_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </strong>

                            </td>


                            <!-- Start Date -->

                            <td>

                                <?= htmlspecialchars(
                                    $period["start_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- End Date -->

                            <td>

                                <?= htmlspecialchars(
                                    $period["end_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- Status -->

                            <td>

                                <?php if (
                                    $period["status"] === "Open"
                                ): ?>

                                    <span class="status-open">
                                        Open
                                    </span>

                                <?php else: ?>

                                    <span class="status-closed">
                                        Closed
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Action -->

                            <td>

                                <div class="action-buttons">

                                    <a
                                        href="evaluation-periods-edit.php?id=<?= (int) $period["period_id"] ?>"
                                        class="btn-small edit"
                                    >
                                        Edit
                                    </a>

                                </div>

                            </td>


                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </section>


</div>

</body>

</html>
