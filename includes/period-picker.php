<?php

/*
|--------------------------------------------------------------------------
| Period Picker (ปี + เดือน แบบปุ่ม)
|--------------------------------------------------------------------------
|
| ใช้ร่วมกันระหว่างหน้า Admin Summary และ Employee ผลการปฏิบัติงาน
|
|   renderPeriodPicker([
|       "year" => 2026, "month" => 9,              // month 0 = ทั้งปี
|       "stats" => periodPickerMonthStats(...),
|       "url" => fn(int $year, int $month): string => "...",
|       "show_all" => true,                         // แสดงปุ่ม "ทั้งปี"
|       "css" => "../assets/css/period-picker.css",
|       "dot_label" => "มีผลประเมิน"
|   ]);
|
*/

require_once __DIR__ . "/quarter-helper.php";
require_once __DIR__ . "/monthly-period-helper.php";


function periodPickerShortMonths(): array
{
    return [
        1 => "ม.ค.", 2 => "ก.พ.", 3 => "มี.ค.", 4 => "เม.ย.", 5 => "พ.ค.", 6 => "มิ.ย.",
        7 => "ก.ค.", 8 => "ส.ค.", 9 => "ก.ย.", 10 => "ต.ค.", 11 => "พ.ย.", 12 => "ธ.ค."
    ];
}


/*
| [1..12 => ["period" => รอบประเมิน|null, "results" => จำนวนผลประเมินที่มีคะแนน]]
| $employeeId > 0 → นับเฉพาะผลของพนักงานคนนั้น
*/
function periodPickerMonthStats(PDO $pdo, int $year, int $employeeId = 0): array
{
    $stats = [];

    for ($month = 1; $month <= 12; $month++) {
        $stats[$month] = ["period" => null, "results" => 0];
    }

    $employeeCondition = $employeeId > 0 ? " AND kp.employee_id = :employee_id " : "";

    $stmt = $pdo->prepare("
        SELECT
            ep.period_id,
            ep.period_name,
            ep.period_month,
            ep.quarter,
            ep.start_date,
            ep.end_date,
            ep.status,
            COUNT(kp.performance_id) AS results
        FROM evaluation_periods ep
        LEFT JOIN kpi_performances kp
            ON kp.period_id = ep.period_id
           AND kp.score IS NOT NULL
           {$employeeCondition}
        WHERE ep.period_year = :year
        GROUP BY ep.period_id, ep.period_name, ep.period_month, ep.quarter, ep.start_date, ep.end_date, ep.status
    ");

    $params = [":year" => $year];

    if ($employeeId > 0) {
        $params[":employee_id"] = $employeeId;
    }

    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stats[(int) $row["period_month"]] = [
            "period" => $row,
            "results" => (int) $row["results"]
        ];
    }

    return $stats;
}


function renderPeriodPicker(array $options): void
{
    static $cssPrinted = false;

    $year = (int) $options["year"];
    $selectedMonth = (int) ($options["month"] ?? 0);
    $stats = $options["stats"];
    $urlFor = $options["url"];
    $showAll = (bool) ($options["show_all"] ?? true);
    $dotLabel = (string) ($options["dot_label"] ?? "มีผลประเมิน");

    $monthNames = monthlyPeriodMonths();
    $shortMonths = periodPickerShortMonths();
    $currentYear = (int) date("Y");
    $currentMonth = (int) date("n");

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, "UTF-8");

    if (!empty($options["css"]) && !$cssPrinted) {
        echo '<link rel="stylesheet" href="' . $e($options["css"]) . '">';
        $cssPrinted = true;
    }

    ?>

    <div class="period-picker">


        <!-- ปี -->

        <div class="picker-group">

            <span class="picker-label">ปี</span>

            <div class="year-stepper">

                <a
                    class="year-step <?= $year <= 2000 ? "is-disabled" : "" ?>"
                    href="<?= $e($urlFor($year - 1, $selectedMonth)) ?>"
                    aria-label="ปีก่อนหน้า">‹</a>

                <span class="year-value"><?= $year ?></span>

                <a
                    class="year-step <?= $year >= 2100 ? "is-disabled" : "" ?>"
                    href="<?= $e($urlFor($year + 1, $selectedMonth)) ?>"
                    aria-label="ปีถัดไป">›</a>

            </div>

        </div>


        <!-- เดือน (จัดกลุ่มตาม Quarter) -->

        <div class="month-groups">

            <?php if ($showAll): ?>

                <div class="picker-group">

                    <span class="picker-label">ช่วงเวลา</span>

                    <div class="month-track">
                        <a
                            class="month-chip <?= $selectedMonth === 0 ? "is-active" : "" ?>"
                            href="<?= $e($urlFor($year, 0)) ?>">
                            ทั้งปี
                        </a>
                    </div>

                </div>

            <?php endif; ?>


            <?php foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter): ?>

                <?php [$firstMonth, $lastMonth] = getQuarterMonths($quarter); ?>

                <div class="picker-group">

                    <span class="picker-label"><?= $quarter ?></span>

                    <div class="month-track">

                        <?php for ($month = $firstMonth; $month <= $lastMonth; $month++): ?>

                            <?php
                            $stat = $stats[$month] ?? ["period" => null, "results" => 0];
                            $classes = [];

                            if ($month === $selectedMonth) {
                                $classes[] = "is-active";
                            }

                            if (!$stat["period"]) {
                                $classes[] = "no-period";
                            }

                            if ($year === $currentYear && $month === $currentMonth) {
                                $classes[] = "is-current";
                            }

                            $title = $monthNames[$month] . " " . $year . " · " . (
                                !$stat["period"]
                                    ? "ยังไม่มีรอบประเมิน"
                                    : ($stat["results"] > 0 ? $dotLabel . " " . $stat["results"] . " รายการ" : "ยังไม่มีผล")
                            );
                            ?>

                            <a
                                class="month-chip <?= implode(" ", $classes) ?>"
                                href="<?= $e($urlFor($year, $month)) ?>"
                                title="<?= $e($title) ?>">

                                <?= $shortMonths[$month] ?>

                                <?php if ($stat["results"] > 0): ?>
                                    <span class="month-dot"></span>
                                <?php endif; ?>

                            </a>

                        <?php endfor; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <div class="picker-legend">
            <span><i class="legend-dot"></i> <?= $e($dotLabel) ?></span>
            <span><b class="legend-muted">ม.ค.</b> ยังไม่มีรอบประเมิน</span>
        </div>

    </div>

    <?php
}
