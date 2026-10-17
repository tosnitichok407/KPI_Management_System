<?php

/*
|--------------------------------------------------------------------------
| KPI Import (Excel) — พนักงานนำเข้าผลงานของตนเองจาก Template กลาง
|--------------------------------------------------------------------------
|
| การนำเข้าผ่าน Template ของระบบปิดแล้ว (origin template → error ทุกแถว / Commit ไม่ทำงาน)
| ช่องทางเดียวที่เปิด: ไฟล์ Excel ของบริษัท (includes/kpi-import-company-reader.php) → Validate / Commit ด้วย origin company
| ฟังก์ชันอ่าน Template (kpiImportReadFile) ยังเก็บไว้ แต่ไม่มีหน้าใดเรียกใช้
| ขั้นตอนเดิม: ตรวจไฟล์ (kpiImportCheckUpload) → อ่าน Template (kpiImportReadFile)
|          → ตรวจข้อมูลกับฐานข้อมูล (kpiImportValidate · ไม่เขียนข้อมูล ใช้ทำ Preview)
|          → ยืนยัน (kpiImportCommit · ตรวจซ้ำพร้อมล็อกใน Transaction แล้วจึงบันทึก)
|
| เป็นช่องทางเดียวที่พนักงานบันทึกผล KPI (หน้ากรอกเอง employee/kpi/kpi.php และ kpi-detail.php ปิดแล้ว)
| กฎเดียวกับหน้ากรอกเดิม:
|   - Assignment ต้อง Active และครอบคลุมเดือนนั้น · Target มาจาก kpi_assignments.target_value
|   - Performance: Actual เป็นตัวเลขไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง · คะแนนคำนวณที่ kpiCalculateScore() เท่านั้น
|   - Competency: คะแนนจำนวนเต็ม 1-5 (ไม่มี Actual)
|   - รอบประเมินของเดือนนั้นต้องมีและเป็น Open
| ที่เข้มกว่าหน้ากรอกเดิม: แก้ไขรายการเดิมได้เฉพาะสถานะ Draft และผู้ใช้ต้องยืนยันทีละรายการ
| (Submitted / Approved / Rejected → นำเข้าไม่ได้) · ไม่รับเกรดหรือคะแนนจาก Excel
| ข้อมูลผิดแม้หนึ่งแถว → ไม่บันทึกอะไรจากไฟล์นั้นเลย
|
*/

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/kpi-score-helper.php";
require_once __DIR__ . "/monthly-period-helper.php";

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const KPI_IMPORT_SHEET = "KPI_Import";
const KPI_IMPORT_COLUMNS = ["employee_code", "kpi_id", "year", "month", "actual", "competency_score", "note"];
const KPI_IMPORT_MAX_FILE_BYTES = 2 * 1024 * 1024;     // 2 MB
const KPI_IMPORT_MAX_DATA_ROWS = 500;                   // แถวข้อมูลที่ไม่ว่าง
const KPI_IMPORT_MAX_SHEET_ROWS = 1001;                 // รวมหัวตาราง
const KPI_IMPORT_MAX_SHEET_COLUMNS = 26;                // A..Z (ข้อมูลอยู่ใน A..G เท่านั้น)
const KPI_IMPORT_MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;
const KPI_IMPORT_MAX_ZIP_ENTRIES = 200;
const KPI_IMPORT_NOTE_MAX_LENGTH = 5000;                // เท่ากับช่องความคิดเห็นในหน้า KPI ของฉัน

/* ที่มาของแถวข้อมูล (กำหนดฝั่ง Server จาก Session ไม่รับจากผู้ใช้) */
const KPI_IMPORT_ORIGIN_TEMPLATE = "template";
const KPI_IMPORT_ORIGIN_COMPANY = "company";

/*
| KPI ที่ยืนยัน Mapping กับไฟล์ Excel ของบริษัทแล้ว → บันทึกได้จากไฟล์บริษัทเท่านั้น (ปิดทาง Template)
| อ่านจาก includes/kpi-import-company-map.php (รายการ confirmed = true ที่มี kpi_id)
*/
function kpiImportCompanyOnlyKpiIds(): array
{
    static $ids = null;

    if ($ids === null) {
        $ids = [];
        foreach (require __DIR__ . "/kpi-import-company-map.php" as $entry) {
            if (!empty($entry["confirmed"]) && is_int($entry["kpi_id"] ?? null)) {
                $ids[$entry["kpi_id"]] = true;
            }
        }
    }

    return $ids;
}
const KPI_IMPORT_SESSION_TTL = 1800;                    // Preview ค้างได้ไม่เกิน 30 นาที
const KPI_IMPORT_ALLOWED_MIME = [
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/zip"
];


