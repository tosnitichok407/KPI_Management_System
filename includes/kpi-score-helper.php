<?php

/*
|--------------------------------------------------------------------------
| KPI Score Helper — ตัดเกรดจาก "เกณฑ์ระดับผลงาน" (Criteria) ที่ Admin กำหนด
|--------------------------------------------------------------------------
| ใช้ร่วมกันระหว่างหน้ากรอกผลงาน (employee/kpi/kpi.php, employee/kpi-detail.php)
| และสคริปต์คำนวณคะแนนย้อนหลัง เพื่อให้ทุกที่ใช้กติกาเดียวกัน
|
*/

/* ค่าคลาดเคลื่อนที่ยอมรับได้ตอนเทียบทศนิยม (คอลัมน์ actual เป็น decimal(15,2)) */
const KPI_SCORE_EPSILON = 0.0001;

const KPI_MIN_LEVEL = 1;
const KPI_MAX_LEVEL = 5;

/*
| หน่วยของ KPI กำหนดอัตโนมัติเป็น % (ข้อกำหนด: ฟอร์มเพิ่ม/แก้ไข KPI ของ Admin ไม่มีช่องหน่วยแล้ว)
| หน้าเพิ่มและหน้าแก้ไข KPI บันทึกค่านี้ทุกครั้ง · หน่วยใช้แสดงผลเท่านั้น ไม่ใช้ในการคำนวณคะแนน
*/
const KPI_DEFAULT_UNIT = "%";

/*
| หน่วยที่ใช้ต่อท้ายค่า Target / Actual บนหน้าจอและไฟล์ Export
| Competency ให้คะแนนเป็นระดับ 1–5 จึงแสดงเป็น "คะแนน" เสมอ (ไม่ต่อท้าย % แม้หน่วยที่บันทึกเป็น %)
| Performance ใช้หน่วยที่บันทึกไว้
*/
function kpiDisplayUnit(array $kpi): string
{
    if (strcasecmp(trim((string) ($kpi["kpi_type"] ?? "")), "Competency") === 0) {
        return "คะแนน";
    }

    return trim((string) ($kpi["unit"] ?? ""));
}

/*
| ระดับคะแนนของ KPI หนึ่งตัวต้องเป็นจำนวนเต็มเสมอ (ค่าเฉลี่ยมีทศนิยมได้)
| คะแนนเก่าที่บันทึกเป็นทศนิยม เช่น 4.75 ปัดเศษลงเป็น 4
*/
function kpiScoreLevel(float $score): int
{
    return (int) floor($score + KPI_SCORE_EPSILON);
}

/*
| KPI 10 "ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม" — สูตรผลการดำเนินงานที่ผู้ใช้ยืนยัน (26 ต.ค. 2026)
|   KPI10 (%) = จำนวนลูกค้าเดิมที่ขายเพิ่มได้ ÷ จำนวนลูกค้าเดิมทั้งหมด × 100   เช่น 25 ÷ 200 × 100 = 12.5
| เจ้าของระบบยืนยัน (26 ต.ค. 2026): ตัวตั้ง = จำนวนแถวที่คอลัมน์ S (ผลต่างราคา) > 0 · ตัวหาร = จำนวนลูกค้าเดิมที่ต่อสัญญา (คอลัมน์ B)
| ตัวอ่านไฟล์บริษัท (KPI_COMPANY_UPSELL_RULE) นับจากชีตของเดือน · เกณฑ์คะแนนแยกที่ kpiUpsellGradeExact()
| คืน ["percent" => ?float (ไม่ปัดเศษ), "error" => ?string]
*/
function kpiUpsellPercent($upsoldCustomers, $existingCustomers): array
{
    $fail = fn(string $message) => ["percent" => null, "error" => $message];
    $isCount = fn($value) => $value !== null && $value !== "" && preg_match('/^\d+$/', trim((string) $value)) === 1;

    if (!$isCount($existingCustomers)) {
        return $fail("จำนวนลูกค้าเดิมทั้งหมดต้องเป็นจำนวนเต็มไม่ติดลบ");
    }
    if (!$isCount($upsoldCustomers)) {
        return $fail("จำนวนลูกค้าเดิมที่ขายเพิ่มได้ต้องเป็นจำนวนเต็มไม่ติดลบ");
    }

    $total = (int) $existingCustomers;
    $upsold = (int) $upsoldCustomers;

    if ($total === 0) {
        return $fail("ไม่มีลูกค้าเดิม (จำนวนลูกค้าเดิมทั้งหมด = 0) จึงคำนวณร้อยละไม่ได้");
    }
    if ($upsold > $total) {
        return $fail("จำนวนลูกค้าที่ขายเพิ่มได้ ({$upsold}) มากกว่าจำนวนลูกค้าเดิมทั้งหมด ({$total}) ข้อมูลไม่สอดคล้อง กรุณาตรวจการนับซ้ำ");
    }

    return ["percent" => $upsold / $total * 100, "error" => null];
}

/*
| คะแนนรวมแบบถ่วงน้ำหนักของพนักงานหนึ่งคนในหนึ่งเดือน (ใช้ร่วมกันทุกหน้าที่แสดงคะแนนรวม)
|
| $rows = [["score" => ?float, "weight" => float, "max_score" => ?float], ...] (1 แถวต่อ KPI ที่มอบหมาย)
|   percent    = Σ(score / max_score × weight) / Σweight × 100 ของ KPI ที่ประเมินแล้ว
|                (สูตรเดียวกับรายงาน Manager และ Admin)
|   real       = Σ floor(weight × ระดับ) ของ KPI ที่ประเมินแล้ว   (= "คะแนนจริง" ในแบบฟอร์ม KPI AM: M30)
|   full       = Σ(weight × 5) ของ KPI ที่มอบหมายทั้งหมด            (= "คะแนนเต็ม" ในแบบฟอร์ม KPI AM: J30)
|   average    = ค่าเฉลี่ยคะแนน 1–5 แบบไม่ถ่วงน้ำหนัก (แสดงประกอบ)
| เดือนที่ประเมินครบทุก KPI: percent = real / full × 100 (เช่น 388 / 500 = 77.6%)
*/
function kpiWeightedSummary(array $rows): array
{
    $weightedSum = 0.0;
    $weightedWeight = 0.0;
    $real = 0.0;
    $full = 0.0;
    $scoreSum = 0.0;
    $evaluated = 0;

    foreach ($rows as $row) {
        $weight = (float) ($row["weight"] ?? 0);
        $maxScore = (float) ($row["max_score"] ?? 0) ?: (float) KPI_MAX_LEVEL;
        $full += $weight * KPI_MAX_LEVEL;

        if (!isset($row["score"]) || $row["score"] === null || $row["score"] === "") {
            continue;
        }

        $score = (float) $row["score"];
        $weightedSum += $score / $maxScore * $weight;
        $weightedWeight += $weight;
        $real += floor($weight * kpiScoreLevel($score) + KPI_SCORE_EPSILON);
        $scoreSum += $score;
        $evaluated++;
    }

    return [
        "percent" => $weightedWeight > 0 ? $weightedSum / $weightedWeight * 100 : null,
        "real" => $real,
        "full" => $full,
        "average" => $evaluated > 0 ? $scoreSum / $evaluated : null,
        "evaluated" => $evaluated,
    ];
}

