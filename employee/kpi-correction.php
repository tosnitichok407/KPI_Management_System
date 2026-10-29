<?php

/*
|--------------------------------------------------------------------------
| พนักงาน: คำขอแก้ไขข้อมูลผลงาน KPI
|--------------------------------------------------------------------------
|
| ส่งคำขอ: เลือกเดือน → เลือก KPI → เห็นข้อมูลเดิม → ค่าที่เสนอ / ส่งหลักฐานอย่างเดียว → เหตุผล → เอกสาร → ตรวจทาน → ส่ง
| ติดตามสถานะ / เหตุผลที่ส่งกลับ / ส่งคำขอใหม่ (ข้อมูลและไฟล์ถูกตรวจซ้ำที่ includes/kpi-correction.php)
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/monthly-period-helper.php";
require_once __DIR__ . "/../includes/kpi-correction.php";
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
$enabled = kpiCorrectionEnabled($pdo);

$flash = $_SESSION["correction_flash"] ?? null;
$old = $_SESSION["correction_old"] ?? [];
unset($_SESSION["correction_flash"], $_SESSION["correction_old"]);

/* ส่งคำขอใหม่จากคำขอที่ถูกส่งกลับ */
$resubmit = null;
$resubmitFiles = [];
if ($enabled && (int) ($_GET["resubmit"] ?? 0) > 0) {
    $resubmit = kpiCorrectionGet($pdo, (int) $_GET["resubmit"]);
    if ($resubmit === null || (int) $resubmit["employee_id"] !== $employeeId || $resubmit["status"] !== "Returned" || !empty($resubmit["next_request_id"])) {
        $resubmit = null;
    } else {
        $resubmitFiles = kpiCorrectionFiles($pdo, (int) $resubmit["request_id"]);
    }
}

/* เดือนที่มีผลงาน */
$periods = [];
if ($enabled) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT ep.period_id, ep.period_year, ep.period_month
        FROM kpi_performances p
        INNER JOIN evaluation_periods ep ON ep.period_id = p.period_id
        WHERE p.employee_id = :employee_id
        ORDER BY ep.period_year DESC, ep.period_month DESC
    ");
    $stmt->execute([":employee_id" => $employeeId]);
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selectedPerformance = null;
$performanceId = (int) ($resubmit["performance_id"] ?? $_GET["performance_id"] ?? $old["performance_id"] ?? 0);
if ($enabled && $performanceId > 0) {
    $selectedPerformance = kpiCorrectionPerformance($pdo, $performanceId);
    if ($selectedPerformance === null || (int) $selectedPerformance["employee_id"] !== $employeeId) {
        $selectedPerformance = null;
    }
}

$selectedPeriodId = $selectedPerformance ? (int) $selectedPerformance["period_id"] : (int) ($_GET["period_id"] ?? 0);
$validPeriodIds = array_map(fn($p) => (int) $p["period_id"], $periods);
if (!in_array($selectedPeriodId, $validPeriodIds, true)) {
    $selectedPeriodId = 0;
}
$monthRows = $enabled && $selectedPeriodId > 0 ? kpiCorrectionMonthRows($pdo, $employeeId, $selectedPeriodId) : [];

$pendingForSelected = null;
foreach ($monthRows as $monthRow) {
    if ($selectedPerformance && (int) $monthRow["performance_id"] === (int) $selectedPerformance["performance_id"] && $monthRow["pending_request_id"]) {
        $pendingForSelected = (int) $monthRow["pending_request_id"];
    }
}

$myRequests = $enabled ? kpiCorrectionList($pdo, ["employee_id" => $employeeId]) : [];

