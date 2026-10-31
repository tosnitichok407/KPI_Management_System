<?php

/*
|--------------------------------------------------------------------------
| Manager (ผู้จัดการสาขา): KPI12 รายการรับคืนสัญญาตัวจริงของพนักงานทุกแผนกในสาขา
|--------------------------------------------------------------------------
|
| รายการ (กรองสถานะ) + รายละเอียด (?return_id=) : ข้อมูลสัญญา · วันที่ · หลักฐาน · คำขอแก้ไข · ประวัติ
| อนุมัติ / ส่งกลับ → manager/kpi-contract-return-action.php (เฉพาะ Manager · Admin ดูได้อย่างเดียว)
| include จาก manager/index.php
|
*/

if (!isset($pdo)) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . "/../../includes/kpi-contract-return.php";
require_once __DIR__ . "/../../includes/kpi-import-history.php";

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
$monthNames = $monthNames ?? monthlyPeriodMonths();
$contractEnabled = kpiContractReturnEnabled($pdo);
$canReview = (int) ($_SESSION["role_id"] ?? 0) === KPI_APPROVAL_ROLE_MANAGER;
$actorEmployeeId = (int) ($_SESSION["employee_id"] ?? 0);
$actorUserId = (int) ($_SESSION["user_id"] ?? 0);
$today = kpiContractReturnToday();
$contractFlash = $_SESSION["mgr_contract_return_flash"] ?? null;
unset($_SESSION["mgr_contract_return_flash"]);

$statusOptions = [
    "Submitted" => "รออนุมัติ",
    "revision" => "คำขอแก้ไขรอพิจารณา",
    "Rejected" => "ส่งกลับให้แก้ไข",
    "Approved" => "อนุมัติแล้ว",
    "all" => "ทั้งหมด",
];
$statusFilter = (string) ($_GET["status"] ?? "Submitted");
if (!isset($statusOptions[$statusFilter])) {
    $statusFilter = "Submitted";
}

$detail = $contractEnabled && (int) ($_GET["return_id"] ?? 0) > 0 ? kpiContractReturnGet($pdo, (int) $_GET["return_id"]) : null;
$rows = [];
if ($contractEnabled && $detail === null) {
    $rows = kpiContractReturnList($pdo, $statusFilter === "revision"
        ? ["pending_revision" => true]
        : ["review_status" => $statusFilter === "all" ? "" : $statusFilter]);
}
$submittedTotal = $contractEnabled ? count(kpiContractReturnList($pdo, ["review_status" => "Submitted"])) : 0;
$revisionTotal = $contractEnabled ? count(kpiContractReturnList($pdo, ["pending_revision" => true])) : 0;

$periodLabel = static fn($row): string => $monthNames[(int) $row["period_month"]] . " " . $row["period_year"];
$fieldLabels = [
    "contract_period_id" => "เดือนของสัญญา (รหัสรอบ)", "contract_no_new" => "เลขที่สัญญาใหม่", "contract_no_old" => "เลขที่สัญญาเดิม",
    "customer_name" => "ชื่อลูกค้า", "doc_status" => "สถานะเอกสาร", "sent_date" => "วันที่ส่ง", "due_date" => "วันครบกำหนด",
    "returned_date" => "วันที่รับคืน", "cancel_reason" => "เหตุผลยกเลิก", "note" => "หมายเหตุ",
];
$fieldValue = static function (string $field, $value) use ($e): string {
    if ($value === null || $value === "") {
        return "-";
    }
    if ($field === "doc_status") {
        return $e(KPI_CONTRACT_RETURN_DOC_LABELS[$value] ?? $value);
    }
    if (str_ends_with($field, "_date")) {
        return $e(kpiContractReturnDateText((string) $value));
    }

    return $e($value);
};

?>

<header class="page-header">
    <div class="page-title-block">
        <h1>KPI12 การรับคืนสัญญาตัวจริง</h1>
        <p>รายการรายสัญญาจากพนักงานทุกแผนกในสาขา · ใช้คำนวณเมื่ออนุมัติแล้วเท่านั้น · ไม่เกี่ยวข้องกับการคำนวณค่าคอมมิชชัน</p>
    </div>
</header>

<?php if (is_array($contractFlash)): ?>
    <div class="alert <?= $contractFlash["type"] === "success" ? "alert-success" : "alert-error" ?>" role="status"><?= $e($contractFlash["message"]) ?></div>
<?php endif; ?>

<?php if (!$contractEnabled): ?>
    <section class="corr-card"><p>ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน (ยังไม่ได้รัน Migration 20261028_kpi_contract_returns)</p></section>
