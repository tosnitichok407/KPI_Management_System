<?php

/*
|--------------------------------------------------------------------------
| Manager (ผู้จัดการสาขา): คำขอแก้ไขข้อมูล KPI ของพนักงานทุกแผนกในสาขา
|--------------------------------------------------------------------------
|
| รายการคำขอ (กรองสถานะ / ปี) + รายละเอียด (?request_id=) : ค่าเดิม · ค่าที่เสนอ · ผลที่จะเกิด · เอกสาร · ประวัติ
| อนุมัติ / ส่งกลับ → manager/kpi-correction-action.php (เฉพาะ Manager · Admin ดูได้อย่างเดียว)
| include จาก manager/index.php
|
*/

if (!isset($pdo)) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . "/../../includes/kpi-correction.php";
require_once __DIR__ . "/../../includes/kpi-import-history.php";

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
$monthNames = $monthNames ?? monthlyPeriodMonths();
$correctionEnabled = kpiCorrectionEnabled($pdo);
$canReview = (int) ($_SESSION["role_id"] ?? 0) === KPI_APPROVAL_ROLE_MANAGER;
$correctionFlash = $_SESSION["mgr_correction_flash"] ?? null;
unset($_SESSION["mgr_correction_flash"]);

$statusFilter = (string) ($_GET["status"] ?? "Pending");
if ($statusFilter !== "all" && !isset(KPI_CORRECTION_STATUS_LABELS[$statusFilter])) {
    $statusFilter = "Pending";
}

$detail = null;
if ($correctionEnabled && (int) ($_GET["request_id"] ?? 0) > 0) {
    $detail = kpiCorrectionGet($pdo, (int) $_GET["request_id"]);
}
$requests = $correctionEnabled && $detail === null
    ? kpiCorrectionList($pdo, ["status" => $statusFilter === "all" ? "" : $statusFilter])
    : [];
$pendingTotal = $correctionEnabled ? count(kpiCorrectionList($pdo, ["status" => "Pending"])) : 0;

?>

<header class="page-header">
    <div class="page-title-block">
        <h1>คำขอแก้ไขข้อมูล KPI</h1>
        <p>คำขอจากพนักงานทุกแผนกในสาขา · ค่าในผลงานเปลี่ยนเมื่ออนุมัติเท่านั้น</p>
    </div>
</header>

<?php if (is_array($correctionFlash)): ?>
    <div class="alert <?= $correctionFlash["type"] === "success" ? "alert-success" : "alert-error" ?>" role="status"><?= $e($correctionFlash["message"]) ?></div>
<?php endif; ?>

<?php if (!$correctionEnabled): ?>
    <section class="corr-card"><p>ระบบคำขอแก้ไขข้อมูลยังไม่เปิดใช้งาน (ยังไม่ได้รัน Migration 20261026_kpi_correction_requests)</p></section>
