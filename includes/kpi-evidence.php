<?php

/*
|--------------------------------------------------------------------------
| ไฟล์หลักฐานของผลงานรายเดือน (หน้า "ผลการปฏิบัติงาน")
|--------------------------------------------------------------------------
|
| แนบกับผลงาน 1 รายการ (KPI × เดือน = 1 แถวใน kpi_performances)
|   ชนิดไฟล์: Excel (.xlsx .xls) · รูปภาพ (.jpg .jpeg .png) · PDF (.pdf)
|   ตรวจจากเนื้อไฟล์จริง ไม่เชื่อนามสกุลหรือ MIME ที่เบราว์เซอร์ส่งมา:
|     xlsx = ZIP ที่มี xl/workbook.xml · xls = OLE2 (D0 CF 11 E0) · jpg / png = getimagesize() ตรงชนิด
|     pdf = ขึ้นต้น %PDF- · นามสกุลต้องตรงกับชนิดที่ตรวจได้
|   ไฟล์ละไม่เกิน 10 MB · สูงสุด 5 ไฟล์ต่อผลงาน 1 รายการ
| แนบ / ลบ: เฉพาะพนักงานเจ้าของผลงาน และผลงานยังเป็น Draft (ล็อกแถวผลงานใน Transaction)
| ดู / ดาวน์โหลด: พนักงานเจ้าของผลงาน · หัวหน้า (Manager) · ผู้ดูแลระบบ (Admin)
|   (หัวหน้าเห็นผลงานของพนักงานทุกคนในหน้า Manager อยู่แล้ว จึงใช้ขอบเขตเดียวกัน)
|
*/

require_once __DIR__ . "/kpi-file-storage.php";
require_once __DIR__ . "/kpi-approval.php";

const KPI_EVIDENCE_MAX_BYTES = 10 * 1024 * 1024;
const KPI_EVIDENCE_MAX_FILES = 5;
const KPI_EVIDENCE_TYPES = [
    "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "xls" => "application/vnd.ms-excel",
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png",
    "pdf" => "application/pdf"
];
const KPI_EVIDENCE_ACCEPT = ".xlsx,.xls,.jpg,.jpeg,.png,.pdf";
const KPI_ROLE_ADMIN = 1;
const KPI_ROLE_MANAGER = 2;

/* ตรวจไฟล์ที่อัปโหลด · คืน ["error" => ?string, "ext" => ?string, "mime" => ?string] */
function kpiEvidenceCheckUpload(array $file, bool $requireUploadedFile = true): array
{
    $fail = fn(string $message) => ["error" => $message, "ext" => null, "mime" => null];
    $code = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);

    if ($code === UPLOAD_ERR_NO_FILE) {
        return $fail("กรุณาเลือกไฟล์หลักฐาน");
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return $fail("ไฟล์มีขนาดเกิน 10 MB");
    }
    if ($code !== UPLOAD_ERR_OK) {
        return $fail("อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง");
    }

    $tmp = (string) ($file["tmp_name"] ?? "");

    if ($tmp === "" || !is_file($tmp) || ($requireUploadedFile && !is_uploaded_file($tmp))) {
        return $fail("อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง");
    }

    $size = (int) filesize($tmp);

    if ($size <= 0) {
        return $fail("ไฟล์ว่างเปล่า");
    }
    if ($size > KPI_EVIDENCE_MAX_BYTES) {
        return $fail("ไฟล์มีขนาดเกิน 10 MB");
    }

    $ext = strtolower(pathinfo(kpiStorageCleanName((string) ($file["name"] ?? "")), PATHINFO_EXTENSION));

    if (!isset(KPI_EVIDENCE_TYPES[$ext])) {
        return $fail("รองรับเฉพาะไฟล์ Excel (.xlsx .xls) รูปภาพ (.jpg .png) และ PDF");
    }

    $head = (string) file_get_contents($tmp, false, null, 0, 8);
    $valid = false;

    switch ($ext) {
        case "xlsx":
            if (str_starts_with($head, "PK\x03\x04") && class_exists("ZipArchive")) {
                $zip = new ZipArchive();
                if ($zip->open($tmp, ZipArchive::RDONLY) === true) {
                    $valid = $zip->locateName("xl/workbook.xml") !== false && $zip->locateName("[Content_Types].xml") !== false;
                    $zip->close();
                }
            }
            break;
        case "xls":
            $valid = $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
            break;
        case "jpg":
        case "jpeg":
            $info = @getimagesize($tmp);
            $valid = str_starts_with($head, "\xFF\xD8\xFF") && is_array($info) && $info[2] === IMAGETYPE_JPEG;
            break;
        case "png":
            $info = @getimagesize($tmp);
            $valid = $head === "\x89PNG\r\n\x1A\n" && is_array($info) && $info[2] === IMAGETYPE_PNG;
            break;
        case "pdf":
            $valid = str_starts_with($head, "%PDF-");
            break;
    }

    if (!$valid) {
        return $fail("เนื้อไฟล์ไม่ตรงกับชนิดไฟล์ (." . $ext . ") หรือไฟล์เสียหาย");
    }

    return ["error" => null, "ext" => $ext, "mime" => KPI_EVIDENCE_TYPES[$ext]];
}

