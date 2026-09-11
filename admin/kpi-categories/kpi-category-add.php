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
| Variables
|--------------------------------------------------------------------------
*/

$error = "";
$category_name = "";
$description = "";


/*
|--------------------------------------------------------------------------
| Add KPI Category
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | Get Form Data
    |--------------------------------------------------------------------------
    */

    $category_name = trim(
        $_POST["category_name"] ?? ""
    );

    $description = trim(
        $_POST["description"] ?? ""
    );


    /*
    |--------------------------------------------------------------------------
    | Validate Category Name
    |--------------------------------------------------------------------------
    */

    if ($category_name === "") {

        $error = "กรุณากรอกชื่อหมวดหมู่ KPI";

    } elseif (mb_strlen($category_name) > 100) {

        $error = "ชื่อหมวดหมู่ KPI ต้องไม่เกิน 100 ตัวอักษร";

    } elseif (mb_strlen($description) > 255) {

        $error = "รายละเอียดต้องไม่เกิน 255 ตัวอักษร";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Category
        |--------------------------------------------------------------------------
        |
        | category_name ใน DB ปัจจุบันยังไม่ได้ตั้ง UNIQUE
        | ดังนั้นตรวจสอบซ้ำในระบบก่อน INSERT
        |
        */

        try {

            $check = $pdo->prepare("
                SELECT category_id
                FROM kpi_categories
                WHERE category_name = :category_name
                LIMIT 1
            ");

            $check->execute([
                ":category_name" => $category_name
            ]);

            $existing = $check->fetch(PDO::FETCH_ASSOC);


            /*
            |--------------------------------------------------------------------------
            | Duplicate Category
            |--------------------------------------------------------------------------
            */

            if ($existing) {

                $error =
                    "มีหมวดหมู่ KPI ชื่อนี้อยู่แล้ว";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Insert Category
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO kpi_categories
                    (
                        category_name,
                        description
                    )
                    VALUES
                    (
                        :category_name,
                        :description
                    )
                ");

                $stmt->execute([
                    ":category_name" => $category_name,
                    ":description" =>
                        $description !== ""
                            ? $description
                            : null
                ]);


                /*
                |--------------------------------------------------------------------------
                | Success
                |--------------------------------------------------------------------------
                */

                $_SESSION["category_success"] =
                    "เพิ่ม KPI Category เรียบร้อยแล้ว";


                /*
                |--------------------------------------------------------------------------
                | Redirect
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: ../index.php?page=kpi-categories"
                );

                exit;
            }

        } catch (PDOException $e) {

            $error =
                "ไม่สามารถเพิ่ม KPI Category ได้ กรุณาลองใหม่อีกครั้ง";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Form
|--------------------------------------------------------------------------
*/

$formAction = "kpi-category-add.php";
$submitLabel = "+ เพิ่มหมวดหมู่ KPI";

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
        Add KPI Category
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
                Add KPI Category
            </h1>

            <p>
                เพิ่มหมวดหมู่สำหรับ KPI Indicator
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


<script src="../../assets/js/admin.js?v=scroll-2"></script>

</body>

</html>