$formType = (string) ($old["request_type"] ?? ($resubmit["request_type"] ?? "value_change"));
$formValue = (string) ($old["proposed_value"] ?? ($resubmit && $resubmit["proposed_value"] !== null ? kpiCorrectionNumber($resubmit["proposed_value"]) : ""));
$formReason = (string) ($old["reason"] ?? ($resubmit["reason"] ?? ""));
$isCompetency = $selectedPerformance && strcasecmp((string) $selectedPerformance["kpi_type"], "Competency") === 0;
$criteriaPending = $selectedPerformance ? (KPI_CRITERIA_PENDING_CONFIRMATION[(int) $selectedPerformance["kpi_id"]] ?? null) : null;

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คำขอแก้ไขข้อมูล KPI</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/icons.css?v=1">
    <link rel="stylesheet" href="../assets/css/employee-kpi.css?v=layout-employee-2">
    <link rel="stylesheet" href="../assets/css/kpi-correction.css?v=3">
</head>
<body>

<?php employeeLayoutStart("corrections", "../"); ?>

    <section class="page-header">
        <div class="page-title-block">
            <h1>คำขอแก้ไขข้อมูล KPI</h1>
            <p>ส่งเอกสารประกอบเพื่อขอแก้ไขผลการประเมิน · ค่าจริงจะเปลี่ยนเมื่อผู้จัดการสาขาอนุมัติแล้วเท่านั้น</p>
        </div>
    </section>

    <?php if (is_array($flash)): ?>
        <div class="corr-flash corr-flash-<?= $flash["type"] === "success" ? "success" : "error" ?>" role="status"><?= $e($flash["message"]) ?></div>
    <?php endif; ?>

    <?php if (!$enabled): ?>
        <section class="corr-card"><p>ระบบคำขอแก้ไขข้อมูลยังไม่เปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ</p></section>
    <?php else: ?>

    <section class="corr-card">
        <h2><?= icon("square-pen") ?><?= $resubmit ? "ส่งคำขอใหม่ (ต่อจากคำขอ #" . (int) $resubmit["request_id"] . " ที่ถูกส่งกลับ)" : "ส่งคำขอแก้ไขข้อมูล" ?></h2>

        <?php if ($resubmit): ?>
            <div class="corr-returned">
                <strong>เหตุผลที่ผู้จัดการส่งกลับ:</strong> <?= nl2br($e($resubmit["review_comment"])) ?>
            </div>
        <?php else: ?>
            <form method="get" class="corr-step">
                <label for="period_id">1. เดือน</label>
                <select name="period_id" id="period_id" onchange="this.form.submit()">
                    <option value="">เลือกเดือน</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int) $period["period_id"] ?>" <?= (int) $period["period_id"] === $selectedPeriodId ? "selected" : "" ?>><?= $e($monthNames[(int) $period["period_month"]] . " " . $period["period_year"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($monthRows): ?>
                    <label for="performance_id">2. KPI</label>
                    <select name="performance_id" id="performance_id" onchange="this.form.submit()">
                        <option value="">เลือก KPI</option>
                        <?php foreach ($monthRows as $monthRow): ?>
                            <option value="<?= (int) $monthRow["performance_id"] ?>" <?= $selectedPerformance && (int) $monthRow["performance_id"] === (int) $selectedPerformance["performance_id"] ? "selected" : "" ?>>
                                <?= $e($monthRow["kpi_name"]) ?> · <?= $e($monthRow["status"]) ?><?= $monthRow["pending_request_id"] ? " · มีคำขอรอพิจารณา" : "" ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <noscript><button type="submit">เลือก</button></noscript>
            </form>
            <?php if (!$periods): ?><p class="corr-muted">ยังไม่มีผลงาน KPI ที่บันทึกไว้</p><?php endif; ?>
        <?php endif; ?>

        <?php if ($selectedPerformance): ?>
            <?php $unitText = kpiDisplayUnit($selectedPerformance) === "%" ? "%" : ""; ?>
            <h3>ข้อมูลเดิมในระบบ</h3>
            <table class="corr-table">
                <tbody>
                    <tr><th>KPI</th><td><?= $e($selectedPerformance["kpi_name"]) ?> <span class="corr-muted">(<?= $e($selectedPerformance["kpi_type"]) ?>)</span></td></tr>
                    <tr><th>เดือน</th><td><?= $e($monthNames[(int) $selectedPerformance["period_month"]] . " " . $selectedPerformance["period_year"]) ?></td></tr>
                    <?php if (!$isCompetency): ?>
                        <tr><th>Target</th><td><?= $e(kpiCorrectionNumber($selectedPerformance["target"] ?? $selectedPerformance["assignment_target"])) . $unitText ?></td></tr>
                        <tr><th>Actual</th><td><?= $e(kpiCorrectionNumber($selectedPerformance["actual"])) . ($selectedPerformance["actual"] !== null ? $unitText : "") ?></td></tr>
                    <?php endif; ?>
                    <tr><th>คะแนน</th><td><?= $selectedPerformance["score"] === null ? "-" : $e(kpiCorrectionNumber($selectedPerformance["score"])) . " / 5" ?></td></tr>
                    <tr><th>สถานะผลงาน</th><td><?= $e($selectedPerformance["status"]) ?></td></tr>
                </tbody>
            </table>
            <?php if ($criteriaPending !== null): ?>
                <p class="corr-warning">เกณฑ์ให้คะแนนของ KPI นี้รอเจ้าของระบบยืนยัน · คะแนนที่คำนวณจากค่าที่เสนอยังยืนยันความถูกต้องไม่ได้</p>
            <?php endif; ?>

            <?php if ($pendingForSelected): ?>
                <p class="corr-warning">รายการนี้มีคำขอ #<?= $pendingForSelected ?> ที่รอผู้จัดการพิจารณาอยู่ ส่งคำขอใหม่ได้หลังจากพิจารณาแล้ว</p>
            <?php else: ?>
                <form method="post" action="kpi-correction-action.php" enctype="multipart/form-data" class="corr-form" id="correctionForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="performance_id" value="<?= (int) $selectedPerformance["performance_id"] ?>">
                    <?php if ($resubmit): ?><input type="hidden" name="previous_request_id" value="<?= (int) $resubmit["request_id"] ?>"><?php endif; ?>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= KPI_EVIDENCE_MAX_BYTES ?>">

                    <fieldset>
                        <legend>3. ประเภทคำขอ</legend>
                        <label><input type="radio" name="request_type" value="value_change" <?= $formType === "value_change" ? "checked" : "" ?>> ขอแก้ไขค่า<?= $isCompetency ? "คะแนน" : " Actual" ?></label>
                        <label><input type="radio" name="request_type" value="evidence_only" <?= $formType === "evidence_only" ? "checked" : "" ?>> ส่งหลักฐานเพิ่มเติม (ไม่เปลี่ยนค่า)</label>
                    </fieldset>

                    <div class="corr-field" id="proposedField">
                        <label for="proposed_value">4. <?= $isCompetency ? "คะแนนที่เสนอ (จำนวนเต็ม 1–5)" : "Actual ที่เสนอ" . ($unitText !== "" ? " (%)" : "") ?></label>
                        <input type="text" inputmode="decimal" name="proposed_value" id="proposed_value" value="<?= $e($formValue) ?>" autocomplete="off">
                        <span class="corr-muted"><?= $isCompetency ? "คะแนนใหม่ = ค่าที่เสนอ" : "ระบบคำนวณคะแนนใหม่ตามเกณฑ์ตอนผู้จัดการอนุมัติ" ?></span>
                    </div>

                    <div class="corr-field">
                        <label for="reason">5. เหตุผล <span class="corr-required">*</span></label>
                        <textarea name="reason" id="reason" rows="4" maxlength="<?= KPI_CORRECTION_REASON_MAX ?>" required placeholder="อธิบายว่าข้อมูลเดิมผิดอย่างไร และเอกสารที่แนบยืนยันอะไร"><?= $e($formReason) ?></textarea>
                    </div>

                    <div class="corr-field">
                        <label for="files">6. เอกสารประกอบ <?= KPI_CORRECTION_REQUIRE_FILE ? '<span class="corr-required">*</span>' : "" ?></label>
                        <?php if ($resubmitFiles): ?>
                            <label class="corr-check"><input type="checkbox" name="reuse_previous_files" value="1" checked> แนบเอกสารเดิมของคำขอ #<?= (int) $resubmit["request_id"] ?> ไปด้วย (<?= count($resubmitFiles) ?> ไฟล์: <?= $e(implode(", ", array_column($resubmitFiles, "original_name"))) ?>)</label>
                        <?php endif; ?>
                        <input type="file" name="files[]" id="files" multiple accept="<?= KPI_EVIDENCE_ACCEPT ?>">
                        <span class="corr-muted">Excel (.xlsx .xls) PDF หรือรูปภาพ (.jpg .png) · ไฟล์ละไม่เกิน 10 MB · สูงสุด <?= KPI_CORRECTION_MAX_FILES ?> ไฟล์ต่อคำขอ</span>
                    </div>

                    <div class="corr-review" id="reviewBox" aria-live="polite">
                        <strong>7. ตรวจทานก่อนส่ง</strong>
                        <ul id="reviewList"></ul>
                        <label class="corr-check"><input type="checkbox" id="reviewed" required> ตรวจข้อมูลและเอกสารแล้ว ถูกต้องครบถ้วน</label>
                    </div>

                    <button type="submit" class="corr-button"><?= icon("send") ?>ส่งคำขอให้ผู้จัดการสาขา</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="corr-card">
        <h2><?= icon("clipboard-list") ?>คำขอของฉัน</h2>
        <?php if (!$myRequests): ?>
            <p class="corr-muted">ยังไม่มีคำขอแก้ไขข้อมูล</p>
        <?php else: ?>
            <div class="corr-table-wrap">
                <table class="corr-table corr-list">
                    <thead>
                        <tr><th>#</th><th>เดือน / KPI</th><th>คำขอ</th><th>สถานะ</th><th>ส่งเมื่อ</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myRequests as $request): ?>
                            <?php $files = kpiCorrectionFiles($pdo, (int) $request["request_id"]); $events = kpiCorrectionEvents($pdo, (int) $request["request_id"]); ?>
                            <tr id="request-<?= (int) $request["request_id"] ?>">
                                <td><?= (int) $request["request_id"] ?><?= $request["previous_request_id"] ? '<div class="corr-muted">ต่อจาก #' . (int) $request["previous_request_id"] . "</div>" : "" ?></td>
                                <td><?= $e($monthNames[(int) $request["period_month"]] . " " . $request["period_year"]) ?><div class="corr-muted"><?= $e($request["kpi_name"]) ?></div></td>
                                <td>
                                    <?php if ($request["request_type"] === "evidence_only"): ?>
                                        ส่งหลักฐานเพิ่มเติม (ไม่เปลี่ยนค่า)
                                    <?php else: ?>
                                        <?= $request["value_field"] === "score" ? "คะแนน" : "Actual" ?>
                                        <?= $e(kpiCorrectionNumber($request["value_field"] === "score" ? $request["original_score"] : $request["original_actual"])) ?> → <strong><?= $e(kpiCorrectionNumber($request["proposed_value"])) ?></strong>
                                    <?php endif; ?>
                                    <div class="corr-muted"><?= $e(mb_strimwidth((string) $request["reason"], 0, 120, "…")) ?></div>
                                    <?php foreach ($files as $file): ?>
                                        <div><a href="../file-download.php?type=correction&amp;id=<?= (int) $file["file_id"] ?>"><?= icon("file-text") ?><?= $e($file["original_name"]) ?></a></div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <span class="corr-badge corr-<?= strtolower($e($request["status"])) ?>"><?= $e(KPI_CORRECTION_STATUS_LABELS[$request["status"]] ?? $request["status"]) ?></span>
                                    <?php if ($request["review_comment"]): ?><div class="corr-muted">หมายเหตุผู้จัดการ: <?= $e($request["review_comment"]) ?></div><?php endif; ?>
                                    <?php if ($request["status"] === "Approved" && $request["applied_at"]): ?>
                                        <div class="corr-muted">เปลี่ยนค่าแล้ว: Actual <?= $e(kpiCorrectionNumber($request["applied_old_actual"])) ?> → <?= $e(kpiCorrectionNumber($request["applied_new_actual"])) ?> · คะแนน <?= $e(kpiCorrectionNumber($request["applied_old_score"])) ?> → <?= $e(kpiCorrectionNumber($request["applied_new_score"])) ?></div>
                                    <?php elseif ($request["status"] === "Approved"): ?>
                                        <div class="corr-muted">ไม่เปลี่ยนค่า (หลักฐานเพิ่มเติม)</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $e(date("d/m/Y H:i", strtotime((string) $request["submitted_at"]))) ?></td>
                                <td>
                                    <?php if ($request["status"] === "Returned" && empty($request["next_request_id"])): ?>
                                        <a class="corr-link" href="kpi-correction.php?resubmit=<?= (int) $request["request_id"] ?>">ส่งคำขอใหม่</a>
                                    <?php elseif (!empty($request["next_request_id"])): ?>
                                        <span class="corr-muted">ส่งใหม่เป็น #<?= (int) $request["next_request_id"] ?></span>
                                    <?php endif; ?>
                                    <details class="corr-history">
                                        <summary>ประวัติ (<?= count($events) ?>)</summary>
                                        <ul>
                                            <?php foreach ($events as $event): ?>
                                                <li><?= $e(KPI_CORRECTION_ACTION_LABELS[$event["action"]] ?? $event["action"]) ?> · <?= $e(kpiCorrectionActorName($event)) ?> · <?= $e(date("d/m/Y H:i", strtotime((string) $event["created_at"]))) ?><?= $event["comment"] ? " · " . $e($event["comment"]) : "" ?></li>
                                            <?php endforeach; ?>
                                        </ul>
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
    var form = document.getElementById("correctionForm");
    if (!form) return;
    var field = document.getElementById("proposedField");
    var input = document.getElementById("proposed_value");
    var list = document.getElementById("reviewList");
    var reviewed = document.getElementById("reviewed");
    function text(value) { var li = document.createElement("li"); li.textContent = value; list.appendChild(li); }
    function update() {
        var type = form.querySelector("input[name=request_type]:checked");
        var change = type && type.value === "value_change";
        field.hidden = !change;
        input.required = change;
        list.innerHTML = "";
        text("ประเภท: " + (change ? "ขอแก้ไขค่า → " + (input.value || "(ยังไม่ระบุ)") : "ส่งหลักฐานเพิ่มเติม (ไม่เปลี่ยนค่า)"));
        var reason = document.getElementById("reason").value.trim();
        text("เหตุผล: " + (reason ? reason.substring(0, 120) : "(ยังไม่ระบุ)"));
        var files = Array.prototype.map.call(document.getElementById("files").files, function (f) { return f.name + " (" + Math.ceil(f.size / 1024) + " KB)"; });
        var reuse = form.querySelector("input[name=reuse_previous_files]");
        if (reuse && reuse.checked) files.unshift("เอกสารเดิมของคำขอที่ถูกส่งกลับ");
        text("เอกสาร: " + (files.length ? files.join(", ") : "(ยังไม่ได้แนบ)"));
        reviewed.checked = false;
    }
    form.addEventListener("input", update);
    form.addEventListener("change", function (event) { if (event.target !== reviewed) update(); });
    update();
})();
</script>

<?php employeeLayoutEnd("../"); ?>
</body>
</html>
