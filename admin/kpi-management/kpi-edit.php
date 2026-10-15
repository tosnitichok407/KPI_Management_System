<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../../includes/kpi-score-helper.php";


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
| เกณฑ์ที่ระบบใช้อยู่จริง (kpi_score_criteria > kpi_score_levels > score_5..score_1)
| นำมาแสดงในฟอร์ม เพื่อให้สิ่งที่ Admin เห็นตรงกับสิ่งที่ใช้ตัดเกรด
| (ไม่รวมกฎเฉพาะ KPI ที่กำหนดในโค้ด)
*/
/*
| KPI 9 มูลค่าไม่ต่อสัญญา: ระบุด้วย kpi_id เป็นหลัก (ไม่ขึ้นกับชื่อ)
| เกณฑ์ / ชื่อ / ประเภท ถูกล็อก · แสดงเกณฑ์จากกฎในระบบ (NON_RENEWAL_KPI_CRITERIA)
| และไม่อ่าน/ไม่เขียน kpi_score_criteria, kpi_score_levels, score_5..score_1 จากหน้านี้
*/
$isLockedKpi9 = (int) $kpi["kpi_id"] === NON_RENEWAL_KPI_ID;

if ($isLockedKpi9) {

    for ($level = 5; $level >= 1; $level--) {
        $kpi["score_" . $level] = NON_RENEWAL_KPI_CRITERIA[$level];
    }
} else {

    $currentLevels = loadKpiScoreCriteria($pdo, [$kpi], false)[(int) $kpi["kpi_id"]] ?? [];

    for ($level = 5; $level >= 1; $level--) {
        $kpi["score_" . $level] = $currentLevels[$level] ?? "";
    }
}

/* ชื่อ / ประเภทเดิมในฐานข้อมูล (KPI 9 ใช้ค่านี้เสมอ ไม่รับค่าจากฟอร์ม) */
$lockedKpiName = (string) $kpi["kpi_name"];
$lockedKpiType = (string) $kpi["kpi_type"];

/*
| KPI 9: คะแนนสูงสุดตามกฎ = 5 เสมอ
| กฎคะแนนเลือกจาก kpi_id = 9 เท่านั้น · ชื่อที่ไม่ตรงและประเภทที่ไม่ใช่ Performance
| ตรวจแยกต่างหากและแสดงเป็นคำเตือนด้านล่าง
*/
if ($isLockedKpi9) {
    $kpi["max_score"] = (string) KPI_MAX_LEVEL;
}