<?php elseif ($detail !== null): ?>

    <?php
    $performance = kpiCorrectionPerformance($pdo, (int) $detail["performance_id"]);
    $outcome = $performance && $detail["status"] === "Pending" ? kpiCorrectionOutcome($pdo, $detail, $performance) : null;
    $files = kpiCorrectionFiles($pdo, (int) $detail["request_id"]);
    $chain = kpiCorrectionChain($pdo, (int) $detail["request_id"]);
    $isOwn = (int) $detail["employee_id"] === (int) ($_SESSION["employee_id"] ?? 0);
    $isScore = $detail["value_field"] === "score";
    ?>

    <section class="corr-card" id="correction-detail">
        <p><a class="corr-link" href="index.php?page=corrections&amp;status=<?= $e($statusFilter) ?>">← กลับไปรายการคำขอ</a></p>
        <h2><?= icon("square-pen") ?>คำขอ #<?= (int) $detail["request_id"] ?> · <span class="corr-badge corr-<?= strtolower($e($detail["status"])) ?>"><?= $e(KPI_CORRECTION_STATUS_LABELS[$detail["status"]] ?? $detail["status"]) ?></span></h2>

        <table class="corr-table">
            <tbody>
                <tr><th>พนักงาน</th><td><?= $e($detail["first_name"] . " " . $detail["last_name"]) ?> <span class="corr-muted"><?= $e($detail["employee_code"]) ?> · <?= $e($detail["department_name"] ?? "-") ?></span></td></tr>
                <tr><th>เดือน / KPI</th><td><?= $e($monthNames[(int) $detail["period_month"]] . " " . $detail["period_year"]) ?> · <?= $e($detail["kpi_name"]) ?> <span class="corr-muted">(<?= $e($detail["kpi_type"]) ?>)</span></td></tr>
                <tr><th>ประเภทคำขอ</th><td><?= $detail["request_type"] === "evidence_only" ? "ส่งหลักฐานเพิ่มเติม (ไม่เปลี่ยนค่า)" : "ขอแก้ไข" . ($isScore ? "คะแนน" : " Actual") ?></td></tr>
                <tr><th>ส่งเมื่อ</th><td><?= $e(date("d/m/Y H:i", strtotime((string) $detail["submitted_at"]))) ?><?= $detail["previous_request_id"] ? ' · <span class="corr-muted">ส่งใหม่ต่อจากคำขอ #' . (int) $detail["previous_request_id"] . "</span>" : "" ?></td></tr>
                <tr><th>เหตุผลของพนักงาน</th><td><?= nl2br($e($detail["reason"])) ?></td></tr>
            </tbody>
        </table>

        <div class="corr-compare">
            <div>
                <strong>ค่าเดิม (ตอนส่งคำขอ)</strong>
                Actual <?= $e(kpiCorrectionNumber($detail["original_actual"])) ?> · Target <?= $e(kpiCorrectionNumber($detail["original_target"])) ?><br>
                คะแนน <?= $e(kpiCorrectionNumber($detail["original_score"])) ?> · สถานะผลงาน <?= $e($detail["original_status"]) ?>
            </div>
            <div>
                <strong>ค่าที่เสนอ</strong>
                <?= $detail["request_type"] === "evidence_only" ? "ไม่เปลี่ยนค่า" : ($isScore ? "คะแนน " : "Actual ") . $e(kpiCorrectionNumber($detail["proposed_value"])) ?>
            </div>
            <?php if ($outcome !== null): ?>
                <div>
                    <strong>ผลถ้าอนุมัติ (คำนวณตามเกณฑ์ปัจจุบัน)</strong>
                    <?php if ($outcome["conflict"]): ?>
                        <span class="corr-warning">ค่าปัจจุบันในผลงาน (Actual <?= $e(kpiCorrectionNumber($performance["actual"])) ?> · คะแนน <?= $e(kpiCorrectionNumber($performance["score"])) ?>) ไม่ตรงกับตอนส่งคำขอ · อนุมัติไม่ได้</span>
                    <?php elseif ($outcome["error"] !== null): ?>
                        <span class="corr-warning"><?= $e($outcome["error"]) ?></span>
                    <?php elseif ($detail["request_type"] === "evidence_only"): ?>
                        ไม่เปลี่ยนค่าในผลงาน (บันทึกเป็นหลักฐานประกอบ)
                    <?php else: ?>
                        Actual <?= $e(kpiCorrectionNumber($performance["actual"])) ?> → <?= $e(kpiCorrectionNumber($outcome["new_actual"])) ?><br>
                        คะแนน <?= $e(kpiCorrectionNumber($performance["score"])) ?> → <?= $e(kpiCorrectionNumber($outcome["new_score"])) ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($detail["status"] === "Approved"): ?>
                <div>
                    <strong>ผลที่เกิดขึ้นจริง</strong>
                    <?php if ($detail["applied_at"]): ?>
                        Actual <?= $e(kpiCorrectionNumber($detail["applied_old_actual"])) ?> → <?= $e(kpiCorrectionNumber($detail["applied_new_actual"])) ?><br>
                        คะแนน <?= $e(kpiCorrectionNumber($detail["applied_old_score"])) ?> → <?= $e(kpiCorrectionNumber($detail["applied_new_score"])) ?><br>
                        <span class="corr-muted">เปลี่ยนเมื่อ <?= $e(date("d/m/Y H:i", strtotime((string) $detail["applied_at"]))) ?></span>
                    <?php else: ?>
                        ไม่เปลี่ยนค่าในผลงาน (หลักฐานเพิ่มเติม)
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($outcome !== null && $outcome["criteria_pending"] !== null && $detail["request_type"] === "value_change"): ?>
            <p class="corr-warning">เกณฑ์ของ KPI นี้รอเจ้าของระบบยืนยัน: <?= $e($outcome["criteria_pending"]) ?> · คะแนนใหม่ยืนยันความถูกต้องไม่ได้</p>
        <?php endif; ?>

        <h3>เอกสารประกอบ (<?= count($files) ?>)</h3>
        <?php if (!$files): ?>
            <p class="corr-muted">ไม่มีเอกสาร</p>
        <?php else: ?>
            <ul>
                <?php foreach ($files as $file): ?>
                    <?php $viewable = in_array($file["file_ext"], ["jpg", "jpeg", "png", "pdf"], true); ?>
                    <li>
                        <a class="corr-link" href="../file-download.php?type=correction&amp;id=<?= (int) $file["file_id"] ?><?= $viewable ? "&amp;view=1" : "" ?>"<?= $viewable ? ' target="_blank" rel="noopener"' : "" ?>><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a>
                        <span class="corr-muted"><?= strtoupper($e($file["file_ext"])) ?> · <?= $e(kpiFormatFileSize((int) $file["file_size"])) ?> · SHA-256 <?= $e(substr((string) $file["file_sha256"], 0, 12)) ?>…<?= $file["copied_from_file_id"] ? " · ใช้ซ้ำจากคำขอก่อนหน้า" : "" ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($detail["status"] === "Pending"): ?>
            <?php if (!$canReview): ?>
                <p class="corr-muted">การอนุมัติทำได้เฉพาะบัญชีผู้จัดการสาขา (Manager)</p>
            <?php elseif ($isOwn): ?>
                <p class="corr-warning">ไม่สามารถพิจารณาคำขอของตัวเองได้</p>
            <?php else: ?>
                <form method="post" action="kpi-correction-action.php" class="corr-review-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $detail["request_id"] ?>">
                    <label for="correctionComment">หมายเหตุ <span class="corr-muted">(บังคับเมื่อส่งกลับ)</span></label>
                    <textarea name="comment" id="correctionComment" rows="3" maxlength="<?= KPI_CORRECTION_COMMENT_MAX ?>" placeholder="ผลการตรวจกับหลักฐานต้นทาง หรือเหตุผลที่ส่งกลับ"></textarea>
                    <?php if ($outcome !== null && $outcome["criteria_pending"] !== null && $detail["request_type"] === "value_change"): ?>
                        <label class="corr-check"><input type="checkbox" name="acknowledge_warnings" value="1" id="correctionAck"> รับทราบว่าเกณฑ์ของ KPI นี้รอยืนยัน และยืนยันอนุมัติค่าที่เสนอ (บันทึกไว้ในประวัติ)</label>
                    <?php endif; ?>
                    <div class="corr-actions">
                        <button type="submit" name="decision" value="approve" class="corr-btn approve" <?= $outcome !== null && ($outcome["conflict"] || $outcome["error"] !== null) ? "disabled" : "" ?> onclick="var a=document.getElementById('correctionAck'); if(a && !a.checked){alert('กรุณาติ๊กรับทราบก่อนอนุมัติ'); a.focus(); return false;} return confirm('อนุมัติคำขอ #<?= (int) $detail["request_id"] ?>?<?= $detail["request_type"] === "value_change" ? " ระบบจะแก้ค่าในผลงานทันที" : "" ?>');"><?= icon("clipboard-check") ?>อนุมัติ</button>
                        <button type="submit" name="decision" value="return" class="corr-btn return" onclick="var c=document.getElementById('correctionComment'); if(!c.value.trim()){alert('กรุณาระบุเหตุผลที่ส่งกลับ'); c.focus(); return false;} return confirm('ส่งคำขอกลับให้พนักงานแก้ไข?');"><?= icon("rotate-ccw") ?>ส่งกลับให้แก้ไข</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="corr-muted">พิจารณาแล้วโดย <?= $e(kpiApprovalActorName(["first_name" => null, "last_name" => null, "username" => (string) ($pdo->query("SELECT username FROM users WHERE user_id = " . (int) $detail["reviewed_by"])->fetchColumn() ?: "-"), "actor_role_id" => $detail["reviewed_role_id"]])) ?> · <?= $e(date("d/m/Y H:i", strtotime((string) $detail["reviewed_at"]))) ?><?= $detail["review_comment"] ? " · " . $e($detail["review_comment"]) : "" ?></p>
        <?php endif; ?>

        <h3>ประวัติคำขอของรายการนี้</h3>
        <ul>
            <?php foreach ($chain as $item): ?>
                <li>
                    <a class="corr-link" href="index.php?page=corrections&amp;request_id=<?= (int) $item["request_id"] ?>">#<?= (int) $item["request_id"] ?></a>
                    · <?= $e(KPI_CORRECTION_STATUS_LABELS[$item["status"]] ?? $item["status"]) ?>
                    <ul>
                        <?php foreach (kpiCorrectionEvents($pdo, (int) $item["request_id"]) as $event): ?>
                            <li><?= $e(KPI_CORRECTION_ACTION_LABELS[$event["action"]] ?? $event["action"]) ?> · <?= $e(kpiCorrectionActorName($event)) ?> · <?= $e(date("d/m/Y H:i", strtotime((string) $event["created_at"]))) ?><?= $event["comment"] ? " · " . $e($event["comment"]) : "" ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