/*
|--------------------------------------------------------------------------
| 1) ตรวจไฟล์ที่อัปโหลด (ก่อนเปิดด้วย PhpSpreadsheet)
|--------------------------------------------------------------------------
|
| คืนข้อความ error หรือ null ถ้าผ่าน · $requireUploadedFile = false ใช้ในสคริปต์ทดสอบ (CLI) เท่านั้น
| kpiImportCheckUpload()        : Template กลาง (ตรวจไฟล์ + kpiImportCheckPackage)
| kpiImportCheckUploadedFile()  : ส่วนที่ใช้ร่วมทุกรูปแบบ (error / ขนาด / นามสกุล / MIME)
|                                 แบบฟอร์มบริษัทตรวจ package ต่อด้วย kpiCompanyCheckPackage()
|
*/
function kpiImportCheckUpload(array $file, bool $requireUploadedFile = true): ?string
{
    return kpiImportCheckUploadedFile($file, $requireUploadedFile)
        ?? kpiImportCheckPackage((string) $file["tmp_name"]);
}

function kpiImportCheckUploadedFile(array $file, bool $requireUploadedFile = true): ?string
{
    $errorCode = $file["error"] ?? UPLOAD_ERR_NO_FILE;

    if (is_array($errorCode)) {
        return "กรุณาเลือกไฟล์เพียงไฟล์เดียว";
    }

    switch ((int) $errorCode) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return "กรุณาเลือกไฟล์ Excel (.xlsx)";
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "ไฟล์มีขนาดเกิน " . (KPI_IMPORT_MAX_FILE_BYTES / 1024 / 1024) . " MB";
        default:
            return "อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }

    $tmpPath = (string) ($file["tmp_name"] ?? "");
    $name = (string) ($file["name"] ?? "");

    if ($tmpPath === "" || !is_file($tmpPath) || ($requireUploadedFile && !is_uploaded_file($tmpPath))) {
        return "อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }

    $size = (int) filesize($tmpPath);

    if ($size <= 0) {
        return "ไฟล์ว่างเปล่า";
    }

    if ($size > KPI_IMPORT_MAX_FILE_BYTES) {
        return "ไฟล์มีขนาดเกิน " . (KPI_IMPORT_MAX_FILE_BYTES / 1024 / 1024) . " MB";
    }

    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== "xlsx") {
        return "รองรับเฉพาะไฟล์ .xlsx (ไม่รองรับ .xls, .xlsm, .csv)";
    }

    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);

    if (!in_array($mime, KPI_IMPORT_ALLOWED_MIME, true)) {
        return "รูปแบบไฟล์ไม่ใช่ Excel (.xlsx) ที่ถูกต้อง";
    }

    return null;
}

/* ตรวจโครงสร้างภายในไฟล์ .xlsx (zip): ต้องเป็น workbook · ไม่มี Macro · ไม่ใหญ่ผิดปกติหลังแตกไฟล์ */
function kpiImportCheckPackage(string $path): ?string
{
    if (!class_exists("ZipArchive")) {
        return "เซิร์ฟเวอร์ไม่รองรับการอ่านไฟล์ .xlsx กรุณาติดต่อผู้ดูแลระบบ";
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        return "ไฟล์เสียหายหรือไม่ใช่ไฟล์ .xlsx";
    }

    $invalid = null;

    if ($zip->numFiles <= 0 || $zip->numFiles > KPI_IMPORT_MAX_ZIP_ENTRIES) {
        $invalid = "โครงสร้างไฟล์ไม่ถูกต้อง";
    } else {

        $totalSize = 0;
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $invalid = "ไฟล์เสียหายหรือไม่ใช่ไฟล์ .xlsx";
                break;
            }
            $totalSize += (int) $stat["size"];
            $names[strtolower((string) $stat["name"])] = true;
        }

        if ($invalid === null && $totalSize > KPI_IMPORT_MAX_UNCOMPRESSED_BYTES) {
            $invalid = "ไฟล์มีข้อมูลมากเกินกว่าที่ Template กำหนด";
        } elseif ($invalid === null && (!isset($names["[content_types].xml"]) || !isset($names["xl/workbook.xml"]))) {
            $invalid = "ไฟล์ไม่ใช่ Excel Workbook (.xlsx) ที่ถูกต้อง";
        } elseif ($invalid === null && isset($names["xl/vbaproject.bin"])) {
            $invalid = "ไฟล์มี Macro ซึ่งระบบไม่รับ กรุณาใช้ Template .xlsx ของระบบ";
        } elseif ($invalid === null) {
            $contentTypes = (string) $zip->getFromName("[Content_Types].xml");
            if (stripos($contentTypes, "macroEnabled") !== false) {
                $invalid = "ไฟล์มี Macro ซึ่งระบบไม่รับ กรุณาใช้ Template .xlsx ของระบบ";
            }
        }
    }

    $zip->close();

    if ($invalid !== null) {
        return $invalid;
    }

    return (new XlsxReader())->canRead($path) ? null : "ไฟล์ไม่ใช่ Excel (.xlsx) ที่อ่านได้";
}


/*
|--------------------------------------------------------------------------
| 2) อ่าน Template
|--------------------------------------------------------------------------
|
| คืน ["errors" => [ข้อความระดับไฟล์], "rows" => [["row" => เลขแถว Excel, "values" => [...], "errors" => [...]]]]
| อ่านเฉพาะชีต KPI_Import · ไม่คำนวณสูตร · เซลล์ที่เป็นสูตรหรือค่า Error ถือว่าผิด
|
*/
final class KpiImportReadFilter implements IReadFilter
{
    public function readCell(string $columnAddress, int $row, string $worksheetName = ""): bool
    {
        return $row <= KPI_IMPORT_MAX_SHEET_ROWS;
    }
}

