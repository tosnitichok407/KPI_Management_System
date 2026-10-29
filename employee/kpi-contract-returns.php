<?php

/*
|--------------------------------------------------------------------------
| พนักงาน: KPI12 การรับคืนสัญญาตัวจริง (บันทึกรายสัญญา)
|--------------------------------------------------------------------------
|
| สรุปรายเดือน (จากรายการที่อนุมัติแล้วเท่านั้น · ไม่บันทึกเป็นคะแนน KPI)
| ฟอร์ม: เพิ่ม / แก้ไขฉบับร่าง (?edit=) · ขอแก้ไขรายการที่อนุมัติแล้ว (?revise=)
| รายการของฉัน: สถานะเอกสาร · สถานะอนุมัติ · ความเห็น Manager · หลักฐาน · ประวัติ
| ข้อมูลและไฟล์ถูกตรวจซ้ำที่ includes/kpi-contract-return.php
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/kpi-import-history.php";
require_once __DIR__ . "/../includes/kpi-contract-return.php";
require_once __DIR__ . "/includes/layout.php";

/** @var PDO $pdo */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ((int) ($_SESSION["role_id"] ?? 0) !== 3 || $employeeId <= 0) {
    redirectToRoleHome("../");
}

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
$monthNames = monthlyPeriodMonths();
$enabled = kpiContractReturnEnabled($pdo);
$eligible = $enabled && kpiContractReturnEmployeeEligible($pdo, $employeeId);
$today = kpiContractReturnToday();

$flash = $_SESSION["contract_return_flash"] ?? null;
$old = $_SESSION["contract_return_old"] ?? [];
unset($_SESSION["contract_return_flash"], $_SESSION["contract_return_old"]);

