<?php

require_once __DIR__ . "/../includes/security.php";

require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
require_once __DIR__ . "/../includes/kpi-import-helper.php";
require_once __DIR__ . "/../includes/kpi-import-company-reader.php";
require_once __DIR__ . "/includes/layout.php";

/*
|--------------------------------------------------------------------------
| นำเข้าผลการปฏิบัติงานจาก Excel (เฉพาะพนักงาน · ข้อมูลของตนเองเท่านั้น)
|--------------------------------------------------------------------------
|
| POST action=upload  : ตรวจไฟล์ → อ่าน Template → ตรวจข้อมูล (ไม่เขียนฐานข้อมูล) → เก็บผลไว้ใน session
| GET                 : แสดง Preview จาก session (หรือฟอร์มอัปโหลด)
| POST action=confirm : ตรวจซ้ำพร้อมล็อกใน Transaction แล้วบันทึก (kpiImportCommit) · token ใช้ได้ครั้งเดียว
| POST action=cancel  : ยกเลิก Preview
| POST action=upload + format=company : อ่านแบบฟอร์ม KPI AM ของบริษัท (kpiCompanyPrepare)
|     - นำเข้าได้เฉพาะรายการ READY (Mapping ยืนยันแล้ว + ผ่าน Validation เดิม) ผ่าน Preview / ยืนยัน เดียวกับ Template
|     - รายการที่ยืนยัน Mapping แล้วแต่ไม่ผ่านการตรวจ → ปิดการนำเข้าทั้งไฟล์ · NEEDS_CONFIRMATION ไม่ถูกบันทึก
| ไฟล์ที่อัปโหลดไม่ถูกเก็บไว้ (PHP ลบไฟล์ชั่วคราวเมื่อจบ request)
|
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 3) {
    redirectToRoleHome("../");
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}

$thaiMonths = monthlyPeriodMonths();
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");

/* Preview ที่ค้างอยู่ (หมดอายุ / ไม่ใช่ของพนักงานคนนี้ → ทิ้ง) */
$pending = $_SESSION["kpi_import"] ?? null;

if (
    !is_array($pending)
    || (int) ($pending["employee_id"] ?? 0) !== $employeeId
    || time() - (int) ($pending["created_at"] ?? 0) > KPI_IMPORT_SESSION_TTL
    || ($pending["format"] ?? "template") !== "company"
) {
    unset($_SESSION["kpi_import"]);
    $pending = null;
}