function kpiImportReadFile(string $path): array
{
    $result = ["errors" => [], "rows" => []];

    try {

        $reader = new XlsxReader();

        $sheetInfo = null;
        foreach ($reader->listWorksheetInfo($path) as $info) {
            if ((string) $info["worksheetName"] === KPI_IMPORT_SHEET) {
                $sheetInfo = $info;
                break;
            }
        }

        if ($sheetInfo === null) {
            $result["errors"][] = "ไม่พบชีต \"" . KPI_IMPORT_SHEET . "\" กรุณาใช้ Template ที่ดาวน์โหลดจากระบบ";
            return $result;
        }

        if ((int) $sheetInfo["totalRows"] > KPI_IMPORT_MAX_SHEET_ROWS) {
            $result["errors"][] = "ชีต " . KPI_IMPORT_SHEET . " มีมากกว่า " . (KPI_IMPORT_MAX_SHEET_ROWS - 1)
                . " แถว กรุณาแบ่งไฟล์";
            return $result;
        }

        if ((int) $sheetInfo["totalColumns"] > KPI_IMPORT_MAX_SHEET_COLUMNS) {
            $result["errors"][] = "ชีต " . KPI_IMPORT_SHEET . " มีคอลัมน์เกินกว่าที่ Template กำหนด";
            return $result;
        }

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([KPI_IMPORT_SHEET]);
        $reader->setReadFilter(new KpiImportReadFilter());

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName(KPI_IMPORT_SHEET);

    } catch (Throwable $e) {
        error_log("kpi-import: read failed (" . get_class($e) . ")");
        $result["errors"][] = "อ่านไฟล์ไม่สำเร็จ ไฟล์อาจเสียหายหรือไม่ใช่ Template ของระบบ";
        return $result;
    }

    if ($sheet === null) {
        $result["errors"][] = "ไม่พบชีต \"" . KPI_IMPORT_SHEET . "\" กรุณาใช้ Template ที่ดาวน์โหลดจากระบบ";
        return $result;
    }

    $columnCount = count(KPI_IMPORT_COLUMNS);
    $highestRow = min((int) $sheet->getHighestDataRow(), KPI_IMPORT_MAX_SHEET_ROWS);
    $highestColumn = min(
        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
        KPI_IMPORT_MAX_SHEET_COLUMNS
    );

    /* หัวตาราง: ต้องตรงชื่อและลำดับทุกคอลัมน์ และไม่มีคอลัมน์เพิ่มทางขวา */
    $header = [];
    for ($col = 1; $col <= max($columnCount, $highestColumn); $col++) {
        $header[] = trim(kpiImportCellText($sheet, $col, 1));
    }

    $expected = KPI_IMPORT_COLUMNS;
    $actualHeader = array_slice($header, 0, $columnCount);
    $extraHeader = array_filter(array_slice($header, $columnCount), fn($v) => $v !== "");

    if ($actualHeader !== $expected || $extraHeader) {
        $result["errors"][] = "หัวตารางแถวที่ 1 ต้องเป็น: " . implode(", ", $expected) . " (ตามลำดับ และไม่มีคอลัมน์อื่น)";
        return $result;
    }

    $dataRows = 0;

    for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {

        $values = [];
        $errors = [];
        $isEmpty = true;

        for ($col = 1; $col <= $highestColumn; $col++) {

            $cellResult = kpiImportCellValue($sheet, $col, $rowNumber);

            if ($cellResult["error"] !== null) {
                $errors[] = $cellResult["error"];
            }

            if ($cellResult["value"] !== null && $cellResult["value"] !== "") {
                $isEmpty = false;
            }

            if ($col <= $columnCount) {
                $values[KPI_IMPORT_COLUMNS[$col - 1]] = $cellResult["value"];
            } elseif ($cellResult["value"] !== null && $cellResult["value"] !== "") {
                $errors[] = "มีข้อมูลนอกคอลัมน์ที่ Template กำหนด (คอลัมน์ "
                    . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . ")";
            }
        }

        foreach (KPI_IMPORT_COLUMNS as $name) {
            $values[$name] = $values[$name] ?? null;
        }

        if ($isEmpty && !$errors) {
            continue;
        }

        $dataRows++;

        if ($dataRows > KPI_IMPORT_MAX_DATA_ROWS) {
            $result["errors"][] = "ไฟล์มีข้อมูลเกิน " . KPI_IMPORT_MAX_DATA_ROWS . " แถว กรุณาแบ่งไฟล์";
            $result["rows"] = [];
            return $result;
        }

        $result["rows"][] = ["row" => $rowNumber, "values" => $values, "errors" => $errors];
    }

    if (!$result["rows"]) {
        $result["errors"][] = "ไม่พบข้อมูลในไฟล์ (กรอกข้อมูลตั้งแต่แถวที่ 2 ของชีต " . KPI_IMPORT_SHEET . ")";
    }

    return $result;
}