$periods = $enabled ? $pdo->query("SELECT period_id, period_year, period_month FROM evaluation_periods ORDER BY period_year DESC, period_month DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
$periodLabel = static fn($year, $month): string => $monthNames[(int) $month] . " " . $year;

/* รายการที่กำลังแก้ไข (ฉบับร่าง / ส่งกลับ) หรือขอแก้ไข (อนุมัติแล้ว) · ต้องเป็นของตัวเองเท่านั้น */
$editing = null;
$mode = "create";
foreach (["edit" => ["Draft", "Rejected"], "revise" => ["Approved"]] as $param => $statuses) {
    $id = (int) ($_GET[$param] ?? 0);
    if ($enabled && $id > 0) {
        $row = kpiContractReturnGet($pdo, $id);
        if ($row !== null && (int) $row["employee_id"] === $employeeId && in_array($row["review_status"], $statuses, true)
            && !($param === "revise" && $row["pending_revision_id"])) {
            $editing = $row;
            $mode = $param;
        }
    }
}

$form = $old ?: ($editing ?? []);
$formValue = static fn(string $key): string => (string) ($form[$key] ?? "");
$editingFiles = $editing ? kpiContractReturnFiles($pdo, (int) $editing["return_id"]) : [];

$myRows = $enabled ? kpiContractReturnList($pdo, ["employee_id" => $employeeId]) : [];

/* สรุปเฉพาะเดือนที่มีรายการ */
$summaryPeriods = [];
foreach ($myRows as $row) {
    $summaryPeriods[(int) $row["contract_period_id"]] = $periodLabel($row["period_year"], $row["period_month"]);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPI12 การรับคืนสัญญาตัวจริง</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/icons.css?v=1">
    <link rel="stylesheet" href="../assets/css/employee-kpi.css?v=layout-employee-2">
    <link rel="stylesheet" href="../assets/css/kpi-correction.css?v=3">
</head>
<body>

<?php employeeLayoutStart("contract_returns", "../"); ?>

    <section class="page-header">
        <div class="page-title-block">
            <h1>KPI12 การรับคืนสัญญาตัวจริง</h1>
            <p>บันทึกแยกตามสัญญา · แนบหลักฐาน · ส่งให้ผู้จัดการสาขาอนุมัติ · ผลสรุปคำนวณจากรายการที่อนุมัติแล้วเท่านั้น</p>
        </div>
    </section>

    <?php if (is_array($flash)): ?>
        <div class="corr-flash corr-flash-<?= $flash["type"] === "success" ? "success" : "error" ?>" role="status">
            <?php foreach ($flash["messages"] as $message): ?><div><?= $e($message) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$enabled): ?>
        <section class="corr-card"><p>ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ</p></section>
    <?php elseif (!$eligible): ?>
        <section class="corr-card"><p>ไม่พบการมอบหมาย KPI12 ของคุณ จึงบันทึกรายการไม่ได้ กรุณาติดต่อผู้ดูแลระบบ</p></section>
    <?php else: ?>

    <section class="corr-card">
        <h2><?= icon("chart-column") ?>สรุปตามเดือนของสัญญา</h2>
        <p class="corr-muted">อัตรารับคืนตรงเวลา = รับคืนภายในวันครบกำหนด ÷ สัญญาที่เลยวันครบกำหนดแล้ว (เฉพาะรายการที่อนุมัติ) · 100% = เกรด 5 · ต่ำกว่า 100% รอการกำหนดเกณฑ์ · ยังไม่บันทึกเป็นคะแนน KPI · ประเมิน ณ <?= $e(kpiContractReturnDateText($today)) ?></p>
        <?php if (!$summaryPeriods): ?>
            <p class="corr-muted">ยังไม่มีรายการ</p>
        <?php else: ?>
            <div class="corr-table-wrap">
                <table class="corr-table corr-list">
                    <thead><tr><th>เดือนของสัญญา</th><th>รายการ</th><th>ผล (อนุมัติแล้ว)</th><th>ไม่นับ</th><th>คำเตือน</th></tr></thead>
                    <tbody>
                        <?php foreach ($summaryPeriods as $periodId => $label): ?>
                            <?php $summary = kpiContractReturnSummary($pdo, $employeeId, $periodId, $today); ?>
                            <tr>
                                <td><?= $e($label) ?></td>
                                <td><?= (int) $summary["total"] ?></td>
                                <td><strong><?= $e(kpiContractReturnSummaryText($summary)) ?></strong>
                                    <?php if ($summary["late"] || $summary["overdue"]): ?><div class="corr-muted">คืนเกินกำหนด <?= (int) $summary["late"] ?> · ค้างเกินกำหนด <?= (int) $summary["overdue"] ?></div><?php endif; ?>
                                </td>
                                <td class="corr-muted">ยังไม่ถึงกำหนด <?= (int) $summary["pending_due"] ?> · ยังไม่ส่ง <?= (int) $summary["not_sent"] ?> · ยกเลิก <?= (int) $summary["cancelled"] ?></td>
                                <td><?php foreach ($summary["warnings"] as $warning): ?><div class="corr-warning"><?= $e($warning) ?></div><?php endforeach; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="corr-card" id="form">
        <h2><?= icon("square-pen") ?>
            <?php if ($mode === "edit"): ?>แก้ไขรายการ <?= $e($editing["contract_no_new"]) ?> (<?= $e(KPI_CONTRACT_RETURN_REVIEW_LABELS[$editing["review_status"]]) ?>)
            <?php elseif ($mode === "revise"): ?>ขอแก้ไขรายการที่อนุมัติแล้ว <?= $e($editing["contract_no_new"]) ?>
            <?php else: ?>เพิ่มรายการสัญญา<?php endif; ?>
        </h2>

        <?php if ($mode === "edit" && $editing["review_status"] === "Rejected" && $editing["review_comment"]): ?>
            <div class="corr-returned"><strong>เหตุผลที่ผู้จัดการส่งกลับ:</strong> <?= nl2br($e($editing["review_comment"])) ?></div>
        <?php endif; ?>
        <?php if ($mode === "revise"): ?>
            <p class="corr-warning">ค่าที่อนุมัติแล้วยังใช้คำนวณต่อจนกว่าผู้จัดการจะอนุมัติคำขอแก้ไข · ระบบเก็บค่าเดิมไว้ในประวัติ</p>
        <?php endif; ?>
        <?php if ($mode !== "create"): ?>
            <p><a class="corr-link" href="kpi-contract-returns.php#form">+ เพิ่มรายการใหม่แทน</a></p>
        <?php endif; ?>

        <form method="post" action="kpi-contract-return-action.php" enctype="multipart/form-data" class="corr-form" id="contractReturnForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $mode === "revise" ? "revise" : "save" ?>">
            <input type="hidden" name="return_id" value="<?= (int) ($editing["return_id"] ?? 0) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= KPI_EVIDENCE_MAX_BYTES ?>">

            <div class="corr-field">
                <label for="contract_period_id">เดือนของสัญญา <span class="corr-required">*</span></label>
                <select name="contract_period_id" id="contract_period_id" required>
                    <option value="">เลือกเดือน</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int) $period["period_id"] ?>" <?= (int) $period["period_id"] === (int) $formValue("contract_period_id") ? "selected" : "" ?>><?= $e($periodLabel($period["period_year"], $period["period_month"])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="corr-field">
                <label for="contract_no_new">เลขที่สัญญาใหม่ <span class="corr-required">*</span></label>
                <input type="text" name="contract_no_new" id="contract_no_new" value="<?= $e($formValue("contract_no_new")) ?>" maxlength="20" required autocomplete="off" placeholder="CT26-0000001" pattern="CT[0-9]{2}-[0-9]{7}">
                <span class="corr-muted">ตัวระบุหลักของรายการ · รูปแบบ CTปป-เลข 7 หลัก · หากเลขในไฟล์ Excel ผิด ให้ผู้รับผิดชอบแก้ที่ไฟล์ต้นทาง (ระบบไม่แก้เลขให้)</span>
            </div>

            <div class="corr-field">
                <label for="contract_no_old">เลขที่สัญญาเดิม</label>
                <input type="text" name="contract_no_old" id="contract_no_old" value="<?= $e($formValue("contract_no_old")) ?>" maxlength="20" autocomplete="off" placeholder="CT25-0000001" pattern="CT[0-9]{2}-[0-9]{7}">
                <span class="corr-muted">ถ้ามี (สัญญาต่ออายุ)</span>
            </div>

            <div class="corr-field">
                <label for="customer_name">ชื่อลูกค้า <span class="corr-required">*</span></label>
                <input type="text" name="customer_name" id="customer_name" value="<?= $e($formValue("customer_name")) ?>" maxlength="<?= KPI_CONTRACT_RETURN_NAME_MAX ?>" required autocomplete="off">
                <span class="corr-muted">ชื่อบริษัท / ลูกค้าตามสัญญา · ไม่ต้องกรอกชื่อผู้ติดต่อหรือเบอร์โทร</span>
            </div>

            <div class="corr-field">
                <label for="doc_status">สถานะเอกสาร <span class="corr-required">*</span></label>
                <select name="doc_status" id="doc_status" required>
                    <?php foreach (KPI_CONTRACT_RETURN_DOC_LABELS as $value => $label): ?>
                        <option value="<?= $e($value) ?>" <?= $formValue("doc_status") === $value ? "selected" : "" ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="corr-muted">การแนบไฟล์ไม่เปลี่ยนสถานะเอกสาร · "รับสัญญาคืนแล้ว" ต้องมีวันที่รับคืนจริง<?= KPI_CONTRACT_RETURN_REQUIRE_FILE_FOR_RETURNED ? " และหลักฐานอย่างน้อย 1 ไฟล์" : "" ?></span>
            </div>

            <div class="corr-field" data-show-for="sent returned cancelled">
                <label for="sent_date">วันที่ส่งสัญญาตัวจริง</label>
                <input type="date" name="sent_date" id="sent_date" value="<?= $e($formValue("sent_date")) ?>" max="<?= $e($today) ?>">
            </div>

            <div class="corr-field" data-show-for="sent returned cancelled">
                <label for="due_preview">วันครบกำหนดคืน (ระบบคำนวณ)</label>
                <input type="text" id="due_preview" value="" readonly tabindex="-1">
                <span class="corr-muted">= วันที่ <?= KPI_CONTRACT_RETURN_DUE_DAY ?> ของเดือนถัดจากเดือนที่ส่งสัญญา · แก้ไขเองไม่ได้</span>
            </div>

            <div class="corr-field" data-show-for="returned cancelled">
                <label for="returned_date">วันที่รับคืนจริง</label>
                <input type="date" name="returned_date" id="returned_date" value="<?= $e($formValue("returned_date")) ?>" max="<?= $e($today) ?>">
                <span class="corr-muted" id="timeliness_preview"></span>
            </div>

            <div class="corr-field" data-show-for="cancelled">
                <label for="cancel_reason">เหตุผลที่ยกเลิก <span class="corr-required">*</span></label>
                <textarea name="cancel_reason" id="cancel_reason" rows="2" maxlength="<?= KPI_CONTRACT_RETURN_TEXT_MAX ?>"><?= $e($formValue("cancel_reason")) ?></textarea>
                <span class="corr-muted">การยกเลิกมีผลเมื่อผู้จัดการอนุมัติ</span>
            </div>

            <div class="corr-field">
                <label for="note">หมายเหตุ</label>
                <textarea name="note" id="note" rows="2" maxlength="<?= KPI_CONTRACT_RETURN_TEXT_MAX ?>"><?= $e($formValue("note")) ?></textarea>
            </div>

            <?php if ($mode === "revise"): ?>
                <div class="corr-field">
                    <label for="revision_reason">เหตุผลในการขอแก้ไข <span class="corr-required">*</span></label>
                    <textarea name="revision_reason" id="revision_reason" rows="3" maxlength="<?= KPI_CONTRACT_RETURN_REASON_MAX ?>" required placeholder="ข้อมูลเดิมผิดอย่างไร และหลักฐานที่แนบยืนยันอะไร"><?= $e((string) ($old["revision_reason"] ?? "")) ?></textarea>
                </div>
            <?php endif; ?>

            <div class="corr-field">
                <label for="files">หลักฐาน (เช่น สำเนาสัญญาที่ลงนาม / ใบรับเอกสาร)</label>
                <?php if ($editingFiles): ?>
                    <ul>
                        <?php foreach ($editingFiles as $file): ?>
                            <?php if ($file["revision_id"] !== null && $file["revision_status"] !== "Approved") continue; ?>
                            <li><a class="corr-link" href="../file-download.php?type=contract_return&amp;id=<?= (int) $file["file_id"] ?>"><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a>
                                <span class="corr-muted"><?= $e(kpiFormatFileSize((int) $file["file_size"])) ?></span>
                                <?php if ($mode === "edit" && $file["revision_id"] === null): ?>
                                    <button type="submit" form="deleteFile<?= (int) $file["file_id"] ?>" class="corr-link" onclick="return confirm('ลบไฟล์นี้?');"><?= icon("trash-2") ?>ลบ</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <input type="file" name="files[]" id="files" multiple accept="<?= KPI_EVIDENCE_ACCEPT ?>">
                <span class="corr-muted">Excel (.xlsx .xls) PDF หรือรูปภาพ (.jpg .png) · ไฟล์ละไม่เกิน 10 MB · สูงสุด <?= KPI_CONTRACT_RETURN_MAX_FILES ?> ไฟล์ต่อรายการ</span>
            </div>

            <button type="submit" class="corr-button"><?= $mode === "revise" ? icon("send") . "ส่งคำขอแก้ไขให้ผู้จัดการสาขา" : icon("save") . "บันทึกฉบับร่าง" ?></button>
        </form>

        <?php if ($mode === "edit"): ?>
            <?php foreach ($editingFiles as $file): ?>
                <?php if ($file["revision_id"] === null): ?>
                    <form method="post" action="kpi-contract-return-action.php" id="deleteFile<?= (int) $file["file_id"] ?>" hidden>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_file">
                        <input type="hidden" name="return_id" value="<?= (int) $editing["return_id"] ?>">
                        <input type="hidden" name="file_id" value="<?= (int) $file["file_id"] ?>">
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
            <form method="post" action="kpi-contract-return-action.php" class="corr-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="return_id" value="<?= (int) $editing["return_id"] ?>">
                <p class="corr-muted">ส่งข้อมูลที่บันทึกไว้ล่าสุด (บันทึกฉบับร่างก่อนหากแก้ไขฟอร์มด้านบน) · หลังส่งแก้ไขไม่ได้จนกว่าผู้จัดการจะส่งกลับ</p>
                <button type="submit" class="corr-button" onclick="return confirm('ส่งรายการ <?= $e($editing["contract_no_new"]) ?> ให้ผู้จัดการสาขาอนุมัติ?');"><?= icon("send") ?>ส่งให้ Manager</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="corr-card">
        <h2><?= icon("clipboard-list") ?>รายการของฉัน</h2>
        <?php if (!$myRows): ?>
            <p class="corr-muted">ยังไม่มีรายการ</p>
        <?php else: ?>
            <div class="corr-table-wrap">
                <table class="corr-table corr-list">
                    <thead><tr><th>เดือน / สัญญา</th><th>วันที่</th><th>สถานะเอกสาร</th><th>สถานะอนุมัติ</th><th>หลักฐาน</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($myRows as $row): ?>
                            <?php
                            $returnId = (int) $row["return_id"];
                            $timeliness = kpiContractReturnTimeliness($row, $today);
                            $files = kpiContractReturnFiles($pdo, $returnId);
                            $events = kpiContractReturnEvents($pdo, $returnId);
                            $revisions = kpiContractReturnRevisions($pdo, $returnId);
                            ?>
                            <tr id="return-<?= $returnId ?>">
                                <td><?= $e($periodLabel($row["period_year"], $row["period_month"])) ?>
                                    <div><strong><?= $e($row["contract_no_new"]) ?></strong><?= $row["contract_no_old"] ? '<span class="corr-muted"> (เดิม ' . $e($row["contract_no_old"]) . ")</span>" : "" ?></div>
                                    <div class="corr-muted"><?= $e($row["customer_name"]) ?></div>
                                </td>
                                <td class="corr-muted">ส่ง <?= $e(kpiContractReturnDateText($row["sent_date"])) ?><br>ครบกำหนด <?= $e(kpiContractReturnDateText($row["due_date"])) ?><br>รับคืน <?= $e(kpiContractReturnDateText($row["returned_date"])) ?></td>
                                <td><?= $e(KPI_CONTRACT_RETURN_DOC_LABELS[$row["doc_status"]]) ?>
                                    <div class="corr-muted"><?= $e(KPI_CONTRACT_RETURN_TIMELINESS_LABELS[$timeliness]) ?></div>
                                    <?php if ($row["cancel_reason"]): ?><div class="corr-muted">เหตุผล: <?= $e($row["cancel_reason"]) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <span class="corr-badge corr-<?= $row["review_status"] === "Approved" ? "approved" : ($row["review_status"] === "Rejected" ? "returned" : "pending") ?>"><?= $e(KPI_CONTRACT_RETURN_REVIEW_LABELS[$row["review_status"]]) ?></span>
                                    <?php if ($row["review_comment"]): ?><div class="corr-muted">หมายเหตุผู้จัดการ: <?= $e($row["review_comment"]) ?></div><?php endif; ?>
                                    <?php if ($row["pending_revision_id"]): ?><div class="corr-warning">มีคำขอแก้ไขรอพิจารณา</div><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($files as $file): ?>
                                        <div><a class="corr-link" href="../file-download.php?type=contract_return&amp;id=<?= (int) $file["file_id"] ?>"><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a><?= $file["revision_id"] !== null ? ' <span class="corr-muted">(คำขอแก้ไข · ' . $e(KPI_CONTRACT_RETURN_REVISION_LABELS[$file["revision_status"]] ?? "") . ")</span>" : "" ?></div>
                                    <?php endforeach; ?>
                                    <?php if (!$files): ?><span class="corr-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($row["review_status"], ["Draft", "Rejected"], true)): ?>
                                        <a class="corr-link" href="kpi-contract-returns.php?edit=<?= $returnId ?>#form">แก้ไข / ส่ง</a>
                                    <?php elseif ($row["review_status"] === "Approved" && !$row["pending_revision_id"]): ?>
                                        <a class="corr-link" href="kpi-contract-returns.php?revise=<?= $returnId ?>#form">ขอแก้ไข</a>
                                    <?php endif; ?>
                                    <details class="corr-history">
                                        <summary>ประวัติ (<?= count($events) ?>)</summary>
                                        <ul>
                                            <?php foreach ($events as $event): ?>
                                                <li><?= $e(KPI_CONTRACT_RETURN_ACTION_LABELS[$event["action"]] ?? $event["action"]) ?> · <?= $e(kpiContractReturnActorName($event)) ?> · <?= $e(date("d/m/Y H:i", strtotime((string) $event["created_at"]))) ?><?= $event["comment"] ? " · " . $e($event["comment"]) : "" ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php foreach ($revisions as $revision): ?>
                                            <div class="corr-muted">คำขอแก้ไข #<?= (int) $revision["revision_id"] ?> · <?= $e(KPI_CONTRACT_RETURN_REVISION_LABELS[$revision["status"]]) ?> · <?= $e($revision["reason"]) ?><?= $revision["review_comment"] ? " · ผู้จัดการ: " . $e($revision["review_comment"]) : "" ?></div>
                                        <?php endforeach; ?>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>

