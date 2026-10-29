<?php

/*
|--------------------------------------------------------------------------
| คำขอแก้ไขข้อมูลผลงาน KPI (Migration 20261026_kpi_correction_requests)
|--------------------------------------------------------------------------
|
| พนักงานส่งคำขอ 1 รายการ = ผลงาน 1 แถว (KPI × เดือน) พร้อมเหตุผลและเอกสารประกอบ
|   value_change  : เสนอค่าใหม่ — Performance = Actual (คะแนนคำนวณใหม่ตามเกณฑ์ตอนอนุมัติ) · Competency = คะแนน 1–5
|   evidence_only : ส่งหลักฐานเพิ่มเติม ไม่เปลี่ยนค่า
| Manager (role 2) อนุมัติ → แก้ค่าในผลงานใน Transaction เดียวกัน · ส่งกลับ → ต้องระบุเหตุผล
| ค่าจริงไม่เปลี่ยนจนกว่าจะอนุมัติ · สถานะของผลงาน (Draft / Submitted / Approved / Rejected) ไม่ถูกเปลี่ยน
| ห้ามอนุมัติคำขอของตัวเอง · Admin ดูได้อย่างเดียว · ทุกขั้นตอนบันทึกลง kpi_correction_events
| ไม่มีตาราง → ปิดฟีเจอร์ (หน้าเว็บทำงานแบบเดิม)
|
*/

require_once __DIR__ . "/kpi-score-helper.php";
require_once __DIR__ . "/kpi-evidence.php";
require_once __DIR__ . "/kpi-approval.php";

const KPI_CORRECTION_REASON_MAX = 2000;
const KPI_CORRECTION_COMMENT_MAX = 1000;
const KPI_CORRECTION_MAX_FILES = 5;
/* นโยบาย: ทุกคำขอต้องมีเอกสารประกอบอย่างน้อย 1 ไฟล์ (รอเจ้าของระบบยืนยัน · เปลี่ยนเป็น false ได้ที่นี่จุดเดียว) */
const KPI_CORRECTION_REQUIRE_FILE = true;
const KPI_CORRECTION_ACTUAL_MAX = 99999999.99;

const KPI_CORRECTION_STATUS_LABELS = [
    "Pending" => "รอ Manager พิจารณา",
    "Approved" => "อนุมัติแล้ว",
    "Returned" => "ส่งกลับให้แก้ไข",
];

const KPI_CORRECTION_ACTION_LABELS = [
    "submit" => "ส่งคำขอ",
    "resubmit" => "ส่งคำขอใหม่",
    "approve" => "อนุมัติ",
    "return" => "ส่งกลับให้แก้ไข",
];

function kpiCorrectionEnabled(PDO $pdo): bool
{
    static $enabled = null;

    if ($enabled === null) {
        try {
            $count = (int) $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('kpi_correction_requests', 'kpi_correction_files', 'kpi_correction_events')
            ")->fetchColumn();
            $enabled = $count === 3;
        } catch (Throwable $exception) {
            $enabled = false;
        }
    }

    return $enabled;
}

/* ตัวเลขทศนิยมไม่เกิน 2 ตำแหน่ง แสดงแบบไม่มีศูนย์ท้าย */
function kpiCorrectionNumber($value): string
{
    if ($value === null || $value === "") {
        return "-";
    }

    return rtrim(rtrim(number_format((float) $value, 2, ".", ","), "0"), ".");
}

