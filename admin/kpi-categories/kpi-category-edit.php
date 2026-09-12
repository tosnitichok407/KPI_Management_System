<?php

session_start();

require_once __DIR__ . "/../../config/database.php";


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
| Get Category
|--------------------------------------------------------------------------
*/

$category_id = (int) ($_GET["id"] ?? 0);

if ($category_id <= 0) {

    header("Location: ../index.php?page=kpi-categories");
    exit;
}

try {

    $stmt = $pdo->prepare("
        SELECT
            category_id,
            category_name,
            description
        FROM kpi_categories
        WHERE category_id = :category_id
        LIMIT 1
    ");

    $stmt->execute([
        ":category_id" => $category_id
    ]);

    $category = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$category) {

        $_SESSION["category_error"] =
            "ไม่พบ KPI Category ที่ต้องการแก้ไข";

        header("Location: ../index.php?page=kpi-categories");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | KPI ที่ใช้หมวดหมู่นี้ (แสดงให้เห็นผลกระทบก่อนแก้)
    |--------------------------------------------------------------------------
    */

    $kpiStmt = $pdo->prepare("
        SELECT kpi_name, kpi_type
        FROM kpi_indicators
        WHERE category_id = :category_id
        ORDER BY kpi_type, kpi_name
    ");

    $kpiStmt->execute([
        ":category_id" => $category_id
    ]);

    $usedKpis = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    die("Database error.");
}


/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$error = "";
$category_name = $category["category_name"];
$description = $category["description"] ?? "";


/*
|--------------------------------------------------------------------------
| Update KPI Category
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $category_name = trim(
        $_POST["category_name"] ?? ""
    );

    $description = trim(
        $_POST["description"] ?? ""
    );


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if ($category_name === "") {

        $error = "กรุณากรอกชื่อหมวดหมู่ KPI";

    } elseif (mb_strlen($category_name) > 100) {

        $error = "ชื่อหมวดหมู่ KPI ต้องไม่เกิน 100 ตัวอักษร";

    } elseif (mb_strlen($description) > 255) {

        $error = "รายละเอียดต้องไม่เกิน 255 ตัวอักษร";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check Duplicate (ไม่นับตัวเอง)
            |--------------------------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT category_id
                FROM kpi_categories
                WHERE category_name = :category_name
                  AND category_id <> :category_id
                LIMIT 1
            ");

            $check->execute([
                ":category_name" => $category_name,
                ":category_id" => $category_id
            ]);


            if ($check->fetch(PDO::FETCH_ASSOC)) {

                $error = "มีหมวดหมู่ KPI ชื่อนี้อยู่แล้ว";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Update
                |--------------------------------------------------------------------------
                */

                $update = $pdo->prepare("
                    UPDATE kpi_categories
                    SET
                        category_name = :category_name,
                        description = :description
                    WHERE category_id = :category_id
                ");

                $update->execute([
                    ":category_name" => $category_name,
                    ":description" =>
                        $description !== ""
                            ? $description
                            : null,
                    ":category_id" => $category_id
                ]);


                $_SESSION["category_success"] =
                    "แก้ไข KPI Category เรียบร้อยแล้ว";

                header(
                    "Location: ../index.php?page=kpi-categories"
                );

                exit;
            }

        } catch (PDOException $e) {

            $error =
                "ไม่สามารถแก้ไข KPI Category ได้ กรุณาลองใหม่อีกครั้ง";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Form
|--------------------------------------------------------------------------
*/

$formAction = "kpi-category-edit.php?id=" . $category_id;
$submitLabel = "บันทึกการแก้ไข";

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Edit KPI Category
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/kpi.css?v=category-form-1"
    >

</head>


<body>


<div class="page-container category-page">


    <!-- =========================================================
         PAGE HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                Edit KPI Category
            </h1>

            <p>
                แก้ไขหมวดหมู่
                <?= htmlspecialchars($category["category_name"], ENT_QUOTES, "UTF-8") ?>
                · ID <?= (int) $category_id ?>
            </p>

        </div>

    </header>


    <!-- =========================================================
         FORM CARD
    ========================================================== -->

    <div class="category-card">


        <?php if ($error !== ""): ?>

            <div
                class="alert alert-error"
                role="alert"
            >

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <?php include __DIR__ . "/kpi-category-form.php"; ?>


    </div>


</div>


<script src="../../assets/js/admin.js?v=scroll-3"></script>

</body>

</html>