/*
| เพดานที่ยังถือว่า Target กับเกณฑ์เป็นหน่วยเดียวกัน (ดู kpiCriteriaCompareValue)
| เช่น เกณฑ์สูงสุด "98% ขึ้นไป" แต่ตั้งเป้าไว้ 100% ยังนับเป็นหน่วยเดียวกัน
*/
const KPI_CRITERIA_TARGET_TOLERANCE = 1.5;


/*
|--------------------------------------------------------------------------
| กฎเฉพาะ KPI ID 9 "มูลค่าไม่ต่อสัญญา"
|--------------------------------------------------------------------------
|
| Target = Budget (จำนวนเงิน) · Actual = Total R (ไม่รวม Total N และห้ามใช้ Total R+N)
| Achievement = Total R / Budget × 100
|
|   Achievement >= 100%          → 5
|   96% <= Achievement < 100%    → 4
|   90% <= Achievement < 96%     → 3
|   85% <= Achievement < 90%     → 2
|   Achievement < 85%            → 1
|
| ช่วงคะแนนต่อเนื่องนี้เป็นข้อกำหนดของระบบ เพื่อให้ทุกเปอร์เซ็นต์ทศนิยมมีคะแนน
| (เช่น 99.5% → 4) ไม่ใช่การคัดลอกสูตร Excel เดิมทุกประการ
|
| Budget เป็น 0 / NULL / ไม่ใช่ตัวเลข → ไม่คำนวณคะแนน (kpiCalculateScore คืน error)
|
| ระบุ KPI ด้วย kpi_id = 9 เท่านั้น (ชื่อไม่ใช่เงื่อนไขเลือกกฎ)
| ถ้าชื่อไม่ตรง "มูลค่าไม่ต่อสัญญา" → บันทึกคำเตือนลง error_log แต่ยังใช้กฎ KPI 9 ต่อ
| (ไม่กลับไปใช้เกณฑ์ทั่วไป ซึ่งของ KPI 9 ในฐานข้อมูลเป็นเกณฑ์เก่ากลับทิศ)
| KPI 9 ต้องเป็นประเภท Performance · ถ้าไม่ใช่ หรือไม่ได้ส่ง kpi_type มา
| → kpiCalculateScore() คืน error ไม่คำนวณ ไม่บันทึก และไม่แก้ฐานข้อมูลเอง
|
| ทุกหน้าต้องคำนวณผ่าน kpiCalculateScore() / nonRenewalAchievement() เท่านั้น:
| หน้า KPI ของฉัน · หน้ารายละเอียด KPI · หน้าผลงาน · แบบฟอร์ม Excel/PDF · สคริปต์คำนวณย้อนหลัง
| loadKpiScoreCriteria() คืนข้อความเกณฑ์ชุดนี้ (ใช้แสดงผลเท่านั้น ไม่ผ่านตัวอ่านเกณฑ์ทั่วไป)
|
*/

const NON_RENEWAL_KPI_ID = 9;
const NON_RENEWAL_KPI_NAME = "มูลค่าไม่ต่อสัญญา";

/* เพดานของ kpi_assignments.target_value = decimal(10,2) */
const KPI_BUDGET_MAX = 99999999.99;

/* เกณฑ์ขั้นต่ำ (%) ของแต่ละระดับ · ต่ำกว่า 85% = ระดับ 1 */
const NON_RENEWAL_KPI_THRESHOLDS = [
    5 => 100,
    4 => 96,
    3 => 90,
    2 => 85,
];

/*
| ข้อความเกณฑ์สำหรับแสดงผล (แบบฟอร์ม PDF / Excel · หน้ารายละเอียด KPI · หน้า Admin) — ใช้แสดงผลเท่านั้น
| เขียนแบบเดียวกับสูตรเกรด L14 ในแบบฟอร์ม KPI AM ของบริษัท (เฉพาะเครื่องหมายกับตัวเลข · 2026-10-19)
| การตัดเกรดจริงใช้ NON_RENEWAL_KPI_THRESHOLDS ด้านบน (≥100 / ≥96 / ≥90 / ≥85 · ค่าระหว่างช่วง เช่น 99.5% = ระดับ 4)
*/
const NON_RENEWAL_KPI_CRITERIA = [
    5 => "≥100%",
    4 => "96-99%",
    3 => "90-95%",
    2 => "85-89%",
    1 => "<85%",
];

function isNonRenewalKpi(array $kpi): bool
{
    if ((int) ($kpi["kpi_id"] ?? 0) !== NON_RENEWAL_KPI_ID) {
        return false;
    }

    /* ชื่อใช้ตรวจความถูกต้องของข้อมูลเท่านั้น (เตือนครั้งเดียวต่อ request) */
    if (array_key_exists("kpi_name", $kpi) && !nonRenewalKpiNameMatches($kpi)) {
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log(
                "kpi-score-helper: kpi_id " . NON_RENEWAL_KPI_ID . " name mismatch ("
                . (string) $kpi["kpi_name"]
                . ") · non-renewal rule still applied by kpi_id"
            );
        }
    }

    return true;
}

