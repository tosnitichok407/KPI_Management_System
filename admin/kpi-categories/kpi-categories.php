<?php

session_start();

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| Admin Access Check
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
| Delete Category
|--------------------------------------------------------------------------
*/

if (isset($_GET["delete"])) {

    $category_id = (int) $_GET["delete"];

    if ($category_id > 0) {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check whether category is being used
            |--------------------------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM kpi_indicators
                WHERE category_id = :category_id
            ");

            $check->execute([
                ":category_id" => $category_id
            ]);

            $count = (int) $check->fetchColumn();


            if ($count > 0) {

                $_SESSION["category_error"] =
                    "ไม่สามารถลบ Category นี้ได้ เนื่องจากมี KPI Indicator ใช้งานอยู่";
            } else {

                $delete = $pdo->prepare("
                    DELETE FROM kpi_categories
                    WHERE category_id = :category_id
                ");

                $delete->execute([
                    ":category_id" => $category_id
                ]);

                $_SESSION["category_success"] =
                    "ลบ KPI Category เรียบร้อยแล้ว";
            }
        } catch (PDOException $e) {

            $_SESSION["category_error"] =
                "ไม่สามารถลบ KPI Category ได้";
        }
    }

    header("Location: kpi-categories.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->query("
        SELECT
            c.category_id,
            c.category_name,
            c.description,
            COUNT(k.kpi_id) AS kpi_count

        FROM kpi_categories c

        LEFT JOIN kpi_indicators k
            ON c.category_id = k.category_id

        GROUP BY
            c.category_id,
            c.category_name,
            c.description

        ORDER BY c.category_id ASC
    ");

    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

    $categories = [];

    $_SESSION["category_error"] =
        "ไม่สามารถโหลดข้อมูล KPI Category ได้";
}


/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$success = $_SESSION["category_success"] ?? "";
$error = $_SESSION["category_error"] ?? "";

unset($_SESSION["category_success"]);
unset($_SESSION["category_error"]);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>KPI Category Management</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../../assets/css/variables.css">

    <link
        rel="stylesheet"
        href="../../assets/css/responsive.css">

    <link
        rel="stylesheet"
        href="../../assets/css/kpi.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Kanit", sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .page-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px;
        }


        /* =========================================================
   HEADER
========================================================= */

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


        /* =========================================================
   BUTTON
========================================================= */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 10px 18px;

            border-radius: 8px;

            text-decoration: none;

            border: none;

            font-family: inherit;
            font-size: 15px;

            cursor: pointer;
        }

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
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-edit {
            background: #e8eefc;
            color: #243f8f;
        }

        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px 18px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }

        tr:hover {
            background: #f9fafb;
        }

        .category-id {
            font-weight: 600;
            color: #243f8f;
        }

        .kpi-count {
            display: inline-block;
            padding: 4px 10px;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 20px;
            font-size: 13px;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .empty {
            text-align: center;
            padding: 50px;
            color: #6b7280;
        }

        @media (max-width: 768px) {

            .page-container {
                padding: 15px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .actions {
                flex-direction: column;
            }

        }
    </style>

</head>

<body>

    <div class="page-container">

        <!-- === PAGE HEADER === -->

        <div class="page-header">
            <div class="page-title">

                <h1>
                    KPI Category Management
                </h1>

                <p>
                    จัดการหมวดหมู่ของตัวชี้วัด KPI
                </p>

            </div>
            <div class="header-actions">

                <a
                    href="../index.php"
                    class="btn btn-secondary">
                    Dashboard
                </a>

                <a
                    href="kpi-category-add.php"
                    class="btn btn-primary">
                    + Add Category
                </a>

            </div>

        </div>


        <!-- =========================================================
         SUCCESS MESSAGE
    ========================================================== -->

        <?php if ($success !== ""): ?>

            <div class="alert alert-success">

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =========================================================
         ERROR MESSAGE
    ========================================================== -->

        <?php if ($error !== ""): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =========================================================
         CATEGORY TABLE
    ========================================================== -->

        <div class="card">

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                ID
                            </th>

                            <th>
                                Category Name
                            </th>

                            <th>
                                Description
                            </th>

                            <th>
                                KPI Indicators
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (count($categories) > 0): ?>

                            <?php foreach ($categories as $category): ?>

                                <tr>

                                    <td class="category-id">

                                        #<?= (int) $category["category_id"] ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $category["category_name"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </td>


                                    <td>

                                        <?php

                                        $description =
                                            $category["description"];

                                        if (
                                            $description === null ||
                                            trim($description) === ""
                                        ) {

                                            echo "-";
                                        } else {

                                            echo htmlspecialchars(
                                                $description,
                                                ENT_QUOTES,
                                                "UTF-8"
                                            );
                                        }

                                        ?>

                                    </td>


                                    <td>

                                        <span class="kpi-count">

                                            <?= (int) $category["kpi_count"] ?>

                                            KPI

                                        </span>

                                    </td>


                                    <td>

                                        <div class="actions">

                                            <a
                                                href="kpi-category-edit.php?id=<?= (int) $category["category_id"] ?>"
                                                class="btn btn-edit">
                                                Edit
                                            </a>


                                            <a
                                                href="kpi-categories.php?delete=<?= (int) $category["category_id"] ?>"
                                                class="btn btn-delete"
                                                onclick="return confirm('ต้องการลบ KPI Category นี้หรือไม่?');">
                                                Delete
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="empty">

                                    ยังไม่มี KPI Category

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
