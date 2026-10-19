<?php

/*
|--------------------------------------------------------------------------
| ตัวอ่านแบบฟอร์ม "KPI AM" ของบริษัท (บันทึกผ่าน Preview / Commit เดิม เฉพาะหัวข้อที่ยืนยัน Mapping แล้ว)
|--------------------------------------------------------------------------
|
| อ่านเฉพาะช่อง "ที่ทำได้" รายเดือนของส่วนที่ 1 (Performance) และส่วนที่ 2 (Competency)
| ยกเว้น "บรรลุเป้าหมายการต่อสัญญา" ที่อ่านจากชีตสรุปรายเดือน (ดู KPI_COMPANY_RENEWAL_RULE)
| และ "จำนวนรายการปรับเพิ่มมูลค่าสัญญา" ที่อ่านจากชีตของเดือน (ดู KPI_COMPANY_UPLIFT_RULE)
| ไม่อ่านคอลัมน์ "เกรด" / "คะแนนจริง" · เดือนที่ว่างถูกข้าม (ไม่ถือว่าเป็น 0 หรือเกรด 1)
| ผลลัพธ์ที่พร้อม (READY) อยู่ในรูปแบบภายในเดียวกับ Template กลาง (ใช้ kpiImportValidate / Preview / Commit เดิม)
|
| ความปลอดภัยของไฟล์:
|   - ใช้ข้อจำกัดเดียวกับ Template: ไฟล์ ≤ 2 MB, ZIP ≤ 200 entries, ไม่มี Macro
|   - เพดาน 20 MB หลังแตกไฟล์ (ค่าเดิม) ใช้กับ "ส่วนของไฟล์ที่ถูกอ่านจริง" ทั้งหมด
|   - KpiCompanyXlsxReader ปฏิเสธการแตกไฟล์ส่วนใดที่ไม่อยู่ใน allowlist (drawings, externalLinks,
|     calcChain, ชีตที่ไม่เกี่ยวข้อง ฯลฯ) ขณะอ่านจริง ไม่ได้พึ่งพฤติกรรมของ Library อย่างเดียว
|
| ค่าจากสูตร: ใช้ค่าที่ Excel คำนวณเก็บไว้ (ไม่คำนวณใหม่) เฉพาะเมื่อไล่ตรวจสูตรต้นทางทุกชั้นแล้วว่า
|   ไม่อ้าง External Link / ตาราง, ไม่มีค่า Error, ไม่ใช้ฟังก์ชันที่ผลเปลี่ยนเอง (TODAY, INDIRECT, OFFSET ...),
|   ไม่ใช้ Defined Name และไม่อ้างทั้งคอลัมน์/ทั้งแถว · ไม่เข้าเงื่อนไข → ปฏิเสธค่านั้น
|
| Mapping KPI มาจาก includes/kpi-import-company-map.php · ยังไม่ยืนยัน → NEEDS_CONFIRMATION
|
*/

require_once __DIR__ . "/kpi-import-helper.php";

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\File as SpreadsheetFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const KPI_COMPANY_FORM_SHEET = "KPI AM";
const KPI_COMPANY_MAX_LOAD_PASSES = 12;
const KPI_COMPANY_MAX_SHEETS = 20;
const KPI_COMPANY_MAX_DEPENDENCY_CELLS = 5000;
const KPI_COMPANY_MAX_META_PART_BYTES = 5 * 1024 * 1024;   // .rels / workbook.xml ที่ตัวอ่านเปิดเอง
const KPI_COMPANY_SCAN_ROWS = 300;
const KPI_COMPANY_SCAN_COLUMNS = 80;

const KPI_COMPANY_STATUS_READY = "READY";
const KPI_COMPANY_STATUS_NEEDS_CONFIRMATION = "NEEDS_CONFIRMATION";
const KPI_COMPANY_STATUS_REJECTED = "REJECTED";

/*
| KPI "บรรลุเป้าหมายการต่อสัญญา" (value_rule ที่ยืนยันแล้ว 2026-10-17)
|   แหล่งข้อมูล = ชีต "สรุปทั้งปี.." แถวของแต่ละเดือน (หาแถวจากชื่อเดือนในคอลัมน์ B ไม่ยึดเลขแถว)
|   C = Budget · G = Total R = SUM(D:F) (Renew 1Y / 2Y / Bait 3Y ไม่รวม New) · Q = G/C (อัตราส่วน เช่น 0.95)
|   Actual ที่บันทึก = (Total R / Budget) × 100 ตัดทศนิยมลงเหลือ 2 ตำแหน่ง (หน่วย % เทียบกับ Target 100)
|   ไม่อ่านช่อง "ที่ทำได้" ในแบบฟอร์ม KPI AM ของหัวข้อนี้ และไม่ใช้เกรดจาก Excel
*/
const KPI_COMPANY_RENEWAL_RULE = "renewal_total_r_over_budget_percent";
const KPI_COMPANY_RENEWAL_LABEL = "บรรลุเป้าหมายการต่อสัญญา";
const KPI_COMPANY_SUMMARY_SHEET_PREFIX = "สรุปทั้งปี";
const KPI_COMPANY_SUMMARY_SCAN_ROWS = 40;
const KPI_COMPANY_SUMMARY_SCAN_COLUMNS = ["B", "C", "D", "E", "F", "G", "Q", "T"];
const KPI_COMPANY_RENEWAL_TARGET = "100.00";

/*
| ชื่อชีตเดือนตามแบบฟอร์มที่ยืนยันแล้ว: "<ตัวย่อเดือน>.<ปี พ.ศ. 2 หลัก>" เช่น ม.ค.69 · เม.ษ.69 (ตัวสะกดของแบบฟอร์ม)
| จับคู่โดยตัดช่องว่างหัว/ท้ายชื่อชีตเท่านั้น (เช่น "ส.ค.69 ") ต้องตรงทุกตัวอักษรและพบชีตเดียว
*/
const KPI_COMPANY_RENEWAL_MONTH_SHEETS = [
    1 => "ม.ค", 2 => "ก.พ", 3 => "มี.ค", 4 => "เม.ษ", 5 => "พ.ค", 6 => "มิ.ย",
    7 => "ก.ค", 8 => "ส.ค", 9 => "ก.ย", 10 => "ต.ค", 11 => "พ.ย", 12 => "ธ.ค"
];
const KPI_COMPANY_CONFIRM_ZERO_TOTAL_R = "zero_total_r";

/*
| KPI 8 "จำนวนรายการปรับเพิ่มมูลค่าสัญญา" (value_rule KPI_COMPANY_UPLIFT_RULE · แยกจากกฎของ KPI 14)
|   ความหมายที่เจ้าของระบบยืนยัน: ร้อยละที่มูลค่าสัญญารวมเพิ่มขึ้นจากมูลค่าเดิม · Target 20 (%)
|   แหล่งข้อมูล = ชีตของเดือนที่เลือก (ชื่อตาม KPI_COMPANY_RENEWAL_MONTH_SHEETS) ไม่ใช้ช่อง "ที่ทำได้" ใน KPI AM
|   แถวรวม n หาจากคอลัมน์ O (O<n> = SUM(O5:O<n-2>) ต้องมีแถวเดียว · ไม่ยึดเลขแถว เช่น มิ.ย. ในไฟล์จริงคือแถว 40)
|     O<n> = SUM(O5:O<n-2>) ราคาเดิม · R<n> = SUM(R5:R<n-2>) ราคาสรุป · S<n> = SUM(S5:S<n-2>) ผลต่าง
|     S<n+1> = S<n>/O<n> (สัดส่วน เช่น 0.2075 = 20.75%)
|   รายแถว (เจ้าของระบบยืนยัน): S = ผลต่างที่ใช้คำนวณโดยตรง พิมพ์เองได้ ไม่ต้องเป็นสูตร =R-O · O ว่างได้ · R เป็นข้อมูลประกอบ
|   Actual = FLOOR(S<n+1> × 100) เป็นจำนวนเต็ม (หน่วย %) ปัดลงครั้งเดียวด้วยเลขทศนิยมแม่นยำ (bcmath)
|     และต้องได้ค่าเดียวกับ FLOOR(S<n> × 100 / O<n>) · ไม่ใช้ nonRenewalAchievementBasisPoints()
|   ปฏิเสธ: เดือนที่ยังไม่สิ้นสุดตามเวลาไทย (KPI_COMPANY_UPLIFT_TIMEZONE) · R ว่างทั้งเดือน · แถวที่มี O/R แต่ S ว่าง · S เป็นสูตร =R-O แต่ R ว่าง · ผลติดลบ · O<n> ≤ 0 · สูตรหาย/พิมพ์ทับ/ผิดรูปแบบ
|           · อ้างชีตของเดือนอื่น · External Link · ค่าที่ Excel เก็บไว้ไม่สอดคล้องกับสูตร
|   ตอนเตรียมนำเข้าต้องผ่านเพิ่ม: kpi_id 8 · Target ใน Assignment = 20 · เกณฑ์คะแนนระดับ 1-5 ครบและครอบคลุมทุก Actual
*/
const KPI_COMPANY_UPLIFT_RULE = "contract_uplift_s_over_o_floor_percent";
const KPI_COMPANY_UPLIFT_LABEL = "จำนวนรายการปรับเพิ่มมูลค่าสัญญา";
const KPI_COMPANY_UPLIFT_KPI_ID = 8;
const KPI_COMPANY_UPLIFT_TARGET = "20.00";
const KPI_COMPANY_UPLIFT_FIRST_ROW = 5;
const KPI_COMPANY_UPLIFT_SCAN_ROWS = 100;
const KPI_COMPANY_UPLIFT_HEADER_ROWS = 4;
const KPI_COMPANY_UPLIFT_HEADERS = ["O" => "ราคาเดิม", "R" => "ราคาสรุป", "S" => "ผลต่าง"];
/*
| ค่าที่ Excel คำนวณเก็บไว้เทียบกับการคำนวณซ้ำใน PHP (double เหมือนกัน ต่างกันได้เฉพาะลำดับการบวก ระดับ 1e-15)
| เผื่อไว้ 1e-9 ของขนาดค่า · ต่างเกินนี้ถือว่าไม่ใช่ผลของสูตรเดียวกัน → ปฏิเสธ
*/
const KPI_COMPANY_UPLIFT_REL_TOLERANCE = 1e-9;
/* เกณฑ์คะแนนต้องให้ทุก Actual จำนวนเต็ม 0..ค่านี้ (และ Actual ของรายการ) เข้าระดับใดระดับหนึ่ง */
const KPI_COMPANY_UPLIFT_CRITERIA_CHECK_MAX = 1000;
/* วัน/เดือนที่ใช้ตัดสินว่าเดือนสิ้นสุดแล้วหรือยัง (เฉพาะ KPI 8) ตามเวลาไทย ไม่ขึ้นกับ date.timezone ของ php.ini */
const KPI_COMPANY_UPLIFT_TIMEZONE = "Asia/Bangkok";

/*
| KPI 10 / 11 / 12 (เจ้าของระบบยืนยัน 2026-10-19: ใช้ช่อง "ที่ทำได้" ในแบบฟอร์ม KPI AM แถว 15-17 ได้เลย)
|   ค่าในช่องเป็นสัดส่วนแบบ Excel (1 = 100% · สูตรเกรดในแบบฟอร์มเทียบกับ %) → Actual = ค่า × 100 (หน่วย %)
|   คำนวณด้วยเลขทศนิยมแม่นยำ ไม่ปัดเศษ: ผลมีทศนิยมเกิน 2 ตำแหน่ง / ติดลบ / ไม่ใช่ตัวเลข → ปฏิเสธ
|   ช่องที่เป็นสูตรยังต้องผ่านการไล่ตรวจสูตรต้นทางเหมือนช่องอื่น · ช่องว่าง = เดือนนั้นไม่มีข้อมูล (ข้าม)
|   ใช้ได้เฉพาะ kpi_id ในรายการนี้ · คะแนนจาก kpiCalculateScore() ตามเกณฑ์ / Target ของ KPI ในระบบ (ไม่ใช้เกรดจาก Excel)
*/
const KPI_COMPANY_FORM_RATIO_RULE = "kpi_am_cell_ratio_times_100";
const KPI_COMPANY_FORM_RATIO_KPI_IDS = [10, 11, 12];

/*
| KPI 9 "มูลค่าไม่ต่อสัญญา" (เจ้าของระบบกำหนด 2026-10-19: Actual จาก สรุปทั้งปี69!T23 = ช่อง K14 ของแบบฟอร์ม KPI AM)
|   แหล่งข้อมูล = ตาราง "Portfolio(จำนวนราย)" ของชีตสรุป แถวของแต่ละเดือน (มกราคม = T23)
|   T = G/C (จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget) · Actual = T × 100 ตัดทศนิยมลง 2 ตำแหน่ง (%) · Target = 100 (%)
|   คะแนน = kpiCalculateScore() กฎ KPI 9 (≥100 / 96 / 90 / 85%) ซึ่งตรงกับสูตรเกรด L14 ในแบบฟอร์ม
|   ใช้ได้เฉพาะหัวข้อ / ชื่อ KPI / kpi_id 9 นี้เท่านั้น
*/
const KPI_COMPANY_NON_RENEWAL_RULE = "non_renewal_count_t_over_c_percent";
const KPI_COMPANY_NON_RENEWAL_TARGET = "100.00";

/* ฟังก์ชันที่ผลขึ้นกับค่าที่อ้างอิงเท่านั้น (นอกจากนี้ → ตรวจสอบไม่ได้ → ปฏิเสธ) */
const KPI_COMPANY_ALLOWED_FUNCTIONS = [
    "SUM", "AVERAGE", "MIN", "MAX", "COUNT", "COUNTA", "COUNTIF", "COUNTIFS", "SUMIF", "SUMIFS",
    "AVERAGEIF", "AVERAGEIFS", "IF", "IFERROR", "AND", "OR", "NOT", "ROUND", "ROUNDUP", "ROUNDDOWN",
    "ABS", "INT", "MOD", "ISBLANK", "ISNUMBER", "ISERROR", "VLOOKUP", "HLOOKUP", "INDEX", "MATCH"
];


/*
|--------------------------------------------------------------------------
| Reader ที่แตกไฟล์ได้เฉพาะส่วนใน allowlist
|--------------------------------------------------------------------------
*/
final class KpiCompanyXlsxReader extends XlsxReader
{
    /** @var array<string, true> ค่าเริ่มต้นว่าง = ไม่อนุญาตให้แตกส่วนใดเลย */
    private array $allowedParts = [];

    public function setAllowedParts(array $allowedParts): void
    {
        $this->allowedParts = $allowedParts;
    }

    protected function getFromZipArchive(ZipArchive $archive, string $fileName = ""): string
    {
        if (!isset($this->allowedParts[kpiCompanyNormalizePart($fileName)])) {
            throw new RuntimeException("Workbook part outside the allowlist was requested");
        }

        return parent::getFromZipArchive($archive, $fileName);
    }
}

/*
| ชีตอื่นนอกจากแบบฟอร์ม: เก็บเฉพาะเซลล์ที่สูตรอ้างถึง (ขยายทีละรอบ) ไม่เก็บข้อมูลอื่นของชีตนั้น
| หมายเหตุ: Library ยังต้องอ่าน XML ของชีตนั้นทั้งชีตเพื่อหาเซลล์ แต่ไม่สร้าง/เก็บเซลล์ที่ไม่ผ่านตัวกรอง
*/
final class KpiCompanyCellFilter implements IReadFilter
{
    public function __construct(private string $formSheet, private array $cells)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ""): bool
    {
        if ($worksheetName === $this->formSheet) {
            return true;
        }

        return isset($this->cells[$worksheetName][$columnAddress . $row]);
    }
}

/* ชื่อ part ในรูปแบบเดียวกับที่ Reader ใช้เปิด (ตัดราก, ./ , .. และไม่สนตัวพิมพ์) */
function kpiCompanyNormalizePart(string $name): string
{
    if (str_contains($name, "//")) {
        $name = substr($name, strpos($name, "//") + 1);
    }

    $name = preg_replace('#^\./#', "", $name);
    $name = SpreadsheetFile::realpath(str_replace("\\", "/", $name));

    return strtolower(ltrim($name, "/"));
}

/* ชื่อหัวข้อสำหรับจับคู่: ตัดช่องว่างซ้ำ และเลขข้อด้านหน้า เช่น "1.", "2. " */
function kpiCompanyNormalizeLabel(string $label): string
{
    $label = trim(preg_replace('/\s+/u', " ", $label));

    return trim(preg_replace('/^\d+\s*\.\s*/u', "", $label));
}