/* ชื่อ KPI ตรงกับชื่อที่กฎ KPI 9 คาดไว้หรือไม่ (ใช้แสดงคำเตือน ไม่ใช่เงื่อนไขเลือกกฎ) */
function nonRenewalKpiNameMatches(array $kpi): bool
{
    return mb_strpos((string) ($kpi["kpi_name"] ?? ""), NON_RENEWAL_KPI_NAME) !== false;
}

const NON_RENEWAL_TYPE_ERROR =
    "ข้อมูล KPI มูลค่าไม่ต่อสัญญา (ID 9) ต้องเป็นประเภท Performance จึงจะคำนวณคะแนนได้"
    . " ระบบไม่บันทึกคะแนน กรุณาติดต่อผู้ดูแลระบบ";

/*
| ตรวจประเภทของ KPI 9 · null = ถูกต้อง
| ไม่ได้ส่ง kpi_type มา ถือว่าข้อมูลไม่ครบ (fail closed) เพื่อไม่ให้คำนวณจากข้อมูลที่ตรวจไม่ได้
*/
function nonRenewalKpiTypeError(array $kpi): ?string
{
    if (!isNonRenewalKpi($kpi)) {
        return null;
    }

    return strtolower(trim((string) ($kpi["kpi_type"] ?? ""))) === "performance"
        ? null
        : NON_RENEWAL_TYPE_ERROR;
}

/* แปลงจำนวนเงิน / ตัวเลข → หน่วยสตางค์ (จำนวนเต็ม) · null = ไม่ใช่ตัวเลข */
function kpiToCents($value): ?int
{
    if ($value === null || $value === "" || !is_numeric($value)) {
        return null;
    }

    return (int) round((float) $value * 100);
}

/*
| Achievement (%) แบบตัดทศนิยมลง 2 ตำแหน่ง (basis point) เพื่อไม่ให้ค่าที่แสดง
| ข้ามเกณฑ์ที่ยังไม่ถึงจริง เช่น 95.999% แสดง 95.99% (ไม่ใช่ 96.00%)
| คำนวณจากจำนวนเต็มเพื่อเลี่ยงความคลาดเคลื่อนของ float ที่ขอบเกณฑ์
| null = Budget เป็น 0 / NULL / ไม่ใช่ตัวเลข หรือไม่มี Total R
*/
function nonRenewalAchievementBasisPoints($totalR, $budget): ?int
{
    $budgetCents = kpiToCents($budget);
    $actualCents = kpiToCents($totalR);

    if ($budgetCents === null || $budgetCents <= 0 || $actualCents === null || $actualCents < 0) {
        return null;
    }

    if ($actualCents > intdiv(PHP_INT_MAX, 10000)) {
        return (int) floor($actualCents / $budgetCents * 10000);
    }

    return intdiv($actualCents * 10000, $budgetCents);
}

function nonRenewalAchievement($totalR, $budget): ?float
{
    $basisPoints = nonRenewalAchievementBasisPoints($totalR, $budget);

    return $basisPoints === null ? null : $basisPoints / 100;
}

/* ระดับ 1..5 จาก Total R และ Budget · null = คำนวณไม่ได้ */
function nonRenewalKpiGrade($totalR, $budget): ?int
{
    $basisPoints = nonRenewalAchievementBasisPoints($totalR, $budget);

    if ($basisPoints === null) {
        return null;
    }

    foreach (NON_RENEWAL_KPI_THRESHOLDS as $level => $minimumPercent) {
        if ($basisPoints >= $minimumPercent * 100) {
            return $level;
        }
    }

    return KPI_MIN_LEVEL;
}

/* Budget ใช้ได้หรือไม่ (มากกว่า 0) */
function kpiBudgetIsValid($budget): bool
{
    $cents = kpiToCents($budget);

    return $cents !== null && $cents > 0;
}

const NON_RENEWAL_BUDGET_ERROR =
    "KPI มูลค่าไม่ต่อสัญญายังไม่ได้กำหนด Budget (เป้าหมายจำนวนเงิน) หรือ Budget เป็น 0"
    . " จึงคำนวณคะแนนไม่ได้ กรุณาติดต่อผู้ดูแลระบบ";

/*
|--------------------------------------------------------------------------
| กฎเฉพาะ KPI ID 10 และ KPI ID 14 (เจ้าของระบบยืนยัน 26 ต.ค. 2026)
|--------------------------------------------------------------------------
|
| KPI 10 "ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม"
|   ผล (%) = จำนวนแถวที่ผลต่างราคา (คอลัมน์ S) > 0 ÷ จำนวนลูกค้าเดิมที่ต่อสัญญา (คอลัมน์ B) × 100
|   เป้าหมาย 10% · เกณฑ์: > 10% = 5 · = 10% = 4 · 5% ถึงต่ำกว่า 10% = 3 · < 5% = 2 (ไม่มีระดับ 1)
|   ตัดสินจากค่าจริงไม่ปัดเศษ (ตอนนำเข้าใช้จำนวนรายโดยตรง: n × 100 เทียบ 10 × d)
|
| KPI 14 "บรรลุเป้าหมายการต่อสัญญา" (Total R ÷ Budget × 100 · เป้าหมาย 100%)
|   เกณฑ์ตามสูตร Excel KPI AM!L12 ที่เจ้าของระบบยืนยัน:
|   =IF(K>100%,5, IF(AND(K<=100%,K>=91%),4, IF(K=90%,3, IF(AND(K<=89%,K>=85%),2, IF(K<85%,1,"")))))
|   ช่วงที่สูตรไม่กำหนดคะแนน (89% < K < 90% และ 90% < K < 91%) → ไม่ให้คะแนน (รอเจ้าของระบบยืนยัน)
|   ตอนนำเข้าตัดสินจาก Total R และ Budget หน่วยสตางค์ (ไม่ใช้ค่าที่ตัดทศนิยมแล้ว)
|
| ระบุ KPI ด้วย kpi_id เท่านั้น (เหมือน KPI 9) · เกณฑ์ข้อความในตาราง kpi_score_criteria ของ KPI 10 / 14 ไม่ถูกใช้ตัดเกรด
|
*/

const UPSELL_KPI_ID = 10;
const RENEWAL_KPI_ID = 14;

const UPSELL_KPI_CRITERIA = [
    5 => "> 10%",
    4 => "= 10%",
    3 => "5% ถึงต่ำกว่า 10%",
    2 => "< 5%",
];