<?php else: ?>

    <section class="corr-card">
        <form method="get" class="corr-filter">
            <input type="hidden" name="page" value="corrections">
            <label for="corrStatus">สถานะ</label>
            <select name="status" id="corrStatus" onchange="this.form.submit()">
                <option value="Pending" <?= $statusFilter === "Pending" ? "selected" : "" ?>>รอพิจารณา (<?= $pendingTotal ?>)</option>
                <option value="Returned" <?= $statusFilter === "Returned" ? "selected" : "" ?>>ส่งกลับให้แก้ไข</option>
                <option value="Approved" <?= $statusFilter === "Approved" ? "selected" : "" ?>>อนุมัติแล้ว</option>
                <option value="all" <?= $statusFilter === "all" ? "selected" : "" ?>>ทั้งหมด</option>
            </select>
        </form>

        <?php if (!$requests): ?>
            <p class="corr-muted">ไม่มีคำขอในสถานะนี้</p>
        <?php else: ?>
            <div class="corr-table-wrap">
                <table class="corr-table corr-list">
                    <thead><tr><th>#</th><th>พนักงาน</th><th>เดือน / KPI</th><th>คำขอ</th><th>เอกสาร</th><th>สถานะ</th><th>ส่งเมื่อ</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?= (int) $request["request_id"] ?></td>
                                <td><?= $e($request["first_name"] . " " . $request["last_name"]) ?><div class="corr-muted"><?= $e($request["employee_code"]) ?> · <?= $e($request["department_name"] ?? "-") ?></div></td>
                                <td><?= $e($monthNames[(int) $request["period_month"]] . " " . $request["period_year"]) ?><div class="corr-muted"><?= $e($request["kpi_name"]) ?></div></td>
                                <td><?= $request["request_type"] === "evidence_only" ? "หลักฐานเพิ่มเติม" : ($request["value_field"] === "score" ? "คะแนน " : "Actual ") . $e(kpiCorrectionNumber($request["value_field"] === "score" ? $request["original_score"] : $request["original_actual"])) . " → " . $e(kpiCorrectionNumber($request["proposed_value"])) ?></td>
                                <td><?= (int) $request["file_count"] ?> ไฟล์</td>
                                <td><span class="corr-badge corr-<?= strtolower($e($request["status"])) ?>"><?= $e(KPI_CORRECTION_STATUS_LABELS[$request["status"]] ?? $request["status"]) ?></span></td>
                                <td><?= $e(date("d/m/Y H:i", strtotime((string) $request["submitted_at"]))) ?></td>
                                <td><a class="corr-link" href="index.php?page=corrections&amp;status=<?= $e($statusFilter) ?>&amp;request_id=<?= (int) $request["request_id"] ?>">ตรวจสอบ</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>