/* ค่าในเซลล์ (string|int|float|null) · สูตร / Error / Boolean → error */
function kpiImportCellValue(Worksheet $sheet, int $col, int $row): array
{
    $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

    if (!$sheet->cellExists($coordinate)) {
        return ["value" => null, "error" => null];
    }

    $cell = $sheet->getCell($coordinate);
    $type = $cell->getDataType();
    $value = $cell->getValue();

    if ($type === DataType::TYPE_FORMULA || (is_string($value) && str_starts_with($value, "="))) {
        return ["value" => null, "error" => "เซลล์ {$coordinate} เป็นสูตร ระบบรับเฉพาะค่าที่พิมพ์ลงไปโดยตรง"];
    }

    if ($type === DataType::TYPE_ERROR) {
        return ["value" => null, "error" => "เซลล์ {$coordinate} มีค่า Error"];
    }

    if ($value instanceof RichText) {
        $value = $value->getPlainText();
    }

    if (is_bool($value)) {
        return ["value" => null, "error" => "เซลล์ {$coordinate} เป็นค่า TRUE/FALSE ซึ่งไม่ถูกต้อง"];
    }

    if (is_string($value)) {
        $value = trim($value);
    }

    if (is_float($value) && !is_finite($value)) {
        return ["value" => null, "error" => "เซลล์ {$coordinate} มีตัวเลขไม่ถูกต้อง"];
    }

    return ["value" => $value, "error" => null];
}

function kpiImportCellText(Worksheet $sheet, int $col, int $row): string
{
    $result = kpiImportCellValue($sheet, $col, $row);
    return $result["value"] === null ? "" : (string) $result["value"];
}


/*
|--------------------------------------------------------------------------
| 3) แปลงค่าในเซลล์ (ไม่เดา: รูปแบบไม่ชัดเจน → error)
|--------------------------------------------------------------------------
*/

/* ข้อความ: ตัวเลขจำนวนเต็มแปลงเป็นข้อความ (เช่น รหัสพนักงานที่ Excel เก็บเป็นตัวเลข) */
function kpiImportText(mixed $value): string
{
    if ($value === null) {
        return "";
    }

    if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
        return (string) (int) $value;
    }

    return trim((string) $value);
}

/* จำนวนเต็มบวก/ศูนย์ · คืน null ถ้าไม่ใช่จำนวนเต็มที่ชัดเจน */
function kpiImportInt(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e9) {
        return (int) $value;
    }

    if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value))) {
        return (int) trim($value);
    }

    return null;
}

/*
| Actual → ข้อความตัวเลขรูปแบบเดียวกับหน้า KPI ของฉัน (^\d{1,13}(\.\d{1,2})?$)
| คืน ["value" => ?string, "error" => ?string]
*/
function kpiImportDecimal(mixed $value): array
{
    $invalid = ["value" => null, "error" => "ต้องเป็นตัวเลขไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง (ไม่มีจุลภาคหรือหน่วย)"];

    if (is_int($value)) {
        $text = (string) $value;
    } elseif (is_float($value)) {
        if (!is_finite($value) || $value < 0) {
            return $invalid;
        }
        $rounded = round($value, 2);
        if (abs($value - $rounded) > 1e-9 * max(1.0, abs($value))) {
            return $invalid;
        }
        $text = rtrim(rtrim(sprintf("%.2f", $rounded), "0"), ".");
    } elseif (is_string($value)) {
        $text = trim($value);
    } else {
        return $invalid;
    }

    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $text)) {
        return $invalid;
    }

    return ["value" => $text, "error" => null];
}

/* ตัวเลขทศนิยม (ข้อความ) → รูปแบบ 2 ตำแหน่งเสมอ สำหรับเปรียบเทียบกับค่าในฐานข้อมูลแบบข้อความ */
function kpiImportFixed2(?string $value): ?string
{
    if ($value === null || $value === "") {
        return null;
    }

    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
        return $value;
    }

    $integer = ltrim($m[1], "0");
    $fraction = str_pad($m[2] ?? "", 2, "0");

    return ($integer === "" ? "0" : $integer) . "." . $fraction;
}