/*
|--------------------------------------------------------------------------
| 1) ตรวจโครงสร้าง package และหา part ของแต่ละชีต (อ่านเฉพาะ .rels และ workbook.xml)
|--------------------------------------------------------------------------
*/
function kpiCompanyInspectPackage(string $path): array
{
    $result = ["error" => null, "sheets" => [], "base_parts" => [], "sizes" => [], "calc" => ["mode" => "auto", "full_calc_on_load" => false]];

    if (!class_exists("ZipArchive")) {
        $result["error"] = "เซิร์ฟเวอร์ไม่รองรับการอ่านไฟล์ .xlsx กรุณาติดต่อผู้ดูแลระบบ";
        return $result;
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        $result["error"] = "ไฟล์เสียหายหรือไม่ใช่ไฟล์ .xlsx";
        return $result;
    }

    try {

        if ($zip->numFiles <= 0 || $zip->numFiles > KPI_IMPORT_MAX_ZIP_ENTRIES) {
            $result["error"] = "โครงสร้างไฟล์ไม่ถูกต้อง (จำนวนส่วนประกอบในไฟล์เกิน " . KPI_IMPORT_MAX_ZIP_ENTRIES . ")";
            return $result;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $result["error"] = "ไฟล์เสียหายหรือไม่ใช่ไฟล์ .xlsx";
                return $result;
            }
            $result["sizes"][kpiCompanyNormalizePart((string) $stat["name"])] = (int) $stat["size"];
        }

        if (!isset($result["sizes"]["[content_types].xml"]) || !isset($result["sizes"]["_rels/.rels"])) {
            $result["error"] = "ไฟล์ไม่ใช่ Excel Workbook (.xlsx) ที่ถูกต้อง";
            return $result;
        }

        if (isset($result["sizes"]["xl/vbaproject.bin"])) {
            $result["error"] = "ไฟล์มี Macro ซึ่งระบบไม่รับ";
            return $result;
        }

        $contentTypes = kpiCompanyReadMetaPart($zip, $result["sizes"], "[Content_Types].xml");
        if ($contentTypes === null || stripos($contentTypes, "macroEnabled") !== false) {
            $result["error"] = $contentTypes === null ? "ไฟล์ไม่ใช่ Excel Workbook (.xlsx) ที่ถูกต้อง" : "ไฟล์มี Macro ซึ่งระบบไม่รับ";
            return $result;
        }

        /* ราก: officeDocument (workbook) + document properties */
        $rootRels = kpiCompanyParseRels(kpiCompanyReadMetaPart($zip, $result["sizes"], "_rels/.rels"), "");
        $workbookPart = null;
        $base = ["_rels/.rels" => true];

        foreach ($rootRels as $rel) {
            if (str_ends_with($rel["type"], "/officeDocument")) {
                $workbookPart = $rel["target"];
            } elseif (preg_match('#/(core-properties|extended-properties|custom-properties)$#', $rel["type"])) {
                $base[$rel["target"]] = true;
            }
        }

        if ($workbookPart === null || !isset($result["sizes"][$workbookPart])) {
            $result["error"] = "ไฟล์ไม่ใช่ Excel Workbook (.xlsx) ที่ถูกต้อง";
            return $result;
        }

        $workbookDir = dirname($workbookPart) === "." ? "" : dirname($workbookPart);
        $workbookRelsPart = ($workbookDir === "" ? "" : $workbookDir . "/") . "_rels/" . basename($workbookPart) . ".rels";
        $base[$workbookPart] = true;
        $base[$workbookRelsPart] = true;

        $workbookRels = kpiCompanyParseRels(kpiCompanyReadMetaPart($zip, $result["sizes"], $workbookRelsPart), $workbookDir);
        $relTargets = [];

        foreach ($workbookRels as $rel) {
            $relTargets[$rel["id"]] = $rel["target"];
            if (preg_match('#/(styles|theme|sharedStrings)$#', $rel["type"])) {
                $base[$rel["target"]] = true;
            }
        }

        $workbookXml = kpiCompanyReadMetaPart($zip, $result["sizes"], $workbookPart);

        if ($workbookXml === null || !preg_match_all('/<(?:\w+:)?sheet\b([^>]*)\/?>/u', $workbookXml, $matches, PREG_SET_ORDER)) {
            $result["error"] = "ไม่พบรายการชีตในไฟล์";
            return $result;
        }

        if (preg_match('/<(?:\w+:)?calcPr\b([^>]*)\/?>/u', $workbookXml, $calcMatch)) {
            if (preg_match('/\bcalcMode="([^"]*)"/', $calcMatch[1], $mode)) {
                $result["calc"]["mode"] = strtolower($mode[1]);
            }
            $result["calc"]["full_calc_on_load"] = (bool) preg_match('/\bfullCalcOnLoad="(1|true)"/i', $calcMatch[1]);
        }

        foreach ($matches as $match) {
            if (!preg_match('/\bname="([^"]*)"/u', $match[1], $nameMatch) || !preg_match('/\br:id="([^"]*)"/u', $match[1], $idMatch)) {
                continue;
            }
            $name = html_entity_decode($nameMatch[1], ENT_QUOTES | ENT_XML1, "UTF-8");
            if (isset($relTargets[$idMatch[1]])) {
                $result["sheets"][$name] = $relTargets[$idMatch[1]];
            }
        }

        $result["base_parts"] = $base;
        return $result;

    } finally {
        $zip->close();
    }
}

/* อ่าน part เล็ก ๆ ที่ตัวอ่านต้องเปิดเอง (ตรวจขนาดก่อนแตกไฟล์) */
function kpiCompanyReadMetaPart(ZipArchive $zip, array $sizes, string $part): ?string
{
    $normalized = kpiCompanyNormalizePart($part);

    if (!isset($sizes[$normalized]) || $sizes[$normalized] > KPI_COMPANY_MAX_META_PART_BYTES) {
        return null;
    }

    $content = $zip->getFromName($part, 0, ZipArchive::FL_NOCASE);

    return $content === false ? null : $content;
}

/* Relationship ของ .rels → [["id","type","target"(normalized)]] */
function kpiCompanyParseRels(?string $xml, string $baseDir): array
{
    if ($xml === null || !preg_match_all('/<(?:\w+:)?Relationship\b([^>]*)\/?>/u', $xml, $matches)) {
        return [];
    }

    $rels = [];

    foreach ($matches[1] as $attributes) {
        preg_match('/\bId="([^"]*)"/', $attributes, $id);
        preg_match('/\bType="([^"]*)"/', $attributes, $type);
        preg_match('/\bTarget="([^"]*)"/', $attributes, $target);
        if (!$id || !$type || !$target || preg_match('/\bTargetMode="External"/', $attributes)) {
            continue;
        }
        $targetPath = html_entity_decode($target[1], ENT_QUOTES | ENT_XML1, "UTF-8");
        $full = str_starts_with($targetPath, "/") ? $targetPath : ($baseDir === "" ? $targetPath : $baseDir . "/" . $targetPath);
        $rels[] = ["id" => $id[1], "type" => $type[1], "target" => kpiCompanyNormalizePart($full)];
    }

    return $rels;
}

/* allowlist ของชีตที่เลือก + ตรวจเพดานขนาดรวม (ค่าเดิม 20 MB) */
function kpiCompanyAllowedParts(array $package, array $sheetNames): array
{
    $allowed = $package["base_parts"];

    foreach ($sheetNames as $name) {
        $part = $package["sheets"][$name];
        $dir = dirname($part) === "." ? "" : dirname($part);
        $allowed[$part] = true;
        $allowed[($dir === "" ? "" : $dir . "/") . "_rels/" . basename($part) . ".rels"] = true;
    }

    $total = 0;
    foreach (array_keys($allowed) as $part) {
        $total += $package["sizes"][$part] ?? 0;
    }

    return [
        "parts" => $allowed,
        "error" => $total > KPI_IMPORT_MAX_UNCOMPRESSED_BYTES ? "ส่วนของไฟล์ที่ต้องอ่านมีขนาดเกินกว่าที่ระบบรองรับ" : null
    ];
}


/*
|--------------------------------------------------------------------------
| 2) ตรวจสูตร (ไม่คำนวณใหม่ · ไล่อ้างอิงทุกชั้นในชีตที่โหลด)
|--------------------------------------------------------------------------
|
| คืน ["refs" => [[sheet, range]], "error" => ?string]
|
*/
function kpiCompanyFormulaReferences(string $formula, string $currentSheet): array
{
    $text = ltrim($formula, "=");
    $text = preg_replace('/"(?:[^"]|"")*"/u', '""', $text);

    if (str_contains($text, "[")) {
        return ["refs" => [], "error" => "สูตรอ้างอิงไฟล์ภายนอก (External Link) หรือตาราง"];
    }

    $refs = [];
    $cell = '\$?[A-Z]{1,3}\$?[0-9]+';
    $reference = "(?:{$cell}(?::{$cell})?|\\$?[A-Z]{1,3}:\\$?[A-Z]{1,3}|\\$?[0-9]+:\\$?[0-9]+)";

    $text = preg_replace_callback(
        "/(?:'((?:[^']|'')+)'|([^\\s'!(),=+\\-*\\/^&<>%\":;{}]+))!({$reference})/u",
        function ($m) use (&$refs) {
            $refs[] = [$m[1] !== "" ? str_replace("''", "'", $m[1]) : $m[2], $m[3]];
            return " 0 ";
        },
        $text
    );

    $text = preg_replace_callback(
        "/(?<![A-Za-z0-9_.\$])({$reference})(?![A-Za-z0-9_(])/u",
        function ($m) use (&$refs, $currentSheet) {
            $refs[] = [$currentSheet, $m[1]];
            return " 0 ";
        },
        $text
    );

    if (preg_match_all('/([A-Za-z_][A-Za-z0-9_.]*)\s*\(/u', $text, $functions)) {
        foreach ($functions[1] as $function) {
            if (!in_array(strtoupper($function), KPI_COMPANY_ALLOWED_FUNCTIONS, true)) {
                return ["refs" => [], "error" => "สูตรใช้ฟังก์ชัน " . strtoupper($function) . " ซึ่งระบบตรวจสอบค่าไม่ได้"];
            }
        }
    }

    $rest = preg_replace('/([A-Za-z_][A-Za-z0-9_.]*)\s*\(/u', "(", $text);
    $rest = preg_replace('/\b(TRUE|FALSE)\b/i', "0", $rest);
    $rest = preg_replace('/[0-9]+(\.[0-9]+)?(E[+-]?[0-9]+)?/i', "0", $rest);
    $rest = preg_replace('/[\s()+\-*\/^&<>=%,;:"0]/u', "", $rest);

    if ($rest !== "") {
        return ["refs" => [], "error" => "สูตรมีชื่อหรือรูปแบบที่ระบบตรวจสอบไม่ได้ (เช่น Defined Name)"];
    }

    foreach ($refs as [, $range]) {
        if (!preg_match('/[0-9]/', explode(":", $range)[0]) || !preg_match('/[A-Z]/', explode(":", $range)[0])) {
            return ["refs" => [], "error" => "สูตรอ้างอิงทั้งคอลัมน์หรือทั้งแถว ซึ่งระบบตรวจสอบไม่ได้"];
        }
    }

    return ["refs" => $refs, "error" => null];
}

/*
| ไล่ตรวจเซลล์ที่สูตรอ้างอิง (รวมทุกชั้น) · คืนข้อความ error หรือ null
| เซลล์ที่ยังไม่ได้โหลด (ชีตอื่นโหลดเฉพาะเซลล์ที่อ้างถึง) → ใส่ใน $ctx["need"][ชีต][เซลล์] ให้โหลดเพิ่มในรอบถัดไป
*/
function kpiCompanyCheckCell(Spreadsheet $spreadsheet, array $workbookSheets, string $sheetName, string $coordinate, array &$ctx, int $depth = 0): ?string
{
    $coordinate = str_replace("$", "", $coordinate);
    $canonical = kpiCompanySheetName($workbookSheets, $sheetName);

    if ($canonical === null) {
        return "สูตรอ้างอิงชีตที่ไม่มีในไฟล์";
    }

    $key = $canonical . "!" . $coordinate;

    if (isset($ctx["visited"][$key])) {
        return null;
    }

    $ctx["visited"][$key] = true;

    if (--$ctx["budget"] < 0 || $depth > 50) {
        return "สูตรอ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจสอบได้";
    }

    $sheet = $spreadsheet->getSheetByName($canonical);

    if ($sheet === null || ($canonical !== $ctx["form"] && !isset($ctx["loaded"][$canonical][$coordinate]))) {
        $ctx["need"][$canonical][$coordinate] = true;
        return null;
    }

    if (!$sheet->cellExists($coordinate)) {
        return null;
    }

    $cell = $sheet->getCell($coordinate);
    $type = $cell->getDataType();

    if ($type === DataType::TYPE_ERROR) {
        return "ข้อมูลต้นทางมีค่า Error ({$canonical}!{$coordinate})";
    }

    if ($type !== DataType::TYPE_FORMULA) {
        return null;
    }

    $cached = $cell->getOldCalculatedValue();

    if (is_string($cached) && str_starts_with($cached, "#")) {
        return "ข้อมูลต้นทางมีค่า Error ({$canonical}!{$coordinate})";
    }

    $parsed = kpiCompanyFormulaReferences((string) $cell->getValue(), $canonical);

    if ($parsed["error"] !== null) {
        return $parsed["error"] . " ({$canonical}!{$coordinate})";
    }

    foreach ($parsed["refs"] as [$refSheet, $range]) {

        $range = str_replace("$", "", $range);
        [$start, $end] = array_pad(explode(":", $range), 2, null);
        $end = $end ?? $start;
        [$c1, $r1] = Coordinate::coordinateFromString($start);
        [$c2, $r2] = Coordinate::coordinateFromString($end);
        $ci1 = Coordinate::columnIndexFromString($c1);
        $ci2 = Coordinate::columnIndexFromString($c2);
        $count = (abs($ci2 - $ci1) + 1) * (abs((int) $r2 - (int) $r1) + 1);

        if ($count > $ctx["budget"]) {
            return "สูตรอ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจสอบได้";
        }

        for ($col = min($ci1, $ci2); $col <= max($ci1, $ci2); $col++) {
            for ($row = min((int) $r1, (int) $r2); $row <= max((int) $r1, (int) $r2); $row++) {
                $error = kpiCompanyCheckCell(
                    $spreadsheet,
                    $workbookSheets,
                    $refSheet,
                    Coordinate::stringFromColumnIndex($col) . $row,
                    $ctx,
                    $depth + 1
                );
                if ($error !== null) {
                    return $error;
                }
            }
        }
    }

    return null;
}

/* ชื่อชีตจริงในไฟล์ (Excel ไม่สนตัวพิมพ์) */
function kpiCompanySheetName(array $workbookSheets, string $name): ?string
{
    foreach (array_keys($workbookSheets) as $sheet) {
        if (mb_strtolower((string) $sheet) === mb_strtolower($name)) {
            return (string) $sheet;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| 3) โครงสร้างแบบฟอร์ม KPI AM (หาจากข้อความ ไม่ยึดเลขแถว)
|--------------------------------------------------------------------------
*/
function kpiCompanyCellText(Worksheet $sheet, int $col, int $row): string
{
    $coordinate = Coordinate::stringFromColumnIndex($col) . $row;

    if (!$sheet->cellExists($coordinate)) {
        return "";
    }

    $value = $sheet->getCell($coordinate)->getValue();

    if ($value instanceof RichText) {
        $value = $value->getPlainText();
    }

    return is_scalar($value) ? trim(preg_replace('/\s+/u', " ", (string) $value)) : "";
}

function kpiCompanyFindLayout(Worksheet $sheet): array
{
    $layout = ["errors" => [], "employee_code_cell" => null, "sections" => []];
    $thaiMonths = monthlyPeriodMonths();
    $maxRow = min((int) $sheet->getHighestDataRow(), KPI_COMPANY_SCAN_ROWS);
    $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), KPI_COMPANY_SCAN_COLUMNS);

    for ($row = 1; $row <= min(15, $maxRow) && $layout["employee_code_cell"] === null; $row++) {
        for ($col = 1; $col <= $maxCol; $col++) {
            if (kpiCompanyCellText($sheet, $col, $row) === "รหัสพนักงาน") {
                $layout["employee_code_cell"] = Coordinate::stringFromColumnIndex($col) . ($row + 1);
                break;
            }
        }
    }

    if ($layout["employee_code_cell"] === null) {
        $layout["errors"][] = "ไม่พบช่อง \"รหัสพนักงาน\" ในชีต " . KPI_COMPANY_FORM_SHEET;
    }

    $markers = [];
    for ($row = 1; $row <= $maxRow; $row++) {
        $text = kpiCompanyCellText($sheet, 1, $row);
        foreach (["p_start" => "ส่วนที่ 1", "p_end" => "รวมคะแนนส่วนที่ 1", "c_start" => "ส่วนที่ 2", "c_end" => "รวมคะแนนส่วนที่ 2"] as $key => $prefix) {
            if (!isset($markers[$key]) && str_starts_with($text, $prefix)
                && !($key === "p_start" && str_starts_with($text, "ส่วนที่ 1 +"))) {
                $markers[$key] = $row;
            }
        }
    }

    foreach (["performance" => ["p_start", "p_end", "ส่วนที่ 1"], "competency" => ["c_start", "c_end", "ส่วนที่ 2"]] as $section => [$startKey, $endKey, $label]) {

        if (!isset($markers[$startKey], $markers[$endKey]) || $markers[$startKey] >= $markers[$endKey]) {
            $layout["errors"][] = "ไม่พบโครงสร้าง{$label} (หัวข้อและบรรทัดรวมคะแนน) ในชีต " . KPI_COMPANY_FORM_SHEET;
            continue;
        }

        $start = $markers[$startKey];
        $end = $markers[$endKey];
        $monthColumns = [];
        $headerRow = null;

        for ($row = $start; $row < $end && $headerRow === null; $row++) {
            $found = [];
            for ($col = 1; $col <= $maxCol; $col++) {
                $month = array_search(kpiCompanyCellText($sheet, $col, $row), $thaiMonths, true);
                if ($month !== false && !isset($found[$month])) {
                    $found[$month] = $col;
                }
            }
            if (count($found) === 12) {
                $headerRow = $row;
                $monthColumns = $found;
            }
        }

        if ($headerRow === null) {
            $layout["errors"][] = "ไม่พบหัวคอลัมน์เดือนครบ 12 เดือนใน{$label}";
            continue;
        }

        $valueHeaderRow = null;
        foreach ($monthColumns as $month => $col) {
            $match = null;
            for ($row = $headerRow + 1; $row <= $headerRow + 3; $row++) {
                if (kpiCompanyCellText($sheet, $col, $row) === "ที่ทำได้") {
                    $match = $row;
                    break;
                }
            }
            if ($match === null) {
                $layout["errors"][] = "ไม่พบหัวคอลัมน์ \"ที่ทำได้\" ของเดือน{$thaiMonths[$month]}ใน{$label}";
                continue 2;
            }
            $valueHeaderRow = max((int) $valueHeaderRow, $match);
        }

        $weightCol = null;
        for ($row = $start; $row <= $valueHeaderRow && $weightCol === null; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                if (str_starts_with(kpiCompanyCellText($sheet, $col, $row), "น้ำหนัก")) {
                    $weightCol = $col;
                    break;
                }
            }
        }

        $rows = [];
        for ($row = $valueHeaderRow + 1; $row < $end; $row++) {
            if (kpiCompanyCellText($sheet, 1, $row) !== "") {
                $rows[] = $row;
            }
        }

        $layout["sections"][$section] = [
            "month_columns" => $monthColumns,
            "weight_column" => $weightCol,
            "rows" => $rows
        ];
    }

    return $layout;
}

