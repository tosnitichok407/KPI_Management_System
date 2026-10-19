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
$fieldErrors = [];   // ชื่อช่อง => ข้อความ error (แสดงใต้ช่องนั้น)

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

    /* ตรวจทุกช่องแล้วแสดงข้อความใต้ช่องที่ผิด (ไม่หยุดที่ช่องแรก) */
    $validCategoryIds = array_map(fn($category) => (string) $category["category_id"], $categories);

    if ($category_id === "") {
        $fieldErrors["category_id"] = "กรุณาเลือกหมวดหมู่ KPI";
    } elseif (!ctype_digit($category_id) || !in_array($category_id, $validCategoryIds, true)) {
        $fieldErrors["category_id"] = "หมวดหมู่ KPI ไม่ถูกต้อง";
    }

    if (!in_array($kpi_type, ["Performance", "Competency"], true)) {
        $fieldErrors["kpi_type"] = "กรุณาเลือกประเภท KPI (Performance หรือ Competency)";
    }

    if ($kpi_name === "") {
        $fieldErrors["kpi_name"] = "กรุณากรอกชื่อ KPI";
    } elseif (mb_strlen($kpi_name) > 255) {
        $fieldErrors["kpi_name"] = "ชื่อ KPI ต้องไม่เกิน 255 ตัวอักษร";
    }

    if ($weight === "") {
        $fieldErrors["weight"] = "กรุณากรอก Weight";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {
        $fieldErrors["weight"] = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    }

    if (mb_strlen($unit) > 50) {
        $fieldErrors["unit"] = "หน่วยวัดต้องไม่เกิน 50 ตัวอักษร";
    }

    if ($max_score === "") {
        $fieldErrors["max_score"] = "กรุณากรอกคะแนนสูงสุด";
    } elseif (!is_numeric($max_score) || $max_score < 1 || $max_score > 5) {
        $fieldErrors["max_score"] = "คะแนนสูงสุดต้องเป็นตัวเลขระหว่าง 1 - 5";
    }

    if (max(array_map("mb_strlen", $levels)) > 255) {
        $fieldErrors["levels"] = "เกณฑ์คะแนนแต่ละระดับต้องไม่เกิน 255 ตัวอักษร";
    }

    if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($fieldErrors) {

        $error = "กรุณาตรวจสอบข้อมูลที่ไม่ถูกต้อง (" . count($fieldErrors) . " ช่อง)";
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

    <link rel="stylesheet" href="../../assets/css/icons.css?v=1">

    <link
        rel="stylesheet"
        href="../../assets/css/kpi.css?v=category-form-1">

    <link
        rel="stylesheet"
        href="../../assets/css/admin-kpi-add.css?v=required-1">

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


            <p class="required-note"><span class="required-mark" aria-hidden="true">*</span> = ช่องที่ต้องกรอก</p>

            <?php if ($error !== ""): ?>

                <div class="error" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


                        <form method="POST">

                <?= csrfField() ?>

                <div class="form-group<?= isset($fieldErrors["category_id"]) ? " has-error" : "" ?>">

                    <label for="category_id">
                        หมวดหมู่ KPI <span class="required-mark" aria-hidden="true">*</span>
                    </label>

                    <select
                        id="category_id"
                        name="category_id"
                        aria-required="true"<?= isset($fieldErrors["category_id"]) ? " aria-invalid=\"true\" aria-describedby=\"error-category_id\"" : "" ?>
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

                    <?php if (isset($fieldErrors["category_id"])): ?>
                        <div class="field-error" id="error-category_id"><?= htmlspecialchars($fieldErrors["category_id"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["kpi_type"]) ? " has-error" : "" ?>">

                    <label for="kpi_type">
                        ประเภท KPI <span class="required-mark" aria-hidden="true">*</span>
                    </label>

                    <select
                        id="kpi_type"
                        name="kpi_type"
                        aria-required="true"
                        required>

                        <option value="Performance" <?= $form["kpi_type"] === "Performance" ? "selected" : "" ?>>
                            Performance (วัดจาก Target / Actual)
                        </option>

                        <option value="Competency" <?= $form["kpi_type"] === "Competency" ? "selected" : "" ?>>
                            Competency (เลือกระดับ 1 - 5)
                        </option>

                    </select>

                    <?php if (isset($fieldErrors["kpi_type"])): ?>
                        <div class="field-error" id="error-kpi_type"><?= htmlspecialchars($fieldErrors["kpi_type"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["kpi_name"]) ? " has-error" : "" ?>">

                    <label for="kpi_name">
                        ชื่อ KPI <span class="required-mark" aria-hidden="true">*</span>
                    </label>

                    <input
                        type="text"
                        id="kpi_name"
                        name="kpi_name"
                        aria-required="true"<?= isset($fieldErrors["kpi_name"]) ? " aria-invalid=\"true\" aria-describedby=\"error-kpi_name\"" : "" ?>
                        value="<?= htmlspecialchars($form["kpi_name"], ENT_QUOTES, "UTF-8") ?>"
                        placeholder="เช่น บรรลุเป้าหมายการต่อสัญญา"
                        maxlength="255"
                        required>

                    <?php if (isset($fieldErrors["kpi_name"])): ?>
                        <div class="field-error" id="error-kpi_name"><?= htmlspecialchars($fieldErrors["kpi_name"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group">

                    <label>
                        รายละเอียด
                    </label>

                    <textarea
                        name="description"
                        placeholder="รายละเอียดของ KPI"><?= htmlspecialchars($form["description"], ENT_QUOTES, "UTF-8") ?></textarea>

                </div>


                <div class="form-group<?= isset($fieldErrors["weight"]) ? " has-error" : "" ?>">

                    <label for="weight">
                        Weight <span class="required-mark" aria-hidden="true">*</span>
                    </label>

                    <input
                        type="number"
                        id="weight"
                        name="weight"
                        aria-required="true"<?= isset($fieldErrors["weight"]) ? " aria-invalid=\"true\" aria-describedby=\"error-weight\"" : "" ?>
                        step="0.01"
                        min="0"
                        max="100"
                        value="<?= htmlspecialchars($form["weight"], ENT_QUOTES, "UTF-8") ?>"
                        required>

                    <?php if (isset($fieldErrors["weight"])): ?>
                        <div class="field-error" id="error-weight"><?= htmlspecialchars($fieldErrors["weight"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["unit"]) ? " has-error" : "" ?>">

                    <label for="unit">
                        หน่วยวัด
                    </label>

                    <input
                        type="text"
                        id="unit"
                        name="unit"
                        value="<?= htmlspecialchars($form["unit"], ENT_QUOTES, "UTF-8") ?>"
                        maxlength="50"
                        placeholder="เช่น %, ราย, บาท">

                    <?php if (isset($fieldErrors["unit"])): ?>
                        <div class="field-error" id="error-unit"><?= htmlspecialchars($fieldErrors["unit"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["max_score"]) ? " has-error" : "" ?>">

                    <label for="max_score">
                        คะแนนสูงสุด <span class="required-mark" aria-hidden="true">*</span>
                    </label>

                    <input
                        type="number"
                        id="max_score"
                        name="max_score"
                        aria-required="true"<?= isset($fieldErrors["max_score"]) ? " aria-invalid=\"true\" aria-describedby=\"error-max_score\"" : "" ?>
                        value="<?= htmlspecialchars($form["max_score"], ENT_QUOTES, "UTF-8") ?>"
                        min="1"
                        max="5"
                        required>

                    <?php if (isset($fieldErrors["max_score"])): ?>
                        <div class="field-error" id="error-max_score"><?= htmlspecialchars($fieldErrors["max_score"]) ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["levels"]) ? " has-error" : "" ?>">

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

                    <?php if (isset($fieldErrors["levels"])): ?>
                        <div class="field-error" id="error-levels"><?= htmlspecialchars($fieldErrors["levels"]) ?></div>
                    <?php endif; ?>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary">
                    <?= icon("save") ?>บันทึก KPI
                </button>


                <a
                    href="../index.php?page=kpi-management"
                    class="btn btn-secondary">
                    <?= icon("x") ?>ยกเลิก
                </a>

            </form>

        </div>

    </div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
