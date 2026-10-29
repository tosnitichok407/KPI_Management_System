<?php

/*
|--------------------------------------------------------------------------
| KPI12 (kpi_id 12) อัตราค้างสัญญาตัวจริงนำกลับ: บันทึกรายสัญญา (Migration 20261028_kpi_contract_returns)
|--------------------------------------------------------------------------
|
| 1 รายการ = สัญญา 1 ฉบับ (เลขที่สัญญาใหม่ไม่ซ้ำ) · ไม่ใช่ 1 ลูกค้า
| พนักงาน (role 3) บันทึก Draft + แนบหลักฐาน → ส่ง (Submitted ล็อก) → Manager (role 2) อนุมัติ / ส่งกลับ (ต้องมีเหตุผล)
|   ส่งกลับ (Rejected) → แก้ไขแล้วส่งใหม่ได้ · อนุมัติแล้ว (Approved) แก้ไม่ได้ ต้องส่งคำขอแก้ไข แล้ว Manager อนุมัติอีกครั้ง
|   ห้ามพิจารณารายการของตัวเอง · Admin ดูได้อย่างเดียว · ทุกขั้นตอนบันทึกลง kpi_contract_return_events
| สถานะเอกสาร (doc_status) แยกจากสถานะอนุมัติ (review_status) · แนบไฟล์อย่างเดียวไม่เปลี่ยนสถานะเอกสาร
| วันครบกำหนดคืน = วันที่ 15 ของเดือนถัดจากเดือนที่ส่งสัญญา (ระบบคำนวณ) · ตรงเวลา = วันที่รับคืน <= วันครบกำหนด (คำนวณทุกครั้ง ไม่เก็บ)
| สรุปรายเดือนคำนวณจากรายการที่อนุมัติแล้วเท่านั้น และไม่เขียน kpi_performances
|   100% = เกรด 5 · ต่ำกว่า 100% = "รอการกำหนดเกณฑ์" (ไม่ใช้ kpiCalculateScore / เกณฑ์ >90% เดิม) · ตัวหาร 0 = ไม่มีข้อมูล
| ไม่มีตาราง → ปิดฟีเจอร์ (หน้าเว็บทำงานแบบเดิม)
|
*/

require_once __DIR__ . "/kpi-evidence.php";
require_once __DIR__ . "/kpi-approval.php";

const KPI_CONTRACT_RETURN_KPI_ID = 12;
const KPI_CONTRACT_RETURN_KPI_LABEL = "KPI12";
/* รูปแบบเลขที่สัญญาตามข้อมูลจริงในไฟล์บริษัท (เช่น CT26-0000001) · ไม่แก้ตัวพิมพ์หรือเติมเลขให้ */
const KPI_CONTRACT_RETURN_NO_PATTERN = '/^CT\d{2}-\d{7}$/';
const KPI_CONTRACT_RETURN_DUE_DAY = 15;
const KPI_CONTRACT_RETURN_TIMEZONE = "Asia/Bangkok";
const KPI_CONTRACT_RETURN_MAX_FILES = KPI_EVIDENCE_MAX_FILES;
/* นโยบาย: สถานะ "รับคืนแล้ว" ต้องมีหลักฐานอย่างน้อย 1 ไฟล์ก่อนส่ง/อนุมัติ (รอเจ้าของระบบยืนยัน · เปลี่ยนเป็น false ได้ที่นี่จุดเดียว) */
const KPI_CONTRACT_RETURN_REQUIRE_FILE_FOR_RETURNED = true;
const KPI_CONTRACT_RETURN_TEXT_MAX = 1000;
const KPI_CONTRACT_RETURN_REASON_MAX = 2000;
const KPI_CONTRACT_RETURN_NAME_MAX = 255;
const KPI_CONTRACT_RETURN_STORAGE_AREA = "contract_returns";

const KPI_CONTRACT_RETURN_DOC_LABELS = [
    "not_sent" => "ยังไม่ส่งสัญญา",
    "sent" => "ส่งสัญญาแล้ว รอรับคืน",
    "returned" => "รับสัญญาคืนแล้ว",
    "cancelled" => "ยกเลิก",
];

const KPI_CONTRACT_RETURN_REVIEW_LABELS = [
    "Draft" => "ฉบับร่าง",
    "Submitted" => "รอ Manager อนุมัติ",
    "Approved" => "อนุมัติแล้ว",
    "Rejected" => "ส่งกลับให้แก้ไข",
];

const KPI_CONTRACT_RETURN_REVISION_LABELS = [
    "Pending" => "รอ Manager พิจารณา",
    "Approved" => "อนุมัติแล้ว",
    "Rejected" => "ส่งกลับ",
];

const KPI_CONTRACT_RETURN_ACTION_LABELS = [
    "create" => "สร้างรายการ",
    "update" => "แก้ไขฉบับร่าง",
    "file_add" => "แนบหลักฐาน",
    "file_delete" => "ลบหลักฐาน",
    "submit" => "ส่งให้ Manager",
    "approve" => "อนุมัติ",
    "reject" => "ส่งกลับให้แก้ไข",
    "revision_request" => "ขอแก้ไขรายการที่อนุมัติแล้ว",
    "revision_approve" => "อนุมัติคำขอแก้ไข",
    "revision_reject" => "ส่งกลับคำขอแก้ไข",
];

const KPI_CONTRACT_RETURN_TIMELINESS_LABELS = [
    "on_time" => "คืนตรงเวลา",
    "late" => "คืนเกินกำหนด",
    "overdue" => "ค้างเกินกำหนด",
    "pending_due" => "ยังไม่ถึงกำหนด",
    "not_sent" => "ยังไม่ส่ง",
    "cancelled" => "ยกเลิก",
];

/* ฟิลด์ธุรกิจของรายการ (ใช้เก็บค่าก่อน / หลังในประวัติ และเทียบค่าในคำขอแก้ไข) */
const KPI_CONTRACT_RETURN_FIELDS = [
    "contract_period_id", "contract_no_new", "contract_no_old", "customer_name",
    "doc_status", "sent_date", "due_date", "returned_date", "cancel_reason", "note",
];

function kpiContractReturnEnabled(PDO $pdo): bool
{
    static $enabled = null;

    if ($enabled === null) {
        try {
            $count = (int) $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('kpi_contract_returns', 'kpi_contract_return_revisions', 'kpi_contract_return_files', 'kpi_contract_return_events')
            ")->fetchColumn();
            $enabled = $count === 4;
        } catch (Throwable $exception) {
            $enabled = false;
        }
    }

    return $enabled;
}

/* วันที่วันนี้ตามเวลาไทย (Y-m-d) · ไม่ขึ้นกับ date.timezone ของ php.ini */
function kpiContractReturnToday(): string
{
    return (new DateTimeImmutable("now", new DateTimeZone(KPI_CONTRACT_RETURN_TIMEZONE)))->format("Y-m-d");
}

