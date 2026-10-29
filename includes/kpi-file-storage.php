<?php

/*
|--------------------------------------------------------------------------
| ที่เก็บไฟล์ของระบบ (ไฟล์นำเข้า Excel ของบริษัท / ไฟล์หลักฐานผลงาน)
|--------------------------------------------------------------------------
|
| ตำแหน่ง: KPI_STORAGE_PATH (ถ้ากำหนดไว้ก่อน require ไฟล์นี้) หรือ <โปรเจกต์>/storage
|   storage/.htaccess ปฏิเสธการเข้าถึงตรงทุกไฟล์ (Apache AllowOverride All)
|   ชื่อไฟล์บนดิสก์ = hex สุ่ม 32 ตัว + ".dat" (ไม่มีนามสกุลที่เว็บเซิร์ฟเวอร์รันได้)
|   ดาวน์โหลดผ่าน file-download.php ที่ตรวจสิทธิ์เท่านั้น
| โฟลเดอร์ย่อย:
|   pending/   ไฟล์นำเข้าที่รอผู้ใช้กดยืนยัน (ลบเมื่อยกเลิก / ยืนยันแล้ว / ค้างเกินเวลา)
|   imports/   ไฟล์นำเข้าที่ยืนยันแล้ว (เก็บ 3 ไฟล์ล่าสุดต่อพนักงาน)
|   evidence/  ไฟล์หลักฐานผลงาน
|   contract_returns/  หลักฐานการรับคืนสัญญาตัวจริง (KPI12 · includes/kpi-contract-return.php)
| storage/ ไม่ขึ้น Git (.gitignore) เพราะมีข้อมูลบริษัท / ข้อมูลพนักงาน
|
*/

const KPI_STORAGE_AREAS = ["pending", "imports", "evidence", "corrections", "contract_returns"];
const KPI_STORAGE_NAME_PATTERN = '/^[a-f0-9]{32}\.dat$/';

/*
| ฟีเจอร์ไฟล์เปิดใช้เมื่อมีตารางของ Migration 20261019_import_history_and_evidence แล้วเท่านั้น
| ยังไม่มีตาราง → ปิดฟีเจอร์ (หน้าเว็บทำงานแบบเดิม ไม่เก็บไฟล์ ไม่บล็อกการนำเข้า)
*/
function kpiFileFeatureEnabled(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (!in_array($table, ["kpi_import_history", "kpi_performance_evidence"], true)) {
        return false;
    }

    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $stmt->execute([":t" => $table]);
            $cache[$table] = (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $exception) {
            $cache[$table] = false;
        }
    }

    return $cache[$table];
}

function kpiStorageRoot(): string
{
    return defined("KPI_STORAGE_PATH") ? rtrim((string) constant("KPI_STORAGE_PATH"), "/") : dirname(__DIR__) . "/storage";
}

/* โฟลเดอร์ของพื้นที่ย่อย (สร้างให้ถ้ายังไม่มี) · คืน null ถ้าสร้าง / เขียนไม่ได้ */
function kpiStorageDir(string $area): ?string
{
    if (!in_array($area, KPI_STORAGE_AREAS, true)) {
        return null;
    }

    $root = kpiStorageRoot();
    $dir = $root . "/" . $area;

    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        error_log("kpi-file-storage: cannot create storage area {$area}");
        return null;
    }

    /* ปิดการเข้าถึงตรงทั้งโฟลเดอร์ (Apache 2.4 + รูปแบบเก่า) */
    $htaccess = $root . "/.htaccess";
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    return is_writable($dir) ? $dir : null;
}

function kpiStorageNewName(): string
{
    return bin2hex(random_bytes(16)) . ".dat";
}

/* path เต็มของไฟล์ (ตรวจชื่อกัน path traversal) · null = ชื่อไม่ถูกต้อง */
function kpiStoragePath(string $area, string $storedName): ?string
{
    if (!in_array($area, KPI_STORAGE_AREAS, true) || !preg_match(KPI_STORAGE_NAME_PATTERN, $storedName)) {
        return null;
    }

    return kpiStorageRoot() . "/" . $area . "/" . $storedName;
}

/* ลบไฟล์ (ไม่มีอยู่แล้วถือว่าสำเร็จ) */
function kpiStorageDelete(string $area, ?string $storedName): bool
{
    if ($storedName === null || $storedName === "") {
        return true;
    }

    $path = kpiStoragePath($area, $storedName);

    return $path !== null && (!is_file($path) || @unlink($path));
}

/* ชื่อไฟล์สำหรับแสดงผล / ดาวน์โหลด: ตัดโฟลเดอร์ อักขระควบคุม และความยาวเกิน */
function kpiStorageCleanName(string $name, string $fallback = "file"): string
{
    $name = basename(str_replace("\\", "/", $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', "", $name) ?? "";
    $name = trim($name);

    return $name === "" ? $fallback : mb_substr($name, 0, 200);
}

/*
| ส่งไฟล์ให้ผู้ใช้ (หลังตรวจสิทธิ์แล้ว)
| $inline = true เฉพาะรูปภาพ / PDF (ดูในเบราว์เซอร์ได้) · ไฟล์อื่นดาวน์โหลดเสมอ
| ปิด MIME sniffing และใส่ CSP sandbox กันสคริปต์ในไฟล์ที่เปิดแบบ inline
*/
function kpiStorageSend(string $path, string $downloadName, string $mime, bool $inline = false): void
{
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', "_", $downloadName) ?: "file";

    header("Content-Type: " . $mime);
    header("Content-Length: " . (string) filesize($path));
    header("Content-Disposition: " . ($inline ? "inline" : "attachment")
        . "; filename=\"" . $ascii . "\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header("X-Content-Type-Options: nosniff");
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    header("Cache-Control: private, no-store");

    readfile($path);
}
