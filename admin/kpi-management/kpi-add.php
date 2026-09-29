<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../../includes/kpi-score-helper.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
}

$error = "";
$success = "";

/* ค่าในฟอร์ม (คงไว้เมื่อบันทึกไม่ผ่าน) */
$form = [
    "category_id" => "",
    "kpi_type" => "Performance",
    "kpi_name" => "",
    "description" => "",
    "weight" => "",
    "unit" => "",
    "max_score" => "5",
    "levels" => [5 => "", 4 => "", 3 => "", 2 => "", 1 => ""]
];


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
    $kpi_type = trim($_POST["kpi_type"] ?? "");
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = trim($_POST["weight"] ?? "");
    $unit = trim($_POST["unit"] ?? "");
    $max_score = trim($_POST["max_score"] ?? "5");

    $levels = [];

    for ($level = KPI_MAX_LEVEL; $level >= KPI_MIN_LEVEL; $level--) {
        $levels[$level] = trim((string) ($_POST["score_" . $level] ?? ""));
    }

    $form = [
        "category_id" => $category_id,
        "kpi_type" => $kpi_type,
        "kpi_name" => $kpi_name,
        "description" => $description,
        "weight" => $weight,
        "unit" => $unit,
        "max_score" => $max_score,
        "levels" => $levels
    ];

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
    } elseif (!in_array($kpi_type, ["Performance", "Competency"], true)) {

        $error = "กรุณาเลือกประเภท KPI (Performance หรือ Competency)";
    } elseif (mb_strlen($kpi_name) > 255 || mb_strlen($unit) > 50) {

        $error = "ข้อมูลยาวเกินกำหนด";
    } elseif (max(array_map("mb_strlen", $levels)) > 255) {

        $error = "เกณฑ์คะแนนแต่ละระดับต้องไม่เกิน 255 ตัวอักษร";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {

        $error = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    } elseif ($max_score === "" || !is_numeric($max_score) || $max_score < 1 || $max_score > 5) {

        $error = "คะแนนสูงสุดต้องเป็นตัวเลขระหว่าง 1 - 5";
    } else {

        try {

            /*
            | KPI 1 แถว + เกณฑ์สูงสุด 5 แถว ต้องสำเร็จทั้งหมดหรือไม่บันทึกเลย
            */

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO kpi_indicators
                (
                    category_id,
                    kpi_name,
                    kpi_type,
                    description,
                    weight,
                    unit,
                    max_score,
                    score_5,
                    score_4,
                    score_3,
                    score_2,
                    score_1
                )
                VALUES
                (
                    :category_id,
                    :kpi_name,
                    :kpi_type,
                    :description,
                    :weight,
                    :unit,
                    :max_score,
                    :score_5,
                    :score_4,
                    :score_3,
                    :score_2,
                    :score_1
                )
            ");

            $stmt->execute([
                ":category_id" => $category_id,
                ":kpi_name" => $kpi_name,
                ":kpi_type" => $kpi_type,
                ":description" => $description !== ""
                    ? $description
                    : null,
                ":weight" => $weight,
                ":unit" => $unit !== ""
                    ? $unit
                    : null,
                ":max_score" => $max_score,
                ":score_5" => $levels[5] !== "" ? $levels[5] : null,
                ":score_4" => $levels[4] !== "" ? $levels[4] : null,
                ":score_3" => $levels[3] !== "" ? $levels[3] : null,
                ":score_2" => $levels[2] !== "" ? $levels[2] : null,
                ":score_1" => $levels[1] !== "" ? $levels[1] : null
            ]);

            $kpi_id = (int) $pdo->lastInsertId();

            /* เกณฑ์ระดับผลงาน 5..1 (แหล่งหลักที่ระบบใช้ตัดเกรด) */
            saveKpiScoreCriteria($pdo, $kpi_id, $levels);

            $pdo->commit();

            header(
                "Location: ../index.php?page=kpi-management"
            );

            exit;
        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

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
                                value="<?= (int) $category["category_id"] ?>"
                                <?= (string) $form["category_id"] === (string) $category["category_id"] ? "selected" : "" ?>>

                                <?= htmlspecialchars(
                                    $category["category_name"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        ประเภท KPI *
                    </label>

                    <select
                        name="kpi_type"
                        required>

                        <option value="Performance" <?= $form["kpi_type"] === "Performance" ? "selected" : "" ?>>
                            Performance (วัดจาก Target / Actual)
                        </option>

                        <option value="Competency" <?= $form["kpi_type"] === "Competency" ? "selected" : "" ?>>
                            Competency (เลือกระดับ 1 - 5)
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        ชื่อ KPI *
                    </label>

                    <input
                        type="text"
                        name="kpi_name"
                        value="<?= htmlspecialchars($form["kpi_name"], ENT_QUOTES, "UTF-8") ?>"
                        placeholder="เช่น บรรลุเป้าหมายการต่อสัญญา"
                        maxlength="255"
                        required>

                </div>


                <div class="form-group">

                    <label>
                        รายละเอียด
                    </label>

                    <textarea
                        name="description"
                        placeholder="รายละเอียดของ KPI"><?= htmlspecialchars($form["description"], ENT_QUOTES, "UTF-8") ?></textarea>

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
                        max="100"
                        value="<?= htmlspecialchars($form["weight"], ENT_QUOTES, "UTF-8") ?>"
                        required>

                </div>


                <div class="form-group">

                    <label>
                        หน่วยวัด
                    </label>

                    <input
                        type="text"
                        name="unit"
                        value="<?= htmlspecialchars($form["unit"], ENT_QUOTES, "UTF-8") ?>"
                        maxlength="50"
                        placeholder="เช่น %, ราย, บาท">

                </div>


                <div class="form-group">

                    <label>
                        คะแนนสูงสุด
                    </label>

                    <input
                        type="number"
                        name="max_score"
                        value="<?= htmlspecialchars($form["max_score"], ENT_QUOTES, "UTF-8") ?>"
                        min="1"
                        max="5">

                </div>


                <div class="form-group">

                    <label>
                        เกณฑ์ระดับผลงาน (Criteria)
                    </label>

                    <small>
                        ตัวอย่าง Performance: <code>&gt;100%</code>, <code>100%</code>, <code>99-90%</code>, <code>ต่ำกว่า 85%</code>
                        · ระบบใช้เกณฑ์นี้ตัดเกรด 1-5 จากผลที่ทำได้
                    </small>

                    <?php for ($level = 5; $level >= 1; $level--): ?>

                        <input
                            type="text"
                            name="score_<?= $level ?>"
                            value="<?= htmlspecialchars($form["levels"][$level] ?? "", ENT_QUOTES, "UTF-8") ?>"
                            maxlength="255"
                            placeholder="ระดับ <?= $level ?>">

                    <?php endfor; ?>

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
