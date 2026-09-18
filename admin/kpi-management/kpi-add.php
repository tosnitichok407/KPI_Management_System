<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
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

        $category_id = trim($_POST["category_id"] ?? "");
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = trim($_POST["weight"] ?? "");
    $unit = trim($_POST["unit"] ?? "");
    $max_score = trim($_POST["max_score"] ?? "5");

    if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif (
        $category_id === "" ||
        $kpi_name === "" ||
        $weight === ""
    ) {

        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";
    } elseif (!ctype_digit($category_id)) {

        $error = "หมวดหมู่ KPI ไม่ถูกต้อง";
    } elseif (mb_strlen($kpi_name) > 255 || mb_strlen($unit) > 50) {

        $error = "ข้อมูลยาวเกินกำหนด";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {

        $error = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    } elseif ($max_score === "" || !is_numeric($max_score) || $max_score < 1 || $max_score > 5) {

        $error = "คะแนนสูงสุดต้องเป็นตัวเลขระหว่าง 1 - 5";
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
                "Location: ../index.php?page=kpi-management"
            );

            exit;
        } catch (PDOException $e) {

                        error_log("Add KPI failed: " . $e->getMessage());

            $error = "ไม่สามารถเพิ่ม KPI ได้ กรุณาลองใหม่อีกครั้ง";
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
        href="../../assets/css/kpi.css?v=category-form-1">

    <link
        rel="stylesheet"
        href="../../assets/css/admin-kpi-add.css">

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

                <?= csrfField() ?>

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
                                value="<?= (int) $category["category_id"] ?>">

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
                    href="../index.php?page=kpi-management"
                    class="btn btn-secondary">
                    ยกเลิก
                </a>

            </form>

        </div>

    </div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