<?php elseif ($detail !== null): ?>

    <?php
    $returnId = (int) $detail["return_id"];
    $files = kpiContractReturnFiles($pdo, $returnId);
    $revisions = kpiContractReturnRevisions($pdo, $returnId);
    $events = kpiContractReturnEvents($pdo, $returnId);
    $timeliness = kpiContractReturnTimeliness($detail, $today);
    $isOwn = kpiContractReturnIsOwn($detail, $actorUserId, $actorEmployeeId, ["created_by", "submitted_by"]);
    $recordFiles = array_values(array_filter($files, fn($f) => $f["revision_id"] === null || $f["revision_status"] === "Approved"));
    $summary = kpiContractReturnSummary($pdo, (int) $detail["employee_id"], (int) $detail["contract_period_id"], $today);
    ?>

    <section class="corr-card" id="contract-return-detail">
        <p><a class="corr-link" href="index.php?page=contract-returns&amp;status=<?= $e($statusFilter) ?>">← กลับไปรายการ</a></p>
        <h2><?= icon("file-text") ?><?= $e($detail["contract_no_new"]) ?> · <span class="corr-badge corr-<?= $detail["review_status"] === "Approved" ? "approved" : ($detail["review_status"] === "Rejected" ? "returned" : "pending") ?>"><?= $e(KPI_CONTRACT_RETURN_REVIEW_LABELS[$detail["review_status"]]) ?></span></h2>

        <table class="corr-table">
            <tbody>
                <tr><th>พนักงาน</th><td><?= $e($detail["first_name"] . " " . $detail["last_name"]) ?> <span class="corr-muted"><?= $e($detail["employee_code"]) ?> · <?= $e($detail["department_name"] ?? "-") ?></span></td></tr>
                <tr><th>เดือนของสัญญา</th><td><?= $e($periodLabel($detail)) ?></td></tr>
                <tr><th>เลขที่สัญญาใหม่ / เดิม</th><td><?= $e($detail["contract_no_new"]) ?> / <?= $e($detail["contract_no_old"] ?? "-") ?></td></tr>
                <tr><th>ชื่อลูกค้า</th><td><?= $e($detail["customer_name"]) ?></td></tr>
                <tr><th>สถานะเอกสาร</th><td><?= $e(KPI_CONTRACT_RETURN_DOC_LABELS[$detail["doc_status"]]) ?><?= $detail["cancel_reason"] ? " · เหตุผล: " . $e($detail["cancel_reason"]) : "" ?></td></tr>
                <tr><th>วันที่ส่ง / ครบกำหนด / รับคืน</th><td><?= $e(kpiContractReturnDateText($detail["sent_date"])) ?> / <?= $e(kpiContractReturnDateText($detail["due_date"])) ?> <span class="corr-muted">(วันที่ <?= KPI_CONTRACT_RETURN_DUE_DAY ?> ของเดือนถัดจากเดือนที่ส่ง)</span> / <?= $e(kpiContractReturnDateText($detail["returned_date"])) ?></td></tr>
                <tr><th>ความตรงเวลา (คำนวณ)</th><td><?= $e(KPI_CONTRACT_RETURN_TIMELINESS_LABELS[$timeliness]) ?></td></tr>
                <tr><th>หมายเหตุพนักงาน</th><td><?= $detail["note"] ? nl2br($e($detail["note"])) : "-" ?></td></tr>
                <tr><th>สรุปเดือนนี้ของพนักงาน</th><td><?= $e(kpiContractReturnSummaryText($summary)) ?><?php foreach ($summary["warnings"] as $warning): ?><div class="corr-warning"><?= $e($warning) ?></div><?php endforeach; ?></td></tr>
            </tbody>
        </table>

        <h3>หลักฐาน (<?= count($recordFiles) ?>)</h3>
        <?php if (!$recordFiles): ?>
            <p class="corr-muted">ไม่มีหลักฐาน<?= $detail["doc_status"] === "returned" && KPI_CONTRACT_RETURN_REQUIRE_FILE_FOR_RETURNED ? " · สถานะรับคืนแล้วต้องมีหลักฐานก่อนอนุมัติ" : "" ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($recordFiles as $file): ?>
                    <?php $viewable = in_array($file["file_ext"], ["jpg", "jpeg", "png", "pdf"], true); ?>
                    <li>
                        <a class="corr-link" href="../file-download.php?type=contract_return&amp;id=<?= (int) $file["file_id"] ?><?= $viewable ? "&amp;view=1" : "" ?>"<?= $viewable ? ' target="_blank" rel="noopener"' : "" ?>><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a>
                        <span class="corr-muted"><?= strtoupper($e($file["file_ext"])) ?> · <?= $e(kpiFormatFileSize((int) $file["file_size"])) ?> · SHA-256 <?= $e(substr((string) $file["file_sha256"], 0, 12)) ?>… · <?= $e(date("d/m/Y H:i", strtotime((string) $file["uploaded_at"]))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($detail["review_status"] === "Submitted"): ?>
            <?php if (!$canReview): ?>
                <p class="corr-muted">การอนุมัติทำได้เฉพาะบัญชีผู้จัดการสาขา (Manager)</p>
            <?php elseif ($isOwn): ?>
                <p class="corr-warning">ไม่สามารถพิจารณารายการของตัวเองได้</p>
            <?php else: ?>
                <form method="post" action="kpi-contract-return-action.php" class="corr-review-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="target" value="record">
                    <input type="hidden" name="id" value="<?= $returnId ?>">
                    <input type="hidden" name="return_id" value="<?= $returnId ?>">
                    <label for="recordComment">หมายเหตุ <span class="corr-muted">(บังคับเมื่อส่งกลับ)</span></label>
                    <textarea name="comment" id="recordComment" rows="3" maxlength="<?= KPI_CONTRACT_RETURN_TEXT_MAX ?>" placeholder="ผลการตรวจกับหลักฐาน หรือเหตุผลที่ส่งกลับ"></textarea>
                    <div class="corr-actions">
                        <button type="submit" name="decision" value="approve" class="corr-btn approve" onclick="return confirm('อนุมัติรายการ <?= $e($detail["contract_no_new"]) ?>?');"><?= icon("clipboard-check") ?>อนุมัติ</button>
                        <button type="submit" name="decision" value="reject" class="corr-btn return" onclick="var c=document.getElementById('recordComment'); if(!c.value.trim()){alert('กรุณาระบุเหตุผลที่ส่งกลับ'); c.focus(); return false;} return confirm('ส่งรายการกลับให้พนักงานแก้ไข?');"><?= icon("rotate-ccw") ?>ส่งกลับให้แก้ไข</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php elseif ($detail["reviewed_at"]): ?>
            <p class="corr-muted">พิจารณาล่าสุด <?= $e(date("d/m/Y H:i", strtotime((string) $detail["reviewed_at"]))) ?><?= $detail["review_comment"] ? " · " . $e($detail["review_comment"]) : "" ?></p>
        <?php endif; ?>

        <?php if ($revisions): ?>
            <h3>คำขอแก้ไข</h3>
            <?php foreach ($revisions as $revision): ?>
                <?php
                $before = json_decode((string) $revision["before_json"], true) ?: [];
                $after = kpiContractReturnRevisionFields($revision);
                $beforeTimeliness = $before ? kpiContractReturnTimeliness($before, $today) : null;
                $afterTimeliness = kpiContractReturnTimeliness($after, $today);
                $revisionFiles = array_filter($files, fn($f) => (int) $f["revision_id"] === (int) $revision["revision_id"]);
                ?>
                <div class="corr-compare">
                    <div>
                        <strong>คำขอ #<?= (int) $revision["revision_id"] ?> · <?= $e(KPI_CONTRACT_RETURN_REVISION_LABELS[$revision["status"]]) ?></strong>
                        ส่งเมื่อ <?= $e(date("d/m/Y H:i", strtotime((string) $revision["requested_at"]))) ?><br>
                        เหตุผล: <?= nl2br($e($revision["reason"])) ?>
                        <?php foreach ($revisionFiles as $file): ?>
                            <div><a class="corr-link" href="../file-download.php?type=contract_return&amp;id=<?= (int) $file["file_id"] ?>"><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a></div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <strong>ค่าที่เปลี่ยน (เดิม → ที่ขอ)</strong>
                        <?php $changed = false; foreach ($fieldLabels as $field => $label): ?>
                            <?php if (($before[$field] ?? null) !== $after[$field]): $changed = true; ?>
                                <div><?= $e($label) ?>: <?= $fieldValue($field, $before[$field] ?? null) ?> → <strong><?= $fieldValue($field, $after[$field]) ?></strong></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$changed): ?><div class="corr-muted">ไม่เปลี่ยนค่า (เพิ่มหลักฐาน)</div><?php endif; ?>
                        <?php if ($beforeTimeliness !== null && $beforeTimeliness !== $afterTimeliness): ?>
                            <div class="corr-warning">ความตรงเวลาเปลี่ยน: <?= $e(KPI_CONTRACT_RETURN_TIMELINESS_LABELS[$beforeTimeliness]) ?> → <?= $e(KPI_CONTRACT_RETURN_TIMELINESS_LABELS[$afterTimeliness]) ?> · กรุณาตรวจหลักฐานวันที่ก่อนอนุมัติ</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($revision["status"] === "Pending" && $canReview && !kpiContractReturnIsOwn($revision, $actorUserId, $actorEmployeeId, ["requested_by"])): ?>
                    <form method="post" action="kpi-contract-return-action.php" class="corr-review-form">
                        <?= csrfField() ?>
                        <input type="hidden" name="target" value="revision">
                        <input type="hidden" name="id" value="<?= (int) $revision["revision_id"] ?>">
                        <input type="hidden" name="return_id" value="<?= $returnId ?>">
                        <label for="revisionComment<?= (int) $revision["revision_id"] ?>">หมายเหตุ <span class="corr-muted">(บังคับเมื่อส่งกลับ)</span></label>
                        <textarea name="comment" id="revisionComment<?= (int) $revision["revision_id"] ?>" rows="2" maxlength="<?= KPI_CONTRACT_RETURN_TEXT_MAX ?>"></textarea>
                        <div class="corr-actions">
                            <button type="submit" name="decision" value="approve" class="corr-btn approve" onclick="return confirm('อนุมัติคำขอแก้ไข #<?= (int) $revision["revision_id"] ?>? ระบบจะใช้ค่าใหม่ทันที');"><?= icon("clipboard-check") ?>อนุมัติคำขอแก้ไข</button>
                            <button type="submit" name="decision" value="reject" class="corr-btn return" onclick="var c=document.getElementById('revisionComment<?= (int) $revision["revision_id"] ?>'); if(!c.value.trim()){alert('กรุณาระบุเหตุผลที่ส่งกลับ'); c.focus(); return false;} return confirm('ส่งคำขอแก้ไขกลับ?');"><?= icon("rotate-ccw") ?>ส่งกลับ</button>
                        </div>
                    </form>
                <?php elseif ($revision["status"] === "Pending" && $canReview): ?>
                    <p class="corr-warning">ไม่สามารถพิจารณาคำขอของตัวเองได้</p>
                <?php elseif ($revision["review_comment"]): ?>
                    <p class="corr-muted">ผู้จัดการ: <?= $e($revision["review_comment"]) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3>ประวัติ</h3>
        <ul>
            <?php foreach ($events as $event): ?>
                <li><?= $e(KPI_CONTRACT_RETURN_ACTION_LABELS[$event["action"]] ?? $event["action"]) ?> · <?= $e(kpiContractReturnActorName($event)) ?> · <?= $e(date("d/m/Y H:i", strtotime((string) $event["created_at"]))) ?><?= $event["comment"] ? " · " . $e($event["comment"]) : "" ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