/* ปีจากชื่อชีต เช่น "สรุปทั้งปี69", "ม.ค.69" → ค.ศ. 2026 */
function kpiCompanyYearHints(array $sheetNames): array
{
    $years = [];

    foreach ($sheetNames as $name) {
        if (preg_match('/(?:ปี|\.)\s*(\d{2})\s*$/u', (string) $name, $m)) {
            $years[2500 + (int) $m[1] - 543] = true;
        }
    }

    return array_keys($years);
}


/*
|--------------------------------------------------------------------------
| 4) อ่านไฟล์บริษัท
|--------------------------------------------------------------------------
|
| คืน [
|   "errors" => [ข้อความระดับไฟล์], "notes" => [...], "sheets_loaded" => [...],
|   "items"  => [แต่ละ KPI × เดือนที่มีข้อมูล: section, label, month, source, value, kpi_id, status, reasons],
|   "skipped_empty" => จำนวนช่องเดือนที่ว่าง (ไม่นำเข้า),
|   "rows"   => [รูปแบบภายในเดียวกับ kpiImportReadFile() เฉพาะรายการ READY]
| ]
| $map = null → ใช้ includes/kpi-import-company-map.php (สคริปต์ทดสอบส่ง mapping สังเคราะห์ได้)
|
*/
function kpiCompanyRead(string $path, int $year, string $sessionEmployeeCode, ?array $map = null, ?int $onlyMonth = null, ?DateTimeInterface $now = null): array
{
    $result = ["errors" => [], "notes" => [], "sheets_loaded" => [], "items" => [], "skipped_empty" => 0, "rows" => []];
    $map = $map ?? require __DIR__ . "/kpi-import-company-map.php";
    $thaiMonths = monthlyPeriodMonths();

    if ($year < 2000 || $year > 2100) {
        $result["errors"][] = "กรุณาเลือกปีของข้อมูลให้ถูกต้อง";
        return $result;
    }

    if ($onlyMonth !== null && ($onlyMonth < 1 || $onlyMonth > 12)) {
        $result["errors"][] = "กรุณาเลือกเดือนของข้อมูลให้ถูกต้อง";
        return $result;
    }

    $package = kpiCompanyInspectPackage($path);

    if ($package["error"] !== null) {
        $result["errors"][] = $package["error"];
        return $result;
    }

    $formSheet = kpiCompanySheetName($package["sheets"], KPI_COMPANY_FORM_SHEET);

    if ($formSheet === null) {
        $result["errors"][] = "ไม่พบชีต \"" . KPI_COMPANY_FORM_SHEET . "\" ในไฟล์";
        return $result;
    }

    $hints = kpiCompanyYearHints(array_keys($package["sheets"]));

    if (count($hints) > 1) {
        $result["errors"][] = "ชื่อชีตในไฟล์ระบุปีมากกว่าหนึ่งปี (ค.ศ. " . implode(", ", $hints) . ") กรุณาแยกไฟล์ตามปี";
        return $result;
    }

    if ($hints && $hints[0] !== $year) {
        $result["errors"][] = "ปีในชื่อชีตของไฟล์ (ค.ศ. {$hints[0]}) ไม่ตรงกับปีที่เลือก (ค.ศ. {$year})";
        return $result;
    }

    if ($onlyMonth !== null) {
        $result["notes"][] = "นำเข้าเฉพาะเดือน" . monthlyPeriodMonths()[$onlyMonth] . " {$year} · ข้อมูลเดือนอื่นในไฟล์ไม่ถูกอ่านและไม่ถูกบันทึก";
    }

    $result["notes"][] = $hints
        ? "ปีของข้อมูล: ค.ศ. {$year} (ตรงกับชื่อชีตในไฟล์)"
        : "ไม่พบปีในชื่อชีตของไฟล์ ใช้ปีที่เลือก ค.ศ. {$year}";

    $cellSet = [];   // ชีตอื่น => [เซลล์ => true] ที่สูตรอ้างถึง
    $evaluation = null;

    for ($pass = 1; $pass <= KPI_COMPANY_MAX_LOAD_PASSES; $pass++) {

        $sheetsToLoad = array_merge([$formSheet], array_keys($cellSet));
        $allow = kpiCompanyAllowedParts($package, $sheetsToLoad);

        if ($allow["error"] !== null) {
            $result["errors"][] = $allow["error"];
            return $result;
        }

        try {
            $reader = new KpiCompanyXlsxReader();
            $reader->setAllowedParts($allow["parts"]);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly($sheetsToLoad);
            $reader->setReadFilter(new KpiCompanyCellFilter($formSheet, $cellSet));
            $spreadsheet = $reader->load($path);
        } catch (Throwable $e) {
            error_log("kpi-import company: load failed (" . get_class($e) . ")");
            $result["errors"][] = "อ่านไฟล์ไม่สำเร็จ ไฟล์อาจเสียหายหรือมีโครงสร้างที่ระบบไม่รองรับ";
            return $result;
        }

        if ($pass === 1) {

            $form = $spreadsheet->getSheetByName($formSheet);
            $layout = kpiCompanyFindLayout($form);

            if ($layout["errors"]) {
                $result["errors"] = array_merge($result["errors"], $layout["errors"]);
                return $result;
            }

            /* ตัวตน: ต้องตรงกับผู้ใช้ทุกตัวอักษร (ไม่แสดงข้อมูลใดของไฟล์ถ้าไม่ตรง) */
            $codeCell = $form->getCell($layout["employee_code_cell"]);
            $fileCode = $codeCell->getDataType() === DataType::TYPE_FORMULA ? null : $codeCell->getValue();
            $fileCode = is_scalar($fileCode) ? (string) $fileCode : "";

            if ($fileCode !== $sessionEmployeeCode) {
                $result["errors"][] = trim($fileCode) === $sessionEmployeeCode && $fileCode !== ""
                    ? "รหัสพนักงานในไฟล์มีช่องว่างเกิน รหัสต้องตรงกับผู้ใช้ที่เข้าสู่ระบบทุกตัวอักษร"
                    : "รหัสพนักงานในไฟล์ไม่ตรงกับผู้ใช้ที่เข้าสู่ระบบ (นำเข้าได้เฉพาะข้อมูลของตนเอง)";
                return $result;
            }
        }

        $evaluation = kpiCompanyEvaluate($spreadsheet, $package, $formSheet, $layout, $map, $year, $sessionEmployeeCode, $cellSet, false, $onlyMonth, $now);
        $result["sheets_loaded"] = $sheetsToLoad;

        $missing = [];
        foreach ($evaluation["need"] as $sheet => $cells) {
            foreach (array_keys($cells) as $coordinate) {
                if (!isset($cellSet[$sheet][$coordinate])) {
                    $missing[$sheet][$coordinate] = true;
                }
            }
        }

        if (!$missing) {
            break;
        }

        $merged = array_replace_recursive($cellSet, $missing);
        $totalCells = array_sum(array_map("count", $merged));

        if (count($merged) + 1 > KPI_COMPANY_MAX_SHEETS || $totalCells > KPI_COMPANY_MAX_DEPENDENCY_CELLS || $pass === KPI_COMPANY_MAX_LOAD_PASSES) {
            $evaluation = kpiCompanyEvaluate($spreadsheet, $package, $formSheet, $layout, $map, $year, $sessionEmployeeCode, $cellSet, true, $onlyMonth, $now);
            break;
        }

        $cellSet = $merged;
        $spreadsheet->disconnectWorksheets();
    }

    $result["loaded_cells"] = array_map("count", $cellSet);
    $result["calc"] = $package["calc"];

    if ($package["calc"]["mode"] === "manual" || $package["calc"]["full_calc_on_load"]) {
        $result["notes"][] = "คำเตือน: ไฟล์ตั้งค่าการคำนวณแบบ " . ($package["calc"]["mode"] === "manual" ? "Manual" : "คำนวณใหม่เมื่อเปิดไฟล์")
            . " ค่าจากสูตรที่เก็บในไฟล์อาจไม่ใช่ค่าล่าสุด รายการที่มาจากสูตรจึงยังนำเข้าไม่ได้";
    }

    $result["items"] = $evaluation["items"];
    $result["skipped_empty"] = $evaluation["skipped_empty"];
    $result["rows"] = $evaluation["rows"];
    $result["notes"] = array_merge($result["notes"], $evaluation["notes"]);
    $result["notes"][] = "อ่านแบบฟอร์ม " . KPI_COMPANY_FORM_SHEET
        . ($result["loaded_cells"] ? " และเฉพาะเซลล์ที่สูตรอ้างถึงในชีต: " . implode(", ", array_map(
            fn($sheet, $count) => trim((string) $sheet) . " ({$count} เซลล์)",
            array_keys($result["loaded_cells"]),
            $result["loaded_cells"]
        )) : "")
        . " · ไม่อ่านคอลัมน์เกรด/คะแนนจริงจาก Excel";

    return $result;
}

/* ประเมินทุกช่อง "ที่ทำได้" ในชีตที่โหลด · $final = true → ชีตที่ยังขาดถือว่าตรวจไม่ได้ */
function kpiCompanyEvaluate(Spreadsheet $spreadsheet, array $package, string $formSheet, array $layout, array $map, int $year, string $employeeCode, array $loadedCells, bool $final = false, ?int $onlyMonth = null, ?DateTimeInterface $now = null): array
{
    $workbookSheets = $package["sheets"];
    $calcUnreliable = $package["calc"]["mode"] === "manual" || $package["calc"]["full_calc_on_load"];
    $form = $spreadsheet->getSheetByName($formSheet);
    $thaiMonths = monthlyPeriodMonths();
    $out = ["items" => [], "rows" => [], "need" => [], "skipped_empty" => 0, "notes" => []];

    $mapIndex = [];
    $confirmedIds = [];
    foreach ($map as $entry) {
        $mapIndex[$entry["section"] . "|" . kpiCompanyNormalizeLabel((string) $entry["label"])][] = $entry;
        if (!empty($entry["confirmed"]) && is_int($entry["kpi_id"] ?? null)) {
            $confirmedIds[$entry["kpi_id"]] = ($confirmedIds[$entry["kpi_id"]] ?? 0) + 1;
        }
    }

    $labelCount = [];
    foreach ($layout["sections"] as $section => $info) {
        foreach ($info["rows"] as $row) {
            $key = $section . "|" . kpiCompanyNormalizeLabel(kpiCompanyCellText($form, 1, $row));
            $labelCount[$key] = ($labelCount[$key] ?? 0) + 1;
        }
    }

    $sequence = 0;

    foreach ($layout["sections"] as $section => $info) {

        foreach ($info["rows"] as $row) {

            $rawLabel = kpiCompanyCellText($form, 1, $row);
            $label = kpiCompanyNormalizeLabel($rawLabel);
            $weight = null;

            if ($info["weight_column"] !== null) {
                $weightValue = $form->getCell(Coordinate::stringFromColumnIndex($info["weight_column"]) . $row)->getValue();
                $weight = is_numeric($weightValue) ? (float) $weightValue : null;
            }

            $entries = $mapIndex[$section . "|" . $label] ?? [];

            /* หัวข้อที่ยืนยันกฎ "การต่อสัญญา": อ่านจากชีตสรุปรายเดือนแทนช่อง "ที่ทำได้" ในแบบฟอร์ม */
            if (count($entries) === 1 && ($entries[0]["value_rule"] ?? null) === KPI_COMPANY_RENEWAL_RULE) {
                $mappingReasons = kpiCompanyMappingReasons(
                    $section, $label, $weight, $entries[0], ($labelCount[$section . "|" . $label] ?? 0) > 1, $confirmedIds
                );
                kpiCompanyEvaluateRenewal(
                    $spreadsheet, $workbookSheets, $formSheet, $loadedCells, $calcUnreliable, $final,
                    $section, $rawLabel, $weight, $entries[0], $mappingReasons, $year, $employeeCode, $out, $sequence, $onlyMonth
                );
                continue;
            }

            /* KPI 9 "มูลค่าไม่ต่อสัญญา": อ่านจากตารางจำนวนรายของชีตสรุป (T = G/C · มกราคม = T23) */
            if (count($entries) === 1 && ($entries[0]["value_rule"] ?? null) === KPI_COMPANY_NON_RENEWAL_RULE) {
                $mappingReasons = kpiCompanyMappingReasons(
                    $section, $label, $weight, $entries[0], ($labelCount[$section . "|" . $label] ?? 0) > 1, $confirmedIds
                );
                kpiCompanyEvaluateNonRenewalCount(
                    $spreadsheet, $workbookSheets, $formSheet, $loadedCells, $calcUnreliable, $final,
                    $section, $rawLabel, $weight, $entries[0], $mappingReasons, $year, $employeeCode, $out, $sequence, $onlyMonth
                );
                continue;
            }

            /* หัวข้อที่กำหนดกฎ "ปรับเพิ่มมูลค่าสัญญา" (KPI 8): อ่านจากชีตของเดือนแทนช่อง "ที่ทำได้" ในแบบฟอร์ม */
            if (count($entries) === 1 && ($entries[0]["value_rule"] ?? null) === KPI_COMPANY_UPLIFT_RULE) {
                $mappingReasons = kpiCompanyMappingReasons(
                    $section, $label, $weight, $entries[0], ($labelCount[$section . "|" . $label] ?? 0) > 1, $confirmedIds
                );
                kpiCompanyEvaluateUplift(
                    $spreadsheet, $workbookSheets, $formSheet, $loadedCells, $calcUnreliable, $final,
                    $section, $rawLabel, $weight, $entries[0], $mappingReasons, $year, $employeeCode, $out, $sequence, $onlyMonth, $now
                );
                continue;
            }

            foreach ($info["month_columns"] as $month => $col) {

                /* เลือกเดือนตอนอัปโหลด → ไม่อ่านเดือนอื่น (ไม่ตรวจ ไม่นับ ไม่บันทึก) */
                if ($onlyMonth !== null && $month !== $onlyMonth) {
                    continue;
                }

                $coordinate = Coordinate::stringFromColumnIndex($col) . $row;

                if (!$form->cellExists($coordinate)) {
                    $out["skipped_empty"]++;
                    continue;
                }

                $cell = $form->getCell($coordinate);
                $type = $cell->getDataType();
                $value = $cell->getValue();
                $reasons = [];
                $warnings = [];
                $blockers = [];
                $kind = "ค่าที่พิมพ์";
                $formulaText = null;

                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                }

                if ($type === DataType::TYPE_FORMULA) {

                    $kind = "ค่าจากสูตร (คำนวณโดย Excel)";
                    $formulaText = (string) $value;
                    $value = $cell->getOldCalculatedValue();

                    /* สูตรที่ให้ข้อความว่าง = ไม่มีข้อมูลเดือนนั้น · ไม่มีค่าที่ Excel คำนวณเก็บไว้เลย = ยืนยันค่าไม่ได้ */
                    if ($value === "") {
                        $out["skipped_empty"]++;
                        continue;
                    }

                    if ($value === null) {
                        $reasons[] = "ไม่มีค่าที่ Excel คำนวณเก็บไว้ในไฟล์ (เปิดและบันทึกไฟล์ใน Excel ก่อน)";
                    }

                    $warnings[] = "ค่าจากสูตรเป็นค่าที่ Excel คำนวณไว้เมื่อบันทึกไฟล์ครั้งล่าสุด ระบบไม่คำนวณใหม่และยืนยันไม่ได้ว่าเป็นค่าปัจจุบัน";

                    if ($calcUnreliable) {
                        $blockers[] = "ไฟล์ตั้งค่าการคำนวณที่ทำให้ค่าจากสูตรอาจไม่ใช่ค่าล่าสุด";
                    }

                    $ctx = [
                        "visited" => [], "budget" => KPI_COMPANY_MAX_DEPENDENCY_CELLS, "need" => [],
                        "form" => $formSheet, "loaded" => $loadedCells
                    ];
                    $error = kpiCompanyCheckCell($spreadsheet, $workbookSheets, $formSheet, $coordinate, $ctx);

                    if ($error !== null) {
                        $reasons[] = $error;
                    } elseif ($ctx["need"]) {
                        if ($final) {
                            $reasons[] = "ตรวจสอบข้อมูลต้นทางของสูตรไม่ครบ (อ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจได้)";
                        } else {
                            foreach ($ctx["need"] as $needSheet => $needCells) {
                                $out["need"][$needSheet] = ($out["need"][$needSheet] ?? []) + $needCells;
                            }
                        }
                    }

                } elseif ($value === null || (is_string($value) && trim($value) === "")) {
                    $out["skipped_empty"]++;
                    continue;
                } elseif ($type === DataType::TYPE_ERROR) {
                    $reasons[] = "เซลล์มีค่า Error";
                }

                if ($value === null) {
                    // เหตุผลบันทึกไว้แล้วด้านบน
                } elseif (is_string($value) && str_starts_with($value, "#")) {
                    $reasons[] = "เซลล์มีค่า Error";
                } elseif (is_bool($value) || !(is_int($value) || is_float($value)) || (is_float($value) && !is_finite($value))) {
                    $reasons[] = "ค่าในเซลล์ไม่ใช่ตัวเลข";
                }

                $numeric = (is_int($value) || is_float($value)) ? $value + 0 : null;

                if ($section === "competency" && $numeric !== null
                    && (floor((float) $numeric) !== (float) $numeric || $numeric < 1 || $numeric > 5)) {
                    $reasons[] = "คะแนน Competency ต้องเป็นจำนวนเต็ม 1-5";
                }

                $mapping = count($entries) === 1 ? $entries[0] : null;
                $mappingReasons = array_merge(
                    $blockers,
                    kpiCompanyMappingReasons($section, $label, $weight, $mapping, ($labelCount[$section . "|" . $label] ?? 0) > 1, $confirmedIds)
                );

                /* KPI 10 / 11 / 12: Actual = ค่าในช่อง × 100 (ไม่ปัดเศษ · ดู KPI_COMPANY_FORM_RATIO_RULE) */
                $formRatio = $section === "performance" && ($mapping["value_rule"] ?? null) === KPI_COMPANY_FORM_RATIO_RULE;
                $actual = null;
                $details = [];

                if ($formRatio && $numeric !== null && !$reasons) {
                    $ratioText = kpiCompanyPlainDecimal((float) $numeric);
                    $percent = $ratioText === null || !function_exists("bcmul") ? null : bcmul($ratioText, "100", 40);
                    $percent = $percent === null ? null : rtrim(rtrim($percent, "0"), ".");

                    if ($percent === null) {
                        $reasons[] = "คำนวณค่า × 100 ไม่ได้ (ต้องมีส่วนขยาย BCMath บนเซิร์ฟเวอร์)";
                    } elseif (str_starts_with($percent, "-")) {
                        $reasons[] = "ค่าในช่อง {$coordinate} ติดลบ ระบบไม่บันทึกค่าติดลบ";
                    } elseif (str_contains($percent, ".") && strlen($percent) - strpos($percent, ".") - 1 > 2) {
                        $reasons[] = "ค่าในช่อง {$coordinate} × 100 มีทศนิยมเกิน 2 ตำแหน่ง ระบบไม่ปัดเศษ กรุณาแก้ไขไฟล์";
                    } else {
                        $actual = $percent === "" ? "0" : $percent;
                        $details = [
                            "rule" => KPI_COMPANY_FORM_RATIO_RULE, "cell" => $coordinate,
                            "ratio" => rtrim(rtrim(number_format((float) $numeric, 6, ".", ""), "0"), "."), "actual" => $actual
                        ];
                        $warnings[] = "ค่าในช่อง {$coordinate} = " . $details["ratio"] . " (สัดส่วนใน Excel) × 100 → Actual {$actual}%";
                    }
                }

                $status = $reasons
                    ? KPI_COMPANY_STATUS_REJECTED
                    : ($mappingReasons ? KPI_COMPANY_STATUS_NEEDS_CONFIRMATION : KPI_COMPANY_STATUS_READY);

                $item = [
                    "mapping_ready" => !$mappingReasons,
                    "confirmed_kpi_name" => $mapping["confirmed_kpi_name"] ?? null,
                    "action" => null,
                    "section" => $section,
                    "label" => $rawLabel,
                    "weight" => $weight,
                    "month" => $month,
                    "month_label" => $thaiMonths[$month] . " " . $year,
                    "source" => $formSheet . "!" . $coordinate,
                    "kind" => $kind,
                    "formula" => $formulaText,
                    "value" => $formRatio && $actual !== null ? $actual : ($numeric ?? (is_scalar($value) ? (string) $value : null)),
                    "kpi_id" => $mapping["kpi_id"] ?? null,
                    "status" => $status,
                    "reasons" => array_merge($reasons, $mappingReasons),
                    "warnings" => $warnings,
                    "details" => $details
                ];
                if ($formRatio) {
                    $item["value_rule"] = KPI_COMPANY_FORM_RATIO_RULE;
                }

                $out["items"][] = $item;

                if ($status === KPI_COMPANY_STATUS_READY) {
                    $out["items"][array_key_last($out["items"])]["row"] = $sequence + 1;
                    $out["rows"][] = [
                        "row" => ++$sequence,
                        "source" => $item["source"],
                        "values" => [
                            "employee_code" => $employeeCode,
                            "kpi_id" => (int) $mapping["kpi_id"],
                            "year" => $year,
                            "month" => $month,
                            "actual" => $formRatio ? $actual : null,
                            "competency_score" => $formRatio ? null : (int) $numeric,
                            "note" => null
                        ],
                        "errors" => []
                    ];
                }
            }
        }
    }

    return $out;
}