const RENEWAL_KPI_CRITERIA = [
    5 => "> 100%",
    4 => "91% – 100%",
    3 => "= 90%",
    2 => "85% – 89%",
    1 => "< 85%",
];

/*
| KPI 11 "เข้าเยี่ยมลูกค้าตาม KPI ที่บริษัทกำหนด" (เจ้าของระบบกำหนด 29 ต.ค. 2026)
|   ผล (%) = จำนวนครั้งที่เข้าเยี่ยม ÷ จำนวนลูกค้าที่ต้องเข้าเยี่ยม × 100 (ชีต "แผนการตรวจเยี่ยม" · เกินได้ 100%)
|   เกณฑ์ตามสูตร KPI AM!K16:
|   =IF(K16>100%,5, IF(K16=100%,4, IF(AND(K16<=99%,K16>=96%),3, IF(AND(K16<=95%,K16>=90%),2, IF(K16<90%,1,"")))))
|   ช่วงที่สูตรไม่กำหนดคะแนน (99% < K < 100% และ 95% < K < 96%) → ไม่ให้คะแนน (รอเจ้าของระบบยืนยัน)
|   ตอนนำเข้าตัดสินจากจำนวนครั้งและจำนวนลูกค้าโดยตรง (ไม่ใช้ค่าที่ตัดทศนิยมแล้ว)
*/
const VISIT_KPI_ID = 11;

const VISIT_KPI_CRITERIA = [
    5 => "> 100%",
    4 => "= 100%",
    3 => "96% – 99%",
    2 => "90% – 95%",
    1 => "< 90%",
];

const VISIT_KPI_GAP_MESSAGE = "ผลอยู่ในช่วงที่สูตรเกณฑ์ของบริษัทไม่กำหนดคะแนน (มากกว่า 99% แต่ต่ำกว่า 100% หรือมากกว่า 95% แต่ต่ำกว่า 96%)"
    . " ระบบไม่กำหนดคะแนนเอง · รอเจ้าของระบบยืนยัน";

/* KPI 11 จากจำนวนครั้งที่เข้าเยี่ยมและจำนวนลูกค้า (ไม่ปัดเศษ) · null = อยู่ในช่องว่างของเกณฑ์หรือข้อมูลไม่ถูกต้อง */
function kpiVisitGradeExact(int $visits, int $customers): ?int
{
    if ($customers <= 0 || $visits < 0) {
        return null;
    }

    $k = $visits * 100;   // เทียบ (v ÷ c) × 100 กับเกณฑ์ % โดยไม่หาร
    if ($visits > $customers) {
        return 5;
    }
    if ($visits === $customers) {
        return 4;
    }
    if ($k >= 96 * $customers && $k <= 99 * $customers) {
        return 3;
    }
    if ($k >= 90 * $customers && $k <= 95 * $customers) {
        return 2;
    }
    if ($k < 90 * $customers) {
        return 1;
    }

    return null;
}

/* KPI 11 จากร้อยละที่บันทึก (ทศนิยม 2 ตำแหน่ง) · null = ช่องว่างของเกณฑ์ */
function kpiVisitGradeFromPercent($percent): ?int
{
    $bp = kpiToCents($percent);
    if ($bp === null || $bp < 0) {
        return null;
    }

    if ($bp > 10000) {
        return 5;
    }
    if ($bp === 10000) {
        return 4;
    }
    if ($bp >= 9600 && $bp <= 9900) {
        return 3;
    }
    if ($bp >= 9000 && $bp <= 9500) {
        return 2;
    }
    if ($bp < 9000) {
        return 1;
    }

    return null;
}

const RENEWAL_KPI_GAP_MESSAGE = "ผลอยู่ในช่วงที่สูตรเกณฑ์ของบริษัทไม่กำหนดคะแนน (มากกว่า 89% แต่ต่ำกว่า 90% หรือมากกว่า 90% แต่ต่ำกว่า 91%)"
    . " ระบบไม่กำหนดคะแนนเอง · รอเจ้าของระบบยืนยัน";

/* KPI 10 จากจำนวนราย (ไม่ปัดเศษ) · null = ตัวหารไม่ถูกต้อง */
function kpiUpsellGradeExact(int $positive, int $customers): ?int
{
    if ($customers <= 0 || $positive < 0 || $positive > $customers) {
        return null;
    }

    $left = $positive * 100;      // เทียบ n ÷ d × 100 กับ 10 / 5 โดยไม่หาร
    if ($left > 10 * $customers) {
        return 5;
    }
    if ($left === 10 * $customers) {
        return 4;
    }
    if ($left >= 5 * $customers) {
        return 3;
    }

    return 2;
}

/* KPI 10 จากร้อยละที่บันทึก (ทศนิยม 2 ตำแหน่ง) */
function kpiUpsellGradeFromPercent($percent): ?int
{
    $bp = kpiToCents($percent);   // ร้อยละ × 100 (basis point)
    if ($bp === null || $bp < 0) {
        return null;
    }

    return $bp > 1000 ? 5 : ($bp === 1000 ? 4 : ($bp >= 500 ? 3 : 2));
}

/* KPI 14 จาก Total R และ Budget (หน่วยสตางค์) · null = อยู่ในช่องว่างของเกณฑ์หรือข้อมูลไม่ถูกต้อง */
function kpiRenewalGradeExact(int $totalCents, int $budgetCents): ?int
{
    if ($budgetCents <= 0 || $totalCents < 0) {
        return null;
    }

    $k = $totalCents * 100;   // เทียบ (T ÷ B) × 100 กับเกณฑ์ % โดยไม่หาร
    if ($totalCents > $budgetCents) {
        return 5;
    }
    if ($k >= 91 * $budgetCents) {
        return 4;
    }
    if ($k === 90 * $budgetCents) {
        return 3;
    }
    if ($k >= 85 * $budgetCents && $k <= 89 * $budgetCents) {
        return 2;
    }
    if ($k < 85 * $budgetCents) {
        return 1;
    }

    return null;
}

/* KPI 14 จากร้อยละที่บันทึก (ทศนิยม 2 ตำแหน่ง) · null = ช่องว่างของเกณฑ์ */
function kpiRenewalGradeFromPercent($percent): ?int
{
    $bp = kpiToCents($percent);
    if ($bp === null || $bp < 0) {
        return null;
    }

    if ($bp > 10000) {
        return 5;
    }
    if ($bp >= 9100) {
        return 4;
    }
    if ($bp === 9000) {
        return 3;
    }
    if ($bp >= 8500 && $bp <= 8900) {
        return 2;
    }
    if ($bp < 8500) {
        return 1;
    }

    return null;
}

