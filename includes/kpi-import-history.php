<?php

/*
|--------------------------------------------------------------------------
| ประวัติการนำเข้าไฟล์ Excel ของบริษัท (หน้า "บันทึกผล KPI (Excel)")
|--------------------------------------------------------------------------
|
| ลำดับ:
|   1. อัปโหลด + Preview ผ่าน → kpiImportHistoryStash() คัดลอกไฟล์ไป storage/pending (ชื่อสุ่ม + SHA-256)
|      ข้อมูลไฟล์เก็บใน Session คู่กับ Preview
|   2. ยืนยันสำเร็จ → kpiImportHistoryRecord() ตรวจ SHA-256 ซ้ำ ย้ายไฟล์ไป storage/imports แล้วบันทึกประวัติ 1 แถว
|   3. ยกเลิก / ยืนยันไม่สำเร็จ / Preview หมดอายุ → kpiImportHistoryDiscard() ลบไฟล์ใน pending
|      ไฟล์ pending ที่ค้างเกินเวลา (เช่น ปิดเบราว์เซอร์ไป) ถูกลบตอนมีการอัปโหลดครั้งถัดไป
| ประวัติ (วันเวลา เดือนข้อมูล ชื่อไฟล์ ผลการนำเข้า) และตัวไฟล์ใน storage/imports เก็บถาวร ไม่มีวันหมดอายุ (2026-10-29)
|   หน้าจอแสดง KPI_IMPORT_HISTORY_PREVIEW_ROWS ไฟล์ล่าสุด + ปุ่มดูเพิ่มเติม
|   แถวที่ stored_name = NULL คือไฟล์ที่ถูกลบไปก่อนเปลี่ยนเป็นเก็บถาวร (เดิมเก็บเฉพาะ 3 ไฟล์ล่าสุด) · กู้คืนไม่ได้
| บันทึกประวัติไม่สำเร็จไม่ทำให้การนำเข้าที่ Commit แล้วล้มเหลว (แจ้งผู้ใช้และเขียน error_log)
|
*/

require_once __DIR__ . "/kpi-file-storage.php";

const KPI_IMPORT_HISTORY_PREVIEW_ROWS = 3;
const KPI_IMPORT_HISTORY_REMOVED_TEXT = "ไฟล์ถูกลบแล้วก่อนเปลี่ยนเป็นเก็บไฟล์ถาวร";
/* ไฟล์ pending ที่เก่ากว่านี้ (วินาที) ถือว่าค้าง · มากกว่าอายุ Preview ใน Session (30 นาที) */
const KPI_IMPORT_PENDING_MAX_AGE = 3600;

/* คัดลอกไฟล์ที่อัปโหลดไปรอใน pending · คืน ["stored_name", "sha256", "size", "original_name"] หรือ null */
function kpiImportHistoryStash(string $tmpPath, string $originalName): ?array
{
    $dir = kpiStorageDir("pending");

    if ($dir === null || !is_file($tmpPath)) {
        return null;
    }

    kpiImportHistoryCleanupPending();

    $name = kpiStorageNewName();
    $target = $dir . "/" . $name;

    if (!@copy($tmpPath, $target)) {
        error_log("kpi-import-history: cannot stash upload");
        return null;
    }

    @chmod($target, 0640);

    return [
        "stored_name" => $name,
        "sha256" => (string) hash_file("sha256", $target),
        "size" => (int) filesize($target),
        "original_name" => kpiStorageCleanName($originalName, "import.xlsx")
    ];
}

function kpiImportHistoryDiscard(?array $stash): void
{
    if (is_array($stash) && isset($stash["stored_name"])) {
        kpiStorageDelete("pending", (string) $stash["stored_name"]);
    }
}

/* ลบไฟล์ pending ที่ค้างเกินเวลา */
function kpiImportHistoryCleanupPending(): void
{
    $dir = kpiStorageDir("pending");

    if ($dir === null) {
        return;
    }

    foreach (glob($dir . "/*.dat") ?: [] as $path) {
        if (preg_match(KPI_STORAGE_NAME_PATTERN, basename($path)) && time() - (int) @filemtime($path) > KPI_IMPORT_PENDING_MAX_AGE) {
            @unlink($path);
        }
    }
}

