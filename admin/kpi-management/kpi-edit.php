<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


/*
|--------------------------------------------------------------------------
| Admin Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
}


$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: ../index.php?page=kpi-management");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get KPI
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM kpi_indicators
    WHERE kpi_id = :id
");

$stmt->execute([
    ":id" => $id
]);

$kpi = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kpi) {
    header("Location: ../index.php?page=kpi-management");
    exit;
}


/*
|--------------------------------------------------------------------------
| Categories
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


$error = "";


/*
|--------------------------------------------------------------------------
| Update KPI
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $category_id = trim($_POST["category_id"] ?? "");
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = trim($_POST["weight"] ?? "");
    $unit = trim($_POST["unit"] ?? "");
    $max_score = trim($_POST["max_score"] ?? "");

    $score_5 = trim($_POST["score_5"] ?? "");
    $score_4 = trim($_POST["score_4"] ?? "");
    $score_3 = trim($_POST["score_3"] ?? "");
    $score_2 = trim($_POST["score_2"] ?? "");
    $score_1 = trim($_POST["score_1"] ?? "");


        if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($category_id === "" || !ctype_digit($category_id)) {

        $error = "กรุณาเลือก KPI Category";
    } elseif ($kpi_name === "") {

        $error = "กรุณากรอกชื่อ KPI";
    } elseif ($weight === "") {

        $error = "กรุณากรอก Weight";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {

        $error = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    } elseif ($max_score !== "" && (!is_numeric($max_score) || $max_score < 0)) {

        $error = "Max Score ต้องเป็นตัวเลข";
    } elseif (
        mb_strlen($kpi_name) > 255 || mb_strlen($unit) > 50 ||
        mb_strlen($score_5) > 255 || mb_strlen($score_4) > 255 || mb_strlen($score_3) > 255 ||
        mb_strlen($score_2) > 255 || mb_strlen($score_1) > 255
    ) {

        $error = "ข้อมูลยาวเกินกำหนด";
    } else {

        try {

            $sql = "
                UPDATE kpi_indicators

                SET
                    category_id = :category_id,
                    kpi_name = :kpi_name,
                    description = :description,
                    weight = :weight,
                    unit = :unit,
                    max_score = :max_score,

                    score_5 = :score_5,
                    score_4 = :score_4,
                    score_3 = :score_3,
                    score_2 = :score_2,
                    score_1 = :score_1

                WHERE kpi_id = :id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([

                ":category_id" => $category_id,
                ":kpi_name" => $kpi_name,
                ":description" => $description,
                ":weight" => $weight,
                ":unit" => $unit,
                ":max_score" => $max_score !== ""
                    ? $max_score
                    : null,

                ":score_5" => $score_5 !== ""
                    ? $score_5
                    : null,

                ":score_4" => $score_4 !== ""
                    ? $score_4
                    : null,

                ":score_3" => $score_3 !== ""
                    ? $score_3
                    : null,

                ":score_2" => $score_2 !== ""
                    ? $score_2
                    : null,

                ":score_1" => $score_1 !== ""
                    ? $score_1
                    : null,

                ":id" => $id

            ]);

            header("Location: ../index.php?page=kpi-management");
            exit;
                } catch (PDOException $e) {

            error_log("Update KPI failed: " . $e->getMessage());

            $error = "ไม่สามารถแก้ไข KPI ได้";
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

    <title>Edit KPI</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../../assets/css/kpi.css?v=category-form-1">

    <link
        rel="stylesheet"
        href="../../assets/css/admin-kpi-edit.css">

</head>

<body>

    <div class="container">

        <div class="card">

            <h1>Edit KPI</h1>

            <?php if ($error !== ""): ?>

                <div class="error">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            <?php endif; ?>


                        <form method="POST">

                <?= csrfField() ?>

                <div class="form-group">

                    <label>KPI Category</label>

                    <select name="category_id" required>

                        <option value="">
                            -- Select Category --
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?= (int) $category["category_id"] ?>"
                                <?= (
                                    $kpi["category_id"] ==
                                    $category["category_id"]
                                ) ? "selected" : "" ?>>

                                <?= htmlspecialchars(
                                    $category["category_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>KPI Name</label>

                    <input
                        type="text"
                        name="kpi_name"
                        value="<?= htmlspecialchars(
                                    $kpi["kpi_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

                </div>


                <div class="form-group">

                    <label>Description</label>

                    <textarea name="description"><?= htmlspecialchars(
                                                        $kpi["description"] ?? "",
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?></textarea>

                </div>


                <div class="form-group">

                    <label>Weight</label>

                    <input
                        type="number"
                        name="weight"
                        step="0.01"
                        min="0"
                        max="100"
                        value="<?= htmlspecialchars(
                                    $kpi["weight"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

                </div>


                <div class="form-group">

                    <label>Unit</label>

                    <input
                        type="text"
                        name="unit"
                        value="<?= htmlspecialchars(
                                    $kpi["unit"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>">

                </div>


                <div class="form-group">

                    <label>Max Score</label>

                    <input
                        type="number"
                        name="max_score"
                        step="0.01"
                        value="<?= htmlspecialchars(
                                    $kpi["max_score"] ?? "5",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>">

                </div>


                <h3>เกณฑ์คะแนน</h3>

                <div class="score-grid">

                    <?php

                    $scores = [
                        5 => "score_5",
                        4 => "score_4",
                        3 => "score_3",
                        2 => "score_2",
                        1 => "score_1"
                    ];

                    ?>

                    <?php foreach ($scores as $number => $field): ?>

                        <div class="score-box">

                            <div class="score-title">
                                คะแนน <?= $number ?>
                            </div>

                            <input
                                type="text"
                                name="<?= $field ?>"
                                value="<?= htmlspecialchars(
                                            $kpi[$field] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>">

                        </div>

                    <?php endforeach; ?>

                </div>


                <div class="actions">

                    <button type="submit">
                        บันทึกการแก้ไข
                    </button>

                    <a
                        href="../index.php?page=kpi-management"
                        class="back">
                        ยกเลิก
                    </a>

                </div>

            </form>

        </div>

    </div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