function kpiIsRuleKpi(array $kpi, int $kpiId): bool
{
    return (int) ($kpi["kpi_id"] ?? 0) === $kpiId;
}

/*
|--------------------------------------------------------------------------
| ฟังก์ชันกลาง: คำนวณคะแนน Performance KPI หนึ่งรายการ
|--------------------------------------------------------------------------
|
| $kpi ต้องมี kpi_id, kpi_name และ kpi_type · $levels = เกณฑ์จาก loadKpiScoreCriteria()
| คืน ["grade" => ?int, "achievement" => ?float, "error" => ?string]
|   - KPI 9: ใช้ Budget / Total R ตามกฎด้านบน
|   - KPI อื่น: ใช้ kpiPerformanceGrade() ตามเดิม (achievement = null)
|
*/
function kpiCalculateScore(array $kpi, array $levels, $actual, $target): array
{
    $actualMissing = $actual === null || $actual === "";

    if (isNonRenewalKpi($kpi)) {

        $typeError = nonRenewalKpiTypeError($kpi);
        if ($typeError !== null) {
            return ["grade" => null, "achievement" => null, "error" => $typeError];
        }

        if (!kpiBudgetIsValid($target)) {
            return ["grade" => null, "achievement" => null, "error" => NON_RENEWAL_BUDGET_ERROR];
        }

        if ($actualMissing) {
            return ["grade" => null, "achievement" => null, "error" => null];
        }

        return [
            "grade" => nonRenewalKpiGrade($actual, $target),
            "achievement" => nonRenewalAchievement($actual, $target),
            "error" => null
        ];
    }

    /* KPI 10 / 11 / 14: เกณฑ์ที่เจ้าของระบบยืนยัน (ตัดสินจากร้อยละที่บันทึก · ตอนนำเข้าใช้ค่าจริงแทน ดู kpiImportValidate) */
    foreach ([UPSELL_KPI_ID, VISIT_KPI_ID, RENEWAL_KPI_ID] as $ruleKpiId) {
        if (!kpiIsRuleKpi($kpi, $ruleKpiId)) {
            continue;
        }
        if (strtolower(trim((string) ($kpi["kpi_type"] ?? ""))) !== "performance") {
            return ["grade" => null, "achievement" => null, "error" => "KPI รหัส {$ruleKpiId} ต้องเป็นประเภท Performance จึงจะคำนวณคะแนนได้"];
        }
        if ($actualMissing) {
            return ["grade" => null, "achievement" => null, "error" => null];
        }
        if (!is_numeric($actual) || (float) $actual < 0) {
            return ["grade" => null, "achievement" => null, "error" => "ผลการดำเนินงานต้องเป็นตัวเลขไม่ติดลบ"];
        }
        if ($ruleKpiId === UPSELL_KPI_ID) {
            return ["grade" => kpiUpsellGradeFromPercent($actual), "achievement" => null, "error" => null];
        }
        if ($ruleKpiId === VISIT_KPI_ID) {
            $grade = kpiVisitGradeFromPercent($actual);
            return [
                "grade" => $grade,
                "achievement" => null,
                "error" => null,
                "pending" => $grade === null ? VISIT_KPI_GAP_MESSAGE : null,
            ];
        }
        $grade = kpiRenewalGradeFromPercent($actual);
        return [
            "grade" => $grade,
            "achievement" => null,
            "error" => null,
            "pending" => $grade === null ? RENEWAL_KPI_GAP_MESSAGE : null,
        ];
    }

    if ($actualMissing) {
        return ["grade" => null, "achievement" => null, "error" => null];
    }

    $targetValue = $target !== null && $target !== "" && (float) $target > 0 ? (float) $target : null;
    $grade = kpiPerformanceGrade($levels, (float) $actual, $targetValue);

    return [
        "grade" => $grade,
        "achievement" => null,
        "error" => $grade === null
            ? "KPI นี้ยังไม่ได้กำหนดเกณฑ์ระดับผลงานและเป้าหมาย กรุณาติดต่อผู้ดูแลระบบ"
            : null
    ];
}

/* ตรวจค่า Budget ที่ Admin กรอก (เฉพาะ KPI 9) · คืนข้อความ error หรือ null ถ้าผ่าน */
function kpiValidateBudgetInput(array $kpi, string $raw): ?string
{
    if (!isNonRenewalKpi($kpi)) {
        return null;
    }

    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw) || !kpiBudgetIsValid($raw) || (float) $raw > KPI_BUDGET_MAX) {
        return "Budget ของ KPI มูลค่าไม่ต่อสัญญาต้องเป็นจำนวนเงินมากกว่า 0 และไม่เกิน "
            . number_format(KPI_BUDGET_MAX, 2) . " (ทศนิยมไม่เกิน 2 ตำแหน่ง)";
    }

    return null;
}

/* แสดงจำนวนเงิน (Budget / Total R) */
function kpiFormatMoney($value): string
{
    return $value === null || $value === "" ? "-" : number_format((float) $value, 2);
}

/* แสดง Achievement (%) ที่ตัดลง 2 ตำแหน่งแล้ว */
function kpiFormatAchievement(?float $achievement): string
{
    return $achievement === null ? "-" : number_format($achievement, 2) . "%";
}


/*
|--------------------------------------------------------------------------
| แปลงข้อความเกณฑ์ → กฎเชิงตัวเลข
|--------------------------------------------------------------------------
*/

/* จัดข้อความให้อยู่ในรูปมาตรฐานก่อนอ่านค่า (ขีดหลายแบบ / ≥ ≤ / หลักพัน / ช่องว่าง) */
function kpiNormalizeCriterion(string $text): string
{
    $text = str_replace(["–", "—", "−", "‐", "‒", "〜", "~"], "-", trim($text));
    $text = str_replace(["≥", "≧"], ">=", $text);
    $text = str_replace(["≤", "≦"], "<=", $text);

    $text = preg_replace('/(?<=\d),(?=\d{3})/u', "", $text) ?? $text;
    $text = preg_replace('/\s+/u', " ", $text) ?? $text;

    return trim($text);
}


