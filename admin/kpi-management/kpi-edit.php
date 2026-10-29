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
/*
| KPI ที่เกณฑ์คะแนนกำหนดในโค้ดตามที่เจ้าของระบบยืนยัน (ล็อกชื่อ ประเภท และเกณฑ์เหมือนกัน):
|   KPI 9 (NON_RENEWAL_KPI_CRITERIA) · KPI 10 (UPSELL_KPI_CRITERIA · ไม่มีระดับ 1) · KPI 14 (RENEWAL_KPI_CRITERIA)
*/
$ruleCriteriaByKpi = [
    NON_RENEWAL_KPI_ID => NON_RENEWAL_KPI_CRITERIA,
    UPSELL_KPI_ID => UPSELL_KPI_CRITERIA,
    RENEWAL_KPI_ID => RENEWAL_KPI_CRITERIA,
];
$ruleCriteria = $ruleCriteriaByKpi[(int) $kpi["kpi_id"]] ?? null;
$isLockedKpi9 = $ruleCriteria !== null;
$isKpi9 = (int) $kpi["kpi_id"] === NON_RENEWAL_KPI_ID;

if ($isLockedKpi9) {

    for ($level = 5; $level >= 1; $level--) {
        $kpi["score_" . $level] = $ruleCriteria[$level] ?? "";
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

$kpi9NameMismatch = $isKpi9 && !nonRenewalKpiNameMatches($kpi);
$kpi9TypeError = $isKpi9 ? nonRenewalKpiTypeError($kpi) : null;


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
$fieldErrors = [];   // ชื่อช่อง => ข้อความ error (แสดงใต้ช่องนั้น)


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
    /* หน่วยกำหนดอัตโนมัติเป็น % (ไม่มีช่องหน่วยในฟอร์ม และไม่รับค่าจาก POST) */
    $unit = KPI_DEFAULT_UNIT;
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
        $score_5 = $ruleCriteria[5] ?? "";
        $score_4 = $ruleCriteria[4] ?? "";
        $score_3 = $ruleCriteria[3] ?? "";
        $score_2 = $ruleCriteria[2] ?? "";
        $score_1 = $ruleCriteria[1] ?? "";
    }


    /* ตรวจทุกช่องแล้วแสดงข้อความใต้ช่องที่ผิด (ไม่หยุดที่ช่องแรก) */
    $validCategoryIds = array_map(fn($category) => (string) $category["category_id"], $categories);

    if ($category_id === "") {
        $fieldErrors["category_id"] = "กรุณาเลือกหมวดหมู่ KPI";
    } elseif (!ctype_digit($category_id) || !in_array($category_id, $validCategoryIds, true)) {
        $fieldErrors["category_id"] = "หมวดหมู่ KPI ไม่ถูกต้อง";
    }

    if ($kpi_name === "") {
        $fieldErrors["kpi_name"] = "กรุณากรอกชื่อ KPI";
    } elseif (mb_strlen($kpi_name) > 255) {
        $fieldErrors["kpi_name"] = "ชื่อ KPI ต้องไม่เกิน 255 ตัวอักษร";
    }

    if (!in_array($kpi_type, ["Performance", "Competency"], true)) {
        $fieldErrors["kpi_type"] = "กรุณาเลือกประเภท KPI (Performance หรือ Competency)";
    }

    if ($weight === "") {
        $fieldErrors["weight"] = "กรุณากรอก Weight";
    } elseif (!is_numeric($weight) || $weight < 0 || $weight > 100) {
        $fieldErrors["weight"] = "Weight ต้องเป็นตัวเลขระหว่าง 0 - 100";
    }

    if ($max_score === "") {
        $fieldErrors["max_score"] = "กรุณากรอกคะแนนสูงสุด";
    } elseif (!is_numeric($max_score) || $max_score < 1 || $max_score > 5) {
        $fieldErrors["max_score"] = "คะแนนสูงสุดต้องเป็นตัวเลขระหว่าง 1 - 5";
    }

    if (max(mb_strlen($score_5), mb_strlen($score_4), mb_strlen($score_3), mb_strlen($score_2), mb_strlen($score_1)) > 255) {
        $fieldErrors["levels"] = "เกณฑ์คะแนนแต่ละระดับต้องไม่เกิน 255 ตัวอักษร";
    }

    if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($fieldErrors) {

        $error = "กรุณาตรวจสอบข้อมูลที่ไม่ถูกต้อง (" . count($fieldErrors) . " ช่อง)";
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
        href="../../assets/css/admin-kpi-edit.css?v=required-1">

</head>

<body>

    <div class="container">

        <div class="card">

            <h1>Edit KPI</h1>

            <p class="required-note"><span class="required-mark" aria-hidden="true">*</span> = ช่องที่ต้องกรอก</p>

            <?php if ($error !== ""): ?>

                <div class="error" role="alert">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            <?php endif; ?>


                        <form method="POST">

                <?= csrfField() ?>

                <div class="form-group<?= isset($fieldErrors["category_id"]) ? " has-error" : "" ?>">

                    <label for="category_id">KPI Category <span class="required-mark" aria-hidden="true">*</span></label>

                    <select id="category_id" name="category_id" aria-required="true"<?= isset($fieldErrors["category_id"]) ? " aria-invalid=\"true\" aria-describedby=\"error-category_id\"" : "" ?> required>

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

                    <?php if (isset($fieldErrors["category_id"])): ?>
                        <div class="field-error" id="error-category_id"><?= htmlspecialchars($fieldErrors["category_id"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>

                </div>


                <div class="form-group<?= isset($fieldErrors["kpi_type"]) ? " has-error" : "" ?>">

                    <label for="kpi_type">KPI Type <span class="required-mark" aria-hidden="true">*</span></label>

                    <select id="kpi_type" name="kpi_type" <?= $isLockedKpi9 ? "disabled" : "required" ?>>

                        <option value="Performance" <?= ($kpi["kpi_type"] ?? "") === "Performance" ? "selected" : "" ?>>
                            Performance (วัดจาก Target / Actual)
                        </option>

                        <option value="Competency" <?= ($kpi["kpi_type"] ?? "") === "Competency" ? "selected" : "" ?>>
                            Competency (เลือกระดับ 1 - 5)
                        </option>

                    </select>

                    <?php if (isset($fieldErrors["kpi_type"])): ?>
                        <div class="field-error" id="error-kpi_type"><?= htmlspecialchars($fieldErrors["kpi_type"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>

                </div>

                <div class="form-group<?= isset($fieldErrors["kpi_name"]) ? " has-error" : "" ?>">

                    <label for="kpi_name">KPI Name <span class="required-mark" aria-hidden="true">*</span></label>

                    <input
                        type="text"
                        id="kpi_name"
                        name="kpi_name"
                        maxlength="255"
                        <?= isset($fieldErrors["kpi_name"]) ? " aria-invalid=\"true\" aria-describedby=\"error-kpi_name\"" : "" ?>
                        value="<?= htmlspecialchars(
                                    $kpi["kpi_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        <?= $isLockedKpi9 ? "disabled" : "required" ?>>


                    <?php if (isset($fieldErrors["kpi_name"])): ?>
                        <div class="field-error" id="error-kpi_name"><?= htmlspecialchars($fieldErrors["kpi_name"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>

                    <?php if ($isLockedKpi9): ?>
                        <small>ชื่อและประเภทของ KPI นี้ถูกล็อก เพราะกฎคะแนนในระบบผูกกับ KPI ID <?= (int) $kpi["kpi_id"] ?> และ KPI นี้ต้องเป็นประเภท Performance</small>
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


                <div class="form-group<?= isset($fieldErrors["weight"]) ? " has-error" : "" ?>">

                    <label for="weight">Weight <span class="required-mark" aria-hidden="true">*</span></label>

                    <input
                        type="number"
                        id="weight"
                        name="weight"
                        aria-required="true"<?= isset($fieldErrors["weight"]) ? " aria-invalid=\"true\" aria-describedby=\"error-weight\"" : "" ?>
                        step="0.01"
                        min="0"
                        max="100"
                        value="<?= htmlspecialchars(
                                    $kpi["weight"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                        required>

                    <?php if (isset($fieldErrors["weight"])): ?>
                        <div class="field-error" id="error-weight"><?= htmlspecialchars($fieldErrors["weight"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>

                </div>



                <div class="form-group<?= isset($fieldErrors["max_score"]) ? " has-error" : "" ?>">

                    <label for="max_score">Max Score <span class="required-mark" aria-hidden="true">*</span></label>

                    <input
                        type="number"
                        id="max_score"
                        name="max_score"
                        aria-required="true"<?= isset($fieldErrors["max_score"]) ? " aria-invalid=\"true\" aria-describedby=\"error-max_score\"" : "" ?>
                        required
                        step="0.01"
                        min="1"
                        max="5"
                        <?= $isLockedKpi9 ? "readonly" : "" ?>
                        value="<?= htmlspecialchars(
                                    $kpi["max_score"] ?? "5",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>">

                    <?php if (isset($fieldErrors["max_score"])): ?>
                        <div class="field-error" id="error-max_score"><?= htmlspecialchars($fieldErrors["max_score"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>

                </div>


                <h3>เกณฑ์คะแนน</h3>

                <?php if ($isLockedKpi9): ?>

                    <div class="error">
                        เกณฑ์ของ KPI นี้กำหนดในระบบตามที่เจ้าของระบบยืนยัน แก้ไขที่หน้านี้ไม่ได้:
                        <?php if ($isKpi9): ?>
                            ผลงาน (%) = จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget × 100
                            (นำเข้าจากไฟล์บริษัท ชีตสรุปทั้งปี ตารางจำนวนราย คอลัมน์ U · Target 100%)
                        <?php elseif ((int) $kpi["kpi_id"] === UPSELL_KPI_ID): ?>
                            ผลงาน (%) = จำนวนลูกค้าที่ผลต่างราคา (S) มากกว่า 0 ÷ จำนวนลูกค้าเดิมที่ต่อสัญญา (B) × 100
                            (นำเข้าจากชีตของเดือน · เป้าหมาย 10% · ไม่มีคะแนนระดับ 1)
                        <?php else: ?>
                            ผลงาน (%) = Total R ÷ Budget × 100 (ไม่รวมยอดขายใหม่ · ชีตสรุปทั้งปี คอลัมน์ R · เป้าหมาย 100%)
                            · ผลที่อยู่ในช่วงที่สูตรบริษัทไม่กำหนดคะแนน (มากกว่า 89% แต่ต่ำกว่า 90% / มากกว่า 90% แต่ต่ำกว่า 91%) จะไม่ได้รับคะแนนจนกว่าเจ้าของระบบยืนยัน
                        <?php endif; ?>
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

                    <?php if (isset($fieldErrors["levels"])): ?>
                        <div class="field-error" id="error-levels"><?= htmlspecialchars($fieldErrors["levels"], ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>


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