/*
|--------------------------------------------------------------------------
| 4) ตรวจข้อมูลกับฐานข้อมูล
|--------------------------------------------------------------------------
|
| $lock = false → อ่านอย่างเดียว (Preview) · ไม่เขียนข้อมูลใด ๆ
| $lock = true  → ต้องอยู่ใน Transaction · ล็อก Assignment (FOR UPDATE), รอบประเมิน (SHARE)
|                 และผลงานเดิม (FOR UPDATE) เหมือนหน้า KPI ของฉัน
|
| ผลแต่ละแถว: action = insert | update | unchanged | error
|
*/
function kpiImportValidate(PDO $pdo, int $employeeId, array $parsedRows, bool $lock = false, string $origin = KPI_IMPORT_ORIGIN_TEMPLATE): array
{
    /* ปิดการนำเข้าผ่าน Template ของระบบทั้งหมด: รับเฉพาะแถวที่ตัวอ่านไฟล์บริษัทสร้าง (origin = company จาก Session) */
    $templateClosed = $origin !== KPI_IMPORT_ORIGIN_COMPANY;

    $thaiMonths = monthlyPeriodMonths();

    $employeeStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_id = :employee_id LIMIT 1");
    $employeeStmt->execute([":employee_id" => $employeeId]);
    $employeeCode = $employeeStmt->fetchColumn();
    $employeeCode = $employeeCode === false ? null : (string) $employeeCode;

    $results = [];
    $keyRows = [];

    /* รอบแรก: รูปแบบข้อมูลในแต่ละแถว (ยังไม่อ่านฐานข้อมูล) */
    foreach ($parsedRows as $parsed) {

        $values = $parsed["values"];
        $errors = $parsed["errors"] ?? [];

        $code = kpiImportText($values["employee_code"] ?? null);
        $kpiId = kpiImportInt($values["kpi_id"] ?? null);
        $year = kpiImportInt($values["year"] ?? null);
        $month = kpiImportInt($values["month"] ?? null);
        $note = kpiImportText($values["note"] ?? null);

        if ($code === "") {
            $errors[] = "กรุณากรอก employee_code";
        } elseif ($employeeCode === null || $code !== $employeeCode) {
            $errors[] = "employee_code ไม่ตรงกับผู้ใช้ที่เข้าสู่ระบบ (นำเข้าได้เฉพาะข้อมูลของตนเอง)";
        }

        if ($templateClosed) {
            $errors[] = "ระบบปิดการนำเข้าผ่าน Template แล้ว บันทึกได้จากไฟล์ Excel ของบริษัทเฉพาะ KPI ที่ยืนยัน Mapping แล้ว";
        }

        if ($kpiId === null || $kpiId <= 0) {
            $errors[] = "kpi_id ต้องเป็นจำนวนเต็มบวก";
        }

        if ($year === null || $year < 2000 || $year > 2100) {
            $errors[] = "year ต้องเป็นปี ค.ศ. 4 หลัก (2000-2100)";
        }

        if ($month === null || $month < 1 || $month > 12) {
            $errors[] = "month ต้องเป็นจำนวนเต็ม 1-12";
        }

        if (mb_strlen($note) > KPI_IMPORT_NOTE_MAX_LENGTH) {
            $errors[] = "note ยาวเกิน " . KPI_IMPORT_NOTE_MAX_LENGTH . " ตัวอักษร";
        }

        $key = ($kpiId !== null && $year !== null && $month !== null) ? "{$kpiId}|{$year}|{$month}" : null;

        if ($key !== null) {
            $keyRows[$key][] = (int) $parsed["row"];
        }

        $results[] = [
            "row" => (int) $parsed["row"],
            "key" => $key,
            "kpi_id" => $kpiId,
            "year" => $year,
            "month" => $month,
            "month_label" => ($month !== null && isset($thaiMonths[$month])) ? $thaiMonths[$month] . " " . $year : "-",
            "raw_actual" => $values["actual"] ?? null,
            "raw_competency" => $values["competency_score"] ?? null,
            "comment" => $note === "" ? null : $note,
            "kpi_name" => null,
            "kpi_type" => null,
            "unit" => null,
            "assignment_id" => null,
            "period_id" => null,
            "performance_date" => null,
            "target" => null,
            "actual" => null,
            "score" => null,
            "existing" => null,
            "action" => "error",
            "errors" => $errors
        ];
    }

    /* KPI + ปี + เดือนเดียวกันซ้ำในไฟล์ */
    foreach ($results as &$result) {
        if ($result["key"] !== null && count($keyRows[$result["key"]]) > 1) {
            $others = array_diff($keyRows[$result["key"]], [$result["row"]]);
            $result["errors"][] = "KPI และเดือนเดียวกันซ้ำในไฟล์ (แถว " . implode(", ", $others) . ")";
        }
    }
    unset($result);

    /* รอบสอง: ตรวจกับฐานข้อมูล (เฉพาะแถวที่รูปแบบถูกต้อง) */
    $periodSql = "
        SELECT period_id, period_year, period_month, start_date, end_date, status
        FROM evaluation_periods
        WHERE period_year = :year AND period_month = :month
        LIMIT 1" . ($lock ? " LOCK IN SHARE MODE" : "");

    $assignmentLockSql = "
        SELECT assignment_id
        FROM kpi_assignments
        WHERE employee_id = :employee_id AND kpi_id = :kpi_id AND assignment_year = :year
        FOR UPDATE";

    $assignmentSql = "
        SELECT
            a.assignment_id, a.employee_id, a.kpi_id, a.target_value, a.start_date, a.end_date,
            a.assignment_year,
            k.kpi_type, k.kpi_name, k.unit, k.max_score,
            k.score_5, k.score_4, k.score_3, k.score_2, k.score_1
        FROM kpi_assignments a
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        WHERE a.employee_id = :employee_id
          AND a.kpi_id = :kpi_id
          AND a.assignment_year = :year
          AND a.status = 'Active'
        LIMIT 1" . ($lock ? " LOCK IN SHARE MODE" : "");

    $existingSql = "
        SELECT performance_id, employee_id, status, target, actual, score, comment
        FROM kpi_performances
        WHERE assignment_id = :assignment_id AND period_id = :period_id
        LIMIT 1" . ($lock ? " FOR UPDATE" : "");

    $periodStmt = $pdo->prepare($periodSql);
    $assignmentLockStmt = $pdo->prepare($assignmentLockSql);
    $assignmentStmt = $pdo->prepare($assignmentSql);
    $existingStmt = $pdo->prepare($existingSql);

    foreach ($results as &$result) {

        if ($result["errors"]) {
            continue;
        }

        $year = (int) $result["year"];
        $month = (int) $result["month"];
        $monthLabel = $result["month_label"];

        if ($lock) {
            $assignmentLockStmt->execute([":employee_id" => $employeeId, ":kpi_id" => $result["kpi_id"], ":year" => $year]);
            $assignmentLockStmt->fetchAll();
        }

        $periodStmt->execute([":year" => $year, ":month" => $month]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $assignmentStmt->execute([":employee_id" => $employeeId, ":kpi_id" => $result["kpi_id"], ":year" => $year]);
        $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$period) {
            $result["errors"][] = "ยังไม่มีรอบประเมินของเดือน{$monthLabel}";
        } elseif ((string) $period["status"] !== "Open") {
            $result["errors"][] = "รอบประเมินของเดือน{$monthLabel} ปิดแล้ว ไม่สามารถบันทึกหรือแก้ไขผลงานได้";
        }

        /* ไม่บอกว่า KPI นั้นมีอยู่หรือเป็นของใคร · บอกเพียงว่าไม่อยู่ใน Assignment ของผู้ใช้ */
        if (!$assignment) {
            $result["errors"][] = "kpi_id {$result["kpi_id"]} ไม่อยู่ใน KPI ที่ได้รับมอบหมายของคุณในปี {$year}";
            continue;
        }

        $result["kpi_name"] = (string) $assignment["kpi_name"];
        $result["kpi_type"] = (string) $assignment["kpi_type"];
        $result["unit"] = $assignment["unit"] !== null ? (string) $assignment["unit"] : null;
        $result["assignment_id"] = (int) $assignment["assignment_id"];

        $monthStart = sprintf("%04d-%02d-01", $year, $month);
        $monthEnd = date("Y-m-t", strtotime($monthStart));
        $assignmentStart = $assignment["start_date"] ?: sprintf("%04d-01-01", (int) $assignment["assignment_year"]);
        $assignmentEnd = $assignment["end_date"] ?: sprintf("%04d-12-31", (int) $assignment["assignment_year"]);

        if ($assignmentStart > $monthEnd || $assignmentEnd < $monthStart) {
            $result["errors"][] = "KPI นี้ไม่ได้รับมอบหมายในเดือน{$monthLabel}";
        }

        $realType = strtolower(trim((string) $assignment["kpi_type"]));
        $actualFilled = $result["raw_actual"] !== null && $result["raw_actual"] !== "";
        $competencyFilled = $result["raw_competency"] !== null && $result["raw_competency"] !== "";

        $kpi9TypeError = nonRenewalKpiTypeError($assignment);

        if ($kpi9TypeError !== null) {

            $result["errors"][] = $kpi9TypeError;

        } elseif ($realType === "performance") {

            if ($competencyFilled) {
                $result["errors"][] = "KPI ประเภท Performance ให้กรอก actual เท่านั้น (เว้น competency_score ว่าง)";
            }

            if (!$actualFilled) {
                $result["errors"][] = "กรุณากรอก actual";
            } else {

                $decimal = kpiImportDecimal($result["raw_actual"]);

                if ($decimal["error"] !== null) {
                    $result["errors"][] = "actual " . $decimal["error"];
                } elseif (!isNonRenewalKpi($assignment) && ($assignment["target_value"] === null || $assignment["target_value"] === "")) {
                    $result["errors"][] = "KPI นี้ยังไม่ได้กำหนด Target โดย Admin";
                } else {

                    /* คะแนนคำนวณที่ฟังก์ชันกลางเท่านั้น (เหมือนหน้า KPI ของฉัน) */
                    $levels = loadKpiScoreCriteria($pdo, [$assignment])[(int) $assignment["kpi_id"]] ?? [];
                    $score = kpiCalculateScore($assignment, $levels, $decimal["value"], $assignment["target_value"]);

                    if ($score["error"] !== null || $score["grade"] === null) {
                        $result["errors"][] = $score["error"]
                            ?? "KPI นี้ยังไม่ได้กำหนดเกณฑ์ระดับผลงานและเป้าหมาย กรุณาติดต่อผู้ดูแลระบบ";
                    } else {
                        $result["actual"] = $decimal["value"];
                        $result["score"] = (int) $score["grade"];
                    }
                }
            }

        } elseif ($realType === "competency") {

            if ($actualFilled) {
                $result["errors"][] = "KPI ประเภท Competency ให้กรอก competency_score เท่านั้น (เว้น actual ว่าง)";
            }

            $competency = kpiImportInt($result["raw_competency"]);

            if (!$competencyFilled) {
                $result["errors"][] = "กรุณากรอก competency_score (1-5)";
            } elseif ($competency === null || $competency < 1 || $competency > 5) {
                $result["errors"][] = "competency_score ต้องเป็นจำนวนเต็ม 1-5";
            } else {
                $result["score"] = $competency;
            }

        } else {
            $result["errors"][] = "ไม่พบประเภท KPI ที่ถูกต้อง";
        }

        $result["target"] = kpiImportFixed2(
            $assignment["target_value"] === null ? null : (string) $assignment["target_value"]
        );

        if (!$period || $result["errors"]) {
            continue;
        }

        $result["period_id"] = (int) $period["period_id"];
        $result["performance_date"] = (string) $period["start_date"];

        $existingStmt->execute([":assignment_id" => $result["assignment_id"], ":period_id" => $result["period_id"]]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existing === null) {
            $result["action"] = "insert";
            continue;
        }

        $result["existing"] = [
            "performance_id" => (int) $existing["performance_id"],
            "status" => (string) $existing["status"],
            "target" => kpiImportFixed2($existing["target"] === null ? null : (string) $existing["target"]),
            "actual" => kpiImportFixed2($existing["actual"] === null ? null : (string) $existing["actual"]),
            "score" => $existing["score"] === null ? null : kpiImportFixed2((string) $existing["score"]),
            "comment" => ($existing["comment"] === null || $existing["comment"] === "") ? null : (string) $existing["comment"]
        ];

        if ((int) $existing["employee_id"] !== $employeeId) {
            $result["errors"][] = "ไม่สามารถนำเข้ารายการนี้ได้ กรุณาติดต่อผู้ดูแลระบบ";
            continue;
        }

        if ((string) $existing["status"] !== "Draft") {
            $result["errors"][] = (string) $existing["status"] === "Approved"
                ? "ผลงานของเดือน{$monthLabel} ได้รับการอนุมัติแล้ว ไม่สามารถนำเข้าทับได้"
                : "ผลงานของเดือน{$monthLabel} อยู่ในสถานะ " . $existing["status"] . " นำเข้าทับได้เฉพาะสถานะ Draft";
            continue;
        }

        $result["action"] = kpiImportSameAsExisting($result) ? "unchanged" : "update";
    }
    unset($result);

    foreach ($results as &$result) {
        if ($result["errors"]) {
            $result["action"] = "error";
        }
    }
    unset($result);

    return [
        "ok" => !array_filter($results, fn($r) => $r["action"] === "error"),
        "rows" => $results
    ];
}