/* ล็อกผลงานและตรวจว่าแก้หลักฐานได้ · คืนข้อความ error หรือ null */
function kpiEvidenceLockEditable(PDO $pdo, int $employeeId, int $performanceId): ?string
{
    $stmt = $pdo->prepare("SELECT employee_id, status FROM kpi_performances WHERE performance_id = :id FOR UPDATE");
    $stmt->execute([":id" => $performanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int) $row["employee_id"] !== $employeeId) {
        return "ไม่พบผลงานรายการนี้";
    }

    if (!kpiStatusIsEditable((string) $row["status"])) {
        return "ผลงานสถานะ " . $row["status"] . " แนบหรือลบหลักฐานไม่ได้ (ทำได้เฉพาะสถานะ Draft หรือส่งกลับให้แก้ไข)";
    }

    return null;
}

/* แนบหลักฐาน · คืน null = สำเร็จ หรือข้อความ error */
function kpiEvidenceAdd(PDO $pdo, int $employeeId, int $userId, int $performanceId, array $file, bool $requireUploadedFile = true): ?string
{
    $check = kpiEvidenceCheckUpload($file, $requireUploadedFile);

    if ($check["error"] !== null) {
        return $check["error"];
    }

    $dir = kpiStorageDir("evidence");

    if ($dir === null) {
        return "ระบบจัดเก็บไฟล์ไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
    }

    $tmp = (string) $file["tmp_name"];
    $storedName = kpiStorageNewName();
    $target = $dir . "/" . $storedName;
    $moved = false;

    try {
        $pdo->beginTransaction();

        $error = kpiEvidenceLockEditable($pdo, $employeeId, $performanceId);

        if ($error === null) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM kpi_performance_evidence WHERE performance_id = :id");
            $count->execute([":id" => $performanceId]);
            if ((int) $count->fetchColumn() >= KPI_EVIDENCE_MAX_FILES) {
                $error = "แนบหลักฐานได้สูงสุด " . KPI_EVIDENCE_MAX_FILES . " ไฟล์ต่อรายการ";
            }
        }

        if ($error !== null) {
            $pdo->rollBack();
            return $error;
        }

        $moved = $requireUploadedFile ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);

        if (!$moved) {
            $pdo->rollBack();
            return "บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
        }

        @chmod($target, 0640);

        $pdo->prepare("
            INSERT INTO kpi_performance_evidence
                (performance_id, employee_id, user_id, original_name, stored_name, file_ext, mime_type, file_size, file_sha256)
            VALUES
                (:performance_id, :employee_id, :user_id, :original_name, :stored_name, :file_ext, :mime_type, :file_size, :file_sha256)
        ")->execute([
            ":performance_id" => $performanceId,
            ":employee_id" => $employeeId,
            ":user_id" => $userId,
            ":original_name" => kpiStorageCleanName((string) ($file["name"] ?? ""), "evidence." . $check["ext"]),
            ":stored_name" => $storedName,
            ":file_ext" => $check["ext"],
            ":mime_type" => $check["mime"],
            ":file_size" => (int) filesize($target),
            ":file_sha256" => (string) hash_file("sha256", $target)
        ]);

        $pdo->commit();
        return null;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($moved) {
            @unlink($target);
        }
        error_log("kpi-evidence: add failed (" . get_class($exception) . ")");
        return "บันทึกหลักฐานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* ลบหลักฐาน · คืน null = สำเร็จ หรือข้อความ error */
function kpiEvidenceDelete(PDO $pdo, int $employeeId, int $evidenceId): ?string
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT performance_id, employee_id, stored_name FROM kpi_performance_evidence WHERE evidence_id = :id FOR UPDATE");
        $stmt->execute([":id" => $evidenceId]);
        $evidence = $stmt->fetch(PDO::FETCH_ASSOC);

        $error = !$evidence || (int) $evidence["employee_id"] !== $employeeId
            ? "ไม่พบไฟล์หลักฐานนี้"
            : kpiEvidenceLockEditable($pdo, $employeeId, (int) $evidence["performance_id"]);

        if ($error !== null) {
            $pdo->rollBack();
            return $error;
        }

        $pdo->prepare("DELETE FROM kpi_performance_evidence WHERE evidence_id = :id")->execute([":id" => $evidenceId]);
        $pdo->commit();

        kpiStorageDelete("evidence", (string) $evidence["stored_name"]);
        return null;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpi-evidence: delete failed (" . get_class($exception) . ")");
        return "ลบหลักฐานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* หลักฐานของผลงานหลายรายการ → [performance_id => [แถว ...]] */
function kpiEvidenceForPerformances(PDO $pdo, array $performanceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $performanceIds))));

    if (!$ids) {
        return [];
    }

    $in = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("
        SELECT evidence_id, performance_id, original_name, file_ext, file_size, file_sha256, uploaded_at
        FROM kpi_performance_evidence
        WHERE performance_id IN ({$in})
        ORDER BY uploaded_at, evidence_id
    ");
    $stmt->execute($ids);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row["performance_id"]][] = $row;
    }

    return $result;
}

/* ผู้ใช้ปัจจุบันดูไฟล์ของพนักงานคนนี้ได้หรือไม่ (เจ้าของ / Manager / Admin) */
function kpiFileViewerAllowed(int $ownerEmployeeId): bool
{
    $roleId = (int) ($_SESSION["role_id"] ?? 0);

    return in_array($roleId, [KPI_ROLE_ADMIN, KPI_ROLE_MANAGER], true)
        || ((int) ($_SESSION["employee_id"] ?? 0) > 0 && (int) $_SESSION["employee_id"] === $ownerEmployeeId);
}