/* เหตุผลที่ Mapping ของหัวข้อยังใช้นำเข้าไม่ได้ (ว่าง = ยืนยันครบ) */
function kpiCompanyMappingReasons(string $section, string $label, ?float $weight, ?array $mapping, bool $duplicateLabel, array $confirmedIds): array
{
    $reasons = [];

    /*
    | KPI 9 นำเข้าได้เฉพาะกฎ KPI_COMPANY_NON_RENEWAL_RULE (T = G/C ของตารางจำนวนรายในชีตสรุป) ที่หัวข้อ ชื่อ และ kpi_id ตรงกันทั้งหมด
    | และกฎนี้ใช้กับ KPI อื่นไม่ได้ · ไม่ตรง → ไม่นำเข้า
    */
    $isKpi9 = $label === kpiCompanyNormalizeLabel(NON_RENEWAL_KPI_NAME) || (int) ($mapping["kpi_id"] ?? 0) === NON_RENEWAL_KPI_ID;
    if (
        ($isKpi9 || ($mapping["value_rule"] ?? null) === KPI_COMPANY_NON_RENEWAL_RULE)
        && (
            $section !== "performance"
            || $label !== kpiCompanyNormalizeLabel(NON_RENEWAL_KPI_NAME)
            || ($mapping["kpi_id"] ?? null) !== NON_RENEWAL_KPI_ID
            || ($mapping["value_rule"] ?? null) !== KPI_COMPANY_NON_RENEWAL_RULE
            || (string) ($mapping["confirmed_kpi_name"] ?? "") !== NON_RENEWAL_KPI_NAME
        )
    ) {
        $reasons[] = "KPI 9 (" . NON_RENEWAL_KPI_NAME . ") นำเข้าได้เฉพาะกฎ T = G/C ของตารางจำนวนรายในชีตสรุป"
            . " และกฎนี้ใช้ได้เฉพาะหัวข้อ / KPI \"" . NON_RENEWAL_KPI_NAME . "\" (kpi_id " . NON_RENEWAL_KPI_ID . ")";
    }

    if ($duplicateLabel) {
        $reasons[] = "หัวข้อนี้ซ้ำในแบบฟอร์ม";
    } elseif ($mapping === null) {
        $reasons[] = "ยังไม่มีการจับคู่หัวข้อนี้กับ KPI ในระบบ";
    } else {
        if ($weight === null || abs($weight - (float) $mapping["expected_weight"]) > 0.0001) {
            $reasons[] = "น้ำหนักในแบบฟอร์ม (" . ($weight ?? "-") . ") ไม่ตรงกับที่ยืนยันไว้ (" . $mapping["expected_weight"] . ")";
        }
        if (empty($mapping["confirmed"]) || !is_int($mapping["kpi_id"] ?? null)) {
            $reasons[] = "ยังไม่ได้ยืนยันว่าหัวข้อนี้คือ KPI ใดในระบบ";
        } elseif (($confirmedIds[$mapping["kpi_id"]] ?? 0) > 1) {
            $reasons[] = "kpi_id {$mapping["kpi_id"]} ถูกจับคู่กับหลายหัวข้อใน mapping";
        } elseif (
            trim((string) ($mapping["confirmed_kpi_name"] ?? "")) === ""
            || trim((string) ($mapping["confirmed_by"] ?? "")) === ""
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($mapping["confirmed_at"] ?? ""))
        ) {
            $reasons[] = "ข้อมูลการยืนยันไม่ครบ (ชื่อ KPI ในระบบ / ผู้ยืนยัน / วันที่ยืนยัน)";
        } elseif ($section === "competency" && ($mapping["value_rule"] ?? null) !== "competency_integer") {
            $reasons[] = "ยังไม่ได้ยืนยันกฎแปลงค่าของหัวข้อนี้";
        } elseif (
            $section === "performance"
            && !in_array($mapping["value_rule"] ?? null, [KPI_COMPANY_RENEWAL_RULE, KPI_COMPANY_UPLIFT_RULE, KPI_COMPANY_FORM_RATIO_RULE, KPI_COMPANY_NON_RENEWAL_RULE], true)
        ) {
            $reasons[] = "ยังไม่ได้ยืนยันกฎแปลงค่า (หน่วย / Target) ของ KPI Performance";
        } elseif (
            $section === "performance"
            && $mapping["value_rule"] === KPI_COMPANY_RENEWAL_RULE
            && ($label !== kpiCompanyNormalizeLabel(KPI_COMPANY_RENEWAL_LABEL) || (string) $mapping["confirmed_kpi_name"] !== KPI_COMPANY_RENEWAL_LABEL)
        ) {
            /* สูตรของ KPI การต่อสัญญาใช้กับ KPI อื่นไม่ได้ (ทั้งหัวข้อในแบบฟอร์มและชื่อ KPI ในระบบต้องเป็นตัวนี้) */
            $reasons[] = "กฎแปลงค่าการต่อสัญญาใช้ได้เฉพาะหัวข้อและ KPI \"" . KPI_COMPANY_RENEWAL_LABEL . "\"";
        } elseif (
            $section === "performance"
            && $mapping["value_rule"] === KPI_COMPANY_UPLIFT_RULE
            && (
                $label !== kpiCompanyNormalizeLabel(KPI_COMPANY_UPLIFT_LABEL)
                || (string) $mapping["confirmed_kpi_name"] !== KPI_COMPANY_UPLIFT_LABEL
                || $mapping["kpi_id"] !== KPI_COMPANY_UPLIFT_KPI_ID
            )
        ) {
            /* สูตรของ KPI ปรับเพิ่มมูลค่าสัญญาใช้ได้เฉพาะหัวข้อนี้ ชื่อ KPI นี้ และ kpi_id 8 */
            $reasons[] = "กฎแปลงค่าการปรับเพิ่มมูลค่าสัญญาใช้ได้เฉพาะหัวข้อและ KPI \"" . KPI_COMPANY_UPLIFT_LABEL
                . "\" (kpi_id " . KPI_COMPANY_UPLIFT_KPI_ID . ")";
        } elseif (
            $section === "performance"
            && $mapping["value_rule"] === KPI_COMPANY_FORM_RATIO_RULE
            && !in_array($mapping["kpi_id"], KPI_COMPANY_FORM_RATIO_KPI_IDS, true)
        ) {
            /* กฎ "ช่องที่ทำได้ × 100" ยืนยันไว้เฉพาะ KPI 10 / 11 / 12 */
            $reasons[] = "กฎแปลงค่า \"ช่องที่ทำได้ × 100\" ใช้ได้เฉพาะ kpi_id " . implode(", ", KPI_COMPANY_FORM_RATIO_KPI_IDS);
        }
    }

    return $reasons;
}

/* สูตรในรูปแบบเทียบได้ (ไม่สนช่องว่าง / $ / ตัวพิมพ์) */
function kpiCompanyFormulaKey(?string $formula): string
{
    return strtoupper(str_replace([" ", "$"], "", (string) $formula));
}

/* ค่าจริงมีทศนิยมไม่เกิน 2 ตำแหน่ง (เผื่อความคลาดเคลื่อนของ float เล็กน้อย ไม่ได้ปัดค่า) */
function kpiCompanyHasAtMostTwoDecimals(float $value): bool
{
    $cents = $value * 100;

    return abs($cents - round($cents)) <= 1e-4;
}

/* สูตรที่เป็นการอ้างอิงเซลล์เดียวข้ามชีต เช่น ='ม.ค.69'!O41 → [sheet, col, row] · รูปแบบอื่น → null */
function kpiCompanySingleCellRef(?string $formula): ?array
{
    if ($formula === null || !preg_match(
        '/^=\s*(?:\'((?:[^\']|\'\')+)\'|([^\s\'!(),=+\-*\/^&<>%";:{}\[\]]+))!\$?([A-Z]{1,3})\$?([0-9]+)\s*$/u',
        $formula,
        $m
    )) {
        return null;
    }

    return ["sheet" => $m[1] !== "" ? str_replace("''", "'", $m[1]) : $m[2], "col" => $m[3], "row" => (int) $m[4]];
}