/*
| บันทึกประวัติหลัง Commit สำเร็จ · คืน null = สำเร็จ หรือข้อความแจ้งผู้ใช้
| $summary = ผลจาก kpiImportCommit() (inserted / updated / unchanged / skipped / declined)
*/
function kpiImportHistoryRecord(PDO $pdo, int $employeeId, int $userId, int $year, int $month, ?array $stash, array $summary): ?string
{
    $failed = "นำเข้าข้อมูลสำเร็จ แต่บันทึกประวัติไฟล์ไม่สำเร็จ";

    if (!is_array($stash) || !isset($stash["stored_name"], $stash["sha256"], $stash["size"], $stash["original_name"])) {
        return $failed;
    }

    $pendingPath = kpiStoragePath("pending", (string) $stash["stored_name"]);
    $importsDir = kpiStorageDir("imports");

    if ($pendingPath === null || $importsDir === null || !is_file($pendingPath) || !hash_equals((string) $stash["sha256"], (string) hash_file("sha256", $pendingPath))) {
        kpiImportHistoryDiscard($stash);
        error_log("kpi-import-history: pending file missing or changed");
        return $failed;
    }

    $storedName = kpiStorageNewName();
    $finalPath = $importsDir . "/" . $storedName;

    if (!@rename($pendingPath, $finalPath)) {
        kpiImportHistoryDiscard($stash);
        error_log("kpi-import-history: cannot move pending file");
        return $failed;
    }

    try {
        $pdo->prepare("
            INSERT INTO kpi_import_history
                (employee_id, user_id, data_year, data_month, original_name, stored_name, file_size, file_sha256,
                 rows_inserted, rows_updated, rows_unchanged, rows_skipped, rows_declined)
            VALUES
                (:employee_id, :user_id, :data_year, :data_month, :original_name, :stored_name, :file_size, :file_sha256,
                 :rows_inserted, :rows_updated, :rows_unchanged, :rows_skipped, :rows_declined)
        ")->execute([
            ":employee_id" => $employeeId,
            ":user_id" => $userId,
            ":data_year" => $year,
            ":data_month" => $month,
            ":original_name" => (string) $stash["original_name"],
            ":stored_name" => $storedName,
            ":file_size" => (int) $stash["size"],
            ":file_sha256" => (string) $stash["sha256"],
            ":rows_inserted" => (int) ($summary["inserted"] ?? 0),
            ":rows_updated" => (int) ($summary["updated"] ?? 0),
            ":rows_unchanged" => (int) ($summary["unchanged"] ?? 0),
            ":rows_skipped" => (int) ($summary["skipped"] ?? 0),
            ":rows_declined" => (int) ($summary["declined"] ?? 0)
        ]);
    } catch (Throwable $exception) {
        @unlink($finalPath);
        error_log("kpi-import-history: insert failed (" . get_class($exception) . ")");
        return $failed;
    }

    return null;
}

/* ประวัติของพนักงาน (ล่าสุดก่อน) · $limit = null → ทั้งหมด · คืน ["rows" => [...], "total" => int] */
function kpiImportHistoryList(PDO $pdo, int $employeeId, ?int $limit = KPI_IMPORT_HISTORY_PREVIEW_ROWS): array
{
    $count = $pdo->prepare("SELECT COUNT(*) FROM kpi_import_history WHERE employee_id = :employee_id");
    $count->execute([":employee_id" => $employeeId]);

    $sql = "
        SELECT history_id, data_year, data_month, original_name, stored_name, file_size,
               rows_inserted, rows_updated, rows_unchanged, rows_skipped, rows_declined, imported_at, file_removed_at
        FROM kpi_import_history
        WHERE employee_id = :employee_id
        ORDER BY imported_at DESC, history_id DESC"
        . ($limit !== null ? " LIMIT " . max(1, $limit) : "");

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":employee_id" => $employeeId]);

    return ["rows" => $stmt->fetchAll(PDO::FETCH_ASSOC), "total" => (int) $count->fetchColumn()];
}

/* ขนาดไฟล์สำหรับแสดงผล */
function kpiFormatFileSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . " MB";
    }

    return number_format(max(1, (int) ceil($bytes / 1024))) . " KB";
}