/* ตัวเลขหลักของเกณฑ์ · ถ้ามี % ให้ยึดตัวเลขที่ติดกับ % (กันชื่อเกณฑ์ที่มีตัวเลขปนมา) */
function kpiCriterionNumber(string $text): ?float
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*%/u', $text, $matches)) {
        return (float) $matches[1];
    }

    if (preg_match('/(\d+(?:\.\d+)?)/u', $text, $matches)) {
        return (float) $matches[1];
    }

    return null;
}


/*
| ข้อความเกณฑ์ 1 ระดับ → ["type" => range|gte|gt|lte|lt|exact, ...]
| คืน null ถ้าเป็นช่องว่าง "-" หรือข้อความล้วนที่ไม่มีตัวเลข (เช่น "ไม่ค้าง")
*/
function parseKpiCriterion(?string $text): ?array
{
    $text = kpiNormalizeCriterion((string) $text);

    if ($text === "" || $text === "-") {
        return null;
    }

    /* ช่วงตัวเลข เช่น "99-90%", "95% - 97%", "85 ถึง 89" (เขียนกลับหัวได้) */
    if (preg_match('/(\d+(?:\.\d+)?)\s*%?\s*(?:-|ถึง)\s*(\d+(?:\.\d+)?)/u', $text, $matches)) {

        $first = (float) $matches[1];
        $second = (float) $matches[2];

        return [
            "type" => "range",
            "min" => min($first, $second),
            "max" => max($first, $second)
        ];
    }

    $value = kpiCriterionNumber($text);

    if ($value === null) {
        return null;
    }

    /*
    | ลำดับการตรวจสำคัญ: คำที่ยาวกว่าต้องมาก่อน
    | "หรือมากกว่า" ต้องชนะ "มากกว่า" · "ไม่เกิน" ต้องชนะ "เกิน"
    */

    if (preg_match('/(>=|ขึ้นไป|หรือมากกว่า|หรือสูงกว่า|อย่างน้อย|ตั้งแต่|ไม่ต่ำกว่า|ไม่น้อยกว่า)/u', $text)) {
        return ["type" => "gte", "value" => $value];
    }

    if (preg_match('/(<=|ไม่เกิน|ไม่มากกว่า|ไม่สูงกว่า|หรือน้อยกว่า|หรือต่ำกว่า|ลงมา)/u', $text)) {
        return ["type" => "lte", "value" => $value];
    }

    if (preg_match('/(>|มากกว่า|สูงกว่า|เกิน)/u', $text)) {
        return ["type" => "gt", "value" => $value];
    }

    if (preg_match('/(<|ต่ำกว่า|น้อยกว่า)/u', $text)) {
        return ["type" => "lt", "value" => $value];
    }

    return ["type" => "exact", "value" => $value];
}


/* [5..1 => ข้อความเกณฑ์] → [ระดับ => กฎ] เรียงจากระดับสูงไปต่ำ (ข้ามระดับที่อ่านค่าไม่ได้) */
function parseKpiCriteria(array $levels): array
{
    $rules = [];

    for ($level = KPI_MAX_LEVEL; $level >= KPI_MIN_LEVEL; $level--) {

        $rule = parseKpiCriterion($levels[$level] ?? null);

        if ($rule !== null) {
            $rules[$level] = $rule;
        }
    }

    return $rules;
}


/*
|--------------------------------------------------------------------------
| เทียบค่ากับกฎ
|--------------------------------------------------------------------------
*/

function kpiCriterionMatches(array $rule, float $value): bool
{
    $epsilon = KPI_SCORE_EPSILON;

    switch ($rule["type"]) {

        case "range":
            return $value >= $rule["min"] - $epsilon
                && $value <= $rule["max"] + $epsilon;

        case "gte":
            return $value >= $rule["value"] - $epsilon;

        case "gt":
            return $value > $rule["value"] + $epsilon;

        case "lte":
            return $value <= $rule["value"] + $epsilon;

        case "lt":
            return $value < $rule["value"] - $epsilon;

        case "exact":
            return abs($value - $rule["value"]) <= $epsilon;
    }

    return false;
}


/* ขอบล่างของกฎ (-INF ถ้าไม่มีขอบล่าง เช่น "<5%") */
function kpiCriterionLowerBound(array $rule): float
{
    switch ($rule["type"]) {

        case "range":
            return $rule["min"];

        case "gte":
        case "gt":
        case "exact":
            return $rule["value"];
    }

    return -INF;
}


/* ขอบบนของกฎ (INF ถ้าไม่มีขอบบน เช่น ">100%") */
function kpiCriterionUpperBound(array $rule): float
{
    switch ($rule["type"]) {

        case "range":
            return $rule["max"];

        case "lte":
        case "lt":
        case "exact":
            return $rule["value"];
    }

    return INF;
}


/* ค่าตัวแทนของกฎ · ใช้ดูทิศทางของเกณฑ์เท่านั้น */
function kpiCriterionMidpoint(array $rule): float
{
    switch ($rule["type"]) {

        case "range":
            return ($rule["min"] + $rule["max"]) / 2;

        case "gt":
            return $rule["value"] + KPI_SCORE_EPSILON;

        case "lt":
            return $rule["value"] - KPI_SCORE_EPSILON;
    }

    return $rule["value"];
}


/*
| ทิศทางของเกณฑ์: true = ยิ่งมากยิ่งดี, false = ยิ่งน้อยยิ่งดี
|
| ดูจากตัวเลขของเกณฑ์ไล่ระดับ 5 → 1 ถ้าลดลงเรื่อย ๆ แปลว่ายิ่งมากยิ่งดี
| (เช่น 5:">100%" 4:"100%" 3:"99-90%") ถ้าเพิ่มขึ้นแปลว่ายิ่งน้อยยิ่งดี
| (เช่น KPI "มูลค่าไม่ต่อสัญญา" 5:"<5%" 4:"5%")
*/
function kpiCriteriaHigherIsBetter(array $rules): bool
{
    $points = array_values(array_map("kpiCriterionMidpoint", $rules));

    $descending = 0;
    $ascending = 0;

    for ($index = 1; $index < count($points); $index++) {

        if ($points[$index - 1] > $points[$index]) {
            $descending++;
        } elseif ($points[$index - 1] < $points[$index]) {
            $ascending++;
        }
    }

    return $descending >= $ascending;
}