<?php else: ?>

    <section class="corr-card">
        <form method="get" class="corr-filter">
            <input type="hidden" name="page" value="contract-returns">
            <label for="contractStatus">สถานะ</label>
            <select name="status" id="contractStatus" onchange="this.form.submit()">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= $statusFilter === $value ? "selected" : "" ?>><?= $e($label) ?><?= $value === "Submitted" ? " ({$submittedTotal})" : ($value === "revision" ? " ({$revisionTotal})" : "") ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (!$rows): ?>
            <p class="corr-muted">ไม่มีรายการในสถานะนี้</p>
        <?php else: ?>
            <div class="corr-table-wrap">
                <table class="corr-table corr-list">
                    <thead><tr><th>พนักงาน</th><th>เดือน / สัญญา</th><th>สถานะเอกสาร</th><th>ครบกำหนด / รับคืน</th><th>หลักฐาน</th><th>สถานะอนุมัติ</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $e($row["first_name"] . " " . $row["last_name"]) ?><div class="corr-muted"><?= $e($row["employee_code"]) ?> · <?= $e($row["department_name"] ?? "-") ?></div></td>
                                <td><?= $e($periodLabel($row)) ?><div><strong><?= $e($row["contract_no_new"]) ?></strong></div><div class="corr-muted"><?= $e($row["customer_name"]) ?></div></td>
                                <td><?= $e(KPI_CONTRACT_RETURN_DOC_LABELS[$row["doc_status"]]) ?><div class="corr-muted"><?= $e(KPI_CONTRACT_RETURN_TIMELINESS_LABELS[kpiContractReturnTimeliness($row, $today)]) ?></div></td>
                                <td class="corr-muted"><?= $e(kpiContractReturnDateText($row["due_date"])) ?> / <?= $e(kpiContractReturnDateText($row["returned_date"])) ?></td>
                                <td><?= (int) $row["file_count"] ?> ไฟล์</td>
                                <td><span class="corr-badge corr-<?= $row["review_status"] === "Approved" ? "approved" : ($row["review_status"] === "Rejected" ? "returned" : "pending") ?>"><?= $e(KPI_CONTRACT_RETURN_REVIEW_LABELS[$row["review_status"]]) ?></span><?= $row["pending_revision_id"] ? '<div class="corr-warning">มีคำขอแก้ไข</div>' : "" ?></td>
                                <td><a class="corr-link" href="index.php?page=contract-returns&amp;status=<?= $e($statusFilter) ?>&amp;return_id=<?= (int) $row["return_id"] ?>">ตรวจสอบ</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>
