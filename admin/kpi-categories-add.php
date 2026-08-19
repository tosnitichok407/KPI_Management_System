<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Access Check
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

        $error = "Please enter KPI Category Name.";

    } elseif (mb_strlen($category_name) > 100) {

        $error = "KPI Category Name must not exceed 100 characters.";

    } elseif (mb_strlen($description) > 255) {

        $error = "Description must not exceed 255 characters.";

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
                    "This KPI Category already exists.";

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
                    "KPI Category added successfully.";


                /*
                |--------------------------------------------------------------------------
                | Redirect
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: kpi-categories.php"
                );

                exit;
            }

        } catch (PDOException $e) {

            $error =
                "Unable to add KPI Category. Please try again.";
        }
    }
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

    <title>
        Add KPI Category
    </title>


    <!-- Google Font -->

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >


    <!-- Existing CSS -->

    <link
        rel="stylesheet"
        href="../assets/css/variables.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/responsive.css"
    >


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

            padding: 30px;

            max-width: 900px;

            margin: 0 auto;
        }


        /*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */

        .page-header {

            margin-bottom: 25px;
        }


        .page-header h1 {

            margin: 0;

            font-size: 30px;

            color: #1f2937;
        }


        .page-header p {

            margin: 5px 0 0;

            color: #6b7280;
        }


        /*
        |--------------------------------------------------------------------------
        | Card
        |--------------------------------------------------------------------------
        */

        .card {

            background: #ffffff;

            border-radius: 12px;

            padding: 30px;

            box-shadow:
                0 2px 10px rgba(0, 0, 0, 0.05);
        }


        /*
        |--------------------------------------------------------------------------
        | Error
        |--------------------------------------------------------------------------
        */

        .alert-error {

            background: #fee2e2;

            color: #991b1b;

            padding: 14px 18px;

            border-radius: 8px;

            margin-bottom: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | Form
        |--------------------------------------------------------------------------
        */

        .form-group {

            margin-bottom: 20px;
        }


        .form-group label {

            display: block;

            margin-bottom: 7px;

            font-weight: 500;

            color: #374151;
        }


        .required {

            color: #dc2626;
        }


        .form-control {

            width: 100%;

            padding: 12px 14px;

            border: 1px solid #d1d5db;

            border-radius: 8px;

            font-family: "Kanit", sans-serif;

            font-size: 15px;

            outline: none;

            transition: 0.2s;
        }


        .form-control:focus {

            border-color: #243f8f;

            box-shadow:
                0 0 0 3px rgba(36, 63, 143, 0.1);
        }


        textarea.form-control {

            min-height: 120px;

            resize: vertical;
        }


        .form-help {

            margin-top: 5px;

            font-size: 13px;

            color: #6b7280;
        }


        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        .form-actions {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-top: 30px;

            padding-top: 20px;

            border-top: 1px solid #e5e7eb;
        }


        .btn {

            display: inline-block;

            padding: 11px 20px;

            border-radius: 8px;

            border: none;

            text-decoration: none;

            font-family: "Kanit", sans-serif;

            font-size: 15px;

            font-weight: 500;

            cursor: pointer;
        }


        .btn-primary {

            background: #243f8f;

            color: #ffffff;
        }


        .btn-primary:hover {

            background: #1d3477;
        }


        .btn-secondary {

            background: #e5e7eb;

            color: #374151;
        }


        .btn-secondary:hover {

            background: #d1d5db;
        }


        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 768px) {

            .page-container {

                padding: 15px;
            }


            .card {

                padding: 20px;
            }


            .form-actions {

                flex-direction: column-reverse;

                align-items: stretch;

                gap: 10px;
            }


            .form-actions .btn {

                width: 100%;

                text-align: center;
            }

        }

    </style>

</head>


<body>


<div class="page-container">


    <!-- =========================================================
         PAGE HEADER
    ========================================================== -->

    <div class="page-header">

        <h1>
            Add KPI Category
        </h1>

        <p>
            เพิ่มหมวดหมู่สำหรับ KPI Indicator
        </p>

    </div>


    <!-- =========================================================
         FORM CARD
    ========================================================== -->

    <div class="card">


        <!-- =====================================================
             ERROR MESSAGE
        ====================================================== -->

        <?php if ($error !== ""): ?>

            <div
                class="alert-error"
                role="alert"
            >

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FORM
        ====================================================== -->

        <form
            method="POST"
            action="kpi-categories-add.php"
        >


            <!-- =================================================
                 Category Name
            ================================================== -->

            <div class="form-group">

                <label for="category_name">

                    Category Name

                    <span class="required">
                        *
                    </span>

                </label>


                <input
                    type="text"
                    id="category_name"
                    name="category_name"
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $category_name,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    maxlength="100"
                    placeholder="เช่น Sales Performance"
                    required
                    autofocus
                >


                <div class="form-help">

                    ชื่อหมวดหมู่ KPI ความยาวไม่เกิน 100 ตัวอักษร

                </div>

            </div>


            <!-- =================================================
                 Description
            ================================================== -->

            <div class="form-group">

                <label for="description">

                    Description

                </label>


                <textarea
                    id="description"
                    name="description"
                    class="form-control"
                    maxlength="255"
                    placeholder="รายละเอียดของหมวดหมู่ KPI"
                ><?= htmlspecialchars(
                    $description,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?></textarea>


                <div class="form-help">

                    รายละเอียดเพิ่มเติม ความยาวไม่เกิน 255 ตัวอักษร

                </div>

            </div>


            <!-- =================================================
                 Buttons
            ================================================== -->

            <div class="form-actions">


                <a
                    href="kpi-categories.php"
                    class="btn btn-secondary"
                >
                    Cancel
                </a>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    + Add KPI Category
                </button>


            </div>


        </form>


    </div>


</div>


</body>

</html>