/*
| แหล่งข้อมูลของแถวเดือนในชีตสรุป (KPI การต่อสัญญา) ต้องตรงกับโครงสร้างที่ยืนยัน:
|   ชีตเดือน = "<ตัวย่อเดือน>.<ปี พ.ศ. 2 หลัก>" ของเดือนนั้น (จับคู่ด้วยการตัดช่องว่างหัว/ท้ายเท่านั้น และต้องพบชีตเดียว)
|   C (Budget) = '<ชีตเดือน>'!O<n>  และ  O<n> = SUM(O5:O<n-2>)
|   D / E / F  = '<ชีตเดือน>'!D<k> / D<k+1> / D<k+2>  และในชีตเดือน D<k-1> = O<n> (Budget Renew),
|                D<k> = AB<n>, D<k+1> = AC<n>, D<k+2> = AD<n> (Renew 1Y / 2Y / Bait 3Y)
| คืน ["reasons" => [...], "need" => [ชีต => [เซลล์ => true]]] · need ไม่ว่าง = ยังโหลดเซลล์ต้นทางไม่ครบ (ตรวจในรอบถัดไป)
*/
function kpiCompanyRenewalSourceReasons(Spreadsheet $spreadsheet, array $workbookSheets, array $loadedCells, Worksheet $summary, int $row, int $month, string $monthName, int $year): array
{
    $out = ["reasons" => [], "need" => []];
    $expected = KPI_COMPANY_RENEWAL_MONTH_SHEETS[$month] . "." . sprintf("%02d", ($year + 543) % 100);
    $matches = array_values(array_filter(
        array_map("strval", array_keys($workbookSheets)),
        fn($name) => trim($name) === $expected
    ));

    if (count($matches) !== 1) {
        $out["reasons"][] = "ไม่พบชีตของเดือน{$monthName} (\"{$expected}\") หรือพบมากกว่าหนึ่งชีต";
        return $out;
    }

    $sheetName = $matches[0];
    $summaryFormula = function (string $coordinate) use ($summary): ?string {
        if (!$summary->cellExists($coordinate)) {
            return null;
        }
        $cell = $summary->getCell($coordinate);
        return $cell->getDataType() === DataType::TYPE_FORMULA ? (string) $cell->getValue() : null;
    };

    $budgetRef = kpiCompanySingleCellRef($summaryFormula("C{$row}"));

    if ($budgetRef === null || $budgetRef["sheet"] !== $sheetName || $budgetRef["col"] !== "O" || $budgetRef["row"] < 7) {
        $out["reasons"][] = "เดือน{$monthName}: Budget (C{$row}) ต้องเป็นสูตรอ้างอิงเซลล์คอลัมน์ O ของชีต \"{$expected}\" ตามที่ยืนยัน"
            . " (ไม่รับค่าที่พิมพ์เอง สูตรอื่น หรือการอ้างชีตของเดือนอื่น)";
    }

    $renewRefs = [];
    foreach (["D", "E", "F"] as $offset => $col) {
        $ref = kpiCompanySingleCellRef($summaryFormula("{$col}{$row}"));
        $renewRefs[] = $ref;
        if ($ref === null || $ref["sheet"] !== $sheetName || $ref["col"] !== "D" || $ref["row"] !== ($renewRefs[0]["row"] ?? -1) + $offset) {
            $out["reasons"][] = "เดือน{$monthName}: Renew ({$col}{$row}) ต้องอ้างเซลล์คอลัมน์ D ที่ต่อเนื่องกันในชีต \"{$expected}\" ตามที่ยืนยัน"
                . " (ไม่รับค่าที่พิมพ์เองหรือการอ้างชีตของเดือนอื่น)";
            break;
        }
    }

    if ($out["reasons"]) {
        return $out;
    }

    $n = $budgetRef["row"];
    $k = $renewRefs[0]["row"];
    $required = ["O{$n}", "D" . ($k - 1), "D{$k}", "D" . ($k + 1), "D" . ($k + 2)];
    $missing = array_values(array_filter($required, fn($c) => !isset($loadedCells[$sheetName][$c])));

    if ($missing) {
        $out["need"][$sheetName] = array_fill_keys($missing, true);
        return $out;
    }

    $monthSheet = $spreadsheet->getSheetByName($sheetName);
    $key = function (string $coordinate) use ($monthSheet): ?string {
        if ($monthSheet === null || !$monthSheet->cellExists($coordinate)) {
            return null;
        }
        $cell = $monthSheet->getCell($coordinate);
        return $cell->getDataType() === DataType::TYPE_FORMULA ? kpiCompanyFormulaKey((string) $cell->getValue()) : null;
    };

    if ($key("O{$n}") !== "=SUM(O5:O" . ($n - 2) . ")") {
        $out["reasons"][] = "เดือน{$monthName}: เซลล์ Budget ต้นทาง ({$expected}!O{$n}) ต้องเป็นสูตร =SUM(O5:O" . ($n - 2) . ") ตามที่ยืนยัน";
    }

    if (
        $key("D" . ($k - 1)) !== "=O{$n}"
        || $key("D{$k}") !== "=AB{$n}"
        || $key("D" . ($k + 1)) !== "=AC{$n}"
        || $key("D" . ($k + 2)) !== "=AD{$n}"
    ) {
        $out["reasons"][] = "เดือน{$monthName}: บล็อก Renew ในชีต \"{$expected}\" (D" . ($k - 1) . ":D" . ($k + 2) . ") ไม่ตรงกับโครงสร้างที่ยืนยัน"
            . " (Budget Renew = O{$n} · Renew 1Y / 2Y / Bait 3Y = AB / AC / AD{$n})";
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| KPI "บรรลุเป้าหมายการต่อสัญญา": อ่านจากชีตสรุปรายเดือน (กฎ KPI_COMPANY_RENEWAL_RULE)
|--------------------------------------------------------------------------
|
| ต่อเดือน (แถวที่คอลัมน์ B = ชื่อเดือน):
|   ว่าง (Budget และ Total R ว่าง/0) → ข้าม เหมือนเดือนที่ว่างในแบบฟอร์ม
|   ต้องเป็น Q = G/C และ G = SUM(D:F) ตามที่ยืนยัน · Budget > 0 · Total R ≥ 0 · Q ตรงกับ G/C
|   ไล่ตรวจสูตรต้นทางของ Q (รวม G และ C) ด้วยเกณฑ์เดียวกับเซลล์อื่น (External Link / Error / ฟังก์ชัน ...)
|   Actual = nonRenewalAchievementBasisPoints(Total R, Budget) → ตัดทศนิยมลง 2 ตำแหน่ง (หน่วย %)
|
*/
function kpiCompanyEvaluateRenewal(
    Spreadsheet $spreadsheet, array $workbookSheets, string $formSheet, array $loadedCells, bool $calcUnreliable, bool $final,
    string $section, string $rawLabel, ?float $weight, array $mapping, array $mappingReasons, int $year, string $employeeCode,
    array &$out, int &$sequence, ?int $onlyMonth = null
): void {
    $thaiMonths = monthlyPeriodMonths();

    $push = function (int $month, string $source, ?string $formula, $value, array $reasons, array $warnings, ?string $actual, ?string $requires = null, array $details = [])
        use (&$out, &$sequence, $section, $rawLabel, $weight, $mapping, $mappingReasons, $calcUnreliable, $thaiMonths, $year, $employeeCode): void {

        $itemMappingReasons = $mappingReasons;
        if ($calcUnreliable && $formula !== null) {
            array_unshift($itemMappingReasons, "ไฟล์ตั้งค่าการคำนวณที่ทำให้ค่าจากสูตรอาจไม่ใช่ค่าล่าสุด");
        }

        $status = $reasons
            ? KPI_COMPANY_STATUS_REJECTED
            : ($itemMappingReasons ? KPI_COMPANY_STATUS_NEEDS_CONFIRMATION : KPI_COMPANY_STATUS_READY);

        $item = [
            "mapping_ready" => !$itemMappingReasons,
            "confirmed_kpi_name" => $mapping["confirmed_kpi_name"] ?? null,
            "value_rule" => KPI_COMPANY_RENEWAL_RULE,
            "action" => null,
            "section" => $section,
            "label" => $rawLabel,
            "weight" => $weight,
            "month" => $month,
            "month_label" => $month > 0 ? $thaiMonths[$month] . " " . $year : "ทั้งปี " . $year,
            "source" => $source,
            "kind" => $formula !== null ? "ค่าจากสูตร (คำนวณโดย Excel)" : "ค่าที่พิมพ์",
            "formula" => $formula,
            "value" => $actual ?? $value,
            "kpi_id" => $mapping["kpi_id"] ?? null,
            "status" => $status,
            "reasons" => array_merge($reasons, $itemMappingReasons),
            "warnings" => $warnings,
            "requires_confirmation" => $requires,
            "details" => $details
        ];

        if ($status === KPI_COMPANY_STATUS_READY) {
            $item["row"] = ++$sequence;
            $out["rows"][] = [
                "row" => $sequence,
                "source" => $source,
                "requires_confirmation" => $requires,
                "values" => [
                    "employee_code" => $employeeCode,
                    "kpi_id" => (int) $mapping["kpi_id"],
                    "year" => $year,
                    "month" => $month,
                    "actual" => $actual,
                    "competency_score" => null,
                    "note" => null
                ],
                "errors" => []
            ];
        }

        $out["items"][] = $item;
    };

    $summaryNames = array_values(array_filter(
        array_map("strval", array_keys($workbookSheets)),
        fn($name) => str_starts_with(trim($name), KPI_COMPANY_SUMMARY_SHEET_PREFIX)
    ));

    if (count($summaryNames) !== 1) {
        $push(0, "-", null, null, ["ต้องมีชีต \"" . KPI_COMPANY_SUMMARY_SHEET_PREFIX . "..\" หนึ่งชีตพอดี (พบ " . count($summaryNames) . " ชีต)"], [], null);
        return;
    }

    $summaryName = $summaryNames[0];

    /* รอบแรก: โหลดพื้นที่หัวตารางและแถวเดือนของชีตสรุป (เฉพาะคอลัมน์ที่ใช้) */
    $scanNeed = [];
    for ($row = 1; $row <= KPI_COMPANY_SUMMARY_SCAN_ROWS; $row++) {
        foreach (KPI_COMPANY_SUMMARY_SCAN_COLUMNS as $column) {
            if (!isset($loadedCells[$summaryName][$column . $row])) {
                $scanNeed[$column . $row] = true;
            }
        }
    }

    $summary = $spreadsheet->getSheetByName($summaryName);

    if ($scanNeed || $summary === null) {
        if (!$final) {
            $out["need"][$summaryName] = ($out["need"][$summaryName] ?? []) + $scanNeed;
            return;
        }
        $push(0, $summaryName, null, null, ["อ่านชีต {$summaryName} ไม่ครบ"], [], null);
        return;
    }

    /*
    | ชีตสรุปมีหลายตารางที่หัวคล้ายกัน (มูลค่า / จำนวนราย) แต่ละตารางเริ่มที่แถวที่ B = "เดือน"
    | ใช้เฉพาะตารางมูลค่า (D = "Portfolio(มูลค่า)") ซึ่งเป็นแหล่ง C/G/Q ที่ยืนยันไว้ และต้องมีตารางเดียว
    */
    $tableStarts = [];
    for ($row = 1; $row <= KPI_COMPANY_SUMMARY_SCAN_ROWS; $row++) {
        if (kpiCompanyCellText($summary, 2, $row) === "เดือน") {
            $tableStarts[] = $row;
        }
    }

    $valueTables = [];
    foreach ($tableStarts as $index => $start) {
        if (kpiCompanyCellText($summary, 4, $start) === "Portfolio(มูลค่า)") {
            $valueTables[] = [$start, ($tableStarts[$index + 1] ?? KPI_COMPANY_SUMMARY_SCAN_ROWS + 1) - 1];
        }
    }

    if (count($valueTables) !== 1) {
        $push(0, $summaryName, null, null, [
            "ไม่พบตาราง \"Portfolio(มูลค่า)\" ในชีต {$summaryName} หรือพบมากกว่าหนึ่งตาราง"
        ], [], null);
        return;
    }

    [$tableStart, $tableEnd] = $valueTables[0];
    $monthRows = [];
    for ($row = $tableStart + 1; $row <= $tableEnd; $row++) {
        $month = array_search(kpiCompanyCellText($summary, 2, $row), $thaiMonths, true);
        if ($month !== false) {
            $monthRows[$month][] = $row;
        }
    }

    $firstRow = $monthRows ? min(array_merge(...array_values($monthRows))) : $tableStart + 1;
    $header = function (string $column, callable $test) use ($summary, $tableStart, $firstRow): bool {
        $index = Coordinate::columnIndexFromString($column);
        for ($row = $tableStart; $row < $firstRow; $row++) {
            if ($test(kpiCompanyCellText($summary, $index, $row))) {
                return true;
            }
        }
        return false;
    };

    $headerOk = $header("C", fn($t) => $t === "Budget")
        && $header("G", fn($t) => $t === "Total R")
        && $header("Q", fn($t) => $t === "%")
        && $header("D", fn($t) => str_starts_with($t, "Renew"))
        && $header("E", fn($t) => str_starts_with($t, "Renew"))
        && $header("F", fn($t) => str_starts_with($t, "Renew"));

    if (!$headerOk) {
        $push(0, $summaryName, null, null, [
            "หัวตารางของชีต {$summaryName} ไม่ตรงกับที่ยืนยัน (C = Budget, D-F = Renew, G = Total R, Q = %)"
        ], [], null);
        return;
    }

    $out["notes"][] = "หัวข้อ \"" . KPI_COMPANY_RENEWAL_LABEL . "\" อ่านจากชีต {$summaryName} (Q = G/C: Total R ÷ Budget ของแต่ละเดือน ไม่รวมยอดขายใหม่)"
        . " ไม่ใช้ช่อง \"ที่ทำได้\" ในแบบฟอร์ม " . KPI_COMPANY_FORM_SHEET;

    $read = function (string $coordinate) use ($summary): array {
        if (!$summary->cellExists($coordinate)) {
            return [null, null];
        }
        $cell = $summary->getCell($coordinate);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return [(string) $cell->getValue(), $cell->getOldCalculatedValue()];
        }
        return [null, $cell->getValue()];
    };
    $isNumber = fn($v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v);
    $isBlank = fn($v): bool => $v === null || $v === "" || ($isNumber($v) && (float) $v == 0.0);

    foreach ($thaiMonths as $month => $monthName) {

        if ($onlyMonth !== null && $month !== $onlyMonth) {
            continue;
        }

        if (count($monthRows[$month] ?? []) !== 1) {
            $push($month, $summaryName, null, null, [
                "ไม่พบแถวของเดือน{$monthName}ในชีต {$summaryName} หรือพบมากกว่าหนึ่งแถว"
            ], [], null);
            continue;
        }

        $row = $monthRows[$month][0];
        [$qFormula, $q] = $read("Q{$row}");
        [$gFormula, $g] = $read("G{$row}");
        [, $c] = $read("C{$row}");
        $source = "{$summaryName}!Q{$row}";

        if ($isBlank($c) && $isBlank($g)) {
            $out["skipped_empty"]++;
            continue;
        }

        $reasons = [];
        $warnings = [];

        if (kpiCompanyFormulaKey($qFormula) !== "=G{$row}/C{$row}") {
            $reasons[] = "Q{$row} ต้องเป็นสูตร =G{$row}/C{$row} (Total R ÷ Budget) ตามที่ยืนยัน";
        }
        if (kpiCompanyFormulaKey($gFormula) !== "=SUM(D{$row}:F{$row})") {
            $reasons[] = "G{$row} ต้องเป็นสูตร =SUM(D{$row}:F{$row}) (Renew 1Y + 2Y + Bait 3Y ไม่รวม New) ตามที่ยืนยัน";
        }
        if (!$isNumber($c) || (float) $c <= 0) {
            $reasons[] = "Budget (C{$row}) ต้องเป็นตัวเลขมากกว่า 0";
        }
        if (!$isNumber($g) || (float) $g < 0) {
            $reasons[] = "Total R (G{$row}) ต้องเป็นตัวเลขไม่ติดลบ";
        }

        /* B3: ค่าจริงภายในเซลล์ (ไม่ใช่รูปแบบแสดงผล) ต้องมีทศนิยมไม่เกิน 2 ตำแหน่ง · ไม่ปัดเศษให้ผ่าน */
        foreach (["C" => "Budget", "D" => "Renew 1Y", "E" => "Renew 2Y", "F" => "Renew Bait 3Y", "G" => "Total R"] as $col => $field) {
            [, $value] = $read("{$col}{$row}");
            if ($isNumber($value) && !kpiCompanyHasAtMostTwoDecimals((float) $value)) {
                $reasons[] = "เดือน{$monthName}: {$field} ({$col}{$row}) มีทศนิยมเกิน 2 ตำแหน่งในค่าจริงของเซลล์"
                    . " ระบบไม่ปัดเศษ กรุณาแก้ไขไฟล์";
            }
        }

        /* B1 + B2: Budget และ Renew ต้องมาจากชีตของเดือนที่เลือกตามโครงสร้างที่ยืนยัน (ห้ามใช้ข้อมูลเดือนอื่น) */
        $sourceCheck = kpiCompanyRenewalSourceReasons($spreadsheet, $workbookSheets, $loadedCells, $summary, $row, $month, $monthName, $year);
        $reasons = array_merge($reasons, $sourceCheck["reasons"]);

        if ($sourceCheck["need"]) {
            if ($final) {
                $reasons[] = "ตรวจสอบแหล่งข้อมูลของเดือน{$monthName}ไม่ครบ (โหลดเซลล์ต้นทางไม่ได้)";
            } else {
                foreach ($sourceCheck["need"] as $needSheet => $needCells) {
                    $out["need"][$needSheet] = ($out["need"][$needSheet] ?? []) + $needCells;
                }
            }
        }

        if ($qFormula !== null && $q === null) {
            $reasons[] = "ไม่มีค่าที่ Excel คำนวณเก็บไว้ในไฟล์ (เปิดและบันทึกไฟล์ใน Excel ก่อน)";
        } elseif (!$isNumber($q)) {
            $reasons[] = "ค่าใน Q{$row} ไม่ใช่ตัวเลข";
        }

        if (!$reasons && abs((float) $q - (float) $g / (float) $c) > 1e-9 * max(1.0, abs((float) $q))) {
            $reasons[] = "ค่าใน Q{$row} ไม่เท่ากับ G{$row}/C{$row}";
        }

        if ($qFormula !== null && kpiCompanyFormulaKey($qFormula) === "=G{$row}/C{$row}") {
            $ctx = [
                "visited" => [], "budget" => KPI_COMPANY_MAX_DEPENDENCY_CELLS, "need" => [],
                "form" => $formSheet, "loaded" => $loadedCells
            ];
            $error = kpiCompanyCheckCell($spreadsheet, $workbookSheets, $summaryName, "Q{$row}", $ctx);

            if ($error !== null) {
                $reasons[] = $error;
            } elseif ($ctx["need"]) {
                if ($final) {
                    $reasons[] = "ตรวจสอบข้อมูลต้นทางของสูตรไม่ครบ (อ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจได้)";
                } else {
                    foreach ($ctx["need"] as $needSheet => $needCells) {
                        $out["need"][$needSheet] = ($out["need"][$needSheet] ?? []) + $needCells;
                    }
                }
            }
            $warnings[] = "ค่าจากสูตรเป็นค่าที่ Excel คำนวณไว้เมื่อบันทึกไฟล์ครั้งล่าสุด ระบบไม่คำนวณใหม่และยืนยันไม่ได้ว่าเป็นค่าปัจจุบัน";
        }

        $actual = null;
        $requires = null;
        $details = [];

        if (!$reasons) {
            $basisPoints = nonRenewalAchievementBasisPoints($g, $c);
            if ($basisPoints === null) {
                $reasons[] = "คำนวณสัดส่วน Total R ÷ Budget ไม่ได้";
            } else {
                $actual = sprintf("%d.%02d", intdiv($basisPoints, 100), $basisPoints % 100);
                $details = [
                    "budget" => number_format((float) $c, 2), "budget_cell" => "C{$row}",
                    "total_r" => number_format((float) $g, 2), "total_r_cell" => "G{$row}",
                    "ratio" => rtrim(rtrim(number_format((float) $q, 6, ".", ""), "0"), "."),
                    "actual" => $actual, "target" => KPI_COMPANY_RENEWAL_TARGET
                ];
                $warnings[] = "Total R (G{$row}) " . $details["total_r"] . " ÷ Budget (C{$row}) " . $details["budget"]
                    . " = " . $details["ratio"] . " → Actual {$actual}% (ตัดทศนิยมลงเหลือ 2 ตำแหน่ง) เทียบกับ Target 100%";

                /*
                | Budget > 0 แต่ Total R = 0: ไม่บันทึกอัตโนมัติ
                |   เดือนยังไม่สิ้นสุด → ข้อมูลต้นทางอาจยังไม่ครบ → ไม่บันทึก
                |   เดือนสิ้นสุดแล้ว → ระบบแยกไม่ได้ว่าเป็นผลจริงหรือยังไม่ได้กรอก → ต้องให้ผู้ใช้ยืนยันรายแถว
                | (สูตรคำนวณไม่ได้ / ข้อมูลผิด ถูกปฏิเสธไปแล้วด้านบน)
                */
                if ((float) $g == 0.0) {
                    $monthEnd = date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $year, $month)));
                    if ($monthEnd >= date("Y-m-d")) {
                        $reasons[] = "Budget " . $details["budget"] . " แต่ Total R = 0 และเดือน{$monthName} {$year} ยังไม่สิ้นสุด"
                            . " ข้อมูลต้นทางอาจยังไม่ครบ ระบบจึงไม่บันทึกเดือนนี้";
                        $actual = null;
                    } else {
                        $requires = KPI_COMPANY_CONFIRM_ZERO_TOTAL_R;
                        $warnings[] = "คำเตือน: Budget " . $details["budget"] . " แต่ Total R = 0 ผลคำนวณจะเป็น 0% (ระดับต่ำสุด)"
                            . " ระบบแยกไม่ได้ว่าเป็นผลจริง (ไม่มีการต่อสัญญา) หรือข้อมูลในไฟล์ยังไม่ครบ"
                            . " กรุณาตรวจไฟล์ แล้วติ๊กยืนยันรายการนี้ก่อนบันทึก (ไม่ติ๊ก = ไม่บันทึกรายการนี้)";
                    }
                }
            }
        }

        $push($month, $source, $qFormula, $isNumber($q) ? $q + 0 : (is_scalar($q) ? (string) $q : null), $reasons, $warnings, $actual, $requires, $details);
    }
}


