<?php

/*
|--------------------------------------------------------------------------
| ปัดระดับคะแนน KPI ที่บันทึกเป็นทศนิยมให้เป็นจำนวนเต็ม
|--------------------------------------------------------------------------
|
| ระดับคะแนนของ KPI หนึ่งตัวต้องเป็นจำนวนเต็ม 1..5 (ค่าเฉลี่ยมีทศนิยมได้)
| ผลงานเก่าบางรายการยังเก็บคะแนนจากสูตรสัดส่วนเดิม เช่น 4.75
| หน้าเว็บและไฟล์ export ปัดลงตอนแสดงผลแล้ว (kpiScoreLevel) แต่ค่าเฉลี่ยยังคิดจาก
| ค่าในฐานข้อมูล สคริปต์นี้ปัดเศษลงในคอลัมน์ kpi_performances.score ให้ตรงกัน
|
|   php database/migrations/20260929_floor_kpi_score_levels.php             ดูผลอย่างเดียว (ไม่แก้ข้อมูล)
|   php database/migrations/20260929_floor_kpi_score_levels.php --apply     เขียนคะแนนใหม่ลงฐานข้อมูล
|   php database/migrations/20260929_floor_kpi_score_levels.php --apply --backup=ไฟล์.csv
|                                                                          เขียนใหม่ พร้อมสำรองคะแนนเดิมเป็น CSV
|   เพิ่ม --db=ชื่อฐานข้อมูล เพื่อรันกับฐานอื่น (เช่นฐานทดสอบ)
|
| - แตะเฉพาะรายการที่ score มีเศษทศนิยม
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
| ผลงานที่คะแนนมีเศษทศนิยม
|--------------------------------------------------------------------------
*/

$rows = $pdo->query("
    SELECT kp.performance_id, e.employee_code, k.kpi_name, k.kpi_type,
           ep.period_year, ep.period_month, kp.score AS old_score, kp.status
    FROM kpi_performances kp
    INNER JOIN kpi_assignments a ON a.assignment_id = kp.assignment_id
    INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
    INNER JOIN employees e ON e.employee_id = kp.employee_id
    INNER JOIN evaluation_periods ep ON ep.period_id = kp.period_id
    WHERE kp.score IS NOT NULL
      AND kp.score <> FLOOR(kp.score)
    ORDER BY kp.performance_id
")->fetchAll(PDO::FETCH_ASSOC);

$changes = [];

foreach ($rows as $row) {

    $new = max(KPI_MIN_LEVEL, min(KPI_MAX_LEVEL, kpiScoreLevel((float) $row["old_score"])));

    $changes[] = $row + ["new_score" => $new];

    printf(
        "  #%d  %s  %s (%s)  %04d-%02d  %s → %d  [%s]\n",
        $row["performance_id"], $row["employee_code"], $row["kpi_name"], $row["kpi_type"],
        $row["period_year"], $row["period_month"], $row["old_score"], $new, $row["status"]
    );
}

printf("\nต้องแก้ %d รายการ\n", count($changes));

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

    fputcsv($handle, ["performance_id", "employee_code", "kpi_name", "old_score", "new_score", "status"]);

    foreach ($changes as $change) {
        fputcsv($handle, [
            $change["performance_id"], $change["employee_code"], $change["kpi_name"],
            $change["old_score"], $change["new_score"], $change["status"]
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
            ":score" => $change["new_score"],
            ":performance_id" => $change["performance_id"]
        ]);
    }

    $pdo->commit();

} catch (Throwable $exception) {

    $pdo->rollBack();
    exit("ไม่สำเร็จ (ยกเลิกทั้งหมด): " . $exception->getMessage() . "\n");
}

echo "\nอัปเดตคะแนน " . count($changes) . " รายการเรียบร้อย\n";