$errors = [];
$fileErrors = [];
$companyResult = null;
$flash = $_SESSION["kpi_import_flash"] ?? null;
unset($_SESSION["kpi_import_flash"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = (string) ($_POST["action"] ?? "");

    if (!csrfVerify()) {

        $errors[] = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";

    } elseif ($action === "upload" && (string) ($_POST["format"] ?? "company") === "company") {

        /* แบบฟอร์มบริษัท: อ่าน + ตรวจ Mapping กับระบบ + Validation เดิม (ไม่เขียนฐานข้อมูล) */
        unset($_SESSION["kpi_import"]);
        $pending = null;

        $file = $_FILES["import_file"] ?? [];
        $uploadError = kpiImportCheckUploadedFile(is_array($file) ? $file : []);

        if ($uploadError !== null) {
            $fileErrors[] = $uploadError;
        } else {
            try {
                /* แบบฟอร์มบริษัทมีข้อมูลหลายเดือน: นำเข้าเฉพาะเดือนที่ผู้ใช้เลือก (เดือนอื่นไม่ถูกอ่านหรือบันทึก) */
                $companyResult = kpiCompanyPrepare($pdo, $employeeId, (string) $file["tmp_name"], (int) ($_POST["company_year"] ?? 0), null, (int) ($_POST["company_month"] ?? 0));
            } catch (Throwable $exception) {
                error_log("kpi-import company: prepare failed (" . get_class($exception) . ")");
                $companyResult = ["errors" => ["ตรวจสอบข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"]];
            }
            $fileErrors = $companyResult["errors"];

            /* มีรายการ READY → ใช้ Preview / ยืนยัน เดียวกับ Template (token ใช้ครั้งเดียว) */
            if (!$companyResult["errors"] && $companyResult["rows"]) {
                $_SESSION["kpi_import"] = [
                    "token" => bin2hex(random_bytes(16)),
                    "employee_id" => $employeeId,
                    "created_at" => time(),
                    "file_name" => mb_substr(basename((string) ($file["name"] ?? "")), 0, 120),
                    "format" => "company",
                    "company" => [
                        "items" => $companyResult["items"],
                        "notes" => $companyResult["notes"],
                        "skipped_empty" => $companyResult["skipped_empty"]
                    ],
                    "parsed" => $companyResult["rows"],
                    "preview" => $companyResult["preview"],
                    "ok" => $companyResult["commit_ok"]
                ];

                header("Location: kpi-import.php");
                exit;
            }
        }

    } elseif ($action === "upload") {

        /* ปิดการนำเข้าผ่าน Template ของระบบ (ทุก KPI) · รับเฉพาะไฟล์ Excel ของบริษัทที่ยืนยัน Mapping แล้ว */
        unset($_SESSION["kpi_import"]);
        $pending = null;
        $fileErrors[] = "ระบบปิดการนำเข้าผ่าน Template แล้ว กรุณานำเข้าจากไฟล์ Excel ของบริษัท (เฉพาะ KPI ที่ยืนยัน Mapping แล้ว)";

    } elseif ($action === "confirm" || $action === "cancel") {

        $token = (string) ($_POST["import_token"] ?? "");

        if ($pending === null || !hash_equals((string) $pending["token"], $token)) {

            $errors[] = "ไม่พบรายการที่รอยืนยัน หรือรายการหมดอายุแล้ว กรุณาอัปโหลดไฟล์อีกครั้ง";
            unset($_SESSION["kpi_import"]);
            $pending = null;

        } elseif ($action === "cancel") {

            unset($_SESSION["kpi_import"]);
            $_SESSION["kpi_import_flash"] = ["type" => "info", "messages" => ["ยกเลิกการนำเข้าแล้ว ไม่มีข้อมูลถูกบันทึก"]];
            header("Location: kpi-import.php");
            exit;

        } else {

            /* ใช้ token ได้ครั้งเดียว (กดยืนยันซ้ำ / เปิดหลายแท็บ ไม่บันทึกซ้ำ) */
            unset($_SESSION["kpi_import"]);

            $confirmed = $_POST["confirm_update"] ?? [];
            $confirmed = is_array($confirmed) ? array_values(array_filter($confirmed, "is_string")) : [];

            /* ยืนยันรายแถวที่ระบบเตือน (เช่น Budget > 0 แต่ Total R = 0) · ธงต้องยืนยันมาจาก Session ไม่ใช่จากฟอร์ม */
            $confirmedFlags = $_POST["confirm_flag"] ?? [];
            $confirmedFlags = is_array($confirmedFlags) ? array_values(array_filter($confirmedFlags, "is_string")) : [];

            /* ที่มาของข้อมูลกำหนดจาก Session (Template / ไฟล์บริษัท) ใช้ตรวจซ้ำด้วยกฎเดียวกับตอน Preview */
            $origin = ($pending["format"] ?? "template") === "company" ? KPI_IMPORT_ORIGIN_COMPANY : KPI_IMPORT_ORIGIN_TEMPLATE;

            $recheck = ($pending["format"] ?? "template") === "company"
                ? kpiCompanyRecheckKpis($pdo, $pending["company"]["items"])
                : null;

            if ($origin !== KPI_IMPORT_ORIGIN_COMPANY) {
                $summary = ["ok" => false, "errors" => ["ระบบปิดการนำเข้าผ่าน Template แล้ว ไม่มีข้อมูลถูกบันทึก"]];
            } elseif (!$pending["ok"]) {
                $summary = ["ok" => false, "errors" => ["ไฟล์นี้ยังมีข้อมูลที่ไม่ถูกต้อง จึงยังนำเข้าไม่ได้"]];
            } elseif ($recheck !== null) {
                $summary = ["ok" => false, "errors" => [$recheck]];
            } else {
                $summary = kpiImportCommit($pdo, $employeeId, $pending["parsed"], $pending["preview"], $confirmed, $origin, $confirmedFlags);
            }

            $_SESSION["kpi_import_flash"] = $summary["ok"]
                ? [
                    "type" => "success",
                    "messages" => [
                        "นำเข้าเรียบร้อยแล้ว: เพิ่มใหม่ {$summary["inserted"]} รายการ · อัปเดต Draft {$summary["updated"]} รายการ"
                        . " · ไม่เปลี่ยนแปลง {$summary["unchanged"]} รายการ · ไม่ได้ยืนยันอัปเดต {$summary["skipped"]} รายการ",
                        ...(($summary["declined"] ?? 0) > 0
                            ? ["ไม่บันทึก {$summary["declined"]} รายการ เนื่องจากไม่ได้ยืนยันรายการที่ระบบเตือน (เช่น ผล 0% จาก Total R = 0)"]
                            : []),
                        "รายการที่นำเข้ามีสถานะ Draft ดูผลได้ที่หน้าผลการปฏิบัติงาน"
                    ]
                ]
                : ["type" => "error", "messages" => $summary["errors"]];

            header("Location: kpi-import.php");
            exit;
        }

    } else {

        $errors[] = "คำขอไม่ถูกต้อง";
    }
}

/* ตัวเลือกปีสำหรับ Template */
$yearStmt = $pdo->prepare("
    SELECT DISTINCT assignment_year
    FROM kpi_assignments
    WHERE employee_id = :employee_id AND status = 'Active'
");
$yearStmt->execute([":employee_id" => $employeeId]);
$templateYears = array_map("intval", $yearStmt->fetchAll(PDO::FETCH_COLUMN));
$templateYears[] = (int) date("Y");
$templateYears = array_unique($templateYears);
rsort($templateYears);

/* สถานะความพร้อมของ KPI ที่ได้รับมอบหมายปีนี้: พร้อม = ยืนยัน Mapping กับไฟล์บริษัทแล้ว */
$readinessStmt = $pdo->prepare("
    SELECT DISTINCT k.kpi_id, k.kpi_name, k.kpi_type
    FROM kpi_assignments a
    INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
    WHERE a.employee_id = :employee_id AND a.status = 'Active' AND a.assignment_year = :year
    ORDER BY k.kpi_id
");
$readinessStmt->execute([":employee_id" => $employeeId, ":year" => (int) date("Y")]);
$readiness = array_map(
    fn($kpi) => $kpi + ["ready" => isset(kpiImportCompanyOnlyKpiIds()[(int) $kpi["kpi_id"]])],
    $readinessStmt->fetchAll(PDO::FETCH_ASSOC)
);

$counts = ["insert" => 0, "update" => 0, "unchanged" => 0, "error" => 0];

if ($pending !== null) {
    foreach ($pending["preview"] as $row) {
        $counts[$row["action"]]++;
    }
}

$hasWork = $counts["insert"] + $counts["update"] > 0;

/* แบบฟอร์มบริษัท: แสดงเซลล์ต้นทางแทนเลขแถว */
$sourceByRow = [];
foreach ($pending["parsed"] ?? [] as $parsedRow) {
    if (isset($parsedRow["source"])) {
        $sourceByRow[$parsedRow["row"]] = $parsedRow["source"];
    }
}

$actionLabels = [
    "insert" => ["รายการใหม่", "badge-insert"],
    "update" => ["อัปเดต Draft", "badge-update"],
    "unchanged" => ["ไม่เปลี่ยนแปลง", "badge-unchanged"],
    "error" => ["นำเข้าไม่ได้", "badge-error"]
];

?>
<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>นำเข้าผลงานจาก Excel | KPI Management System</title>

    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/icons.css?v=1">
    <link rel="stylesheet" href="../assets/css/employee-kpi.css?v=layout-employee-2">
    <link rel="stylesheet" href="../assets/css/employee-kpi-import.css?v=1">

</head>

<body>

    <?php employeeLayoutStart("import", "../"); ?>

        <section class="page-header import-page-header">
            <div>
                <h1>บันทึกผล KPI (Excel)</h1>
                <p>นำเข้าผลการปฏิบัติงานจากไฟล์ Excel จริงของบริษัท เฉพาะ KPI ที่ยืนยัน Mapping แล้ว · ระบบคำนวณคะแนนเองตามเกณฑ์ของแต่ละ KPI</p>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="import-alert import-alert-<?= $e($flash["type"]) ?>" role="status">
                <?php foreach ($flash["messages"] as $message): ?>
                    <p><?= $e($message) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors || $fileErrors): ?>
            <div class="import-alert import-alert-error" role="alert">
                <?php foreach (array_merge($errors, $fileErrors) as $message): ?>
                    <p><?= $e($message) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php $companyView = $companyResult ?? ($pending["company"] ?? null); ?>
        <?php if ($companyView !== null && empty($companyView["errors"])): ?>
            <?php
            $companyResult = $companyView;
            $statusCounts = ["READY" => 0, "NEEDS_CONFIRMATION" => 0, "REJECTED" => 0];
            foreach ($companyResult["items"] as $item) {
                $statusCounts[$item["status"]]++;
            }
            $statusLabels = [
                "READY" => ["พร้อมนำเข้า", "badge-insert"],
                "NEEDS_CONFIRMATION" => ["รอยืนยัน Mapping", "badge-update"],
                "REJECTED" => ["ใช้ไม่ได้", "badge-error"]
            ];
            $formatValue = static fn($v): string => is_float($v) ? rtrim(rtrim(number_format($v, 6, ".", ""), "0"), ".") : (string) $v;
            ?>
            <section class="import-card">
                <h2><?= icon("clipboard-list") ?>ผลการอ่านแบบฟอร์ม KPI AM ของบริษัท</h2>
                <div class="import-alert import-alert-info" role="status">
                    <?php if ($pending !== null): ?>
                        <p>นำเข้าได้เฉพาะรายการ "พร้อมนำเข้า" (ตรวจสอบในตารางด้านล่างก่อนยืนยัน) · รายการอื่นไม่ถูกบันทึก · คะแนนคำนวณโดยระบบ ไม่ใช้เกรดจาก Excel</p>
                    <?php else: ?>
                        <p>ไม่มีรายการที่พร้อมนำเข้า ระบบไม่บันทึกข้อมูลจากไฟล์นี้ · หัวข้อที่ยังไม่ยืนยัน Mapping ต้องให้เจ้าของระบบยืนยันก่อน</p>
                    <?php endif; ?>
                    <?php foreach ($companyResult["notes"] as $note): ?>
                        <p><?= $e($note) ?></p>
                    <?php endforeach; ?>
                </div>
                <div class="import-counts">
                    <span class="badge-insert">พร้อมนำเข้า <?= $statusCounts["READY"] ?></span>
                    <span class="badge-update">รอยืนยัน Mapping <?= $statusCounts["NEEDS_CONFIRMATION"] ?></span>
                    <span class="badge-error">ใช้ไม่ได้ <?= $statusCounts["REJECTED"] ?></span>
                    <span class="badge-unchanged">เดือนที่ไม่มีข้อมูล (ข้าม) <?= (int) $companyResult["skipped_empty"] ?></span>
                </div>
                <div class="import-table-wrapper">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th>ส่วน</th>
                                <th>หัวข้อในแบบฟอร์ม</th>
                                <th>เดือน</th>
                                <th>แหล่งที่มา</th>
                                <th>ค่าที่อ่านได้</th>
                                <th>KPI ในระบบ</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companyResult["items"] as $item): ?>
                                <?php [$label, $class] = $statusLabels[$item["status"]]; ?>
                                <tr class="row-<?= $item["status"] === "REJECTED" ? "error" : "ok" ?>">
                                    <td><?= $item["section"] === "performance" ? "Performance" : "Competency" ?></td>
                                    <td><?= $e($item["label"]) ?><span class="import-sub">น้ำหนัก <?= $e($item["weight"] ?? "-") ?></span></td>
                                    <td><?= $e($item["month_label"]) ?></td>
                                    <td><?= $e($item["source"]) ?><span class="import-sub"><?= $e($item["kind"]) ?><?= $item["formula"] !== null ? " · " . $e($item["formula"]) : "" ?></span></td>
                                    <td><?= $e($formatValue($item["value"])) ?></td>
                                    <td><?= $item["kpi_id"] !== null ? $e($item["kpi_id"]) : "-" ?></td>
                                    <td>
                                        <span class="<?= $e($class) ?>"><?= $e($label) ?></span>
                                        <?php foreach ($item["reasons"] as $reason): ?>
                                            <span class="import-error-text"><?= $e($reason) ?></span>
                                        <?php endforeach; ?>
                                        <?php foreach ($item["warnings"] ?? [] as $warning): ?>
                                            <span class="import-warning-text"><?= $e($warning) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($pending === null): ?>

            <section class="import-card">
                <h2><?= icon("file-spreadsheet") ?>1. KPI ที่พร้อมนำเข้าจากไฟล์ Excel ของบริษัท (ปี <?= (int) date("Y") ?>)</h2>
                <p class="import-hint">
                    ระบบรับเฉพาะไฟล์ Excel จริงของบริษัท (แบบฟอร์ม KPI AM) และนำเข้าได้เฉพาะ KPI ที่ยืนยัน Mapping แล้ว ·
                    KPI ที่ยังไม่พร้อมจะไม่ถูกบันทึก (ระบบปิดการนำเข้าผ่าน Template แล้ว)
                </p>
                <div class="import-table-wrapper">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th>kpi_id</th>
                                <th>KPI</th>
                                <th>ประเภท</th>
                                <th>สถานะการนำเข้า</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($readiness as $kpi): ?>
                                <tr>
                                    <td><?= $e($kpi["kpi_id"]) ?></td>
                                    <td><?= $e($kpi["kpi_name"]) ?></td>
                                    <td><?= $e($kpi["kpi_type"]) ?></td>
                                    <td>
                                        <?php if ($kpi["ready"]): ?>
                                            <span class="badge-insert">พร้อมนำเข้าจากไฟล์บริษัท</span>
                                        <?php else: ?>
                                            <span class="badge-unchanged">ยังไม่พร้อมนำเข้า (รอยืนยัน Mapping)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$readiness): ?>
                                <tr><td colspan="4">ยังไม่มี KPI ที่ได้รับมอบหมายในปีนี้</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="import-card">
                <h2><?= icon("send") ?>2. อัปโหลดไฟล์ Excel ของบริษัท</h2>
                <p class="import-hint">
                    รองรับเฉพาะไฟล์ .xlsx ขนาดไม่เกิน <?= KPI_IMPORT_MAX_FILE_BYTES / 1024 / 1024 ?> MB · เลือกปีและเดือนของข้อมูล ·
                    ระบบจะแสดงตัวอย่างให้ตรวจก่อน ยังไม่บันทึกจนกว่าคุณจะกดยืนยัน
                </p>
                <form method="post" enctype="multipart/form-data" class="import-inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="format" value="company">
                    <label for="company_year">ปี</label>
                    <select name="company_year" id="company_year">
                        <?php foreach ($templateYears as $year): ?>
                            <option value="<?= $year ?>" <?= $year === (int) date("Y") ? "selected" : "" ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="company_month">เดือน</label>
                    <select name="company_month" id="company_month">
                        <?php foreach ($thaiMonths as $number => $name): ?>
                            <option value="<?= $number ?>" <?= $number === (int) date("n") ? "selected" : "" ?>><?= $e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= KPI_IMPORT_MAX_FILE_BYTES ?>">
                    <input type="file" name="import_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    <button type="submit" class="import-button">ตรวจสอบไฟล์</button>
                </form>
            </section>

        <?php else: ?>

            <section class="import-card">

                <h2><?= icon("clipboard-check") ?>ตรวจสอบก่อนนำเข้า</h2>

                <p class="import-hint">
                    ไฟล์: <strong><?= $e($pending["file_name"]) ?></strong> ·
                    พนักงาน: <strong><?= $e(trim((string) ($_SESSION["first_name"] ?? "") . " " . (string) ($_SESSION["last_name"] ?? ""))) ?></strong>
                    (<?= $e((string) ($_SESSION["employee_code"] ?? "-")) ?>) ·
                    ยังไม่มีข้อมูลถูกบันทึก · คะแนนในตารางคำนวณโดยระบบ (ไม่ได้อ่านจาก Excel) ·
                    สถานะหลังบันทึก: <strong>Draft</strong> (ยังไม่ส่งหรืออนุมัติ)
                </p>

                <div class="import-counts">
                    <span class="badge-insert">รายการใหม่ <?= $counts["insert"] ?></span>
                    <span class="badge-update">อัปเดต Draft <?= $counts["update"] ?></span>
                    <span class="badge-unchanged">ไม่เปลี่ยนแปลง <?= $counts["unchanged"] ?></span>
                    <span class="badge-error">นำเข้าไม่ได้ <?= $counts["error"] ?></span>
                </div>

                <?php if (!$pending["ok"] && ($pending["format"] ?? "template") === "company"): ?>
                    <div class="import-alert import-alert-error" role="alert">
                        <p>มีรายการที่ยืนยัน Mapping แล้วแต่ไม่ผ่านการตรวจสอบ ระบบจะไม่บันทึกข้อมูลใดจากไฟล์นี้ ดูเหตุผลในตารางผลการอ่านด้านบน</p>
                    </div>
                <?php elseif (!$pending["ok"]): ?>
                    <div class="import-alert import-alert-error" role="alert">
                        <p>พบข้อมูลที่นำเข้าไม่ได้ <?= $counts["error"] ?> แถว ระบบจะไม่บันทึกข้อมูลใดจากไฟล์นี้ กรุณาแก้ไขไฟล์ตามเหตุผลในตารางแล้วอัปโหลดใหม่</p>
                    </div>
                <?php elseif (!$hasWork): ?>
                    <div class="import-alert import-alert-info" role="status">
                        <p>ข้อมูลทุกแถวตรงกับที่บันทึกไว้แล้ว ไม่มีรายการที่ต้องนำเข้า</p>
                    </div>
                <?php endif; ?>

                <form method="post">

                    <?= csrfField() ?>
                    <input type="hidden" name="import_token" value="<?= $e($pending["token"]) ?>">

                    <div class="import-table-wrapper">
                        <table class="import-table">
                            <thead>
                                <tr>
                                    <th>แถว</th>
                                    <th>KPI</th>
                                    <th>เดือน</th>
                                    <th>ค่าที่นำเข้า</th>
                                    <th>ข้อมูลต้นทาง</th>
                                    <th>Target</th>
                                    <th>คะแนน (ระบบ)</th>
                                    <th>รายการเดิม</th>
                                    <th>ผลตรวจ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending["preview"] as $row): ?>
                                    <?php [$label, $class] = $actionLabels[$row["action"]]; ?>
                                    <tr class="row-<?= $e($row["action"]) ?>">
                                        <td><?= $e($sourceByRow[$row["row"]] ?? (string) (int) $row["row"]) ?></td>
                                        <td>
                                            <?php if ($row["kpi_name"] !== null): ?>
                                                <strong><?= $e($row["kpi_id"]) ?></strong> <?= $e($row["kpi_name"]) ?>
                                                <span class="import-sub"><?= $e($row["kpi_type"]) ?><?= $row["unit"] ? " · หน่วย " . $e($row["unit"]) : "" ?></span>
                                            <?php else: ?>
                                                <?= $e($row["kpi_id"] ?? "-") ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $e($row["month_label"]) ?></td>
                                        <td>
                                            <?php if (strtolower((string) $row["kpi_type"]) === "competency"): ?>
                                                คะแนน <?= $e($row["score"] ?? $row["raw_competency"] ?? "-") ?>
                                            <?php else: ?>
                                                <?= $e($row["actual"] ?? (is_scalar($row["raw_actual"]) ? $row["raw_actual"] : "-")) ?>
                                            <?php endif; ?>
                                            <?php if ($row["comment"] !== null): ?>
                                                <span class="import-sub">หมายเหตุ: <?= $e(mb_strimwidth($row["comment"], 0, 80, "…")) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row["details"])): ?>
                                                Budget (<?= $e($row["details"]["budget_cell"]) ?>) <?= $e($row["details"]["budget"]) ?>
                                                <span class="import-sub">Total R (<?= $e($row["details"]["total_r_cell"]) ?>) <?= $e($row["details"]["total_r"]) ?> · สัดส่วน <?= $e($row["details"]["ratio"]) ?> → Actual <?= $e($row["details"]["actual"]) ?>%</span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= strtolower((string) $row["kpi_type"]) === "competency" ? "-" : $e($row["target"] ?? "-") ?></td>
                                        <td><?= $row["action"] !== "error" && $row["score"] !== null ? $e($row["score"]) : "-" ?></td>
                                        <td>
                                            <?php if ($row["existing"]): ?>
                                                <?= $e($row["existing"]["status"]) ?>
                                                <span class="import-sub">
                                                    <?= $row["existing"]["actual"] !== null ? "Actual " . $e($row["existing"]["actual"]) . " · " : "" ?>คะแนน <?= $e($row["existing"]["score"] ?? "-") ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?= $e($class) ?>"><?= $e($label) ?></span>
                                            <?php if ($row["action"] === "update" && $pending["ok"]): ?>
                                                <label class="import-confirm-update">
                                                    <input type="checkbox" name="confirm_update[]" value="<?= $e($row["key"]) ?>">
                                                    ยืนยันอัปเดตรายการนี้
                                                </label>
                                            <?php endif; ?>
                                            <?php if (!empty($row["requires_confirmation"]) && in_array($row["action"], ["insert", "update"], true)): ?>
                                                <?php foreach (array_filter($row["warnings"] ?? [], fn($w) => str_starts_with($w, "คำเตือน")) as $warning): ?>
                                                    <span class="import-warning-text"><?= $e($warning) ?></span>
                                                <?php endforeach; ?>
                                                <?php if ($pending["ok"]): ?>
                                                    <label class="import-confirm-update">
                                                        <input type="checkbox" name="confirm_flag[]" value="<?= $e($row["key"]) ?>">
                                                        ยืนยันว่าตรวจไฟล์แล้วและผลจริงเป็น 0% (ไม่ติ๊ก = ไม่บันทึกรายการนี้)
                                                    </label>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php foreach ($row["errors"] as $message): ?>
                                                <span class="import-error-text"><?= $e($message) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="import-hint">
                        รายการใหม่จะถูกบันทึกเป็น Draft · รายการเดิมสถานะ Draft จะถูกอัปเดตเฉพาะที่คุณติ๊กยืนยัน ·
                        ระบบไม่แก้ไขรายการที่ส่งแล้วหรืออนุมัติแล้ว · ถ้าข้อมูลในระบบเปลี่ยนไปหลังแสดงตัวอย่าง ระบบจะยกเลิกทั้งชุด
                    </p>

                    <div class="import-actions">
                        <button type="submit" name="action" value="cancel" class="import-button import-button-secondary" formnovalidate>ยกเลิก</button>
                        <?php if ($pending["ok"] && $hasWork): ?>
                            <button type="submit" name="action" value="confirm" class="import-button">ยืนยันนำเข้า</button>
                        <?php endif; ?>
                    </div>

                </form>

            </section>

        <?php endif; ?>

    <?php employeeLayoutEnd("../"); ?>

</body>

</html>