/*
|--------------------------------------------------------------------------
| KPI 9 "มูลค่าไม่ต่อสัญญา": อ่านจากตาราง "Portfolio(จำนวนราย)" ของชีตสรุป (กฎ KPI_COMPANY_NON_RENEWAL_RULE)
|--------------------------------------------------------------------------
|
| แบบฟอร์ม KPI AM ช่อง "ที่ทำได้" ของหัวข้อนี้ (K14) = สรุปทั้งปี..!T23 = แถวมกราคมของตารางจำนวนราย
| ต่อเดือน (แถวที่คอลัมน์ B = ชื่อเดือน ในตารางจำนวนราย):
|   ว่าง (C และ G ว่าง/0) → ข้าม
|   T = G/C · G = SUM(D:F) (Renew 1Y + 2Y + Bait 3Y) · C / D / E / F = เซลล์เดียวในชีตของเดือนนั้น แถวเดียวกัน (C = คอลัมน์ B)
|   C > 0 และ G ≥ 0 เป็นจำนวนเต็ม (จำนวนราย) · ค่า T ที่เก็บไว้ตรงกับ G/C · ไล่ตรวจสูตรต้นทางของ T (External Link / Error / ฟังก์ชัน ...)
|   Actual = (G / C) × 100 ตัดทศนิยมลงเหลือ 2 ตำแหน่ง (หน่วย %) · Target = 100 (%) → คะแนนตามกฎ KPI 9 (≥100 / 96 / 90 / 85%)
|   G = 0 แต่ C > 0: เดือนยังไม่สิ้นสุด → ไม่บันทึก · เดือนสิ้นสุดแล้ว → ต้องให้ผู้ใช้ยืนยันรายแถว (เหมือน KPI 14)
|
*/
function kpiCompanyEvaluateNonRenewalCount(
    Spreadsheet $spreadsheet, array $workbookSheets, string $formSheet, array $loadedCells, bool $calcUnreliable, bool $final,
    string $section, string $rawLabel, ?float $weight, array $mapping, array $mappingReasons, int $year, string $employeeCode,
    array &$out, int &$sequence, ?int $onlyMonth = null
): void {
    $thaiMonths = monthlyPeriodMonths();

    $push = function (int $month, string $source, ?string $formula, $value, array $reasons, array $warnings, ?string $actual, ?string $requires = null, array $details = [])
        use (&$out, &$sequence, $section, $rawLabel, $weight, $mapping, $mappingReasons, $calcUnreliable, $thaiMonths, $year, $employeeCode): void {

        $itemMappingReasons = $mappingReasons;
        if ($calcUnreliable && $formula !== null) {
            array_unshift($itemMappingReasons, "ไฟล์ตั้งค่าการคำนวณที่ทำให้ค่าจากสูตรอาจไม่ใช่ค่าล่าสุด");
        }

        $status = $reasons
            ? KPI_COMPANY_STATUS_REJECTED
            : ($itemMappingReasons ? KPI_COMPANY_STATUS_NEEDS_CONFIRMATION : KPI_COMPANY_STATUS_READY);

        $item = [
            "mapping_ready" => !$itemMappingReasons,
            "confirmed_kpi_name" => $mapping["confirmed_kpi_name"] ?? null,
            "value_rule" => KPI_COMPANY_NON_RENEWAL_RULE,
            "action" => null,
            "section" => $section,
            "label" => $rawLabel,
            "weight" => $weight,
            "month" => $month,
            "month_label" => $month > 0 ? $thaiMonths[$month] . " " . $year : "ทั้งปี " . $year,
            "source" => $source,
            "kind" => $formula !== null ? "ค่าจากสูตร (คำนวณโดย Excel)" : "ค่าที่พิมพ์",
            "formula" => $formula,
            "value" => $actual ?? $value,
            "kpi_id" => $mapping["kpi_id"] ?? null,
            "status" => $status,
            "reasons" => array_merge($reasons, $itemMappingReasons),
            "warnings" => $warnings,
            "requires_confirmation" => $requires,
            "details" => $details
        ];

        if ($status === KPI_COMPANY_STATUS_READY) {
            $item["row"] = ++$sequence;
            $out["rows"][] = [
                "row" => $sequence,
                "source" => $source,
                "requires_confirmation" => $requires,
                "values" => [
                    "employee_code" => $employeeCode,
                    "kpi_id" => (int) $mapping["kpi_id"],
                    "year" => $year,
                    "month" => $month,
                    "actual" => $actual,
                    "target" => KPI_COMPANY_NON_RENEWAL_TARGET,
                    "competency_score" => null,
                    "note" => null
                ],
                "errors" => []
            ];
        }

        $out["items"][] = $item;
    };

    $summaryNames = array_values(array_filter(
        array_map("strval", array_keys($workbookSheets)),
        fn($name) => str_starts_with(trim($name), KPI_COMPANY_SUMMARY_SHEET_PREFIX)
    ));

    if (count($summaryNames) !== 1) {
        $push(0, "-", null, null, ["ต้องมีชีต \"" . KPI_COMPANY_SUMMARY_SHEET_PREFIX . "..\" หนึ่งชีตพอดี (พบ " . count($summaryNames) . " ชีต)"], [], null);
        return;
    }

    $summaryName = $summaryNames[0];

    $scanNeed = [];
    for ($row = 1; $row <= KPI_COMPANY_SUMMARY_SCAN_ROWS; $row++) {
        foreach (KPI_COMPANY_SUMMARY_SCAN_COLUMNS as $column) {
            if (!isset($loadedCells[$summaryName][$column . $row])) {
                $scanNeed[$column . $row] = true;
            }
        }
    }

    $summary = $spreadsheet->getSheetByName($summaryName);

    if ($scanNeed || $summary === null) {
        if (!$final) {
            $out["need"][$summaryName] = ($out["need"][$summaryName] ?? []) + $scanNeed;
            return;
        }
        $push(0, $summaryName, null, null, ["อ่านชีต {$summaryName} ไม่ครบ"], [], null);
        return;
    }

    /* ตารางจำนวนราย: เริ่มที่แถวที่ B = "เดือน" และ D = "Portfolio(จำนวนราย)" ต้องมีตารางเดียว */
    $tableStarts = [];
    for ($row = 1; $row <= KPI_COMPANY_SUMMARY_SCAN_ROWS; $row++) {
        if (kpiCompanyCellText($summary, 2, $row) === "เดือน") {
            $tableStarts[] = $row;
        }
    }

    $countTables = [];
    foreach ($tableStarts as $index => $start) {
        if (kpiCompanyCellText($summary, 4, $start) === "Portfolio(จำนวนราย)") {
            $countTables[] = [$start, ($tableStarts[$index + 1] ?? KPI_COMPANY_SUMMARY_SCAN_ROWS + 1) - 1];
        }
    }

    if (count($countTables) !== 1) {
        $push(0, $summaryName, null, null, [
            "ไม่พบตาราง \"Portfolio(จำนวนราย)\" ในชีต {$summaryName} หรือพบมากกว่าหนึ่งตาราง"
        ], [], null);
        return;
    }

    [$tableStart, $tableEnd] = $countTables[0];
    $monthRows = [];
    for ($row = $tableStart + 1; $row <= $tableEnd; $row++) {
        $month = array_search(kpiCompanyCellText($summary, 2, $row), $thaiMonths, true);
        if ($month !== false) {
            $monthRows[$month][] = $row;
        }
    }

    $firstRow = $monthRows ? min(array_merge(...array_values($monthRows))) : $tableStart + 1;
    $header = function (string $column, callable $test) use ($summary, $tableStart, $firstRow): bool {
        $index = Coordinate::columnIndexFromString($column);
        for ($row = $tableStart; $row < $firstRow; $row++) {
            if ($test(kpiCompanyCellText($summary, $index, $row))) {
                return true;
            }
        }
        return false;
    };

    $headerOk = $header("C", fn($t) => $t === "Budget")
        && $header("G", fn($t) => trim($t) === "Total R")
        && $header("D", fn($t) => str_starts_with($t, "Renew"))
        && $header("E", fn($t) => str_starts_with($t, "Renew"))
        && $header("F", fn($t) => str_starts_with($t, "Renew"));

    if (!$headerOk) {
        $push(0, $summaryName, null, null, [
            "หัวตารางจำนวนรายของชีต {$summaryName} ไม่ตรงกับที่ยืนยัน (C = Budget, D-F = Renew, G = Total R)"
        ], [], null);
        return;
    }

    $out["notes"][] = "หัวข้อ \"" . NON_RENEWAL_KPI_NAME . "\" อ่านจากตารางจำนวนรายของชีต {$summaryName} (T = G/C: จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget ของแต่ละเดือน · มกราคม = T{$firstRow})";

    $read = function (string $coordinate) use ($summary): array {
        if (!$summary->cellExists($coordinate)) {
            return [null, null];
        }
        $cell = $summary->getCell($coordinate);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return [(string) $cell->getValue(), $cell->getOldCalculatedValue()];
        }
        return [null, $cell->getValue()];
    };
    $isNumber = fn($v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v);
    $isBlank = fn($v): bool => $v === null || $v === "" || ($isNumber($v) && (float) $v == 0.0);
    $isWhole = fn($v): bool => $isNumber($v) && floor((float) $v) == (float) $v;

    foreach ($thaiMonths as $month => $monthName) {

        if ($onlyMonth !== null && $month !== $onlyMonth) {
            continue;
        }

        if (count($monthRows[$month] ?? []) !== 1) {
            $push($month, $summaryName, null, null, [
                "ไม่พบแถวของเดือน{$monthName}ในตารางจำนวนรายของชีต {$summaryName} หรือพบมากกว่าหนึ่งแถว"
            ], [], null);
            continue;
        }

        $row = $monthRows[$month][0];
        [$tFormula, $t] = $read("T{$row}");
        [$gFormula, $g] = $read("G{$row}");
        [$cFormula, $c] = $read("C{$row}");
        $source = "{$summaryName}!T{$row}";

        if ($isBlank($c) && $isBlank($g)) {
            $out["skipped_empty"]++;
            continue;
        }

        $reasons = [];
        $warnings = [];

        if (kpiCompanyFormulaKey($tFormula) !== "=G{$row}/C{$row}") {
            $reasons[] = "T{$row} ต้องเป็นสูตร =G{$row}/C{$row} (จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget) ตามที่ยืนยัน";
        }
        if (kpiCompanyFormulaKey($gFormula) !== "=SUM(D{$row}:F{$row})") {
            $reasons[] = "G{$row} ต้องเป็นสูตร =SUM(D{$row}:F{$row}) (Renew 1Y + 2Y + Bait 3Y) ตามที่ยืนยัน";
        }
        if (!$isWhole($c) || (float) $c <= 0) {
            $reasons[] = "Budget (C{$row}) ต้องเป็นจำนวนรายเต็มมากกว่า 0";
        }
        if (!$isWhole($g) || (float) $g < 0) {
            $reasons[] = "Total R (G{$row}) ต้องเป็นจำนวนรายเต็มไม่ติดลบ";
        }

        /* C / D / E / F ต้องอ้างเซลล์เดียวในชีตของเดือนนั้น แถวเดียวกัน (ห้ามพิมพ์เอง / อ้างชีตเดือนอื่น) */
        $expected = KPI_COMPANY_RENEWAL_MONTH_SHEETS[$month] . "." . sprintf("%02d", ($year + 543) % 100);
        $monthSheets = array_values(array_filter(array_map("strval", array_keys($workbookSheets)), fn($name) => trim($name) === $expected));
        if (count($monthSheets) !== 1) {
            $reasons[] = "ไม่พบชีตของเดือน{$monthName} (\"{$expected}\") หรือพบมากกว่าหนึ่งชีต";
        } else {
            $cRef = kpiCompanySingleCellRef($cFormula);
            if ($cRef === null || $cRef["sheet"] !== $monthSheets[0] || $cRef["col"] !== "B") {
                $reasons[] = "เดือน{$monthName}: Budget (C{$row}) ต้องเป็นสูตรอ้างเซลล์คอลัมน์ B ของชีต \"{$expected}\" (ไม่รับค่าที่พิมพ์เองหรือการอ้างชีตเดือนอื่น)";
            } else {
                foreach (["D", "E", "F"] as $col) {
                    [$refFormula] = $read("{$col}{$row}");
                    $ref = kpiCompanySingleCellRef($refFormula);
                    if ($ref === null || $ref["sheet"] !== $monthSheets[0] || $ref["row"] !== $cRef["row"]) {
                        $reasons[] = "เดือน{$monthName}: Renew ({$col}{$row}) ต้องอ้างเซลล์ในชีต \"{$expected}\" แถวเดียวกับ Budget (ไม่รับค่าที่พิมพ์เองหรือการอ้างชีตเดือนอื่น)";
                        break;
                    }
                }
            }
        }

        if ($tFormula !== null && $t === null) {
            $reasons[] = "ไม่มีค่าที่ Excel คำนวณเก็บไว้ในไฟล์ (เปิดและบันทึกไฟล์ใน Excel ก่อน)";
        } elseif (!$isNumber($t)) {
            $reasons[] = "ค่าใน T{$row} ไม่ใช่ตัวเลข";
        }

        if (!$reasons && abs((float) $t - (float) $g / (float) $c) > 1e-9 * max(1.0, abs((float) $t))) {
            $reasons[] = "ค่าใน T{$row} ไม่เท่ากับ G{$row}/C{$row}";
        }

        if ($tFormula !== null && kpiCompanyFormulaKey($tFormula) === "=G{$row}/C{$row}") {
            $ctx = [
                "visited" => [], "budget" => KPI_COMPANY_MAX_DEPENDENCY_CELLS, "need" => [],
                "form" => $formSheet, "loaded" => $loadedCells
            ];
            $error = kpiCompanyCheckCell($spreadsheet, $workbookSheets, $summaryName, "T{$row}", $ctx);

            if ($error !== null) {
                $reasons[] = $error;
            } elseif ($ctx["need"]) {
                if ($final) {
                    $reasons[] = "ตรวจสอบข้อมูลต้นทางของสูตรไม่ครบ (อ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจได้)";
                } else {
                    foreach ($ctx["need"] as $needSheet => $needCells) {
                        $out["need"][$needSheet] = ($out["need"][$needSheet] ?? []) + $needCells;
                    }
                }
            }
            $warnings[] = "ค่าจากสูตรเป็นค่าที่ Excel คำนวณไว้เมื่อบันทึกไฟล์ครั้งล่าสุด ระบบไม่คำนวณใหม่และยืนยันไม่ได้ว่าเป็นค่าปัจจุบัน";
        }

        $actual = null;
        $requires = null;
        $details = [];

        if (!$reasons) {
            $basisPoints = nonRenewalAchievementBasisPoints($g, $c);
            if ($basisPoints === null) {
                $reasons[] = "คำนวณสัดส่วน G ÷ C ไม่ได้";
            } else {
                $actual = sprintf("%d.%02d", intdiv($basisPoints, 100), $basisPoints % 100);
                $details = [
                    "rule" => KPI_COMPANY_NON_RENEWAL_RULE,
                    "budget" => number_format((float) $c), "budget_cell" => "C{$row}",
                    "total_r" => number_format((float) $g), "total_r_cell" => "G{$row}",
                    "ratio_cell" => "T{$row}",
                    "ratio" => rtrim(rtrim(number_format((float) $t, 6, ".", ""), "0"), "."),
                    "actual" => $actual, "target" => KPI_COMPANY_NON_RENEWAL_TARGET
                ];
                $warnings[] = "T{$row} = G{$row} ÷ C{$row} (" . $details["total_r"] . " ÷ " . $details["budget"] . " ราย) = " . $details["ratio"]
                    . " → Actual {$actual}% (ตัดทศนิยมลงเหลือ 2 ตำแหน่ง) เทียบกับ Target 100%";

                if ((float) $g == 0.0) {
                    $monthEnd = date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $year, $month)));
                    if ($monthEnd >= date("Y-m-d")) {
                        $reasons[] = "Budget " . $details["budget"] . " ราย แต่ Total R = 0 และเดือน{$monthName} {$year} ยังไม่สิ้นสุด"
                            . " ข้อมูลต้นทางอาจยังไม่ครบ ระบบจึงไม่บันทึกเดือนนี้";
                        $actual = null;
                    } else {
                        $requires = KPI_COMPANY_CONFIRM_ZERO_TOTAL_R;
                        $warnings[] = "คำเตือน: Budget " . $details["budget"] . " ราย แต่ Total R = 0 ผลคำนวณจะเป็น 0% (ระดับต่ำสุด)"
                            . " ระบบแยกไม่ได้ว่าเป็นผลจริงหรือข้อมูลในไฟล์ยังไม่ครบ"
                            . " กรุณาตรวจไฟล์ แล้วติ๊กยืนยันรายการนี้ก่อนบันทึก (ไม่ติ๊ก = ไม่บันทึกรายการนี้)";
                    }
                }
            }
        }

        $push($month, $source, $tFormula, $isNumber($t) ? $t + 0 : (is_scalar($t) ? (string) $t : null), $reasons, $warnings, $actual, $requires, $details);
    }
}


/*
| ค่า float → ทศนิยมแบบข้อความ 15 หลักนัยสำคัญ (ความละเอียดที่ Excel ใช้) ไม่มีเลขยกกำลัง สำหรับคำนวณด้วย bcmath
| เช่น 0.57 → "0.570000000000000" (float 0.57 จริง ๆ คือ 0.56999999999999995 ซึ่ง × 100 แล้วปัดลงจะได้ 56 ผิด)
*/
function kpiCompanyPlainDecimal(float $value): ?string
{
    if (!is_finite($value)) {
        return null;
    }

    if ($value == 0.0) {
        return "0";
    }

    [$mantissa, $exponent] = explode("e", sprintf("%.14e", abs($value)));
    $digits = str_replace(".", "", $mantissa);
    $point = (int) $exponent + 1;

    if ($point <= 0) {
        $text = "0." . str_repeat("0", -$point) . $digits;
    } elseif ($point >= strlen($digits)) {
        $text = $digits . str_repeat("0", $point - strlen($digits));
    } else {
        $text = substr($digits, 0, $point) . "." . substr($digits, $point);
    }

    return ($value < 0 ? "-" : "") . $text;
}

/* FLOOR(ตัวตั้ง × 100 ÷ ตัวหาร) เป็นจำนวนเต็ม ปัดลงครั้งเดียว · ต้องไม่ติดลบและตัวหาร > 0 · คำนวณไม่ได้ → null */
function kpiCompanyFloorPercent(string $numerator, string $denominator): ?int
{
    if (!function_exists("bcdiv") || bccomp($denominator, "0", 40) <= 0 || bccomp($numerator, "0", 40) < 0) {
        return null;
    }

    /* bcdiv ที่ scale 0 ตัดเศษทิ้ง = ปัดลงสำหรับค่าที่ไม่ติดลบ */
    return (int) bcdiv(bcmul($numerator, "100", 40), $denominator, 0);
}

