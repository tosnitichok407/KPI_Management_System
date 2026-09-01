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
                    + เพิ่มหมวดหมู่ KPI
                </a>
            </div>

        </div>

        <!-- === SUCCESS MESSAGE === -->

        <?php if ($success !== ""): ?>

            <div class="alert alert-success">

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <!-- === ERROR MESSAGE === -->
        <?php if ($error !== ""): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <!-- === CATEGORY TABLE === -->

        <div class="table-card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>
                                ID
                            </th>

                            <th>
                                หมวดหมู่
                            </th>

                            <th>
                                รายละเอียด
                            </th>

                            <th>
                                จำนวน KPI
                            </th>

                            <th>
                                จัดการ
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
                                                class="btn-small edit">
                                                แก้ไข
                                            </a>

                                            <a
                                                href="kpi-categories.php?delete=<?= (int) $category["category_id"] ?>"
                                                class="btn-small danger"
                                                onclick="return confirm('ต้องการลบ KPI Category นี้หรือไม่?');">
                                                ลบ
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