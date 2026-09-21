<?php

/*
|--------------------------------------------------------------------------
| KPI Score Helper — ตัดเกรดจาก "เกณฑ์ระดับผลงาน" (Criteria) ที่ Admin กำหนด
|--------------------------------------------------------------------------
|
| เดิมหน้ากรอกผลงานคิดคะแนนแบบสัดส่วน (Actual / Target × 5) ซึ่งไม่ตรงกับเกณฑ์
| ที่แสดงอยู่บนหน้าจอ เช่น KPI ที่ตั้งเกณฑ์ไว้ว่า "5 : >100%" และ "4 : 100%"
| แต่สูตรสัดส่วนให้ Actual เท่ากับ Target (= 100%) ได้ 5 เต็ม ทั้งที่ต้องได้ 4
|
| ไฟล์นี้จึงตัดเกรดจากข้อความเกณฑ์จริง ตามลำดับ:
|
|   1. แปลงข้อความเกณฑ์ของแต่ละระดับ (5..1) เป็นกฎเชิงตัวเลข
|      รองรับรูปแบบที่ใช้จริงในระบบ:
|        ">100%"            มากกว่า 100
|        "100%"             เท่ากับ 100 พอดี
|        "99-90%"           ช่วง 90 ถึง 99 (สลับหัวท้ายได้)
|        "ตรงเวลา 95% - 97%" ช่วง 95 ถึง 97 (มีข้อความไทยนำหน้าได้)
|        "<5%"              น้อยกว่า 5
|        "ความพึงพอใจ 90% ขึ้นไป"  ตั้งแต่ 90 ขึ้นไป
|        "ตรงเวลาต่ำกว่า 85%"      น้อยกว่า 85
|        "-" / ว่าง / ข้อความล้วน  ข้ามไป (ตัดเกรดอัตโนมัติไม่ได้)
|
|   2. เลือกฐานเปรียบเทียบ (ดู kpiCriteriaCompareValue)
|      — ค่าที่ทำได้จริง หรือ % ของเป้าหมาย
|
|   3. ไล่จากระดับ 5 ลงมา 1 · ระดับแรกที่เข้าเกณฑ์คือเกรดที่ได้
|
|   4. ถ้าไม่เข้าช่วงใดเลย (เกณฑ์มีช่องว่าง หรือผลงานอยู่นอกทุกช่วง)
|      จัดตำแหน่งตามทิศทางของเกณฑ์ · ต่ำกว่าเกณฑ์ต่ำสุด = 1
|
| ใช้ร่วมกันระหว่างหน้ากรอกผลงาน (employee/kpi/kpi.php, employee/kpi-detail.php)
| และสคริปต์คำนวณคะแนนย้อนหลัง เพื่อให้ทุกที่ใช้กติกาเดียวกัน
|
*/

/* ค่าคลาดเคลื่อนที่ยอมรับได้ตอนเทียบทศนิยม (คอลัมน์ actual เป็น decimal(15,2)) */
const KPI_SCORE_EPSILON = 0.0001;

const KPI_MIN_LEVEL = 1;
const KPI_MAX_LEVEL = 5;

/*
| เพดานที่ยังถือว่า Target กับเกณฑ์เป็นหน่วยเดียวกัน (ดู kpiCriteriaCompareValue)
| เช่น เกณฑ์สูงสุด "98% ขึ้นไป" แต่ตั้งเป้าไว้ 100% ยังนับเป็นหน่วยเดียวกัน
*/
const KPI_CRITERIA_TARGET_TOLERANCE = 1.5;


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

        $reached = $higherIsBetter
            ? $value >= kpiCriterionLowerBound($rule) - KPI_SCORE_EPSILON
            : $value <= kpiCriterionUpperBound($rule) + KPI_SCORE_EPSILON;

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
function loadKpiScoreCriteria(PDO $pdo, array $kpis): array
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

    return $result;
}
