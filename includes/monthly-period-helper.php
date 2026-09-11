<?php

function monthlyPeriodMonths(): array
{
    return [
        1 => "มกราคม", 2 => "กุมภาพันธ์", 3 => "มีนาคม", 4 => "เมษายน",
        5 => "พฤษภาคม", 6 => "มิถุนายน", 7 => "กรกฎาคม", 8 => "สิงหาคม",
        9 => "กันยายน", 10 => "ตุลาคม", 11 => "พฤศจิกายน", 12 => "ธันวาคม"
    ];
}

function monthlyPeriodDetails(int $year, int $month): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException("Invalid evaluation month");
    }
    $startDate = sprintf("%04d-%02d-01", $year, $month);
    $months = monthlyPeriodMonths();
    return [
        "year" => $year,
        "month" => $month,
        "quarter" => "Q" . (int) ceil($month / 3),
        "period_name" => "ประจำเดือน" . $months[$month],
        "start_date" => $startDate,
        "end_date" => date("Y-m-t", strtotime($startDate))
    ];
}

function findEvaluationPeriodByMonth(PDO $pdo, int $year, int $month): ?array
{
    $stmt = $pdo->prepare("
        SELECT period_id, period_name, period_year, period_month, quarter, start_date, end_date, status
        FROM evaluation_periods
        WHERE period_year = :year AND period_month = :month
        LIMIT 1
    ");
    $stmt->execute([":year" => $year, ":month" => $month]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    return $period ?: null;
}

// เดือนที่มีรอบประเมินแล้ว แยกตามปี เช่น [2026 => [1, 8, 9]]
function evaluationPeriodMonthsByYear(PDO $pdo, int $excludePeriodId = 0): array
{
    $stmt = $pdo->prepare("
        SELECT period_year, period_month
        FROM evaluation_periods
        WHERE period_id <> :exclude_period_id
    ");
    $stmt->execute([":exclude_period_id" => $excludePeriodId]);

    $months = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $months[(int) $row["period_year"]][] = (int) $row["period_month"];
    }

    return $months;
}

// ตารางที่อ้างอิงรอบประเมิน ถ้ามีข้อมูลอยู่ห้ามลบรอบ
// (kpi_assignments / employee_kpi / performance_summary เป็น ON DELETE CASCADE จะถูกลบตามไปด้วย)
function evaluationPeriodReferenceTables(): array
{
    return [
        "kpi_performances" => "ผลงาน KPI",
        "kpi_assignments" => "KPI Assignment",
        "employee_kpi" => "คะแนน KPI",
        "performance_summary" => "สรุปผลการประเมิน",
        "manager_feedback" => "Feedback ของหัวหน้า"
    ];
}

// จำนวนข้อมูลที่ใช้รอบประเมินนี้อยู่ แยกตามประเภท (ว่าง = ลบได้)
function evaluationPeriodUsage(PDO $pdo, int $periodId): array
{
    $usage = [];
    foreach (evaluationPeriodReferenceTables() as $table => $label) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE period_id = :period_id");
        $stmt->execute([":period_id" => $periodId]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $usage[$label] = $count;
        }
    }

    return $usage;
}

// เดือนที่ Assignment มีผล เช่น "มกราคม - ธันวาคม" หรือ "กันยายน"
function assignmentMonthRangeLabel(?string $startDate, ?string $endDate): string
{
    if (!$startDate || !$endDate) {
        return "-";
    }
    $months = monthlyPeriodMonths();
    $startMonth = (int) date("n", strtotime($startDate));
    $endMonth = (int) date("n", strtotime($endDate));

    return $startMonth === $endMonth
        ? $months[$startMonth]
        : $months[$startMonth] . " - " . $months[$endMonth];
}

// Quarter ที่ Assignment มีผล เช่น "Q1 - Q4" หรือ "Q3"
function assignmentQuarterRangeLabel(?string $startDate, ?string $endDate): string
{
    if (!$startDate || !$endDate) {
        return "-";
    }
    $startQuarter = "Q" . (int) ceil(date("n", strtotime($startDate)) / 3);
    $endQuarter = "Q" . (int) ceil(date("n", strtotime($endDate)) / 3);

    return $startQuarter === $endQuarter ? $startQuarter : $startQuarter . " - " . $endQuarter;
}
