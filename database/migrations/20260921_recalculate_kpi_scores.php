<?php

/*
|--------------------------------------------------------------------------
| คำนวณคะแนน Performance KPI ย้อนหลังให้ตรงกับเกณฑ์ระดับผลงาน
|--------------------------------------------------------------------------
|
| ผลงานที่บันทึกก่อนหน้านี้ใช้สูตรสัดส่วน (Actual / Target × 5) ซึ่งไม่ตรงกับ
| เกณฑ์ที่ Admin กำหนดไว้ เช่น KPI ที่ตั้งเกณฑ์ "5 : >100%" และ "4 : 100%"
| แต่สูตรเดิมให้ Actual เท่ากับ Target (100%) ได้ 5 เต็ม
|
| สคริปต์นี้ตัดเกรดใหม่จากเกณฑ์จริงด้วย includes/kpi-score-helper.php
| (ตัวเดียวกับที่หน้ากรอกผลงานใช้) แล้วเขียนทับคอลัมน์ kpi_performances.score
|
|   php database/migrations/20260921_recalculate_kpi_scores.php             ดูผลอย่างเดียว (ไม่แก้ข้อมูล)
|   php database/migrations/20260921_recalculate_kpi_scores.php --apply     เขียนคะแนนใหม่ลงฐานข้อมูล
|   php database/migrations/20260921_recalculate_kpi_scores.php --apply --backup=ไฟล์.csv
|                                                                          เขียนใหม่ พร้อมสำรองคะแนนเดิมเป็น CSV
|   เพิ่ม --db=ชื่อฐานข้อมูล เพื่อรันกับฐานอื่น (เช่นฐานทดสอบ)
|
| - แตะเฉพาะ Performance KPI ที่มีทั้ง actual และ score เท่านั้น
| - Competency KPI ไม่ถูกแตะ (พนักงานเลือกคะแนน 1-5 เอง ไม่ได้คำนวณจาก Actual)
| - ทำงานใน transaction เดียว ถ้าพลาดกลางทางจะ rollback ทั้งหมด
|
*/

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../../config/database.php";
require __DIR__ . "/../../includes/kpi-score-helper.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */
/** @var string $host */
/** @var string $username */
/** @var string $password */

$options = getopt("", ["db:", "apply", "backup:"]);

if (!empty($options["db"])) {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$options["db"]};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$apply = isset($options["apply"]);
$backupPath = $options["backup"] ?? null;

echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "โหมด: " . ($apply ? "เขียนข้อมูลจริง (--apply)" : "ดูผลอย่างเดียว (dry run)") . "\n\n";


/*
|--------------------------------------------------------------------------
| ผลงาน Performance KPI ทั้งหมดที่มี Actual
|--------------------------------------------------------------------------
*/

$rows = $pdo->query("
    SELECT
        p.performance_id,
        p.actual,
        p.score AS old_score,
        p.status,
        a.target_value,
        k.kpi_id,
        k.kpi_name,
        k.score_5, k.score_4, k.score_3, k.score_2, k.score_1
    FROM kpi_performances p
    INNER JOIN kpi_assignments a ON a.assignment_id = p.assignment_id
    INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
    WHERE k.kpi_type = 'Performance'
      AND p.actual IS NOT NULL
    ORDER BY k.kpi_id, p.performance_id
")->fetchAll();

if (empty($rows)) {
    exit("ไม่พบผลงาน Performance KPI ที่ต้องคำนวณใหม่\n");
}

$criteria = loadKpiScoreCriteria($pdo, $rows);


/*
|--------------------------------------------------------------------------
| ตัดเกรดใหม่
|--------------------------------------------------------------------------
*/

$changes = [];
$unchanged = 0;
$skipped = [];
$perKpi = [];

foreach ($rows as $row) {

    $kpiId = (int) $row["kpi_id"];
    $target = $row["target_value"] !== null ? (float) $row["target_value"] : null;

    $grade = kpiPerformanceGrade(
        $criteria[$kpiId] ?? [],
        (float) $row["actual"],
        $target !== null && $target > 0 ? $target : null
    );

    if ($grade === null) {
        $skipped[$row["kpi_name"]] = ($skipped[$row["kpi_name"]] ?? 0) + 1;
        continue;
    }

    $oldScore = $row["old_score"] !== null ? (float) $row["old_score"] : null;

    if ($oldScore !== null && abs($oldScore - $grade) < 0.001) {
        $unchanged++;
        continue;
    }

    $changes[] = [
        "performance_id" => (int) $row["performance_id"],
        "kpi_name" => $row["kpi_name"],
        "actual" => (float) $row["actual"],
        "target" => $target,
        "old" => $oldScore,
        "new" => $grade,
        "status" => $row["status"]
    ];

    $key = $row["kpi_name"];
    $perKpi[$key][($oldScore === null ? "-" : rtrim(rtrim(number_format($oldScore, 2), "0"), ".")) . " → " . $grade] =
        ($perKpi[$key][($oldScore === null ? "-" : rtrim(rtrim(number_format($oldScore, 2), "0"), ".")) . " → " . $grade] ?? 0) + 1;
}


/*
|--------------------------------------------------------------------------
| สรุป
|--------------------------------------------------------------------------
*/

printf("ผลงานที่ตรวจ %d รายการ · ถูกต้องอยู่แล้ว %d · ต้องแก้ %d\n\n",
    count($rows), $unchanged, count($changes));

foreach ($perKpi as $kpiName => $transitions) {
    echo "  {$kpiName}\n";
    ksort($transitions);
    foreach ($transitions as $transition => $count) {
        printf("      %-18s %d รายการ\n", $transition, $count);
    }
}

foreach ($skipped as $kpiName => $count) {
    echo "\n  ข้าม (ไม่มีเกณฑ์และไม่มีเป้าหมาย): {$kpiName} · {$count} รายการ\n";
}

if (empty($changes)) {
    exit("\nไม่มีอะไรต้องแก้\n");
}

if (!$apply) {
    exit("\nยังไม่ได้แก้ข้อมูล · รันซ้ำด้วย --apply เพื่อเขียนคะแนนใหม่\n");
}


/*
|--------------------------------------------------------------------------
| สำรองคะแนนเดิม + เขียนคะแนนใหม่
|--------------------------------------------------------------------------
*/

if ($backupPath !== null) {

    $handle = fopen($backupPath, "w");

    if ($handle === false) {
        exit("เปิดไฟล์สำรองไม่ได้: {$backupPath}\n");
    }

    fputcsv($handle, ["performance_id", "kpi_name", "actual", "target", "old_score", "new_score", "status"]);

    foreach ($changes as $change) {
        fputcsv($handle, [
            $change["performance_id"], $change["kpi_name"], $change["actual"],
            $change["target"], $change["old"], $change["new"], $change["status"]
        ]);
    }

    fclose($handle);

    echo "\nสำรองคะแนนเดิมไว้ที่ {$backupPath}\n";
}

$pdo->beginTransaction();

try {

    $update = $pdo->prepare("UPDATE kpi_performances SET score = :score WHERE performance_id = :performance_id");

    foreach ($changes as $change) {
        $update->execute([
            ":score" => $change["new"],
            ":performance_id" => $change["performance_id"]
        ]);
    }

    $pdo->commit();

} catch (Throwable $exception) {

    $pdo->rollBack();
    exit("ไม่สำเร็จ (ยกเลิกทั้งหมด): " . $exception->getMessage() . "\n");
}

echo "\nอัปเดตคะแนน " . count($changes) . " รายการเรียบร้อย\n";