/* วันครบกำหนดคืน = วันที่ 15 ของเดือนถัดจากเดือนที่ส่ง (ส่ง 20 ธ.ค. 2026 → 15 ม.ค. 2027) */
function kpiContractReturnDueDate(string $sentDate): string
{
    $nextMonth = DateTimeImmutable::createFromFormat("!Y-m-d", $sentDate)->modify("first day of next month");

    return $nextMonth->setDate((int) $nextMonth->format("Y"), (int) $nextMonth->format("n"), KPI_CONTRACT_RETURN_DUE_DAY)->format("Y-m-d");
}

/* วันที่รูปแบบ Y-m-d (ปี ค.ศ.) ที่มีอยู่จริง · คืน null ถ้าไม่ถูกต้อง */
function kpiContractReturnParseDate(string $raw): ?string
{
    $raw = trim($raw);
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $raw);

    if ($date === false || $date->format("Y-m-d") !== $raw) {
        return null;
    }

    $year = (int) $date->format("Y");

    return $year >= 2000 && $year <= 2100 ? $raw : null;
}

/* ตรวจรูปแบบเลขที่สัญญา (ไม่แก้ไข / ไม่เดา) */
function kpiContractReturnNoValid(string $value): bool
{
    return preg_match(KPI_CONTRACT_RETURN_NO_PATTERN, $value) === 1;
}