$kpi9NameMismatch = $isLockedKpi9 && !nonRenewalKpiNameMatches($kpi);
$kpi9TypeError = $isLockedKpi9 ? nonRenewalKpiTypeError($kpi) : null;


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
    $kpi_type = trim($_POST["kpi_type"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $weight = trim($_POST["weight"] ?? "");
    $unit = trim($_POST["unit"] ?? "");
    $max_score = trim($_POST["max_score"] ?? "");

    $score_5 = trim($_POST["score_5"] ?? "");
    $score_4 = trim($_POST["score_4"] ?? "");
    $score_3 = trim($_POST["score_3"] ?? "");
    $score_2 = trim($_POST["score_2"] ?? "");
    $score_1 = trim($_POST["score_1"] ?? "");

    /*
    | KPI 9: ไม่รับชื่อ ประเภท และเกณฑ์จากฟอร์ม (กันการเขียนทับฝั่ง server แม้มีการแก้ HTML)
    | ค่า score_* ด้านล่างใช้แสดงผลกลับในฟอร์มเท่านั้น ไม่ถูกบันทึก
    */
    if ($isLockedKpi9) {
        $kpi_name = $lockedKpiName;
        $kpi_type = $lockedKpiType;
        $max_score = (string) KPI_MAX_LEVEL;
        $score_5 = NON_RENEWAL_KPI_CRITERIA[5];
        $score_4 = NON_RENEWAL_KPI_CRITERIA[4];
        $score_3 = NON_RENEWAL_KPI_CRITERIA[3];
        $score_2 = NON_RENEWAL_KPI_CRITERIA[2];
        $score_1 = NON_RENEWAL_KPI_CRITERIA[1];
    }


        if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($category_id === "" || !ctype_digit($category_id)) {

        $error = "กรุณาเลือก KPI Category";
    } elseif ($kpi_name === "") {

        $error = "กรุณากรอกชื่อ KPI";
    } elseif (!in_array($kpi_type, ["Performance", "Competency"], true)) {

        $error = "กรุณาเลือกประเภท KPI (Performance หรือ Competency)";
    } elseif ($weight === "") {

        $error = "กรุณากรอก Weight";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {

        $error = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    } elseif ($max_score === "" || !is_numeric($max_score) || $max_score < 1 || $max_score > 5) {

        $error = "Max Score ต้องเป็นตัวเลขระหว่าง 1 - 5";
    } elseif (
        mb_strlen($kpi_name) > 255 || mb_strlen($unit) > 50 ||
        mb_strlen($score_5) > 255 || mb_strlen($score_4) > 255 || mb_strlen($score_3) > 255 ||
        mb_strlen($score_2) > 255 || mb_strlen($score_1) > 255
    ) {

        $error = "ข้อมูลยาวเกินกำหนด";
    } else {

        try {

            /* ตาราง KPI + ตารางเกณฑ์ ต้องสำเร็จพร้อมกัน */
            $pdo->beginTransaction();

            if ($isLockedKpi9) {

                /*
                | KPI 9: บันทึกเฉพาะฟิลด์ทั่วไป · ไม่แตะ kpi_name, kpi_type, score_5..score_1
                | และไม่เรียก saveKpiScoreCriteria() (ไม่เพิ่ม/แก้ kpi_score_criteria · ไม่ลบ kpi_score_levels)
                */
                $stmt = $pdo->prepare("
                    UPDATE kpi_indicators
                    SET
                        category_id = :category_id,
                        description = :description,
                        weight = :weight,
                        unit = :unit,
                        max_score = :max_score
                    WHERE kpi_id = :id
                ");

                $stmt->execute([
                    ":category_id" => $category_id,
                    ":description" => $description,
                    ":weight" => $weight,
                    ":unit" => $unit,
                    ":max_score" => $max_score,
                    ":id" => $id
                ]);
            } else {

                $sql = "
                    UPDATE kpi_indicators

                    SET
                        category_id = :category_id,
                        kpi_name = :kpi_name,
                        kpi_type = :kpi_type,
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
                    ":kpi_type" => $kpi_type,
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

                /*
                | เกณฑ์ที่ระบบใช้ตัดเกรดอยู่ใน kpi_score_criteria
                | (เดิมหน้านี้บันทึกแค่ score_5..score_1 ซึ่งถูกมองข้ามเมื่อมีเกณฑ์ในตารางอื่น)
                */
                saveKpiScoreCriteria($pdo, $id, [
                    5 => $score_5,
                    4 => $score_4,
                    3 => $score_3,
                    2 => $score_2,
                    1 => $score_1
                ]);
            }

            $pdo->commit();

            header("Location: ../index.php?page=kpi-management");
            exit;
        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Update KPI failed: " . $e->getMessage());

            $error = "ไม่สามารถแก้ไข KPI ได้";
        }
    }

    /* คงค่าที่กรอกไว้ในฟอร์มเมื่อบันทึกไม่ผ่าน */
    $kpi = array_merge($kpi, [
        "category_id" => $category_id,
        "kpi_name" => $kpi_name,
        "kpi_type" => $kpi_type,
        "description" => $description,
        "weight" => $weight,
        "unit" => $unit,
        "max_score" => $max_score,
        "score_5" => $score_5,
        "score_4" => $score_4,
        "score_3" => $score_3,
        "score_2" => $score_2,
        "score_1" => $score_1
    ]);
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

    <link rel="stylesheet" href="../../assets/css/icons.css?v=1">

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

                    <label>KPI Type</label>

                    <select name="kpi_type" <?= $isLockedKpi9 ? "disabled" : "required" ?>>

                        <option value="Performance" <?= ($kpi["kpi_type"] ?? "") === "Performance" ? "selected" : "" ?>>
                            Performance (วัดจาก Target / Actual)
                        </option>

                        <option value="Competency" <?= ($kpi["kpi_type"] ?? "") === "Competency" ? "selected" : "" ?>>
                            Competency (เลือกระดับ 1 - 5)
                        </option>

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
                        <?= $isLockedKpi9 ? "disabled" : "required" ?>>

                    <?php if ($isLockedKpi9): ?>
                        <small>ชื่อและประเภทของ KPI นี้ถูกล็อก เพราะกฎคะแนนในระบบผูกกับ KPI ID 9 และ KPI นี้ต้องเป็นประเภท Performance</small>
                    <?php endif; ?>

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
                        min="1"
                        max="5"
                        <?= $isLockedKpi9 ? "readonly" : "" ?>
                        value="<?= htmlspecialchars(
                                    $kpi["max_score"] ?? "5",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>">

                </div>


                <h3>เกณฑ์คะแนน</h3>

                <?php if ($isLockedKpi9): ?>

                    <div class="error">
                        เกณฑ์ของ KPI นี้กำหนดในระบบ แก้ไขที่หน้านี้ไม่ได้:
                        Achievement = (Total R / Budget) × 100
                        (ไม่รวม Total N และไม่ใช้ Total R+N) · กำหนด Budget ที่หน้ามอบหมาย KPI
                        · คะแนนสูงสุดล็อกไว้ที่ <?= KPI_MAX_LEVEL ?>
                        <?php if ($kpi9NameMismatch): ?>
                            <br>
                            คำเตือน: ชื่อ KPI ในฐานข้อมูลไม่ตรงกับ "<?= htmlspecialchars(NON_RENEWAL_KPI_NAME, ENT_QUOTES, "UTF-8") ?>"
                            ระบบยังใช้กฎคะแนนของ KPI ID 9 ตามปกติ กรุณาตรวจสอบข้อมูลกับผู้พัฒนาระบบ
                        <?php endif; ?>
                        <?php if ($kpi9TypeError !== null): ?>
                            <br>
                            คำเตือน: <?= htmlspecialchars($kpi9TypeError, ENT_QUOTES, "UTF-8") ?>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>


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
                                <?= $isLockedKpi9 ? "disabled" : 'name="' . $field . '"' ?>
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
                        <?= icon("save") ?>บันทึกการแก้ไข
                    </button>

                    <a
                        href="../index.php?page=kpi-management"
                        class="back">
                        <?= icon("x") ?>ยกเลิก
                    </a>

                </div>

            </form>

        </div>

    </div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