/*
| ค่าที่จะนำไปเทียบกับเกณฑ์
|
| เกณฑ์ในระบบมี 2 แบบปนกัน:
|   ก. เขียนเป็นค่าที่วัดได้ตรง ๆ — "ตรงเวลา 95% - 97%" โดยมีเป้าหมาย 98%
|   ข. เขียนเป็น % ของเป้าหมาย — ">100%", "99-96%" โดยมีเป้าหมายเป็นจำนวนครั้ง
|
| แยกด้วยเป้าหมาย: ถ้า Target อยู่ในช่วงตัวเลขของเกณฑ์ (หรือสูงกว่าไม่มาก เช่น
| เกณฑ์สูงสุด "98% ขึ้นไป" แต่ตั้งเป้าไว้ 100%) = เป็นหน่วยเดียวกัน → เทียบค่าดิบ
| ถ้า Target ต่ำกว่าเกณฑ์ต่ำสุด หรือสูงกว่าเกณฑ์สูงสุดมาก (เช่นเป้าเป็นจำนวนครั้ง
| แต่เกณฑ์เขียนเป็น 85-100%) = เกณฑ์เป็น % ของเป้าหมาย → เทียบเป็นสัดส่วน
*/
function kpiCriteriaCompareValue(array $rules, float $actual, ?float $target): float
{
    if ($target === null || $target <= 0) {
        return $actual;
    }

    $bounds = [];

    foreach ($rules as $rule) {

        $lower = kpiCriterionLowerBound($rule);
        $upper = kpiCriterionUpperBound($rule);

        if (is_finite($lower)) {
            $bounds[] = $lower;
        }

        if (is_finite($upper)) {
            $bounds[] = $upper;
        }
    }

    if (empty($bounds)) {
        return $actual;
    }

    $sameUnit = $target >= min($bounds) - KPI_SCORE_EPSILON
        && $target <= max($bounds) * KPI_CRITERIA_TARGET_TOLERANCE + KPI_SCORE_EPSILON;

    return $sameUnit ? $actual : $actual / $target * 100;
}


/*
|--------------------------------------------------------------------------
| ตัดเกรด
|--------------------------------------------------------------------------
*/

/* เกรด 1..5 จากเกณฑ์ · null = KPI นี้ยังไม่มีเกณฑ์เชิงตัวเลขให้ตัดเกรด */
function kpiGradeFromCriteria(array $levels, float $actual, ?float $target = null): ?int
{
    $rules = parseKpiCriteria($levels);

    if (empty($rules)) {
        return null;
    }

    $value = kpiCriteriaCompareValue($rules, $actual, $target);

    /* ระดับสูงสุดที่เข้าเกณฑ์ */
    foreach ($rules as $level => $rule) {

        if (kpiCriterionMatches($rule, $value)) {
            return $level;
        }
    }

    /* ไม่เข้าช่วงใดเลย → วางตามทิศทางของเกณฑ์ */
    $higherIsBetter = kpiCriteriaHigherIsBetter($rules);

    foreach ($rules as $level => $rule) {

        /*
        | ">x" ไม่รวมค่า x เอง และ "<x" ไม่รวมค่า x เอง (ตามความหมายของเกณฑ์ที่ Admin กำหนด)
        | เช่น เกณฑ์ ">10%" = 5 · "5-9%" = 4 → Actual 10% ได้ 4 ไม่ใช่ 5
        */
        if ($higherIsBetter) {
            $reached = $rule["type"] === "gt"
                ? $value > kpiCriterionLowerBound($rule) + KPI_SCORE_EPSILON
                : $value >= kpiCriterionLowerBound($rule) - KPI_SCORE_EPSILON;
        } else {
            $reached = $rule["type"] === "lt"
                ? $value < kpiCriterionUpperBound($rule) - KPI_SCORE_EPSILON
                : $value <= kpiCriterionUpperBound($rule) + KPI_SCORE_EPSILON;
        }

        if ($reached) {
            return $level;
        }
    }

    /* แย่กว่าเกณฑ์ต่ำสุดที่กำหนดไว้ */
    return KPI_MIN_LEVEL;
}


/*
| เกณฑ์สำรองเมื่อ KPI ยังไม่ได้กำหนดเกณฑ์เชิงตัวเลข (เช่นเกณฑ์เป็นข้อความล้วน)
|
| คงสูตรสัดส่วนเดิมของระบบไว้ (Actual / Target × 5) เพื่อไม่ให้คะแนนของ KPI
| ที่ไม่มีเกณฑ์เปลี่ยนไปโดยไม่ตั้งใจ ต่างกันแค่ปัดเป็นระดับเต็ม 1..5
| เพื่อให้ไฮไลต์ "ระดับที่ได้" ตรงกับคะแนนที่บันทึกเสมอ
|
| ทางที่ถูกต้องกว่าคือให้ Admin กำหนดเกณฑ์ 5..1 ของ KPI นั้นให้ครบ
*/
function kpiGradeFromTargetRatio(float $actual, ?float $target): ?int
{
    if ($target === null || $target <= 0) {
        return null;
    }

    $grade = (int) round($actual / $target * KPI_MAX_LEVEL);

    return max(KPI_MIN_LEVEL, min(KPI_MAX_LEVEL, $grade));
}


/*
| เกรดสุดท้ายของ Performance KPI (1..5)
| ใช้เกณฑ์ที่ Admin กำหนดก่อน ถ้ายังไม่มีจึงเทียบสัดส่วนกับเป้าหมาย
| คืน null เมื่อตัดเกรดไม่ได้เลย (ไม่มีทั้งเกณฑ์และเป้าหมาย)
*/
function kpiPerformanceGrade(array $levels, float $actual, ?float $target): ?int
{
    return kpiGradeFromCriteria($levels, $actual, $target)
        ?? kpiGradeFromTargetRatio($actual, $target);
}