/* ข้อความทั่วไป: ตัดช่องว่างหัวท้ายและอักขระควบคุม (คงบรรทัดใหม่ไว้) */
function kpiContractReturnCleanText(string $value): string
{
    return trim(preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/u', "", $value) ?? "");
}

/*
| ตรวจข้อมูลที่กรอก · คืน ["fields" => ค่าที่ใช้บันทึก, "errors" => [ข้อความ ...]]
| $input: contract_period_id, contract_no_new, contract_no_old, customer_name, doc_status, sent_date, returned_date, cancel_reason, note
| due_date คำนวณจาก sent_date เสมอ (ค่า due_date / on_time ที่ส่งมาจากฟอร์มถูกละเลย)
*/
function kpiContractReturnValidate(PDO $pdo, array $input, string $today): array
{
    $errors = [];
    $sourceHint = "หากเลขในไฟล์ Excel ต้นทางไม่ถูกต้อง ให้ผู้รับผิดชอบแก้ไขที่ไฟล์ต้นทาง ระบบไม่แก้เลขให้อัตโนมัติ";

    $periodId = (int) ($input["contract_period_id"] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM evaluation_periods WHERE period_id = :id");
    $stmt->execute([":id" => $periodId]);
    if ($periodId <= 0 || (int) $stmt->fetchColumn() !== 1) {
        $errors[] = "กรุณาเลือกเดือนของสัญญา";
    }

    $noNew = trim((string) ($input["contract_no_new"] ?? ""));
    if ($noNew === "") {
        $errors[] = "กรุณาระบุเลขที่สัญญาใหม่";
    } elseif (!kpiContractReturnNoValid($noNew)) {
        $errors[] = "เลขที่สัญญาใหม่ \"" . mb_substr($noNew, 0, 30) . "\" ไม่ถูกต้อง (รูปแบบ CTปป-เลข 7 หลัก ตัวพิมพ์ใหญ่ เช่น CT26-0000001) · " . $sourceHint;
    }

    $noOld = trim((string) ($input["contract_no_old"] ?? ""));
    if ($noOld !== "" && !kpiContractReturnNoValid($noOld)) {
        $errors[] = "เลขที่สัญญาเดิม \"" . mb_substr($noOld, 0, 30) . "\" ไม่ถูกต้อง (รูปแบบ CTปป-เลข 7 หลัก) · " . $sourceHint;
    }

    $customer = kpiContractReturnCleanText((string) ($input["customer_name"] ?? ""));
    if ($customer === "") {
        $errors[] = "กรุณาระบุชื่อลูกค้า";
    } elseif (mb_strlen($customer) > KPI_CONTRACT_RETURN_NAME_MAX) {
        $errors[] = "ชื่อลูกค้าต้องไม่เกิน " . KPI_CONTRACT_RETURN_NAME_MAX . " ตัวอักษร";
    }

    $docStatus = (string) ($input["doc_status"] ?? "");
    if (!isset(KPI_CONTRACT_RETURN_DOC_LABELS[$docStatus])) {
        $errors[] = "กรุณาเลือกสถานะเอกสาร";
        $docStatus = "";
    }

    $dates = [];
    foreach (["sent_date" => "วันที่ส่งสัญญา", "returned_date" => "วันที่รับคืนจริง"] as $key => $label) {
        $raw = trim((string) ($input[$key] ?? ""));
        $dates[$key] = null;
        if ($raw === "") {
            continue;
        }
        $parsed = kpiContractReturnParseDate($raw);
        if ($parsed === null) {
            $errors[] = "{$label} ไม่ถูกต้อง (ใช้ปี ค.ศ. รูปแบบ ปปปป-ดด-วว)";
        } elseif ($parsed > $today) {
            $errors[] = "{$label} ต้องไม่เป็นวันในอนาคต";
        } else {
            $dates[$key] = $parsed;
        }
    }

    $cancelReason = kpiContractReturnCleanText((string) ($input["cancel_reason"] ?? ""));
    $note = kpiContractReturnCleanText((string) ($input["note"] ?? ""));

    switch ($docStatus) {
        case "not_sent":
            if ($dates["sent_date"] !== null || $dates["returned_date"] !== null) {
                $errors[] = "สถานะ \"ยังไม่ส่งสัญญา\" ต้องไม่มีวันที่ส่งหรือวันที่รับคืน";
            }
            break;
        case "sent":
            if ($dates["sent_date"] === null) {
                $errors[] = "สถานะ \"ส่งสัญญาแล้ว\" ต้องระบุวันที่ส่งสัญญา";
            }
            if ($dates["returned_date"] !== null) {
                $errors[] = "มีวันที่รับคืนแล้ว กรุณาเลือกสถานะ \"รับสัญญาคืนแล้ว\"";
            }
            break;
        case "returned":
            if ($dates["sent_date"] === null || $dates["returned_date"] === null) {
                $errors[] = "สถานะ \"รับสัญญาคืนแล้ว\" ต้องระบุทั้งวันที่ส่งและวันที่รับคืนจริง";
            }
            break;
        case "cancelled":
            if ($cancelReason === "") {
                $errors[] = "สถานะ \"ยกเลิก\" ต้องระบุเหตุผล";
            }
            if ($dates["returned_date"] !== null && $dates["sent_date"] === null) {
                $errors[] = "มีวันที่รับคืนแต่ไม่มีวันที่ส่งสัญญา";
            }
            break;
    }

    if ($dates["sent_date"] !== null && $dates["returned_date"] !== null && $dates["returned_date"] < $dates["sent_date"]) {
        $errors[] = "วันที่รับคืนต้องไม่ก่อนวันที่ส่งสัญญา";
    }
    if (mb_strlen($cancelReason) > KPI_CONTRACT_RETURN_TEXT_MAX || mb_strlen($note) > KPI_CONTRACT_RETURN_TEXT_MAX) {
        $errors[] = "เหตุผลยกเลิก / หมายเหตุ ต้องไม่เกิน " . KPI_CONTRACT_RETURN_TEXT_MAX . " ตัวอักษร";
    }

    return [
        "fields" => [
            "contract_period_id" => $periodId,
            "contract_no_new" => $noNew,
            "contract_no_old" => $noOld !== "" ? $noOld : null,
            "customer_name" => $customer,
            "doc_status" => $docStatus,
            "sent_date" => $dates["sent_date"],
            "due_date" => $dates["sent_date"] !== null ? kpiContractReturnDueDate($dates["sent_date"]) : null,
            "returned_date" => $dates["returned_date"],
            "cancel_reason" => $docStatus === "cancelled" && $cancelReason !== "" ? $cancelReason : null,
            "note" => $note !== "" ? $note : null,
        ],
        "errors" => $errors,
    ];
}

/* ค่าธุรกิจของรายการ (สำหรับประวัติ / เทียบค่า) */
function kpiContractReturnSnapshot(array $row): array
{
    $snapshot = [];
    foreach (KPI_CONTRACT_RETURN_FIELDS as $field) {
        $value = $row[$field] ?? null;
        $snapshot[$field] = $field === "contract_period_id" ? (int) $value : ($value === null ? null : (string) $value);
    }

    return $snapshot;
}

function kpiContractReturnJson(?array $data): ?string
{
    return $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ความตรงเวลา (คำนวณจากวันที่เสมอ) · $today = วันที่ประเมิน */
function kpiContractReturnTimeliness(array $row, string $today): string
{
    if ($row["doc_status"] === "cancelled") {
        return "cancelled";
    }
    if ($row["due_date"] === null) {
        return "not_sent";
    }
    if ($row["doc_status"] === "returned" && $row["returned_date"] !== null) {
        return $row["returned_date"] <= $row["due_date"] ? "on_time" : "late";
    }

    return $row["due_date"] < $today ? "overdue" : "pending_due";
}

/* พนักงานมี Assignment ของ KPI12 ที่ Active หรือไม่ */
function kpiContractReturnEmployeeEligible(PDO $pdo, int $employeeId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM kpi_assignments
        WHERE employee_id = :employee_id AND kpi_id = :kpi_id AND status = 'Active'
    ");
    $stmt->execute([":employee_id" => $employeeId, ":kpi_id" => KPI_CONTRACT_RETURN_KPI_ID]);

    return (int) $stmt->fetchColumn() > 0;
}

/* เก็บไฟล์ที่ตรวจแล้ว (ตรวจเนื้อไฟล์ด้วย kpiEvidenceCheckUpload) · คืน [รายการไฟล์, error] */
function kpiContractReturnStoreUploads(array $files, bool $requireUploadedFile): array
{
    if (!$files) {
        return [[], null];
    }

    $dir = kpiStorageDir(KPI_CONTRACT_RETURN_STORAGE_AREA);
    if ($dir === null) {
        return [[], "ระบบจัดเก็บไฟล์ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ"];
    }

    $stored = [];
    foreach ($files as $file) {
        $check = kpiEvidenceCheckUpload($file, $requireUploadedFile);
        if ($check["error"] !== null) {
            kpiContractReturnDiscardFiles($stored);
            return [[], "ไฟล์ " . kpiStorageCleanName((string) ($file["name"] ?? "")) . ": " . $check["error"]];
        }

        $storedName = kpiStorageNewName();
        $target = $dir . "/" . $storedName;
        $tmp = (string) $file["tmp_name"];
        $moved = $requireUploadedFile ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);

        if (!$moved) {
            kpiContractReturnDiscardFiles($stored);
            return [[], "บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"];
        }

        @chmod($target, 0640);
        $stored[] = [
            "original_name" => kpiStorageCleanName((string) ($file["name"] ?? ""), "evidence." . $check["ext"]),
            "stored_name" => $storedName,
            "file_ext" => $check["ext"],
            "mime_type" => $check["mime"],
            "file_size" => (int) filesize($target),
            "file_sha256" => (string) hash_file("sha256", $target),
        ];
    }

    return [$stored, null];
}

function kpiContractReturnDiscardFiles(array $stored): void
{
    foreach ($stored as $file) {
        kpiStorageDelete(KPI_CONTRACT_RETURN_STORAGE_AREA, $file["stored_name"]);
    }
}

function kpiContractReturnInsertFiles(PDO $pdo, int $returnId, ?int $revisionId, array $stored, int $userId, int $roleId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO kpi_contract_return_files
            (return_id, revision_id, original_name, stored_name, file_ext, mime_type, file_size, file_sha256, uploaded_by)
        VALUES
            (:return_id, :revision_id, :original_name, :stored_name, :file_ext, :mime_type, :file_size, :file_sha256, :uploaded_by)
    ");

    foreach ($stored as $file) {
        $stmt->execute([
            ":return_id" => $returnId,
            ":revision_id" => $revisionId,
            ":original_name" => $file["original_name"],
            ":stored_name" => $file["stored_name"],
            ":file_ext" => $file["file_ext"],
            ":mime_type" => $file["mime_type"],
            ":file_size" => $file["file_size"],
            ":file_sha256" => $file["file_sha256"],
            ":uploaded_by" => $userId,
        ]);
        kpiContractReturnEvent($pdo, $returnId, $revisionId, "file_add", $userId, $roleId, null, null, [
            "file_id" => (int) $pdo->lastInsertId(),
            "original_name" => $file["original_name"],
            "file_sha256" => $file["file_sha256"],
        ]);
    }
}

function kpiContractReturnEvent(PDO $pdo, int $returnId, ?int $revisionId, string $action, int $actorUserId, int $actorRoleId, ?string $comment, ?array $before, ?array $after): void
{
    $pdo->prepare("
        INSERT INTO kpi_contract_return_events
            (return_id, revision_id, action, actor_user_id, actor_role_id, comment, before_json, after_json)
        VALUES
            (:return_id, :revision_id, :action, :actor_user_id, :actor_role_id, :comment, :before_json, :after_json)
    ")->execute([
        ":return_id" => $returnId,
        ":revision_id" => $revisionId,
        ":action" => $action,
        ":actor_user_id" => $actorUserId,
        ":actor_role_id" => $actorRoleId,
        ":comment" => $comment !== null && trim($comment) !== "" ? mb_substr(trim($comment), 0, KPI_CONTRACT_RETURN_TEXT_MAX) : null,
        ":before_json" => kpiContractReturnJson($before),
        ":after_json" => kpiContractReturnJson($after),
    ]);
}

/* เลขที่สัญญาใหม่นี้ถูกใช้โดยรายการอื่นแล้วหรือไม่ */
function kpiContractReturnNoTaken(PDO $pdo, string $contractNo, int $exceptReturnId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM kpi_contract_returns WHERE contract_no_new = :no AND return_id <> :id");
    $stmt->execute([":no" => $contractNo, ":id" => $exceptReturnId]);

    return (int) $stmt->fetchColumn() > 0;
}

function kpiContractReturnDuplicateMessage(string $contractNo): string
{
    return "เลขที่สัญญาใหม่ {$contractNo} มีรายการในระบบแล้ว ระบบไม่รวมหรือสร้างรายการซ้ำให้ · หากเป็นสัญญาฉบับเดียวกันให้แก้ไขรายการเดิม หากเลขซ้ำเพราะข้อมูลต้นทางผิด ให้ผู้รับผิดชอบแก้ไขที่ไฟล์ต้นทาง";
}

/* ล็อกรายการ (FOR UPDATE) */
function kpiContractReturnLock(PDO $pdo, int $returnId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM kpi_contract_returns WHERE return_id = :id FOR UPDATE");
    $stmt->execute([":id" => $returnId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* จำนวนหลักฐานของรายการ (ไม่รวมไฟล์ของคำขอแก้ไขที่ยังไม่อนุมัติ) */
function kpiContractReturnFileCount(PDO $pdo, int $returnId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM kpi_contract_return_files f
        LEFT JOIN kpi_contract_return_revisions v ON v.revision_id = f.revision_id
        WHERE f.return_id = :id AND (f.revision_id IS NULL OR v.status = 'Approved')
    ");
    $stmt->execute([":id" => $returnId]);

    return (int) $stmt->fetchColumn();
}

/*
| พนักงานสร้าง / แก้ไขฉบับร่าง (+ แนบไฟล์) · คืน ["errors" => [..], "return_id" => ?int]
| แก้ไขได้เฉพาะรายการของตัวเองที่เป็น Draft หรือ Rejected · ไม่เปลี่ยนสถานะอนุมัติ
*/
function kpiContractReturnSave(PDO $pdo, int $employeeId, int $userId, int $returnId, array $input, array $files, bool $requireUploadedFile = true): array
{
    $fail = fn(array $errors) => ["errors" => $errors, "return_id" => null];

    if (!kpiContractReturnEnabled($pdo)) {
        return $fail(["ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน"]);
    }
    if (!kpiContractReturnEmployeeEligible($pdo, $employeeId)) {
        return $fail(["ไม่พบการมอบหมาย " . KPI_CONTRACT_RETURN_KPI_LABEL . " ของพนักงาน กรุณาติดต่อผู้ดูแลระบบ"]);
    }

    $validation = kpiContractReturnValidate($pdo, $input, kpiContractReturnToday());
    if ($validation["errors"]) {
        return $fail($validation["errors"]);
    }
    $fields = $validation["fields"];

    if (count($files) > KPI_CONTRACT_RETURN_MAX_FILES) {
        return $fail(["แนบหลักฐานได้สูงสุด " . KPI_CONTRACT_RETURN_MAX_FILES . " ไฟล์ต่อรายการ"]);
    }

    [$stored, $error] = kpiContractReturnStoreUploads($files, $requireUploadedFile);
    if ($error !== null) {
        return $fail([$error]);
    }

    try {
        $pdo->beginTransaction();

        $before = null;
        if ($returnId > 0) {
            $row = kpiContractReturnLock($pdo, $returnId);
            if ($row === null || (int) $row["employee_id"] !== $employeeId) {
                throw new DomainException("ไม่พบรายการนี้");
            }
            if (!in_array($row["review_status"], ["Draft", "Rejected"], true)) {
                throw new DomainException("รายการสถานะ \"" . KPI_CONTRACT_RETURN_REVIEW_LABELS[$row["review_status"]] . "\" แก้ไขโดยตรงไม่ได้"
                    . ($row["review_status"] === "Approved" ? " กรุณาส่งคำขอแก้ไข" : ""));
            }
            if (kpiContractReturnFileCount($pdo, $returnId) + count($stored) > KPI_CONTRACT_RETURN_MAX_FILES) {
                throw new DomainException("แนบหลักฐานได้สูงสุด " . KPI_CONTRACT_RETURN_MAX_FILES . " ไฟล์ต่อรายการ");
            }
            $before = kpiContractReturnSnapshot($row);
        }

        if (kpiContractReturnNoTaken($pdo, $fields["contract_no_new"], $returnId)) {
            throw new DomainException(kpiContractReturnDuplicateMessage($fields["contract_no_new"]));
        }

        $params = [
            ":contract_period_id" => $fields["contract_period_id"],
            ":contract_no_new" => $fields["contract_no_new"],
            ":contract_no_old" => $fields["contract_no_old"],
            ":customer_name" => $fields["customer_name"],
            ":doc_status" => $fields["doc_status"],
            ":sent_date" => $fields["sent_date"],
            ":due_date" => $fields["due_date"],
            ":returned_date" => $fields["returned_date"],
            ":cancel_reason" => $fields["cancel_reason"],
            ":note" => $fields["note"],
            ":updated_by" => $userId,
        ];

        if ($returnId > 0) {
            $pdo->prepare("
                UPDATE kpi_contract_returns
                SET contract_period_id = :contract_period_id, contract_no_new = :contract_no_new, contract_no_old = :contract_no_old,
                    customer_name = :customer_name, doc_status = :doc_status, sent_date = :sent_date, due_date = :due_date,
                    returned_date = :returned_date, cancel_reason = :cancel_reason, note = :note, updated_by = :updated_by
                WHERE return_id = :id AND review_status IN ('Draft', 'Rejected')
            ")->execute($params + [":id" => $returnId]);

            $after = kpiContractReturnSnapshot($fields);
            if ($after !== $before) {
                kpiContractReturnEvent($pdo, $returnId, null, "update", $userId, 3, null, $before, $after);
            }
        } else {
            $pdo->prepare("
                INSERT INTO kpi_contract_returns
                    (employee_id, contract_period_id, contract_no_new, contract_no_old, customer_name, doc_status,
                     sent_date, due_date, returned_date, cancel_reason, note, created_by, updated_by)
                VALUES
                    (:employee_id, :contract_period_id, :contract_no_new, :contract_no_old, :customer_name, :doc_status,
                     :sent_date, :due_date, :returned_date, :cancel_reason, :note, :created_by, :updated_by)
            ")->execute($params + [":employee_id" => $employeeId, ":created_by" => $userId]);
            $returnId = (int) $pdo->lastInsertId();
            kpiContractReturnEvent($pdo, $returnId, null, "create", $userId, 3, null, null, kpiContractReturnSnapshot($fields));
        }

        kpiContractReturnInsertFiles($pdo, $returnId, null, $stored, $userId, 3);
        $pdo->commit();

        return ["errors" => [], "return_id" => $returnId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kpiContractReturnDiscardFiles($stored);

        if ($exception instanceof DomainException) {
            return $fail([$exception->getMessage()]);
        }
        if ($exception instanceof PDOException && $exception->getCode() === "23000" && str_contains($exception->getMessage(), "uq_contract_return_no")) {
            return $fail([kpiContractReturnDuplicateMessage($fields["contract_no_new"])]);
        }
        error_log("kpiContractReturnSave failed (" . get_class($exception) . "): " . $exception->getMessage());
        return $fail(["บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"]);
    }
}

/* ลบหลักฐาน (เฉพาะเจ้าของ · รายการ Draft / Rejected · ไฟล์ของรายการ ไม่ใช่ของคำขอแก้ไข) · คืน null = สำเร็จ */
function kpiContractReturnDeleteFile(PDO $pdo, int $employeeId, int $userId, int $fileId): ?string
{
    if (!kpiContractReturnEnabled($pdo)) {
        return "ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน";
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM kpi_contract_return_files WHERE file_id = :id FOR UPDATE");
        $stmt->execute([":id" => $fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $file ? kpiContractReturnLock($pdo, (int) $file["return_id"]) : null;

        if (!$file || $row === null || (int) $row["employee_id"] !== $employeeId || $file["revision_id"] !== null) {
            $pdo->rollBack();
            return "ไม่พบไฟล์หลักฐานนี้";
        }
        if (!in_array($row["review_status"], ["Draft", "Rejected"], true)) {
            $pdo->rollBack();
            return "ลบหลักฐานได้เฉพาะรายการฉบับร่างหรือที่ถูกส่งกลับให้แก้ไข";
        }

        $pdo->prepare("DELETE FROM kpi_contract_return_files WHERE file_id = :id")->execute([":id" => $fileId]);
        kpiContractReturnEvent($pdo, (int) $row["return_id"], null, "file_delete", $userId, 3, null, [
            "file_id" => (int) $file["file_id"],
            "original_name" => $file["original_name"],
            "file_sha256" => $file["file_sha256"],
        ], null);
        $pdo->commit();

        kpiStorageDelete(KPI_CONTRACT_RETURN_STORAGE_AREA, (string) $file["stored_name"]);
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiContractReturnDeleteFile failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "ลบหลักฐานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* เงื่อนไขหลักฐานของสถานะเอกสาร · คืนข้อความ error หรือ null */
function kpiContractReturnEvidenceError(string $docStatus, int $fileCount): ?string
{
    if (KPI_CONTRACT_RETURN_REQUIRE_FILE_FOR_RETURNED && $docStatus === "returned" && $fileCount < 1) {
        return "สถานะ \"รับสัญญาคืนแล้ว\" ต้องแนบหลักฐานการรับคืนอย่างน้อย 1 ไฟล์";
    }

    return null;
}

/* พนักงานส่งรายการให้ Manager (Draft / Rejected → Submitted) · คืน null = สำเร็จ */
function kpiContractReturnSubmit(PDO $pdo, int $employeeId, int $userId, int $returnId): ?string
{
    if (!kpiContractReturnEnabled($pdo)) {
        return "ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน";
    }

    try {
        $pdo->beginTransaction();

        $row = kpiContractReturnLock($pdo, $returnId);
        if ($row === null || (int) $row["employee_id"] !== $employeeId) {
            $pdo->rollBack();
            return "ไม่พบรายการนี้";
        }
        if (!in_array($row["review_status"], ["Draft", "Rejected"], true)) {
            $pdo->rollBack();
            return "รายการนี้ส่งแล้ว (" . KPI_CONTRACT_RETURN_REVIEW_LABELS[$row["review_status"]] . ")";
        }

        /* ตรวจข้อมูลซ้ำตอนส่ง (เช่น วันที่ / เลขสัญญา) */
        $validation = kpiContractReturnValidate($pdo, $row, kpiContractReturnToday());
        if ($validation["errors"]) {
            $pdo->rollBack();
            return "ข้อมูลยังไม่ครบถ้วน: " . implode(" · ", $validation["errors"]);
        }
        $evidenceError = kpiContractReturnEvidenceError((string) $row["doc_status"], kpiContractReturnFileCount($pdo, $returnId));
        if ($evidenceError !== null) {
            $pdo->rollBack();
            return $evidenceError;
        }

        $pdo->prepare("
            UPDATE kpi_contract_returns
            SET review_status = 'Submitted', submitted_by = :user_id, submitted_at = NOW(),
                reviewed_by = NULL, reviewed_role_id = NULL, reviewed_at = NULL, review_comment = NULL
            WHERE return_id = :id AND review_status IN ('Draft', 'Rejected')
        ")->execute([":user_id" => $userId, ":id" => $returnId]);

        kpiContractReturnEvent($pdo, $returnId, null, "submit", $userId, 3, null, ["review_status" => $row["review_status"]], ["review_status" => "Submitted"]);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiContractReturnSubmit failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "ส่งรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* เป็นรายการของผู้พิจารณาเองหรือไม่ (พนักงานเจ้าของ / ผู้สร้าง / ผู้ส่ง) */
function kpiContractReturnIsOwn(array $row, int $actorUserId, int $actorEmployeeId, array $userColumns): bool
{
    if ($actorEmployeeId > 0 && (int) $row["employee_id"] === $actorEmployeeId) {
        return true;
    }
    foreach ($userColumns as $column) {
        if (isset($row[$column]) && (int) $row[$column] === $actorUserId) {
            return true;
        }
    }

    return false;
}

/* ตรวจสิทธิ์ผู้พิจารณาและความเห็น · คืนข้อความ error หรือ null */
function kpiContractReturnReviewGuard(int $actorRoleId, string $decision, string $comment): ?string
{
    if ($actorRoleId !== KPI_APPROVAL_ROLE_MANAGER) {
        return "เฉพาะ Manager เท่านั้นที่อนุมัติหรือส่งกลับได้";
    }
    if (!in_array($decision, ["approve", "reject"], true)) {
        return "คำสั่งไม่ถูกต้อง";
    }
    if ($decision === "reject" && trim($comment) === "") {
        return "กรุณาระบุเหตุผลที่ส่งกลับให้แก้ไข";
    }
    if (mb_strlen(trim($comment)) > KPI_CONTRACT_RETURN_TEXT_MAX) {
        return "หมายเหตุต้องไม่เกิน " . KPI_CONTRACT_RETURN_TEXT_MAX . " ตัวอักษร";
    }

    return null;
}

/* Manager อนุมัติ / ส่งกลับรายการ (Submitted เท่านั้น) · คืน null = สำเร็จ */
function kpiContractReturnReview(PDO $pdo, int $actorUserId, int $actorRoleId, int $actorEmployeeId, int $returnId, string $decision, string $comment): ?string
{
    if (!kpiContractReturnEnabled($pdo)) {
        return "ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน";
    }
    $guard = kpiContractReturnReviewGuard($actorRoleId, $decision, $comment);
    if ($guard !== null) {
        return $guard;
    }
    $comment = trim($comment);

    try {
        $pdo->beginTransaction();

        $row = kpiContractReturnLock($pdo, $returnId);
        if ($row === null) {
            $pdo->rollBack();
            return "ไม่พบรายการนี้";
        }
        if ($row["review_status"] !== "Submitted") {
            $pdo->rollBack();
            return "รายการนี้ไม่ได้อยู่ในสถานะรออนุมัติ (" . KPI_CONTRACT_RETURN_REVIEW_LABELS[$row["review_status"]] . ")";
        }
        if (kpiContractReturnIsOwn($row, $actorUserId, $actorEmployeeId, ["created_by", "submitted_by"])) {
            $pdo->rollBack();
            return "ไม่สามารถพิจารณารายการของตัวเองได้";
        }
        if ($decision === "approve") {
            $evidenceError = kpiContractReturnEvidenceError((string) $row["doc_status"], kpiContractReturnFileCount($pdo, $returnId));
            if ($evidenceError !== null) {
                $pdo->rollBack();
                return $evidenceError . " · กรุณาส่งกลับให้พนักงานแนบหลักฐาน";
            }
        }

        $status = $decision === "approve" ? "Approved" : "Rejected";
        $update = $pdo->prepare("
            UPDATE kpi_contract_returns
            SET review_status = :status, reviewed_by = :reviewed_by, reviewed_role_id = :role, reviewed_at = NOW(), review_comment = :comment
            WHERE return_id = :id AND review_status = 'Submitted'
        ");
        $update->execute([
            ":status" => $status,
            ":reviewed_by" => $actorUserId,
            ":role" => $actorRoleId,
            ":comment" => $comment !== "" ? $comment : null,
            ":id" => $returnId,
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return "รายการนี้ได้รับการพิจารณาแล้ว";
        }

        kpiContractReturnEvent($pdo, $returnId, null, $decision, $actorUserId, $actorRoleId, $comment, ["review_status" => "Submitted"], ["review_status" => $status]);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiContractReturnReview failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "บันทึกผลการพิจารณาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/*
| พนักงานขอแก้ไขรายการที่อนุมัติแล้ว · คืน ["errors" => [..], "revision_id" => ?int]
| ค่าที่อนุมัติแล้วยังใช้ต่อจนกว่าคำขอจะได้รับอนุมัติ · ต้องมีเหตุผล · ค่าเดิมเก็บใน before_json
*/
function kpiContractReturnRequestRevision(PDO $pdo, int $employeeId, int $userId, int $returnId, array $input, string $reason, array $files, bool $requireUploadedFile = true): array
{
    $fail = fn(array $errors) => ["errors" => $errors, "revision_id" => null];

    if (!kpiContractReturnEnabled($pdo)) {
        return $fail(["ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน"]);
    }

    $reason = kpiContractReturnCleanText($reason);
    if ($reason === "") {
        return $fail(["กรุณาระบุเหตุผลในการขอแก้ไข"]);
    }
    if (mb_strlen($reason) > KPI_CONTRACT_RETURN_REASON_MAX) {
        return $fail(["เหตุผลต้องไม่เกิน " . KPI_CONTRACT_RETURN_REASON_MAX . " ตัวอักษร"]);
    }

    $validation = kpiContractReturnValidate($pdo, $input, kpiContractReturnToday());
    if ($validation["errors"]) {
        return $fail($validation["errors"]);
    }
    $fields = $validation["fields"];

    if (count($files) > KPI_CONTRACT_RETURN_MAX_FILES) {
        return $fail(["แนบหลักฐานได้สูงสุด " . KPI_CONTRACT_RETURN_MAX_FILES . " ไฟล์ต่อคำขอ"]);
    }

    [$stored, $error] = kpiContractReturnStoreUploads($files, $requireUploadedFile);
    if ($error !== null) {
        return $fail([$error]);
    }

    try {
        $pdo->beginTransaction();

        $row = kpiContractReturnLock($pdo, $returnId);
        if ($row === null || (int) $row["employee_id"] !== $employeeId) {
            throw new DomainException("ไม่พบรายการนี้");
        }
        if ($row["review_status"] !== "Approved") {
            throw new DomainException("ขอแก้ไขได้เฉพาะรายการที่อนุมัติแล้ว (รายการอื่นแก้ไขได้โดยตรงเมื่อเป็นฉบับร่างหรือถูกส่งกลับ)");
        }

        $pending = $pdo->prepare("SELECT COUNT(*) FROM kpi_contract_return_revisions WHERE return_id = :id AND status = 'Pending'");
        $pending->execute([":id" => $returnId]);
        if ((int) $pending->fetchColumn() > 0) {
            throw new DomainException("รายการนี้มีคำขอแก้ไขที่รอ Manager พิจารณาอยู่แล้ว");
        }

        $before = kpiContractReturnSnapshot($row);
        $after = kpiContractReturnSnapshot($fields);
        if ($after === $before && !$stored) {
            throw new DomainException("ค่าที่ขอแก้ไขเท่ากับค่าเดิม และไม่มีหลักฐานเพิ่ม");
        }
        if (kpiContractReturnNoTaken($pdo, $fields["contract_no_new"], $returnId)) {
            throw new DomainException(kpiContractReturnDuplicateMessage($fields["contract_no_new"]));
        }
        $fileCount = kpiContractReturnFileCount($pdo, $returnId) + count($stored);
        if ($fileCount > KPI_CONTRACT_RETURN_MAX_FILES) {
            throw new DomainException("แนบหลักฐานได้สูงสุด " . KPI_CONTRACT_RETURN_MAX_FILES . " ไฟล์ต่อรายการ (รวมไฟล์เดิม)");
        }
        $evidenceError = kpiContractReturnEvidenceError($fields["doc_status"], $fileCount);
        if ($evidenceError !== null) {
            throw new DomainException($evidenceError);
        }

        $pdo->prepare("
            INSERT INTO kpi_contract_return_revisions
                (return_id, employee_id, proposed_contract_period_id, proposed_contract_no_new, proposed_contract_no_old,
                 proposed_customer_name, proposed_doc_status, proposed_sent_date, proposed_due_date, proposed_returned_date,
                 proposed_cancel_reason, proposed_note, before_json, reason, requested_by)
            VALUES
                (:return_id, :employee_id, :period_id, :no_new, :no_old, :customer, :doc_status, :sent_date, :due_date,
                 :returned_date, :cancel_reason, :note, :before_json, :reason, :requested_by)
        ")->execute([
            ":return_id" => $returnId,
            ":employee_id" => $employeeId,
            ":period_id" => $fields["contract_period_id"],
            ":no_new" => $fields["contract_no_new"],
            ":no_old" => $fields["contract_no_old"],
            ":customer" => $fields["customer_name"],
            ":doc_status" => $fields["doc_status"],
            ":sent_date" => $fields["sent_date"],
            ":due_date" => $fields["due_date"],
            ":returned_date" => $fields["returned_date"],
            ":cancel_reason" => $fields["cancel_reason"],
            ":note" => $fields["note"],
            ":before_json" => kpiContractReturnJson($before),
            ":reason" => $reason,
            ":requested_by" => $userId,
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        kpiContractReturnEvent($pdo, $returnId, $revisionId, "revision_request", $userId, 3, $reason, $before, $after);
        kpiContractReturnInsertFiles($pdo, $returnId, $revisionId, $stored, $userId, 3);
        $pdo->commit();

        return ["errors" => [], "revision_id" => $revisionId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kpiContractReturnDiscardFiles($stored);

        if ($exception instanceof DomainException) {
            return $fail([$exception->getMessage()]);
        }
        if ($exception instanceof PDOException && $exception->getCode() === "23000" && str_contains($exception->getMessage(), "uq_contract_revision_pending")) {
            return $fail(["รายการนี้มีคำขอแก้ไขที่รอ Manager พิจารณาอยู่แล้ว"]);
        }
        error_log("kpiContractReturnRequestRevision failed (" . get_class($exception) . "): " . $exception->getMessage());
        return $fail(["ส่งคำขอแก้ไขไม่สำเร็จ กรุณาลองใหม่อีกครั้ง"]);
    }
}

/* ค่าที่เสนอในคำขอแก้ไข (ชื่อฟิลด์เดียวกับรายการ) */
function kpiContractReturnRevisionFields(array $revision): array
{
    $fields = [];
    foreach (KPI_CONTRACT_RETURN_FIELDS as $field) {
        $fields[$field] = $revision["proposed_" . $field];
    }

    return kpiContractReturnSnapshot($fields);
}

/* Manager อนุมัติ / ส่งกลับคำขอแก้ไข · อนุมัติ = ใช้ค่าใหม่กับรายการใน Transaction เดียวกัน · คืน null = สำเร็จ */
function kpiContractReturnReviewRevision(PDO $pdo, int $actorUserId, int $actorRoleId, int $actorEmployeeId, int $revisionId, string $decision, string $comment): ?string
{
    if (!kpiContractReturnEnabled($pdo)) {
        return "ระบบบันทึกการรับคืนสัญญายังไม่เปิดใช้งาน";
    }
    $guard = kpiContractReturnReviewGuard($actorRoleId, $decision, $comment);
    if ($guard !== null) {
        return $guard;
    }
    $comment = trim($comment);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM kpi_contract_return_revisions WHERE revision_id = :id FOR UPDATE");
        $stmt->execute([":id" => $revisionId]);
        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $revision ? kpiContractReturnLock($pdo, (int) $revision["return_id"]) : null;

        if (!$revision || $row === null) {
            $pdo->rollBack();
            return "ไม่พบคำขอแก้ไขนี้";
        }
        if ($revision["status"] !== "Pending") {
            $pdo->rollBack();
            return "คำขอแก้ไขนี้ได้รับการพิจารณาแล้ว";
        }
        if (kpiContractReturnIsOwn($revision, $actorUserId, $actorEmployeeId, ["requested_by"])) {
            $pdo->rollBack();
            return "ไม่สามารถพิจารณาคำขอของตัวเองได้";
        }

        $before = kpiContractReturnSnapshot($row);
        $after = kpiContractReturnRevisionFields($revision);

        if ($decision === "approve") {
            if ($row["review_status"] !== "Approved" || kpiContractReturnJson($before) !== (string) $revision["before_json"]) {
                $pdo->rollBack();
                return "ข้อมูลของรายการเปลี่ยนไปหลังพนักงานส่งคำขอ จึงอนุมัติไม่ได้ กรุณาส่งกลับให้พนักงานตรวจสอบ";
            }
            if (kpiContractReturnNoTaken($pdo, $after["contract_no_new"], (int) $row["return_id"])) {
                $pdo->rollBack();
                return kpiContractReturnDuplicateMessage($after["contract_no_new"]);
            }

            $pdo->prepare("
                UPDATE kpi_contract_returns
                SET contract_period_id = :period_id, contract_no_new = :no_new, contract_no_old = :no_old, customer_name = :customer,
                    doc_status = :doc_status, sent_date = :sent_date, due_date = :due_date, returned_date = :returned_date,
                    cancel_reason = :cancel_reason, note = :note, updated_by = :updated_by,
                    reviewed_by = :reviewed_by, reviewed_role_id = :role, reviewed_at = NOW(), review_comment = :comment
                WHERE return_id = :id AND review_status = 'Approved'
            ")->execute([
                ":period_id" => $after["contract_period_id"],
                ":no_new" => $after["contract_no_new"],
                ":no_old" => $after["contract_no_old"],
                ":customer" => $after["customer_name"],
                ":doc_status" => $after["doc_status"],
                ":sent_date" => $after["sent_date"],
                ":due_date" => $after["due_date"],
                ":returned_date" => $after["returned_date"],
                ":cancel_reason" => $after["cancel_reason"],
                ":note" => $after["note"],
                ":updated_by" => (int) $revision["requested_by"],
                ":reviewed_by" => $actorUserId,
                ":role" => $actorRoleId,
                ":comment" => $comment !== "" ? $comment : null,
                ":id" => (int) $row["return_id"],
            ]);
        }

        $done = $pdo->prepare("
            UPDATE kpi_contract_return_revisions
            SET status = :status, reviewed_by = :reviewed_by, reviewed_role_id = :role, reviewed_at = NOW(), review_comment = :comment
            WHERE revision_id = :id AND status = 'Pending'
        ");
        $done->execute([
            ":status" => $decision === "approve" ? "Approved" : "Rejected",
            ":reviewed_by" => $actorUserId,
            ":role" => $actorRoleId,
            ":comment" => $comment !== "" ? $comment : null,
            ":id" => $revisionId,
        ]);
        if ($done->rowCount() !== 1) {
            $pdo->rollBack();
            return "คำขอแก้ไขนี้ได้รับการพิจารณาแล้ว";
        }

        kpiContractReturnEvent(
            $pdo, (int) $row["return_id"], $revisionId, $decision === "approve" ? "revision_approve" : "revision_reject",
            $actorUserId, $actorRoleId, $comment, $before, $decision === "approve" ? $after : null
        );
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiContractReturnReviewRevision failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "บันทึกผลการพิจารณาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* รายการ · $filters: employee_id, review_status, period_id */
function kpiContractReturnList(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters["employee_id"])) {
        $where[] = "r.employee_id = :employee_id";
        $params[":employee_id"] = (int) $filters["employee_id"];
    }
    if (!empty($filters["review_status"]) && isset(KPI_CONTRACT_RETURN_REVIEW_LABELS[$filters["review_status"]])) {
        $where[] = "r.review_status = :review_status";
        $params[":review_status"] = $filters["review_status"];
    }
    if (!empty($filters["period_id"])) {
        $where[] = "r.contract_period_id = :period_id";
        $params[":period_id"] = (int) $filters["period_id"];
    }
    if (!empty($filters["pending_revision"])) {
        $where[] = "EXISTS (SELECT 1 FROM kpi_contract_return_revisions pv WHERE pv.return_id = r.return_id AND pv.status = 'Pending')";
    }

    $stmt = $pdo->prepare("
        SELECT r.*, ep.period_year, ep.period_month,
               e.employee_code, e.first_name, e.last_name, d.department_name,
               (SELECT COUNT(*) FROM kpi_contract_return_files f
                LEFT JOIN kpi_contract_return_revisions fv ON fv.revision_id = f.revision_id
                WHERE f.return_id = r.return_id AND (f.revision_id IS NULL OR fv.status = 'Approved')) AS file_count,
               (SELECT v.revision_id FROM kpi_contract_return_revisions v WHERE v.return_id = r.return_id AND v.status = 'Pending' LIMIT 1) AS pending_revision_id
        FROM kpi_contract_returns r
        INNER JOIN evaluation_periods ep ON ep.period_id = r.contract_period_id
        INNER JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
        ORDER BY ep.period_year DESC, ep.period_month DESC,
                 FIELD(r.review_status, 'Submitted', 'Rejected', 'Draft', 'Approved'), r.contract_no_new
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiContractReturnGet(PDO $pdo, int $returnId): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.*, ep.period_year, ep.period_month,
               e.employee_code, e.first_name, e.last_name, d.department_name,
               (SELECT v.revision_id FROM kpi_contract_return_revisions v WHERE v.return_id = r.return_id AND v.status = 'Pending' LIMIT 1) AS pending_revision_id
        FROM kpi_contract_returns r
        INNER JOIN evaluation_periods ep ON ep.period_id = r.contract_period_id
        INNER JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        WHERE r.return_id = :id
    ");
    $stmt->execute([":id" => $returnId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ไฟล์ทั้งหมดของรายการ พร้อมสถานะคำขอแก้ไขที่ไฟล์สังกัด (NULL = ไฟล์ของรายการโดยตรง) */
function kpiContractReturnFiles(PDO $pdo, int $returnId): array
{
    $stmt = $pdo->prepare("
        SELECT f.*, v.status AS revision_status
        FROM kpi_contract_return_files f
        LEFT JOIN kpi_contract_return_revisions v ON v.revision_id = f.revision_id
        WHERE f.return_id = :id
        ORDER BY f.uploaded_at, f.file_id
    ");
    $stmt->execute([":id" => $returnId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiContractReturnRevisions(PDO $pdo, int $returnId): array
{
    $stmt = $pdo->prepare("SELECT * FROM kpi_contract_return_revisions WHERE return_id = :id ORDER BY requested_at DESC, revision_id DESC");
    $stmt->execute([":id" => $returnId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiContractReturnEvents(PDO $pdo, int $returnId): array
{
    $stmt = $pdo->prepare("
        SELECT ev.*, u.username, e.first_name, e.last_name
        FROM kpi_contract_return_events ev
        INNER JOIN users u ON u.user_id = ev.actor_user_id
        LEFT JOIN employees e ON e.employee_id = u.employee_id
        WHERE ev.return_id = :id
        ORDER BY ev.created_at, ev.event_id
    ");
    $stmt->execute([":id" => $returnId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ผู้มีสิทธิ์ดูรายการ / หลักฐาน: พนักงานเจ้าของ · Manager · Admin (ขอบเขตเดียวกับหลักฐานผลงานเดิม) */
function kpiContractReturnViewerAllowed(array $row): bool
{
    return kpiFileViewerAllowed((int) $row["employee_id"]);
}

/*
| สรุป KPI12 ของพนักงาน × เดือนของสัญญา (อ่านอย่างเดียว · ไม่เขียน kpi_performances)
|   นับเฉพาะรายการ Approved · ยกเลิก / ยังไม่ส่ง / ยังไม่ถึงกำหนด (due_date >= วันประเมิน) ไม่อยู่ในตัวหาร
|   ตัวตั้ง = รับคืนแล้วและวันที่รับคืน <= วันครบกำหนด · ตัวหาร = รายการที่เลยวันครบกำหนดแล้ว
|   state: no_data (ตัวหาร 0) · graded (100% → เกรด 5) · awaiting_criteria (< 100% ยังไม่มีเกณฑ์ที่ยืนยัน)
|   warnings: รายการที่ยังไม่อนุมัติ · คำขอแก้ไขที่รอพิจารณา
*/
function kpiContractReturnSummary(PDO $pdo, int $employeeId, int $periodId, string $today): array
{
    $stmt = $pdo->prepare("
        SELECT r.*,
               (SELECT COUNT(*) FROM kpi_contract_return_revisions v WHERE v.return_id = r.return_id AND v.status = 'Pending') AS pending_revisions
        FROM kpi_contract_returns r
        WHERE r.employee_id = :employee_id AND r.contract_period_id = :period_id
    ");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    $summary = [
        "numerator" => 0, "denominator" => 0, "percent" => null, "grade" => null, "state" => "no_data",
        "late" => 0, "overdue" => 0, "pending_due" => 0, "not_sent" => 0, "cancelled" => 0,
        "unapproved" => 0, "unapproved_due" => 0, "pending_revisions" => 0, "total" => 0, "warnings" => [],
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary["total"]++;
        $summary["pending_revisions"] += (int) $row["pending_revisions"];
        $timeliness = kpiContractReturnTimeliness($row, $today);

        if ($row["review_status"] !== "Approved") {
            $summary["unapproved"]++;
            if ($row["due_date"] !== null && $row["due_date"] < $today) {
                $summary["unapproved_due"]++;
            }
            continue;
        }

        switch ($timeliness) {
            case "cancelled":
            case "not_sent":
                $summary[$timeliness]++;
                break;
            case "pending_due":
                $summary["pending_due"]++;
                break;
            default:
                /* เลยวันครบกำหนดแล้ว หรือรับคืนแล้ว · ยังไม่ถึงวันครบกำหนดไม่นับ แม้รับคืนแล้ว */
                if ($row["due_date"] >= $today) {
                    $summary["pending_due"]++;
                    break;
                }
                $summary["denominator"]++;
                if ($timeliness === "on_time") {
                    $summary["numerator"]++;
                } else {
                    $summary[$timeliness]++;
                }
        }
    }

    if ($summary["denominator"] > 0) {
        /* ตัดทศนิยมลง 2 ตำแหน่ง (จำนวนเต็มล้วน) · ตัดสินเกรดจากจำนวนรายโดยตรง */
        $basis = intdiv($summary["numerator"] * 10000, $summary["denominator"]);
        $summary["percent"] = sprintf("%d.%02d", intdiv($basis, 100), $basis % 100);
        if ($summary["numerator"] === $summary["denominator"]) {
            $summary["grade"] = 5;
            $summary["state"] = "graded";
        } else {
            $summary["state"] = "awaiting_criteria";
        }
    }

    if ($summary["unapproved"] > 0) {
        $summary["warnings"][] = "มี {$summary["unapproved"]} รายการที่ยังไม่ได้รับอนุมัติ (ไม่นำมาคำนวณ)"
            . ($summary["unapproved_due"] > 0 ? " · ในจำนวนนี้ {$summary["unapproved_due"]} รายการเลยวันครบกำหนดแล้ว" : "")
            . " · ผลสรุปยังไม่สมบูรณ์";
    }
    if ($summary["pending_revisions"] > 0) {
        $summary["warnings"][] = "มี {$summary["pending_revisions"]} คำขอแก้ไขที่รอ Manager พิจารณา (ใช้ค่าที่อนุมัติเดิมจนกว่าจะอนุมัติ)";
    }

    return $summary;
}

/* ข้อความสรุปผลสำหรับแสดง */
function kpiContractReturnSummaryText(array $summary): string
{
    if ($summary["state"] === "no_data") {
        return "ไม่มีข้อมูลสำหรับประเมิน";
    }
    $ratio = "{$summary["numerator"]} / {$summary["denominator"]} = {$summary["percent"]}%";

    return $summary["state"] === "graded" ? $ratio . " → เกรด 5" : $ratio . " → รอการกำหนดเกณฑ์";
}

/* วันที่ Y-m-d → d/m/Y (ค.ศ.) */
function kpiContractReturnDateText(?string $date): string
{
    if ($date === null || $date === "") {
        return "-";
    }
    $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

    return $parsed === false ? "-" : $parsed->format("d/m/Y");
}

function kpiContractReturnActorName(array $event): string
{
    return kpiApprovalActorName($event);
}
