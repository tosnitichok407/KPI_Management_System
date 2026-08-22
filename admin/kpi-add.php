<?php

session_start();

require_once "../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
    exit;
}

$error = "";
$success = "";


/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        category_id,
        category_name
    FROM kpi_categories
    ORDER BY category_name
");

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Add KPI
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $category_id = $_POST["category_id"] ?? "";
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = $_POST["weight"] ?? "";
    $unit = trim($_POST["unit"] ?? "");
    $max_score = $_POST["max_score"] ?? 5;


    if (
        $category_id === "" ||
        $kpi_name === "" ||
        $weight === ""
    ) {

        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";
    } else {

        try {

            $sql = "
                INSERT INTO kpi_indicators
                (
                    category_id,
                    kpi_name,
                    description,
                    weight,
                    unit,
                    max_score
                )
                VALUES
                (
                    :category_id,
                    :kpi_name,
                    :description,
                    :weight,
                    :unit,
                    :max_score
                )
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ":category_id" => $category_id,
                ":kpi_name" => $kpi_name,
                ":description" => $description !== ""
                    ? $description
                    : null,
                ":weight" => $weight,
                ":unit" => $unit !== ""
                    ? $unit
                    : null,
                ":max_score" => $max_score
            ]);


            $kpi_id = $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Create Default Score Criteria
            |--------------------------------------------------------------------------
            */

            $criteria = [
                5 => "",
                4 => "",
                3 => "",
                2 => "",
                1 => ""
            ];


            foreach ($criteria as $level => $text) {

                $stmt = $pdo->prepare("
                    INSERT INTO kpi_score_criteria
                    (
                        kpi_id,
                        score_level,
                        criteria
                    )
                    VALUES
                    (
                        :kpi_id,
                        :score_level,
                        :criteria
                    )
                ");

                $stmt->execute([
                    ":kpi_id" => $kpi_id,
                    ":score_level" => $level,
                    ":criteria" => $text
                ]);
            }


            header(
                "Location: kpi-management.php"
            );

            exit;
        } catch (PDOException $e) {

            $error = "ไม่สามารถเพิ่ม KPI ได้: " . $e->getMessage();
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
        content="width=device-width, initial-scale=1.0">

    <title>เพิ่ม KPI</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../assets/css/kpi.css">

    <style>
        body {
            margin: 0;
            background: #f5f7fb;
            font-family: "Kanit", sans-serif;
        }

        .container {
            width: 90%;
            max-width: 900px;
            margin: 40px auto;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, .06);
        }

        h1 {
            margin-top: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 500;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-family: inherit;
            font-size: 15px;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            border: none;
            padding: 11px 20px;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            font-size: 15px;
        }

        .btn-primary {
            background: #24449b;
            color: white;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
        }
    </style>

</head>

<body>

    <div class="container">

        <div class="card">

            <h1>
                เพิ่ม KPI
            </h1>

            <p>
                เพิ่มตัวชี้วัดผลการปฏิบัติงาน
            </p>


            <?php if ($error !== ""): ?>

                <div class="error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <form method="POST">


                <div class="form-group">

                    <label>
                        หมวดหมู่ KPI *
                    </label>

                    <select
                        name="category_id"
                        required>

                        <option value="">
                            -- เลือกหมวดหมู่ --
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?= $category["category_id"] ?>">

                                <?= htmlspecialchars(
                                    $category["category_name"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        ชื่อ KPI *
                    </label>

                    <input
                        type="text"
                        name="kpi_name"
                        placeholder="เช่น บรรลุเป้าหมายการต่อสัญญา"
                        required>

                </div>


                <div class="form-group">

                    <label>
                        รายละเอียด
                    </label>

                    <textarea
                        name="description"
                        placeholder="รายละเอียดของ KPI"></textarea>

                </div>


                <div class="form-group">

                    <label>
                        Weight *
                    </label>

                    <input
                        type="number"
                        name="weight"
                        step="0.01"
                        min="0"
                        required>

                </div>


                <div class="form-group">

                    <label>
                        หน่วยวัด
                    </label>

                    <input
                        type="text"
                        name="unit"
                        placeholder="เช่น %, ราย, บาท">

                </div>


                <div class="form-group">

                    <label>
                        คะแนนสูงสุด
                    </label>

                    <input
                        type="number"
                        name="max_score"
                        value="5"
                        min="1"
                        max="5">

                </div>


                <button
                    type="submit"
                    class="btn btn-primary">
                    บันทึก KPI
                </button>


                <a
                    href="kpi-management.php"
                    class="btn btn-secondary">
                    ยกเลิก
                </a>

            </form>

        </div>

    </div>

</body>

</html>