/*
|--------------------------------------------------------------------------
| โหลดเกณฑ์จากฐานข้อมูล
|--------------------------------------------------------------------------
|
| เกณฑ์ระดับผลงาน 5..1 ของแต่ละ KPI → [kpi_id => [5 => "...", 4 => "...", ...]]
| ใช้แหล่งเดียวต่อ KPI ตามลำดับ: kpi_score_criteria → kpi_score_levels → score_5..score_1 (ข้อมูลเก่า)
|
*/
function loadKpiScoreCriteria(PDO $pdo, array $kpis, bool $applySpecialRules = true): array
{
    $ids = array_values(array_unique(array_map(fn($kpi) => (int) $kpi["kpi_id"], $kpis)));

    if (empty($ids)) {
        return [];
    }

    $in = implode(",", array_fill(0, count($ids), "?"));

    $fromCriteria = [];
    $stmt = $pdo->prepare("SELECT kpi_id, score_level, criteria FROM kpi_score_criteria WHERE kpi_id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        /* แถวเกณฑ์ว่าง (ข้อมูลเก่าจากหน้าเพิ่ม KPI) ไม่นับเป็นเกณฑ์ เพื่อไม่ให้บังแหล่งอื่น */
        if (trim((string) $row["criteria"]) === "") {
            continue;
        }

        $fromCriteria[(int) $row["kpi_id"]][(int) $row["score_level"]] = trim($row["criteria"]);
    }

    $fromLevels = [];
    $stmt = $pdo->prepare("SELECT kpi_id, score, criteria FROM kpi_score_levels WHERE kpi_id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fromLevels[(int) $row["kpi_id"]][(int) round((float) $row["score"])] = trim($row["criteria"]);
    }

    /*
    | ข้อมูลเก่าเก็บเกณฑ์ไว้ในคอลัมน์ kpi_indicators.score_5..score_1
    | ผู้เรียกบางหน้าไม่ได้ SELECT คอลัมน์เหล่านี้มาด้วย จึงอ่านเพิ่มจากฐานข้อมูลเอง
    | (ถ้าไม่ทำ KPI ที่มีแต่เกณฑ์แบบเก่าจะกลายเป็น "ไม่มีเกณฑ์" แบบเงียบ ๆ)
    */
    $legacyIds = array_values(array_filter(
        $ids,
        fn($kpiId) => empty($fromCriteria[$kpiId]) && empty($fromLevels[$kpiId])
    ));

    $fromColumns = [];

    foreach ($kpis as $kpi) {

        $kpiId = (int) $kpi["kpi_id"];

        if (!in_array($kpiId, $legacyIds, true) || !array_key_exists("score_5", $kpi)) {
            continue;
        }

        $fromColumns[$kpiId] = $kpi;
    }

    $missingIds = array_values(array_diff($legacyIds, array_keys($fromColumns)));

    if (!empty($missingIds)) {

        $in = implode(",", array_fill(0, count($missingIds), "?"));

        $stmt = $pdo->prepare("
            SELECT kpi_id, score_5, score_4, score_3, score_2, score_1
            FROM kpi_indicators
            WHERE kpi_id IN ({$in})
        ");
        $stmt->execute($missingIds);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fromColumns[(int) $row["kpi_id"]] = $row;
        }
    }

    $result = [];

    foreach ($kpis as $kpi) {

        $kpiId = (int) $kpi["kpi_id"];

        if (!empty($fromCriteria[$kpiId])) {
            $result[$kpiId] = $fromCriteria[$kpiId];
        } elseif (!empty($fromLevels[$kpiId])) {
            $result[$kpiId] = $fromLevels[$kpiId];
        } else {
            for ($level = KPI_MAX_LEVEL; $level >= KPI_MIN_LEVEL; $level--) {
                $text = trim((string) ($fromColumns[$kpiId]["score_" . $level] ?? ""));
                if ($text !== "") {
                    $result[$kpiId][$level] = $text;
                }
            }
        }
    }

    /* กฎเฉพาะ KPI (ดูหัวไฟล์) · อ่านชื่อ KPI จากฐานข้อมูลเอง ไม่พึ่งคอลัมน์ที่ผู้เรียก SELECT มา */
    if ($applySpecialRules) {

        $in = implode(",", array_fill(0, count($ids), "?"));

        $stmt = $pdo->prepare("SELECT kpi_id, kpi_name FROM kpi_indicators WHERE kpi_id IN ({$in})");
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isNonRenewalKpi($row)) {
                $result[(int) $row["kpi_id"]] = NON_RENEWAL_KPI_CRITERIA;
            } elseif ((int) $row["kpi_id"] === UPSELL_KPI_ID) {
                $result[UPSELL_KPI_ID] = UPSELL_KPI_CRITERIA;
            } elseif ((int) $row["kpi_id"] === VISIT_KPI_ID) {
                $result[VISIT_KPI_ID] = VISIT_KPI_CRITERIA;
            } elseif ((int) $row["kpi_id"] === RENEWAL_KPI_ID) {
                $result[RENEWAL_KPI_ID] = RENEWAL_KPI_CRITERIA;
            }
        }
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| บันทึกเกณฑ์ระดับผลงาน 5..1 ของ KPI
|--------------------------------------------------------------------------
|
| หน้าเพิ่ม KPI และหน้าแก้ไข KPI ของ Admin ใช้ฟังก์ชันนี้ร่วมกัน
| เขียนลง kpi_score_criteria (แหล่งที่ loadKpiScoreCriteria() อ่านก่อนเสมอ)
| ระดับที่เว้นว่างจะถูกลบแถวออก · ผู้เรียกเป็นผู้เปิด/ปิด transaction
|
*/
function saveKpiScoreCriteria(PDO $pdo, int $kpiId, array $levels): void
{
    $upsert = $pdo->prepare("
        INSERT INTO kpi_score_criteria (kpi_id, score_level, criteria)
        VALUES (:kpi_id, :score_level, :criteria)
        ON DUPLICATE KEY UPDATE criteria = VALUES(criteria)
    ");

    $delete = $pdo->prepare("
        DELETE FROM kpi_score_criteria
        WHERE kpi_id = :kpi_id AND score_level = :score_level
    ");

    for ($level = KPI_MAX_LEVEL; $level >= KPI_MIN_LEVEL; $level--) {

        $text = trim((string) ($levels[$level] ?? ""));

        if ($text === "") {
            $delete->execute([":kpi_id" => $kpiId, ":score_level" => $level]);
            continue;
        }

        $upsert->execute([":kpi_id" => $kpiId, ":score_level" => $level, ":criteria" => $text]);
    }
}