/* ผลงาน 1 แถว + KPI + Assignment (ใช้ทั้งตอนส่งและตอนอนุมัติ) · $lock = FOR UPDATE */
function kpiCorrectionPerformance(PDO $pdo, int $performanceId, bool $lock = false): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.performance_id, p.employee_id, p.period_id, p.target, p.actual, p.score, p.status,
               a.assignment_id, a.target_value AS assignment_target,
               k.kpi_id, k.kpi_name, k.kpi_type, k.unit,
               ep.period_year, ep.period_month
        FROM kpi_performances p
        INNER JOIN kpi_assignments a ON a.assignment_id = p.assignment_id
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        INNER JOIN evaluation_periods ep ON ep.period_id = p.period_id
        WHERE p.performance_id = :id" . ($lock ? " FOR UPDATE" : ""));
    $stmt->execute([":id" => $performanceId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ผลงานของพนักงานในเดือน (ตัวเลือกในฟอร์ม) พร้อมธงว่ามีคำขอรอพิจารณาอยู่หรือไม่ */
function kpiCorrectionMonthRows(PDO $pdo, int $employeeId, int $periodId): array
{
    $stmt = $pdo->prepare("
        SELECT p.performance_id, p.target, p.actual, p.score, p.status,
               k.kpi_id, k.kpi_name, k.kpi_type,
               (SELECT r.request_id FROM kpi_correction_requests r WHERE r.performance_id = p.performance_id AND r.status = 'Pending' LIMIT 1) AS pending_request_id
        FROM kpi_performances p
        INNER JOIN kpi_assignments a ON a.assignment_id = p.assignment_id
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        WHERE p.employee_id = :employee_id AND p.period_id = :period_id
        ORDER BY FIELD(k.kpi_type, 'Performance', 'Competency'), k.kpi_id
    ");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* แปลง $_FILES["files"] (หลายไฟล์) เป็นรายการไฟล์ทีละรายการ · ข้ามช่องที่ไม่ได้เลือกไฟล์ */
function kpiCorrectionNormalizeFiles($files): array
{
    if (!is_array($files) || !isset($files["name"])) {
        return [];
    }

    if (!is_array($files["name"])) {
        return ((int) ($files["error"] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_NO_FILE ? [] : [$files];
    }

    $list = [];
    foreach ($files["name"] as $index => $name) {
        $error = (int) ($files["error"][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = [
            "name" => $name,
            "type" => $files["type"][$index] ?? "",
            "tmp_name" => $files["tmp_name"][$index] ?? "",
            "error" => $error,
            "size" => $files["size"][$index] ?? 0,
        ];
    }

    return $list;
}

/*
| ตรวจค่าที่เสนอ · คืน [ค่าที่ใช้ได้ (string 2 ตำแหน่ง) หรือ null, error หรือ null]
| Performance: Actual ≥ 0 ทศนิยมไม่เกิน 2 ตำแหน่ง · Competency: จำนวนเต็ม 1–5
*/
function kpiCorrectionParseProposed(array $performance, string $raw): array
{
    $raw = trim(str_replace(",", "", $raw));

    if ($raw === "") {
        return [null, "กรุณาระบุค่าที่ต้องการแก้ไข"];
    }

    if (strcasecmp((string) $performance["kpi_type"], "Competency") === 0) {
        if (!preg_match('/^[1-5]$/', $raw)) {
            return [null, "คะแนน Competency ต้องเป็นจำนวนเต็ม 1–5"];
        }
        return [number_format((float) $raw, 2, ".", ""), null];
    }

    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return [null, "Actual ต้องเป็นตัวเลขไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง"];
    }

    if ((float) $raw > KPI_CORRECTION_ACTUAL_MAX) {
        return [null, "Actual มีค่าเกินกว่าที่ระบบรองรับ"];
    }

    return [number_format((float) $raw, 2, ".", ""), null];
}

/* ค่าในผลงานที่คำขอนี้จะแก้ (actual หรือ score) */
function kpiCorrectionFieldFor(array $performance): string
{
    return strcasecmp((string) $performance["kpi_type"], "Competency") === 0 ? "score" : "actual";
}

/* คะแนนใหม่ของค่าที่เสนอ (ไม่บันทึก) · คืน ["score" => ?float, "error" => ?string] */
function kpiCorrectionPreviewScore(PDO $pdo, array $performance, string $field, string $proposed): array
{
    if ($field === "score") {
        return ["score" => (float) $proposed, "error" => null];
    }

    $target = $performance["target"] !== null ? $performance["target"] : $performance["assignment_target"];
    $criteria = loadKpiScoreCriteria($pdo, [$performance])[(int) $performance["kpi_id"]] ?? [];
    $result = kpiCalculateScore($performance, $criteria, $proposed, $target);

    return [
        "score" => $result["grade"] === null ? null : (float) $result["grade"],
        "error" => $result["error"] ?? ($result["grade"] === null ? ($result["pending"] ?? "ตัดเกรดจากค่าที่เสนอไม่ได้") : null),
    ];
}

/* เก็บไฟล์ที่ตรวจแล้วลง storage/corrections · คืน [รายการไฟล์, error] (ผู้เรียกลบไฟล์เองถ้า Transaction ล้มเหลว) */
function kpiCorrectionStoreUploads(array $files, bool $requireUploadedFile): array
{
    $dir = kpiStorageDir("corrections");

    if ($dir === null) {
        return [[], "ระบบจัดเก็บไฟล์ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ"];
    }

    $stored = [];

    foreach ($files as $file) {
        $check = kpiEvidenceCheckUpload($file, $requireUploadedFile);

        if ($check["error"] !== null) {
            kpiCorrectionDiscardFiles($stored);
            return [[], "ไฟล์ " . kpiStorageCleanName((string) ($file["name"] ?? "")) . ": " . $check["error"]];
        }

        $storedName = kpiStorageNewName();
        $target = $dir . "/" . $storedName;
        $tmp = (string) $file["tmp_name"];
        $moved = $requireUploadedFile ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);

        if (!$moved) {
            kpiCorrectionDiscardFiles($stored);
            return [[], "บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"];
        }

        @chmod($target, 0640);
        $stored[] = [
            "original_name" => kpiStorageCleanName((string) ($file["name"] ?? ""), "document." . $check["ext"]),
            "stored_name" => $storedName,
            "file_ext" => $check["ext"],
            "mime_type" => $check["mime"],
            "file_size" => (int) filesize($target),
            "file_sha256" => (string) hash_file("sha256", $target),
            "copied_from_file_id" => null,
        ];
    }

    return [$stored, null];
}

function kpiCorrectionDiscardFiles(array $stored): void
{
    foreach ($stored as $file) {
        kpiStorageDelete("corrections", $file["stored_name"]);
    }
}

/* คัดลอกเอกสารของคำขอเดิม (ที่ถูกส่งกลับ) ไปใช้กับคำขอใหม่ เป็นไฟล์ใหม่ (ไฟล์เดิมยังอยู่กับคำขอเดิม) */
function kpiCorrectionCopyFiles(PDO $pdo, int $previousRequestId): array
{
    $dir = kpiStorageDir("corrections");
    if ($dir === null) {
        return [[], "ระบบจัดเก็บไฟล์ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ"];
    }

    $stmt = $pdo->prepare("SELECT * FROM kpi_correction_files WHERE request_id = :id ORDER BY file_id");
    $stmt->execute([":id" => $previousRequestId]);
    $copied = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
        $source = kpiStoragePath("corrections", (string) $file["stored_name"]);
        $storedName = kpiStorageNewName();
        if ($source === null || !is_file($source) || !@copy($source, $dir . "/" . $storedName)) {
            kpiCorrectionDiscardFiles($copied);
            return [[], "คัดลอกเอกสารเดิมไม่สำเร็จ กรุณาแนบไฟล์ใหม่"];
        }
        @chmod($dir . "/" . $storedName, 0640);
        $copied[] = [
            "original_name" => $file["original_name"],
            "stored_name" => $storedName,
            "file_ext" => $file["file_ext"],
            "mime_type" => $file["mime_type"],
            "file_size" => (int) $file["file_size"],
            "file_sha256" => $file["file_sha256"],
            "copied_from_file_id" => (int) $file["file_id"],
        ];
    }

    return [$copied, null];
}

/*
| พนักงานส่งคำขอ · คืน ["error" => ?string, "request_id" => ?int]
| $input: performance_id, request_type, proposed_value, reason, previous_request_id (ส่งใหม่), reuse_previous_files
*/
function kpiCorrectionSubmit(PDO $pdo, int $employeeId, int $userId, array $input, array $files, bool $requireUploadedFile = true): array
{
    $fail = fn(string $message) => ["error" => $message, "request_id" => null];

    if (!kpiCorrectionEnabled($pdo)) {
        return $fail("ระบบคำขอแก้ไขข้อมูลยังไม่เปิดใช้งาน");
    }

    $performanceId = (int) ($input["performance_id"] ?? 0);
    $type = (string) ($input["request_type"] ?? "");
    $reason = trim((string) ($input["reason"] ?? ""));
    $previousId = (int) ($input["previous_request_id"] ?? 0);
    $reusePrevious = !empty($input["reuse_previous_files"]) && $previousId > 0;

    if (!in_array($type, ["value_change", "evidence_only"], true)) {
        return $fail("กรุณาเลือกประเภทคำขอ");
    }
    if ($reason === "") {
        return $fail("กรุณาระบุเหตุผลในการขอแก้ไข");
    }
    if (mb_strlen($reason) > KPI_CORRECTION_REASON_MAX) {
        return $fail("เหตุผลต้องไม่เกิน " . KPI_CORRECTION_REASON_MAX . " ตัวอักษร");
    }
    if (count($files) > KPI_CORRECTION_MAX_FILES) {
        return $fail("แนบเอกสารได้สูงสุด " . KPI_CORRECTION_MAX_FILES . " ไฟล์ต่อคำขอ");
    }

    $performance = kpiCorrectionPerformance($pdo, $performanceId);
    if ($performance === null || (int) $performance["employee_id"] !== $employeeId) {
        return $fail("ไม่พบผลงานรายการนี้");
    }

    $field = null;
    $proposed = null;
    if ($type === "value_change") {
        $field = kpiCorrectionFieldFor($performance);
        [$proposed, $error] = kpiCorrectionParseProposed($performance, (string) ($input["proposed_value"] ?? ""));
        if ($error !== null) {
            return $fail($error);
        }
        $current = $performance[$field];
        if ($current !== null && abs((float) $current - (float) $proposed) < 0.005) {
            return $fail("ค่าที่เสนอเท่ากับค่าเดิม หากต้องการส่งหลักฐานอย่างเดียวให้เลือก \"ส่งหลักฐานเพิ่มเติม (ไม่เปลี่ยนค่า)\"");
        }
        if ($field === "actual") {
            $preview = kpiCorrectionPreviewScore($pdo, $performance, $field, $proposed);
            if ($preview["error"] !== null) {
                return $fail("คำนวณคะแนนจากค่าที่เสนอไม่ได้: " . $preview["error"]);
            }
        }
    }

    /* คำขอเดิมที่ถูกส่งกลับ (ส่งใหม่) */
    $previous = null;
    if ($previousId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM kpi_correction_requests WHERE request_id = :id");
        $stmt->execute([":id" => $previousId]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($previous === null || (int) $previous["employee_id"] !== $employeeId || (int) $previous["performance_id"] !== $performanceId) {
            return $fail("ไม่พบคำขอเดิมที่ต้องการส่งใหม่");
        }
        if ($previous["status"] !== "Returned") {
            return $fail("ส่งใหม่ได้เฉพาะคำขอที่ถูกส่งกลับให้แก้ไข");
        }
    }

    [$stored, $error] = $files ? kpiCorrectionStoreUploads($files, $requireUploadedFile) : [[], null];
    if ($error !== null) {
        return $fail($error);
    }

    if ($reusePrevious) {
        [$copied, $error] = kpiCorrectionCopyFiles($pdo, $previousId);
        if ($error !== null) {
            kpiCorrectionDiscardFiles($stored);
            return $fail($error);
        }
        $stored = array_merge($copied, $stored);
    }

    if (KPI_CORRECTION_REQUIRE_FILE && !$stored) {
        return $fail("กรุณาแนบเอกสารประกอบอย่างน้อย 1 ไฟล์ (Excel, PDF หรือรูปภาพ)");
    }
    if (count($stored) > KPI_CORRECTION_MAX_FILES) {
        kpiCorrectionDiscardFiles($stored);
        return $fail("แนบเอกสารได้สูงสุด " . KPI_CORRECTION_MAX_FILES . " ไฟล์ต่อคำขอ (รวมเอกสารเดิม)");
    }

    try {
        $pdo->beginTransaction();

        /* ล็อกผลงาน แล้วเก็บค่าเดิม ณ เวลาส่ง */
        $locked = kpiCorrectionPerformance($pdo, $performanceId, true);
        if ($locked === null || (int) $locked["employee_id"] !== $employeeId) {
            throw new RuntimeException("performance missing");
        }

        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM kpi_correction_requests WHERE performance_id = :id AND status = 'Pending'");
        $pendingStmt->execute([":id" => $performanceId]);
        if ((int) $pendingStmt->fetchColumn() > 0) {
            $pdo->rollBack();
            kpiCorrectionDiscardFiles($stored);
            return $fail("รายการนี้มีคำขอที่รอ Manager พิจารณาอยู่แล้ว");
        }

        if ($previous !== null) {
            $again = $pdo->prepare("SELECT COUNT(*) FROM kpi_correction_requests WHERE previous_request_id = :id");
            $again->execute([":id" => $previousId]);
            if ((int) $again->fetchColumn() > 0) {
                $pdo->rollBack();
                kpiCorrectionDiscardFiles($stored);
                return $fail("คำขอนี้ถูกส่งใหม่ไปแล้ว");
            }
        }

        $pdo->prepare("
            INSERT INTO kpi_correction_requests
                (employee_id, performance_id, period_id, kpi_id, request_type, value_field,
                 original_target, original_actual, original_score, original_status, proposed_value,
                 reason, previous_request_id, submitted_by)
            VALUES
                (:employee_id, :performance_id, :period_id, :kpi_id, :request_type, :value_field,
                 :original_target, :original_actual, :original_score, :original_status, :proposed_value,
                 :reason, :previous_request_id, :submitted_by)
        ")->execute([
            ":employee_id" => $employeeId,
            ":performance_id" => $performanceId,
            ":period_id" => (int) $locked["period_id"],
            ":kpi_id" => (int) $locked["kpi_id"],
            ":request_type" => $type,
            ":value_field" => $field,
            ":original_target" => $locked["target"],
            ":original_actual" => $locked["actual"],
            ":original_score" => $locked["score"],
            ":original_status" => (string) $locked["status"],
            ":proposed_value" => $proposed,
            ":reason" => $reason,
            ":previous_request_id" => $previous !== null ? $previousId : null,
            ":submitted_by" => $userId,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        $fileStmt = $pdo->prepare("
            INSERT INTO kpi_correction_files
                (request_id, original_name, stored_name, file_ext, mime_type, file_size, file_sha256, copied_from_file_id, uploaded_by)
            VALUES
                (:request_id, :original_name, :stored_name, :file_ext, :mime_type, :file_size, :file_sha256, :copied_from_file_id, :uploaded_by)
        ");
        foreach ($stored as $file) {
            $fileStmt->execute([
                ":request_id" => $requestId,
                ":original_name" => $file["original_name"],
                ":stored_name" => $file["stored_name"],
                ":file_ext" => $file["file_ext"],
                ":mime_type" => $file["mime_type"],
                ":file_size" => $file["file_size"],
                ":file_sha256" => $file["file_sha256"],
                ":copied_from_file_id" => $file["copied_from_file_id"],
                ":uploaded_by" => $userId,
            ]);
        }

        kpiCorrectionEvent($pdo, $requestId, $previous !== null ? "resubmit" : "submit", $userId, 3, null);
        $pdo->commit();

        return ["error" => null, "request_id" => $requestId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kpiCorrectionDiscardFiles($stored);
        error_log("kpiCorrectionSubmit failed (" . get_class($exception) . "): " . $exception->getMessage());
        return $fail("ส่งคำขอไม่สำเร็จ กรุณาลองใหม่อีกครั้ง");
    }
}

function kpiCorrectionEvent(PDO $pdo, int $requestId, string $action, int $actorUserId, int $actorRoleId, ?string $comment): void
{
    $pdo->prepare("
        INSERT INTO kpi_correction_events (request_id, action, actor_user_id, actor_role_id, comment)
        VALUES (:request_id, :action, :actor_user_id, :actor_role_id, :comment)
    ")->execute([
        ":request_id" => $requestId,
        ":action" => $action,
        ":actor_user_id" => $actorUserId,
        ":actor_role_id" => $actorRoleId,
        ":comment" => $comment !== null && trim($comment) !== "" ? trim($comment) : null,
    ]);
}

/* ค่าในผลงานยังเท่ากับค่าเดิม ณ เวลาส่งคำขอหรือไม่ */
function kpiCorrectionSameAsOriginal(array $request, array $performance): bool
{
    $same = static fn($a, $b) => ($a === null && $b === null)
        || ($a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005);

    return $same($request["original_actual"], $performance["actual"])
        && $same($request["original_score"], $performance["score"]);
}

/*
| ผลที่จะเกิดถ้าอนุมัติ (ไม่บันทึก) · ใช้แสดงให้ Manager และตรวจซ้ำตอนอนุมัติ
| คืน ["conflict" => bool, "new_actual", "new_score", "error", "criteria_pending" => ?string]
*/
function kpiCorrectionOutcome(PDO $pdo, array $request, array $performance): array
{
    $outcome = [
        "conflict" => !kpiCorrectionSameAsOriginal($request, $performance),
        "new_actual" => $performance["actual"],
        "new_score" => $performance["score"],
        "error" => null,
        "criteria_pending" => KPI_CRITERIA_PENDING_CONFIRMATION[(int) $performance["kpi_id"]] ?? null,
    ];

    if ($request["request_type"] !== "value_change") {
        return $outcome;
    }

    if ($request["value_field"] === "score") {
        $outcome["new_score"] = (float) $request["proposed_value"];
        return $outcome;
    }

    $preview = kpiCorrectionPreviewScore($pdo, $performance, "actual", (string) $request["proposed_value"]);
    $outcome["new_actual"] = (float) $request["proposed_value"];
    $outcome["new_score"] = $preview["score"];
    $outcome["error"] = $preview["error"];

    return $outcome;
}

/*
| Manager อนุมัติ / ส่งกลับ · คืน null = สำเร็จ หรือข้อความ error
| อนุมัติ: ตรวจสิทธิ์ + สถานะ + ค่าในผลงานยังเท่ากับตอนส่ง แล้วแก้ค่าใน Transaction เดียวกัน
| KPI ที่เกณฑ์รอยืนยัน: ต้องติ๊กรับทราบ (คะแนนใหม่คำนวณตามเกณฑ์ปัจจุบันซึ่งยังไม่ได้รับการยืนยัน)
*/
function kpiCorrectionReview(PDO $pdo, int $actorUserId, int $actorRoleId, int $actorEmployeeId, int $requestId, string $decision, string $comment, bool $acknowledged = false): ?string
{
    if (!kpiCorrectionEnabled($pdo)) {
        return "ระบบคำขอแก้ไขข้อมูลยังไม่เปิดใช้งาน";
    }
    if ($actorRoleId !== KPI_APPROVAL_ROLE_MANAGER) {
        return "เฉพาะ Manager เท่านั้นที่อนุมัติหรือส่งกลับคำขอแก้ไขได้";
    }
    if (!in_array($decision, ["approve", "return"], true)) {
        return "คำสั่งไม่ถูกต้อง";
    }

    $comment = trim($comment);
    if ($decision === "return" && $comment === "") {
        return "กรุณาระบุเหตุผลที่ส่งกลับให้แก้ไข";
    }
    if (mb_strlen($comment) > KPI_CORRECTION_COMMENT_MAX) {
        return "หมายเหตุต้องไม่เกิน " . KPI_CORRECTION_COMMENT_MAX . " ตัวอักษร";
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM kpi_correction_requests WHERE request_id = :id FOR UPDATE");
        $stmt->execute([":id" => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            return "ไม่พบคำขอแก้ไข";
        }
        if ($request["status"] !== "Pending") {
            $pdo->rollBack();
            return "คำขอนี้ได้รับการพิจารณาแล้ว (" . (KPI_CORRECTION_STATUS_LABELS[$request["status"]] ?? $request["status"]) . ")";
        }
        if (($actorEmployeeId > 0 && (int) $request["employee_id"] === $actorEmployeeId) || (int) $request["submitted_by"] === $actorUserId) {
            $pdo->rollBack();
            return "ไม่สามารถพิจารณาคำขอของตัวเองได้";
        }

        $performance = kpiCorrectionPerformance($pdo, (int) $request["performance_id"], true);
        if ($performance === null) {
            $pdo->rollBack();
            return "ไม่พบผลงานของคำขอนี้";
        }

        $applied = ["old_actual" => null, "old_score" => null, "new_actual" => null, "new_score" => null, "at" => false];

        if ($decision === "approve") {
            $outcome = kpiCorrectionOutcome($pdo, $request, $performance);

            if ($outcome["conflict"]) {
                $pdo->rollBack();
                return "ค่าในผลงานเปลี่ยนไปหลังพนักงานส่งคำขอ (เช่น นำเข้าไฟล์ใหม่) จึงอนุมัติไม่ได้ กรุณาส่งกลับให้พนักงานตรวจสอบ";
            }
            if ($outcome["error"] !== null) {
                $pdo->rollBack();
                return "คำนวณคะแนนจากค่าที่เสนอไม่ได้: " . $outcome["error"];
            }
            if ($request["request_type"] === "value_change" && $outcome["criteria_pending"] !== null && !$acknowledged) {
                $pdo->rollBack();
                return "เกณฑ์ของ KPI นี้รอเจ้าของระบบยืนยัน คะแนนใหม่จึงยืนยันความถูกต้องไม่ได้ กรุณาติ๊กรับทราบก่อนอนุมัติ หรือส่งกลับ";
            }

            if ($request["request_type"] === "value_change") {
                $update = $pdo->prepare("
                    UPDATE kpi_performances
                    SET actual = :actual, score = :score
                    WHERE performance_id = :id
                      AND actual <=> :old_actual
                      AND score <=> :old_score
                ");
                $update->execute([
                    ":actual" => $outcome["new_actual"],
                    ":score" => $outcome["new_score"],
                    ":id" => (int) $performance["performance_id"],
                    ":old_actual" => $performance["actual"],
                    ":old_score" => $performance["score"],
                ]);
                if ($update->rowCount() !== 1) {
                    $pdo->rollBack();
                    return "ค่าในผลงานเปลี่ยนไประหว่างอนุมัติ กรุณาลองใหม่อีกครั้ง";
                }
                $applied = [
                    "old_actual" => $performance["actual"],
                    "old_score" => $performance["score"],
                    "new_actual" => $outcome["new_actual"],
                    "new_score" => $outcome["new_score"],
                    "at" => true,
                ];
            }

            if ($request["request_type"] === "value_change" && $outcome["criteria_pending"] !== null) {
                $comment = trim("[อนุมัติโดยรับทราบว่าเกณฑ์ KPI นี้รอยืนยัน] " . $comment);
            }
        }

        $done = $pdo->prepare("
            UPDATE kpi_correction_requests
            SET status = :status, reviewed_by = :reviewed_by, reviewed_role_id = :role, reviewed_at = NOW(),
                review_comment = :comment,
                applied_old_actual = :old_actual, applied_old_score = :old_score,
                applied_new_actual = :new_actual, applied_new_score = :new_score,
                applied_at = IF(:applied = 1, NOW(), NULL)
            WHERE request_id = :id AND status = 'Pending'
        ");
        $done->execute([
            ":status" => $decision === "approve" ? "Approved" : "Returned",
            ":reviewed_by" => $actorUserId,
            ":role" => $actorRoleId,
            ":comment" => $comment !== "" ? mb_substr($comment, 0, KPI_CORRECTION_COMMENT_MAX) : null,
            ":old_actual" => $applied["old_actual"],
            ":old_score" => $applied["old_score"],
            ":new_actual" => $applied["new_actual"],
            ":new_score" => $applied["new_score"],
            ":applied" => $applied["at"] ? 1 : 0,
            ":id" => $requestId,
        ]);
        if ($done->rowCount() !== 1) {
            $pdo->rollBack();
            return "คำขอนี้ได้รับการพิจารณาแล้ว";
        }

        kpiCorrectionEvent($pdo, $requestId, $decision, $actorUserId, $actorRoleId, $comment);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiCorrectionReview failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "บันทึกผลการพิจารณาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* รายการคำขอ · $filters: employee_id, status, year */
function kpiCorrectionList(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters["employee_id"])) {
        $where[] = "r.employee_id = :employee_id";
        $params[":employee_id"] = (int) $filters["employee_id"];
    }
    if (!empty($filters["status"]) && isset(KPI_CORRECTION_STATUS_LABELS[$filters["status"]])) {
        $where[] = "r.status = :status";
        $params[":status"] = $filters["status"];
    }
    if (!empty($filters["year"])) {
        $where[] = "ep.period_year = :year";
        $params[":year"] = (int) $filters["year"];
    }

    $stmt = $pdo->prepare("
        SELECT r.*, k.kpi_name, k.kpi_type, ep.period_year, ep.period_month,
               e.employee_code, e.first_name, e.last_name, d.department_name,
               (SELECT COUNT(*) FROM kpi_correction_files f WHERE f.request_id = r.request_id) AS file_count,
               (SELECT n.request_id FROM kpi_correction_requests n WHERE n.previous_request_id = r.request_id) AS next_request_id
        FROM kpi_correction_requests r
        INNER JOIN kpi_indicators k ON k.kpi_id = r.kpi_id
        INNER JOIN evaluation_periods ep ON ep.period_id = r.period_id
        INNER JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
        ORDER BY FIELD(r.status, 'Pending', 'Returned', 'Approved'), r.submitted_at DESC, r.request_id DESC
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiCorrectionGet(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.*, k.kpi_name, k.kpi_type, ep.period_year, ep.period_month,
               e.employee_code, e.first_name, e.last_name, d.department_name,
               (SELECT n.request_id FROM kpi_correction_requests n WHERE n.previous_request_id = r.request_id) AS next_request_id
        FROM kpi_correction_requests r
        INNER JOIN kpi_indicators k ON k.kpi_id = r.kpi_id
        INNER JOIN evaluation_periods ep ON ep.period_id = r.period_id
        INNER JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        WHERE r.request_id = :id
    ");
    $stmt->execute([":id" => $requestId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kpiCorrectionFiles(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare("SELECT * FROM kpi_correction_files WHERE request_id = :id ORDER BY file_id");
    $stmt->execute([":id" => $requestId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiCorrectionEvents(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare("
        SELECT ev.*, u.username, e.first_name, e.last_name
        FROM kpi_correction_events ev
        INNER JOIN users u ON u.user_id = ev.actor_user_id
        LEFT JOIN employees e ON e.employee_id = u.employee_id
        WHERE ev.request_id = :id
        ORDER BY ev.created_at, ev.event_id
    ");
    $stmt->execute([":id" => $requestId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ลำดับคำขอทั้งสายของผลงานเดียวกัน (คำขอแรก → ส่งใหม่ล่าสุด) */
function kpiCorrectionChain(PDO $pdo, int $requestId): array
{
    $chain = [];
    $current = kpiCorrectionGet($pdo, $requestId);

    while ($current !== null && !empty($current["previous_request_id"]) && count($chain) < 50) {
        $current = kpiCorrectionGet($pdo, (int) $current["previous_request_id"]);
        if ($current !== null) {
            array_unshift($chain, $current);
        }
    }

    $next = kpiCorrectionGet($pdo, $requestId);
    while ($next !== null && count($chain) < 100) {
        $chain[] = $next;
        $next = !empty($next["next_request_id"]) ? kpiCorrectionGet($pdo, (int) $next["next_request_id"]) : null;
    }

    return $chain;
}

/* ผู้มีสิทธิ์ดูคำขอ / เอกสาร: พนักงานเจ้าของ · Manager · Admin */
function kpiCorrectionViewerAllowed(array $request): bool
{
    return kpiFileViewerAllowed((int) $request["employee_id"]);
}

/* จำนวนคำขอที่รอพิจารณาของพนักงาน × เดือน (ใช้เตือนก่อนอนุมัติผลการประเมินรายเดือน) */
function kpiCorrectionPendingCount(PDO $pdo, int $employeeId, int $periodId): int
{
    if (!kpiCorrectionEnabled($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM kpi_correction_requests WHERE employee_id = :employee_id AND period_id = :period_id AND status = 'Pending'");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    return (int) $stmt->fetchColumn();
}

function kpiCorrectionActorName(array $event): string
{
    return kpiApprovalActorName($event);
}
