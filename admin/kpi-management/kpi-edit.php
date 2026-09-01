<?php

session_start();

require_once "../../config/database.php";


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
    header("Location: ../../dashboard.php");
    exit;
}


$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: kpi-management.php");
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
    header("Location: kpi-management.php");
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

    $category_id = $_POST["category_id"] ?? "";
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = $_POST["weight"] ?? "";
    $unit = trim($_POST["unit"] ?? "");
    $max_score = $_POST["max_score"] ?? "";

    $score_5 = trim($_POST["score_5"] ?? "");
    $score_4 = trim($_POST["score_4"] ?? "");
    $score_3 = trim($_POST["score_3"] ?? "");
    $score_2 = trim($_POST["score_2"] ?? "");
    $score_1 = trim($_POST["score_1"] ?? "");


    if ($category_id === "") {

        $error = "กรุณาเลือก KPI Category";
    } elseif ($kpi_name === "") {

        $error = "กรุณากรอกชื่อ KPI";
    } elseif ($weight === "") {

        $error = "กรุณากรอก Weight";
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

            header("Location: kpi-management.php");
            exit;
        } catch (PDOException $e) {

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
        href="../../assets/css/kpi.css">

    <style>
        body {
            margin: 0;
            font-family: Kanit, sans-serif;
            background: #f5f7fb;
        }

        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, .08);
        }

        h1 {
            margin-top: 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-family: inherit;
            font-size: 15px;
            box-sizing: border-box;
        }

        textarea {
            min-height: 100px;
        }

        .score-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }

        .score-box {
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .score-title {
            font-weight: 600;
            margin-bottom: 8px;
        }

        .actions {
            margin-top: 25px;
            display: flex;
            gap: 10px;
        }

        button,
        .back {
            padding: 11px 20px;
            border: none;
            border-radius: 7px;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
        }

        button {
            background: #244397;
            color: white;
        }

        .back {
            background: #e5e7eb;
            color: #111827;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        @media (max-width: 700px) {

            .score-grid {
                grid-template-columns: 1fr;
            }

        }
    </style>

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
                        href="kpi-management.php"
                        class="back">
                        ยกเลิก
                    </a>

                </div>

            </form>

        </div>

    </div>

</body>

</html>