/* ค่าที่จะบันทึกเหมือนรายการเดิมทุกค่า (target, actual, score, comment) */
function kpiImportSameAsExisting(array $result): bool
{
    $existing = $result["existing"];
    $newScore = $result["score"] === null ? null : kpiImportFixed2((string) $result["score"]);

    return $existing["target"] === $result["target"]
        && $existing["actual"] === kpiImportFixed2($result["actual"])
        && $existing["score"] === $newScore
        && $existing["comment"] === $result["comment"];
}


/*
|--------------------------------------------------------------------------
| 5) บันทึก (หลังผู้ใช้ยืนยัน)
|--------------------------------------------------------------------------
|
| ตรวจซ้ำทุกแถวภายใน Transaction พร้อมล็อก แล้วเทียบกับผล Preview ที่ผู้ใช้เห็น
| ต่างจาก Preview แม้แถวเดียว (สถานะ/ค่าเดิม/คะแนน/รอบประเมินเปลี่ยน) → Rollback ทั้งชุด
| update ทำเฉพาะแถวที่ผู้ใช้ติ๊กยืนยัน และยังเป็น Draft ณ ตอนบันทึก
| แถวที่ตัวอ่านไฟล์บริษัทตั้ง requires_confirmation (เช่น Budget > 0 แต่ Total R = 0) บันทึกเฉพาะเมื่อผู้ใช้ยืนยันแถวนั้น
|   ไม่ยืนยัน = ไม่บันทึกแถวนั้น (นับใน declined) · ธงนี้มาจากข้อมูลที่ระบบอ่านเก็บไว้ใน Session ไม่ใช่จากผู้ใช้
| $origin มาจาก Session (template / company) ใช้ตรวจซ้ำด้วยกฎเดียวกับตอน Preview
|
*/
function kpiImportCommit(PDO $pdo, int $employeeId, array $parsedRows, array $previewRows, array $confirmedUpdateKeys, string $origin = KPI_IMPORT_ORIGIN_TEMPLATE, array $confirmedFlagKeys = []): array
{
    $summary = ["ok" => false, "inserted" => 0, "updated" => 0, "unchanged" => 0, "skipped" => 0, "declined" => 0, "errors" => []];
    $confirmed = array_flip(array_map("strval", $confirmedUpdateKeys));
    $confirmedFlags = array_flip(array_map("strval", $confirmedFlagKeys));
    $flaggedRows = [];
    foreach ($parsedRows as $parsed) {
        if ($origin === KPI_IMPORT_ORIGIN_COMPANY && !empty($parsed["requires_confirmation"])) {
            $flaggedRows[(int) $parsed["row"]] = true;
        }
    }

    if ($origin !== KPI_IMPORT_ORIGIN_COMPANY) {
        $summary["errors"][] = "ระบบปิดการนำเข้าผ่าน Template แล้ว ไม่มีข้อมูลถูกบันทึก";
        return $summary;
    }

    $previewByKey = [];
    foreach ($previewRows as $row) {
        if ($row["action"] === "error") {
            $summary["errors"][] = "ไฟล์นี้ยังมีข้อมูลที่ไม่ถูกต้อง จึงยังนำเข้าไม่ได้";
            return $summary;
        }
        $previewByKey[$row["key"]] = $row;
    }

    try {

        $pdo->beginTransaction();

        $fresh = kpiImportValidate($pdo, $employeeId, $parsedRows, true, $origin);
        $changed = [];

        foreach ($fresh["rows"] as $row) {

            $before = $previewByKey[$row["key"]] ?? null;

            if (
                $row["action"] === "error"
                || $before === null
                || $before["action"] !== $row["action"]
                || $before["assignment_id"] !== $row["assignment_id"]
                || $before["period_id"] !== $row["period_id"]
                || $before["target"] !== $row["target"]
                || $before["actual"] !== $row["actual"]
                || $before["score"] !== $row["score"]
                || $before["comment"] !== $row["comment"]
                || $before["existing"] != $row["existing"]
            ) {
                $changed[] = "แถว " . $row["row"] . ($row["errors"] ? ": " . implode(" / ", $row["errors"]) : "");
            }
        }

        if ($changed || count($fresh["rows"]) !== count($previewByKey)) {
            $pdo->rollBack();
            $summary["errors"][] = "ข้อมูลในระบบเปลี่ยนไปหลังแสดงตัวอย่าง จึงยกเลิกการนำเข้าทั้งชุด กรุณาอัปโหลดไฟล์อีกครั้ง";
            $summary["errors"] = array_merge($summary["errors"], $changed);
            return $summary;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO kpi_performances
                (assignment_id, employee_id, period_id, performance_date, target, actual, score, comment, status)
            VALUES
                (:assignment_id, :employee_id, :period_id, :performance_date, :target, :actual, :score, :comment, 'Draft')
        ");

        $updateStmt = $pdo->prepare("
            UPDATE kpi_performances
            SET performance_date = :performance_date,
                target = :target,
                actual = :actual,
                score = :score,
                comment = :comment
            WHERE performance_id = :performance_id
              AND employee_id = :employee_id
              AND status = 'Draft'
        ");

        foreach ($fresh["rows"] as $row) {

            $params = [
                ":performance_date" => $row["performance_date"],
                ":target" => $row["target"],
                ":actual" => $row["actual"],
                ":score" => $row["score"],
                ":comment" => $row["comment"],
                ":employee_id" => $employeeId
            ];

            /* ต้องยืนยันเป็นรายแถว (เช่น ผล 0%) · ไม่ยืนยัน → ไม่บันทึกแถวนี้ */
            /* เดือนที่ยังไม่สิ้นสุดยืนยันไม่ได้ (ข้อมูลอาจยังไม่ครบ) แม้จะส่งค่ายืนยันมา */
            if (
                isset($flaggedRows[(int) $row["row"]]) && in_array($row["action"], ["insert", "update"], true)
                && (!isset($confirmedFlags[$row["key"]]) || date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $row["year"], $row["month"]))) >= date("Y-m-d"))
            ) {
                $summary["declined"]++;
                continue;
            }

            if ($row["action"] === "insert") {

                $insertStmt->execute($params + [
                    ":assignment_id" => $row["assignment_id"],
                    ":period_id" => $row["period_id"]
                ]);
                $summary["inserted"]++;

            } elseif ($row["action"] === "update") {

                if (!isset($confirmed[$row["key"]])) {
                    $summary["skipped"]++;
                    continue;
                }

                $updateStmt->execute($params + [":performance_id" => $row["existing"]["performance_id"]]);

                if ($updateStmt->rowCount() !== 1) {
                    $pdo->rollBack();
                    $summary["inserted"] = $summary["updated"] = $summary["skipped"] = $summary["unchanged"] = $summary["declined"] = 0;
                    $summary["errors"][] = "แถว " . $row["row"] . ": แก้ไขรายการเดิมไม่สำเร็จ จึงยกเลิกการนำเข้าทั้งชุด";
                    return $summary;
                }

                $summary["updated"]++;

            } else {
                $summary["unchanged"]++;
            }
        }

        $pdo->commit();
        $summary["ok"] = true;
        return $summary;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("kpi-import: commit failed (" . get_class($e) . ")");
        $summary["inserted"] = $summary["updated"] = $summary["skipped"] = $summary["unchanged"] = $summary["declined"] = 0;
        $summary["errors"][] = "บันทึกไม่สำเร็จ ระบบยกเลิกการนำเข้าทั้งชุด กรุณาลองใหม่อีกครั้ง";
        return $summary;
    }
}