<script>
(function () {
    var form = document.getElementById("contractReturnForm");
    if (!form) return;
    var status = document.getElementById("doc_status");
    var sent = document.getElementById("sent_date");
    var returned = document.getElementById("returned_date");
    var due = document.getElementById("due_preview");
    var timeliness = document.getElementById("timeliness_preview");
    function pad(n) { return (n < 10 ? "0" : "") + n; }
    /* แสดงผลอย่างเดียว · ระบบคำนวณวันครบกำหนดซ้ำที่เซิร์ฟเวอร์เสมอ */
    function dueDate(value) {
        var m = /^(\d{4})-(\d{2})-\d{2}$/.exec(value || "");
        if (!m) return "";
        var year = +m[1], month = +m[2] + 1;
        if (month > 12) { month = 1; year++; }
        return year + "-" + pad(month) + "-<?= sprintf("%02d", KPI_CONTRACT_RETURN_DUE_DAY) ?>";
    }
    function update() {
        form.querySelectorAll("[data-show-for]").forEach(function (el) {
            el.hidden = el.getAttribute("data-show-for").split(" ").indexOf(status.value) < 0;
            el.querySelectorAll("input, textarea").forEach(function (input) { input.disabled = el.hidden; });
        });
        var d = dueDate(sent.value);
        due.value = d ? d.split("-").reverse().join("/") : "-";
        timeliness.textContent = d && returned.value ? (returned.value <= d ? "ตรงเวลา (คำนวณจากวันที่)" : "เกินกำหนด (คำนวณจากวันที่)") : "";
    }
    form.addEventListener("input", update);
    form.addEventListener("change", update);
    update();
})();
</script>

<?php employeeLayoutEnd("../"); ?>
</body>
</html>