/*
| เดือนนั้นสิ้นสุดแล้วหรือยัง ตามวันที่ใน KPI_COMPANY_UPLIFT_TIMEZONE (สิ้นสุด = วันนี้เลยวันสุดท้ายของเดือนไปแล้ว)
| ไม่เปลี่ยน timezone ของ runtime · $now ใช้ทดสอบรอยต่อเดือน (null = เวลาปัจจุบัน)
*/
function kpiCompanyUpliftMonthEnded(int $year, int $month, ?DateTimeInterface $now = null): bool
{
    $zone = new DateTimeZone(KPI_COMPANY_UPLIFT_TIMEZONE);
    $today = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable("now"))->setTimezone($zone)->format("Y-m-d");
    $monthEnd = (new DateTimeImmutable(sprintf("%04d-%02d-01", $year, $month), $zone))->format("Y-m-t");

    return $today > $monthEnd;
}

/*
| เกณฑ์คะแนนของ KPI 8 ในระบบต้องครบระดับ 1-5 และทุก Actual จำนวนเต็ม (0..KPI_COMPANY_UPLIFT_CRITERIA_CHECK_MAX
| และ Actual ของรายการ) ต้องเข้าเกณฑ์ระดับใดระดับหนึ่ง · ใช้ฟังก์ชันเดิมของ kpi-score-helper.php (ไม่เปลี่ยนกฎคะแนน)
| คืนข้อความเหตุผล หรือ null ถ้าผ่าน
*/
function kpiCompanyUpliftCriteriaReason(PDO $pdo, int $kpiId, int $actual): ?string
{
    $levels = loadKpiScoreCriteria($pdo, [["kpi_id" => $kpiId]])[$kpiId] ?? [];
    $rules = parseKpiCriteria($levels);
    $missing = array_values(array_diff(range(KPI_MIN_LEVEL, KPI_MAX_LEVEL), array_keys($rules)));

    if ($missing) {
        return "เกณฑ์คะแนนของ KPI นี้ในระบบยังไม่ครบระดับ 1-5 (ไม่มีเกณฑ์เชิงตัวเลขของระดับ " . implode(", ", $missing) . ")"
            . " ระบบจึงยังไม่เปิดนำเข้า KPI นี้ กรุณาให้ผู้ดูแลระบบกำหนดเกณฑ์ให้ครบ";
    }

    $target = (float) KPI_COMPANY_UPLIFT_TARGET;

    foreach (array_unique(array_merge(range(0, KPI_COMPANY_UPLIFT_CRITERIA_CHECK_MAX), [$actual])) as $value) {
        $compare = kpiCriteriaCompareValue($rules, (float) $value, $target);
        $matched = false;
        foreach ($rules as $rule) {
            if (kpiCriterionMatches($rule, $compare)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return "เกณฑ์คะแนนของ KPI นี้ในระบบไม่ครอบคลุม Actual {$value}% (ไม่เข้าระดับใดเลย)"
                . " ระบบจึงยังไม่เปิดนำเข้า KPI นี้ กรุณาให้ผู้ดูแลระบบตรวจเกณฑ์";
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| KPI 8 "จำนวนรายการปรับเพิ่มมูลค่าสัญญา": อ่านจากชีตของเดือน (กฎ KPI_COMPANY_UPLIFT_RULE · ดูหัวไฟล์)
|--------------------------------------------------------------------------
|
| ต่อเดือน: ชีตของเดือนนั้นต้องมีชีตเดียว · หาแถวรวม n จาก O · ตรวจหัวคอลัมน์ / สูตร / ค่าทุกแถว
| ไล่ตรวจสูตรต้นทางของ S<n+1> และ R<n> ทุกชั้น (External Link / Error / ฟังก์ชัน) และห้ามอ้างชีตของเดือนอื่น
| เดือนที่ไม่มีข้อมูลไม่ถูกข้าม แต่ปฏิเสธพร้อมเหตุผล (ตามข้อกำหนดของ KPI นี้)
|
*/
function kpiCompanyEvaluateUplift(
    Spreadsheet $spreadsheet, array $workbookSheets, string $formSheet, array $loadedCells, bool $calcUnreliable, bool $final,
    string $section, string $rawLabel, ?float $weight, array $mapping, array $mappingReasons, int $year, string $employeeCode,
    array &$out, int &$sequence, ?int $onlyMonth = null, ?DateTimeInterface $now = null
): void {
    $thaiMonths = monthlyPeriodMonths();
    $first = KPI_COMPANY_UPLIFT_FIRST_ROW;
    $suffix = sprintf("%02d", ($year + 543) % 100);
    $monthSheetPattern = '/^(?:' . implode("|", array_map(fn($a) => preg_quote($a, "/"), KPI_COMPANY_RENEWAL_MONTH_SHEETS)) . ')\.[0-9]{2}$/u';

    $push = function (int $month, string $source, ?string $formula, array $reasons, array $warnings, ?string $actual, array $details = [])
        use (&$out, &$sequence, $section, $rawLabel, $weight, $mapping, $mappingReasons, $calcUnreliable, $thaiMonths, $year, $employeeCode): void {

        $itemMappingReasons = $mappingReasons;
        if ($calcUnreliable && $formula !== null) {
            array_unshift($itemMappingReasons, "ไฟล์ตั้งค่าการคำนวณที่ทำให้ค่าจากสูตรอาจไม่ใช่ค่าล่าสุด");
        }

        $status = $reasons
            ? KPI_COMPANY_STATUS_REJECTED
            : ($itemMappingReasons ? KPI_COMPANY_STATUS_NEEDS_CONFIRMATION : KPI_COMPANY_STATUS_READY);

        $item = [
            "mapping_ready" => !$itemMappingReasons,
            "confirmed_kpi_name" => $mapping["confirmed_kpi_name"] ?? null,
            "value_rule" => KPI_COMPANY_UPLIFT_RULE,
            "action" => null,
            "section" => $section,
            "label" => $rawLabel,
            "weight" => $weight,
            "month" => $month,
            "month_label" => $thaiMonths[$month] . " " . $year,
            "source" => $source,
            "kind" => $formula !== null ? "ค่าจากสูตร (คำนวณโดย Excel)" : "ค่าที่พิมพ์",
            "formula" => $formula,
            "value" => $actual,
            "kpi_id" => $mapping["kpi_id"] ?? null,
            "status" => $status,
            "reasons" => array_merge($reasons, $itemMappingReasons),
            "warnings" => $warnings,
            "requires_confirmation" => null,
            "details" => $details
        ];

        if ($status === KPI_COMPANY_STATUS_READY) {
            $item["row"] = ++$sequence;
            $out["rows"][] = [
                "row" => $sequence,
                "source" => $source,
                "requires_confirmation" => null,
                "values" => [
                    "employee_code" => $employeeCode,
                    "kpi_id" => (int) $mapping["kpi_id"],
                    "year" => $year,
                    "month" => $month,
                    "actual" => $actual,
                    "competency_score" => null,
                    "note" => null
                ],
                "errors" => []
            ];
        }

        $out["items"][] = $item;
    };

    /* โหลดเซลล์ที่ยังไม่ได้โหลด → รอบถัดไป · รอบสุดท้าย (final) ถือว่าตรวจไม่ได้ */
    $needCells = function (string $sheetName, array $coordinates) use ($loadedCells): array {
        return array_values(array_filter($coordinates, fn($c) => !isset($loadedCells[$sheetName][$c])));
    };
    $requestOrFail = function (string $sheetName, array $missing) use (&$out, $final): bool {
        if ($final) {
            return false;
        }
        $out["need"][$sheetName] = ($out["need"][$sheetName] ?? []) + array_fill_keys($missing, true);
        return true;
    };
    $limit = function (array $cells): string {
        return implode(", ", array_slice($cells, 0, 5)) . (count($cells) > 5 ? " และอีก " . (count($cells) - 5) . " เซลล์" : "");
    };
    $isNumber = fn($v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v);
    $isBlank = fn($v): bool => $v === null || $v === "";
    $close = fn(float $a, float $b, float $scale): bool => abs($a - $b) <= KPI_COMPANY_UPLIFT_REL_TOLERANCE * max(1.0, $scale);

    $out["notes"][] = "หัวข้อ \"" . KPI_COMPANY_UPLIFT_LABEL . "\" อ่านจากชีตของเดือน (S = ผลต่าง ÷ ราคาเดิม ของเดือนนั้น)"
        . " ไม่ใช้ช่อง \"ที่ทำได้\" ในแบบฟอร์ม " . KPI_COMPANY_FORM_SHEET;

    foreach ($thaiMonths as $month => $monthName) {

        if ($onlyMonth !== null && $month !== $onlyMonth) {
            continue;
        }

        $expected = KPI_COMPANY_RENEWAL_MONTH_SHEETS[$month] . "." . $suffix;

        /* เดือนที่ยังไม่สิ้นสุด (ตามเวลาไทย) → ไม่นำเข้า ข้อมูลราคาสรุปของเดือนอาจยังไม่ครบ */
        if (!kpiCompanyUpliftMonthEnded($year, $month, $now)) {
            $push($month, $expected, null, [
                "เดือน{$monthName} {$year} ยังไม่สิ้นสุด (ตามเวลา " . KPI_COMPANY_UPLIFT_TIMEZONE . ")"
                . " ข้อมูลในไฟล์อาจยังไม่ครบ ระบบจึงไม่นำเข้าเดือนนี้ของ KPI นี้ นำเข้าได้ตั้งแต่วันแรกของเดือนถัดไป"
            ], [], null);
            continue;
        }

        $matches = array_values(array_filter(
            array_map("strval", array_keys($workbookSheets)),
            fn($name) => trim($name) === $expected
        ));

        if (count($matches) !== 1) {
            $push($month, $expected, null, ["ไม่พบชีตของเดือน{$monthName} (\"{$expected}\") หรือพบมากกว่าหนึ่งชีต"], [], null);
            continue;
        }

        $sheetName = $matches[0];

        /* รอบแรก: คอลัมน์ O สำหรับหาแถวรวม + หัวคอลัมน์ O / R / S */
        $scan = [];
        for ($row = 1; $row <= KPI_COMPANY_UPLIFT_SCAN_ROWS; $row++) {
            $scan[] = "O{$row}";
        }
        for ($row = 1; $row <= KPI_COMPANY_UPLIFT_HEADER_ROWS; $row++) {
            $scan[] = "R{$row}";
            $scan[] = "S{$row}";
        }
        $missing = $needCells($sheetName, $scan);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($missing || $sheet === null) {
            if (!$requestOrFail($sheetName, $missing)) {
                $push($month, $sheetName, null, ["อ่านชีต \"{$expected}\" ไม่ครบ"], [], null);
            }
            continue;
        }

        $read = function (string $coordinate) use ($sheet): array {
            if (!$sheet->cellExists($coordinate)) {
                return [null, null];
            }
            $cell = $sheet->getCell($coordinate);
            $value = $cell->getValue();
            if ($value instanceof RichText) {
                $value = $value->getPlainText();
            }
            if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                return [(string) $value, $cell->getOldCalculatedValue()];
            }
            if ($cell->getDataType() === DataType::TYPE_ERROR) {
                return [null, "#ERROR"];
            }
            return [null, $value];
        };
        $key = fn(string $coordinate): ?string => ($f = $read($coordinate)[0]) === null ? null : kpiCompanyFormulaKey($f);

        $sumRows = [];
        for ($row = $first + 2; $row <= KPI_COMPANY_UPLIFT_SCAN_ROWS; $row++) {
            if ($key("O{$row}") === "=SUM(O{$first}:O" . ($row - 2) . ")") {
                $sumRows[] = $row;
            }
        }

        if (count($sumRows) !== 1) {
            $push($month, $sheetName, null, [
                "เดือน{$monthName}: ไม่พบแถวรวมราคาเดิม (O<n> = SUM(O{$first}:O<n-2>)) ในชีต \"{$expected}\" หรือพบมากกว่าหนึ่งแถว"
            ], [], null);
            continue;
        }

        $n = $sumRows[0];
        $last = $n - 2;
        $ratioCell = "S" . ($n + 1);

        $headerOk = false;
        for ($row = 1; $row <= KPI_COMPANY_UPLIFT_HEADER_ROWS && !$headerOk; $row++) {
            $headerOk = true;
            foreach (KPI_COMPANY_UPLIFT_HEADERS as $col => $text) {
                if (kpiCompanyCellText($sheet, Coordinate::columnIndexFromString($col), $row) !== $text) {
                    $headerOk = false;
                }
            }
        }

        if (!$headerOk) {
            $push($month, $sheetName, null, [
                "เดือน{$monthName}: หัวคอลัมน์ในชีต \"{$expected}\" ไม่ตรงกับที่ยืนยัน (O = ราคาเดิม, R = ราคาสรุป, S = ผลต่าง)"
            ], [], null);
            continue;
        }

        /* รอบถัดไป: ข้อมูลรายแถวและแถวรวม */
        $block = ["R{$n}", "S{$n}", $ratioCell];
        for ($row = $first; $row <= $last; $row++) {
            array_push($block, "O{$row}", "R{$row}", "S{$row}");
        }
        $missing = $needCells($sheetName, $block);

        if ($missing) {
            if (!$requestOrFail($sheetName, $missing)) {
                $push($month, $sheetName, null, ["อ่านข้อมูลในชีต \"{$expected}\" ไม่ครบ"], [], null);
            }
            continue;
        }

        $source = "{$sheetName}!{$ratioCell}";
        [$ratioFormula, $ratio] = $read($ratioCell);
        [, $oTotal] = $read("O{$n}");
        [, $rTotal] = $read("R{$n}");
        [, $sTotal] = $read("S{$n}");

        /*
        | ข้อมูลรายแถว (เจ้าของระบบยืนยัน: S = ผลต่างที่ใช้คำนวณโดยตรง · O ว่างได้ · R เป็นข้อมูลประกอบ)
        |   S เป็นตัวเลข (พิมพ์เองหรือสูตร) → นับทุกแถว · S ต้องไม่ใช่ค่าที่อ่านไม่ได้ / Error
        |   S ว่างแต่แถวมี O หรือ R → ข้อมูลไม่พอ (ไม่เติมค่าแทน) · S เป็นสูตร =R-O แต่ R ว่าง → S มาจากค่าที่ไม่มี
        |   S เป็นสูตร =R-O → ค่าที่ Excel เก็บไว้ต้องเท่ากับ R - O (O ว่าง = 0)
        */
        $invalid = [];
        $noCache = [];
        $noDiff = [];
        $fromBlankR = [];
        $badS = [];
        $rAny = false;
        $sumO = 0.0;
        $sumR = 0.0;
        $sumS = 0.0;
        $scaleO = 0.0;
        $scaleR = 0.0;
        $scaleS = 0.0;

        for ($row = $first; $row <= $last; $row++) {
            $cells = [];
            foreach (["O", "R", "S"] as $col) {
                [$formula, $value] = $read("{$col}{$row}");
                if ($formula !== null && $value === null) {
                    $noCache[] = "{$col}{$row}";
                } elseif (!$isBlank($value) && !$isNumber($value)) {
                    $invalid[] = "{$col}{$row}";
                }
                $cells[$col] = $isNumber($value) ? (float) $value : null;
            }

            $sumO += $cells["O"] ?? 0.0;
            $sumR += $cells["R"] ?? 0.0;
            $sumS += $cells["S"] ?? 0.0;
            $scaleO += abs($cells["O"] ?? 0.0);
            $scaleR += abs($cells["R"] ?? 0.0);
            $scaleS += abs($cells["S"] ?? 0.0);
            $rAny = $rAny || $cells["R"] !== null;
            $sIsFormula = $key("S{$row}") === "=R{$row}-O{$row}";

            if ($cells["S"] === null) {
                if (($cells["O"] !== null || $cells["R"] !== null) && $isBlank($read("S{$row}")[1])) {
                    $noDiff[] = $row;
                }
            } elseif ($sIsFormula && $cells["R"] === null && $cells["O"] !== null) {
                $fromBlankR[] = "S{$row}";
            } elseif ($sIsFormula && !$close($cells["S"], ($cells["R"] ?? 0.0) - ($cells["O"] ?? 0.0), abs($cells["R"] ?? 0.0) + abs($cells["O"] ?? 0.0))) {
                $badS[] = "S{$row}";
            }
        }

        if (!$rAny && !$invalid && !$noCache) {
            $push($month, $source, $ratioFormula, [
                "เดือน{$monthName}: คอลัมน์ R (ราคาสรุป) R{$first}:R{$last} ในชีต \"{$expected}\" ว่างทั้งเดือน"
                . " ถือว่ายังไม่มีข้อมูล ระบบไม่นำค่า {$ratioCell} มาใช้และไม่บันทึกเดือนนี้"
            ], [], null);
            continue;
        }

        $reasons = [];

        if ($invalid) {
            $reasons[] = "เดือน{$monthName}: เซลล์ข้อมูลไม่ใช่ตัวเลขหรือมีค่า Error ({$limit($invalid)})";
        }
        if ($noCache) {
            $reasons[] = "เดือน{$monthName}: ไม่มีค่าที่ Excel คำนวณเก็บไว้ในเซลล์ {$limit($noCache)} (เปิดและบันทึกไฟล์ใน Excel ก่อน)";
        }
        if ($noDiff) {
            $reasons[] = "เดือน{$monthName}: แถว " . $limit(array_map("strval", $noDiff)) . " มีราคาเดิม (O) หรือราคาสรุป (R) แต่ไม่มีผลต่าง (S)"
                . " ข้อมูลไม่พอสำหรับคำนวณ ระบบไม่เติมค่าแทน จึงไม่นำเข้าเดือนนี้";
        }
        if ($fromBlankR) {
            $reasons[] = "เดือน{$monthName}: ผลต่าง {$limit($fromBlankR)} เป็นสูตร =R-O แต่ราคาสรุป (R) ของแถวนั้นว่าง"
                . " ผลต่างจึงคำนวณจากข้อมูลที่ยังไม่มี ระบบไม่นำเข้าเดือนนี้";
        }
        if ($badS) {
            $reasons[] = "เดือน{$monthName}: ผลต่าง {$limit($badS)} เป็นสูตร =R-O แต่ค่าที่ Excel เก็บไว้ไม่เท่ากับ R - O"
                . " (เปิดและบันทึกไฟล์ใน Excel ใหม่ หรือตรวจสูตร)";
        }

        /* สูตรของแถวรวม */
        if ($key("R{$n}") !== "=SUM(R{$first}:R{$last})") {
            $reasons[] = "เดือน{$monthName}: R{$n} ต้องเป็นสูตร =SUM(R{$first}:R{$last}) ตามที่ยืนยัน (ไม่รับค่าที่พิมพ์ทับหรือสูตรอื่น)";
        }
        if ($key("S{$n}") !== "=SUM(S{$first}:S{$last})") {
            $reasons[] = "เดือน{$monthName}: S{$n} ต้องเป็นสูตร =SUM(S{$first}:S{$last}) ตามที่ยืนยัน (ไม่รับค่าที่พิมพ์ทับหรือสูตรอื่น)";
        }
        if ($ratioFormula === null || kpiCompanyFormulaKey($ratioFormula) !== "=S{$n}/O{$n}") {
            $reasons[] = "เดือน{$monthName}: {$ratioCell} ต้องเป็นสูตร =S{$n}/\$O\${$n} ตามที่ยืนยัน (ไม่รับค่าที่พิมพ์ทับ สูตรอื่น หรือการอ้างชีตอื่น)";
        }

        /* ค่าของแถวรวม */
        if (!$isNumber($oTotal) || (float) $oTotal <= 0) {
            $reasons[] = "เดือน{$monthName}: ราคาเดิมรวม (O{$n}) ต้องเป็นตัวเลขมากกว่า 0";
        } elseif (!$close((float) $oTotal, $sumO, $scaleO)) {
            $reasons[] = "เดือน{$monthName}: ค่าใน O{$n} ไม่ตรงกับผลรวมของ O{$first}:O{$last}";
        }
        if (!$isNumber($rTotal)) {
            $reasons[] = "เดือน{$monthName}: ราคาสรุปรวม (R{$n}) ต้องเป็นตัวเลข";
        } elseif (!$close((float) $rTotal, $sumR, $scaleR)) {
            $reasons[] = "เดือน{$monthName}: ค่าใน R{$n} ไม่ตรงกับผลรวมของ R{$first}:R{$last}";
        }
        if (!$isNumber($sTotal)) {
            $reasons[] = "เดือน{$monthName}: ผลต่างรวม (S{$n}) ต้องเป็นตัวเลข";
        } elseif (!$close((float) $sTotal, $sumS, $scaleS)) {
            $reasons[] = "เดือน{$monthName}: ค่าใน S{$n} ไม่ตรงกับผลรวมของ S{$first}:S{$last}";
        }
        if ($ratioFormula !== null && $ratio === null) {
            $reasons[] = "เดือน{$monthName}: ไม่มีค่าที่ Excel คำนวณเก็บไว้ใน {$ratioCell} (เปิดและบันทึกไฟล์ใน Excel ก่อน)";
        } elseif (!$isNumber($ratio)) {
            $reasons[] = "เดือน{$monthName}: ค่าใน {$ratioCell} ไม่ใช่ตัวเลข";
        }

        /* S เป็นแหล่งข้อมูลหลัก (พิมพ์เองได้) จึงไม่บังคับ S<n> = R<n> - O<n> · ตรวจ S<n> กับผลรวมรายแถวด้านบนแล้ว */
        if (!$reasons) {
            if (!$close((float) $ratio, (float) $sTotal / (float) $oTotal, abs((float) $ratio))) {
                $reasons[] = "เดือน{$monthName}: ค่าใน {$ratioCell} ไม่เท่ากับ S{$n}/O{$n}";
            }
            if ((float) $ratio < 0 || (float) $sTotal < 0) {
                $reasons[] = "เดือน{$monthName}: ผลการปรับเพิ่มมูลค่าสัญญาติดลบ (มูลค่ารวมลดลง) ระบบไม่บันทึกค่าติดลบและไม่ปรับเป็น 0";
            }
        }

        /* ไล่ตรวจสูตรต้นทางทุกชั้น · ห้ามอ้างชีตของเดือนอื่น */
        $ctx = [
            "visited" => [], "budget" => KPI_COMPANY_MAX_DEPENDENCY_CELLS, "need" => [],
            "form" => $formSheet, "loaded" => $loadedCells
        ];
        $error = kpiCompanyCheckCell($spreadsheet, $workbookSheets, $sheetName, $ratioCell, $ctx)
            ?? kpiCompanyCheckCell($spreadsheet, $workbookSheets, $sheetName, "R{$n}", $ctx);

        if ($error !== null) {
            $reasons[] = "เดือน{$monthName}: " . $error;
        } elseif ($ctx["need"]) {
            if ($final) {
                $reasons[] = "เดือน{$monthName}: ตรวจสอบข้อมูลต้นทางของสูตรไม่ครบ (อ้างอิงข้อมูลมากเกินกว่าที่ระบบตรวจได้)";
            } else {
                foreach ($ctx["need"] as $needSheet => $cells) {
                    $out["need"][$needSheet] = ($out["need"][$needSheet] ?? []) + $cells;
                }
            }
        }

        $otherMonth = [];
        foreach (array_keys($ctx["visited"]) as $visited) {
            $visitedSheet = substr($visited, 0, (int) strrpos($visited, "!"));
            if ($visitedSheet !== $sheetName && preg_match($monthSheetPattern, trim($visitedSheet))) {
                $otherMonth[] = $visited;
            }
        }
        if ($otherMonth) {
            $reasons[] = "เดือน{$monthName}: สูตรอ้างข้อมูลจากชีตของเดือนอื่น (" . $limit($otherMonth) . ") ระบบไม่ใช้ข้อมูลข้ามเดือน";
        }

        $actual = null;
        $details = [];
        $warnings = [];

        if (!$reasons) {
            $ratioText = kpiCompanyPlainDecimal((float) $ratio);
            $fromRatio = $ratioText === null ? null : kpiCompanyFloorPercent($ratioText, "1");
            $sText = kpiCompanyPlainDecimal((float) $sTotal);
            $oText = kpiCompanyPlainDecimal((float) $oTotal);
            $fromTotals = $sText === null || $oText === null ? null : kpiCompanyFloorPercent($sText, $oText);

            if (!function_exists("bcdiv")) {
                $reasons[] = "เซิร์ฟเวอร์ไม่มีส่วนขยาย bcmath ระบบจึงคำนวณค่าแบบแม่นยำไม่ได้ กรุณาติดต่อผู้ดูแลระบบ";
            } elseif ($fromRatio === null || $fromTotals === null) {
                $reasons[] = "เดือน{$monthName}: คำนวณสัดส่วนการปรับเพิ่มมูลค่าสัญญาไม่ได้";
            } elseif ($fromRatio !== $fromTotals) {
                $reasons[] = "เดือน{$monthName}: ค่าใน {$ratioCell} อยู่ชิดขอบจำนวนเต็มจนยืนยันผลปัดลงไม่ได้"
                    . " (FLOOR ของ {$ratioCell} × 100 ไม่ตรงกับ S{$n} × 100 ÷ O{$n})";
            } else {
                $actual = (string) $fromRatio;
                $details = [
                    "rule" => KPI_COMPANY_UPLIFT_RULE,
                    "base" => number_format((float) $oTotal, 2), "base_cell" => "O{$n}",
                    "diff" => number_format((float) $sTotal, 2), "diff_cell" => "S{$n}",
                    "ratio" => rtrim(rtrim(number_format((float) $ratio, 6, ".", ""), "0"), "."), "ratio_cell" => $ratioCell,
                    "actual" => $actual, "target" => KPI_COMPANY_UPLIFT_TARGET
                ];
                $warnings[] = "ค่าจากสูตรเป็นค่าที่ Excel คำนวณไว้เมื่อบันทึกไฟล์ครั้งล่าสุด ระบบไม่คำนวณใหม่และยืนยันไม่ได้ว่าเป็นค่าปัจจุบัน";
                $warnings[] = "ผลต่าง (S{$n}) " . $details["diff"] . " ÷ ราคาเดิม (O{$n}) " . $details["base"]
                    . " = " . $details["ratio"] . " ({$ratioCell}) → Actual {$actual}% (ปัดลงเป็นจำนวนเต็ม) เทียบกับ Target 20%";
            }
        }

        $push($month, $source, $ratioFormula, $reasons, $warnings, $reasons ? null : $actual, $reasons ? [] : $details);
    }
}


/*
|--------------------------------------------------------------------------
| 5) เตรียมนำเข้า: ตรวจ KPI ที่ยืนยันกับฐานข้อมูล + Validation เดิม (อ่านอย่างเดียว ไม่เขียนข้อมูล)
|--------------------------------------------------------------------------
|
| รายการ READY จากตัวอ่านต้องผ่านเพิ่ม:
|   - kpi_id มีอยู่จริง · ประเภทตรงกับส่วนของแบบฟอร์ม · ชื่อ KPI ในระบบตรงกับ confirmed_kpi_name ทุกตัวอักษร
|   - kpiImportValidate() เดิม (Assignment ของผู้ใช้, รอบประเมิน Open, แก้ได้เฉพาะ Draft, ซ้ำในไฟล์ ฯลฯ)
| ไม่ผ่าน → REJECTED และ commit_ok = false (ปิดการนำเข้าทั้งไฟล์ ไม่บันทึกบางส่วน)
| รายการที่ Mapping ยังไม่ยืนยัน (NEEDS_CONFIRMATION) ไม่อยู่ในขอบเขตการนำเข้าและไม่ถูกบันทึก
|
*/
function kpiCompanyPrepare(PDO $pdo, int $employeeId, string $path, int $year, ?array $map = null, ?int $onlyMonth = null, ?DateTimeInterface $now = null): array
{
    $codeStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE employee_id = :employee_id LIMIT 1");
    $codeStmt->execute([":employee_id" => $employeeId]);
    $employeeCode = $codeStmt->fetchColumn();

    if ($employeeCode === false) {
        return ["errors" => ["ไม่พบข้อมูลพนักงานของผู้ใช้"], "notes" => [], "items" => [], "rows" => [], "preview" => [], "commit_ok" => false, "skipped_empty" => 0];
    }

    $result = kpiCompanyRead($path, $year, (string) $employeeCode, $map, $onlyMonth, $now);
    $result["preview"] = [];
    $result["commit_ok"] = false;

    if ($result["errors"]) {
        return $result;
    }

    $sectionType = ["performance" => "performance", "competency" => "competency"];
    $ids = array_values(array_unique(array_map(
        fn($item) => (int) $item["kpi_id"],
        array_filter($result["items"], fn($item) => $item["status"] === KPI_COMPANY_STATUS_READY)
    )));
    $kpis = [];

    if ($ids) {
        $in = implode(",", array_fill(0, count($ids), "?"));
        $stmt = $pdo->prepare("SELECT kpi_id, kpi_name, kpi_type FROM kpi_indicators WHERE kpi_id IN ({$in})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $kpi) {
            $kpis[(int) $kpi["kpi_id"]] = $kpi;
        }
    }

    $rows = [];

    foreach ($result["items"] as &$item) {

        if ($item["status"] !== KPI_COMPANY_STATUS_READY) {
            continue;
        }

        $kpi = $kpis[(int) $item["kpi_id"]] ?? null;
        $problem = null;

        if ($kpi === null) {
            $problem = "ไม่พบ KPI รหัส {$item["kpi_id"]} ในระบบ (ตรวจ mapping ที่ยืนยันไว้)";
        } elseif (strtolower((string) $kpi["kpi_type"]) !== $sectionType[$item["section"]]) {
            $problem = "KPI รหัส {$item["kpi_id"]} ในระบบไม่ใช่ประเภท " . ucfirst($item["section"]);
        } elseif ((string) $kpi["kpi_name"] !== (string) $item["confirmed_kpi_name"]) {
            $problem = "ชื่อ KPI รหัส {$item["kpi_id"]} ในระบบไม่ตรงกับชื่อที่ยืนยันไว้";
        }

        if ($problem !== null) {
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"][] = $problem;
            continue;
        }

        foreach ($result["rows"] as $row) {
            if ($row["row"] === $item["row"]) {
                $rows[] = $row;
            }
        }
    }
    unset($item);

    $validation = $rows ? kpiImportValidate($pdo, $employeeId, $rows, false, KPI_IMPORT_ORIGIN_COMPANY) : ["ok" => true, "rows" => []];
    $byRow = [];
    foreach ($validation["rows"] as $checked) {
        $byRow[$checked["row"]] = $checked;
    }

    foreach ($result["items"] as &$item) {
        if ($item["status"] !== KPI_COMPANY_STATUS_READY) {
            continue;
        }
        $checked = $byRow[$item["row"]] ?? null;
        if ($checked === null || $checked["action"] === "error") {
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"] = array_merge($item["reasons"], $checked["errors"] ?? ["ตรวจสอบรายการนี้ไม่สำเร็จ"]);
        } elseif (($item["value_rule"] ?? null) === KPI_COMPANY_RENEWAL_RULE && ($checked["target"] ?? null) !== KPI_COMPANY_RENEWAL_TARGET) {
            /* Actual เป็นร้อยละของ Budget จึงใช้ได้เฉพาะเมื่อ Target ใน Assignment = 100 (%) */
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"][] = "Target ของ KPI นี้ในระบบ (" . ($checked["target"] ?? "-") . ") ไม่ใช่ 100 (%) ตามที่ยืนยัน กรุณาติดต่อผู้ดูแลระบบ";
        } elseif (($item["value_rule"] ?? null) === KPI_COMPANY_NON_RENEWAL_RULE && ($checked["target"] ?? null) !== ($item["details"]["target"] ?? false)) {
            /* KPI 9: Target ที่ตรวจ/บันทึกต้องเป็นค่าที่ตัวอ่านกำหนด (100) ไม่ใช่ค่าใน Assignment */
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"][] = "Target ของรายการนี้ไม่ตรงกับที่ตัวอ่านไฟล์กำหนด (100) ระบบจึงไม่บันทึก";
        } elseif (($item["value_rule"] ?? null) === KPI_COMPANY_UPLIFT_RULE && ($checked["target"] ?? null) !== KPI_COMPANY_UPLIFT_TARGET) {
            /* Actual เป็นร้อยละการเพิ่มของมูลค่าสัญญา จึงใช้ได้เฉพาะเมื่อ Target ใน Assignment = 20 (%) */
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"][] = "Target ของ KPI นี้ในระบบ (" . ($checked["target"] ?? "-") . ") ไม่ใช่ 20 (%) ตามที่ยืนยัน กรุณาติดต่อผู้ดูแลระบบ";
        } elseif (
            ($item["value_rule"] ?? null) === KPI_COMPANY_UPLIFT_RULE
            && ($criteriaReason = kpiCompanyUpliftCriteriaReason($pdo, (int) $item["kpi_id"], (int) $item["value"])) !== null
        ) {
            $item["status"] = KPI_COMPANY_STATUS_REJECTED;
            $item["reasons"][] = $criteriaReason;
        } else {
            $item["action"] = $checked["action"];
        }
    }
    unset($item);

    $inScopeFailed = array_filter($result["items"], fn($i) => $i["mapping_ready"] && $i["status"] !== KPI_COMPANY_STATUS_READY);
    $readyRows = array_column(array_filter($result["items"], fn($i) => $i["status"] === KPI_COMPANY_STATUS_READY), "row");

    $result["rows"] = array_values(array_filter($rows, fn($row) => in_array($row["row"], $readyRows, true)));
    $result["preview"] = array_values(array_filter($validation["rows"], fn($row) => in_array($row["row"], $readyRows, true)));

    /* ข้อมูลประกอบ Preview (Budget / Total R / ...) และธงที่ต้องยืนยันรายแถว จากผลการอ่านไฟล์ */
    $itemByRow = [];
    foreach ($result["items"] as $item) {
        if (isset($item["row"])) {
            $itemByRow[$item["row"]] = $item;
        }
    }
    foreach ($result["preview"] as &$previewRow) {
        $source = $itemByRow[$previewRow["row"]] ?? [];
        $previewRow["requires_confirmation"] = $source["requires_confirmation"] ?? null;
        $previewRow["details"] = $source["details"] ?? [];
        $previewRow["warnings"] = $source["warnings"] ?? [];
    }
    unset($previewRow);
    $result["commit_ok"] = !$inScopeFailed && $result["rows"] !== [] && $validation["ok"];

    if ($inScopeFailed) {
        $result["notes"][] = "มีรายการที่ยืนยัน Mapping แล้วแต่ไม่ผ่านการตรวจสอบ " . count($inScopeFailed)
            . " รายการ ระบบจึงยังไม่เปิดให้นำเข้าจากไฟล์นี้ (ไม่บันทึกบางส่วน)";
    }

    return $result;
}

/* ก่อนบันทึก: ตรวจซ้ำว่า KPI ที่ยืนยันยังมีอยู่ ประเภทและชื่อไม่เปลี่ยนตั้งแต่แสดงตัวอย่าง · คืนข้อความ error หรือ null */
function kpiCompanyRecheckKpis(PDO $pdo, array $items): ?string
{
    $stmt = $pdo->prepare("SELECT kpi_name, kpi_type FROM kpi_indicators WHERE kpi_id = :kpi_id LIMIT 1");

    foreach ($items as $item) {
        if (($item["status"] ?? null) !== KPI_COMPANY_STATUS_READY) {
            continue;
        }
        $stmt->execute([":kpi_id" => (int) $item["kpi_id"]]);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$kpi
            || strtolower((string) $kpi["kpi_type"]) !== $item["section"]
            || (string) $kpi["kpi_name"] !== (string) $item["confirmed_kpi_name"]
        ) {
            return "ข้อมูล KPI ในระบบเปลี่ยนไปหลังแสดงตัวอย่าง จึงยกเลิกการนำเข้าทั้งชุด กรุณาอัปโหลดไฟล์อีกครั้ง";
        }
    }

    return null;
}
