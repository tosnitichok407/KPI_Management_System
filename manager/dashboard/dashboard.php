<?php

/*
|--------------------------------------------------------------------------
| Manager Dashboard
|--------------------------------------------------------------------------
|
| การ์ดสรุป + กราฟเส้นเปรียบเทียบ (รายเดือน) ของปีที่เลือก
| include จาก manager/index.php (มี $pdo, $yearData, $filterYear, ...)
|
*/

if (!isset($yearData)) {
    header("Location: index.php");
    exit;
}

$shortMonths = managerShortMonths();
$colors = managerChartColors();
$monthLabels = array_values($shortMonths);


/*
|--------------------------------------------------------------------------
| Employee Set (ทุกแผนก หรือแผนกที่เลือก)
|--------------------------------------------------------------------------
*/

$scopeEmployeeIds = managerEmployeeIds($yearData, $filterDepartment);

$scopeLabel = $filterDepartment > 0
    ? ($yearData["departments"][$filterDepartment] ?? "แผนกที่เลือก")
    : "ทุกแผนก";


/*
|--------------------------------------------------------------------------
| Cards
|--------------------------------------------------------------------------
*/

$yearStats = managerStats($yearData, $scopeEmployeeIds);

$currentMonth = (int) date("n");

$latestMonth = 0;
foreach (array_keys($yearData["periods"]) as $month) {
    if ($filterYear < (int) date("Y") || $month <= $currentMonth) {
        $latestMonth = max($latestMonth, $month);
    }
}

$latestStats = $latestMonth > 0
    ? managerStats($yearData, $scopeEmployeeIds, $latestMonth)
    : null;


/*
|--------------------------------------------------------------------------
| Chart 1: แผนก vs แผนก (คะแนนเฉลี่ยรายเดือน)
|--------------------------------------------------------------------------
*/

$departmentSeries = [];
$departmentCompletion = [];
$departmentQuarters = [];

foreach ($yearData["departments"] as $departmentId => $departmentName) {

    $ids = managerEmployeeIds($yearData, $departmentId);

    // ข้ามแผนกที่ไม่มี KPI มอบหมายเลย
    $hasAssignments = false;
    foreach ($ids as $id) {
        if (!empty($yearData["assignments_by_employee"][$id])) {
            $hasAssignments = true;
            break;
        }
    }

    if (!$hasAssignments) {
        continue;
    }

    $departmentSeries[$departmentName] = array_values(managerMonthlyScores($yearData, $ids));
    $departmentCompletion[$departmentName] = array_values(managerMonthlyCompletion($yearData, $ids));
    $departmentQuarters[$departmentName] = array_values(managerQuarterScores($yearData, $ids));
}


/*
|--------------------------------------------------------------------------
| Chart 2: Performance vs Competency
|--------------------------------------------------------------------------
*/

$typeSeries = [
    "Performance" => array_values(managerMonthlyScores($yearData, $scopeEmployeeIds, "Performance")),
    "Competency" => array_values(managerMonthlyScores($yearData, $scopeEmployeeIds, "Competency"))
];


/*
|--------------------------------------------------------------------------
| Chart 3: พนักงาน vs พนักงาน (เฉพาะคนที่มีคะแนนแล้ว)
|--------------------------------------------------------------------------
*/

$employeeSeries = [];

foreach ($scopeEmployeeIds as $employeeId) {

    $series = managerMonthlyScores($yearData, [$employeeId]);

    if (count(array_filter($series, fn($v) => $v !== null)) === 0) {
        continue;
    }

    $employee = $yearData["employees"][$employeeId];
    $employeeSeries[$employee["first_name"] . " " . $employee["last_name"]] = array_values($series);
}


/*
|--------------------------------------------------------------------------
| Chart 6: ปีนี้ vs ปีก่อน (คะแนนเฉลี่ยรวม)
|--------------------------------------------------------------------------
*/

$previousYearData = managerLoadYearData($pdo, $filterYear - 1);

$yearCompareSeries = [
    (string) $filterYear => array_values(managerMonthlyScores($yearData, $scopeEmployeeIds)),
    (string) ($filterYear - 1) => array_values(managerMonthlyScores($previousYearData, managerEmployeeIds($previousYearData, $filterDepartment)))
];


/* มีข้อมูลคะแนนบ้างไหม (ไว้แสดงข้อความแทนกราฟว่าง) */

$hasAnyScore = count($yearData["performances"]) > 0;

?>


<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                Manager Dashboard
            </h1>

            <p>
                ภาพรวมผลการประเมิน KPI ปี <?= $filterYear ?> · <?= htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8") ?>
            </p>

        </div>

    </header>


    <!-- =========================================================
         FILTER
    ========================================================== -->

    <div class="card">

        <?php
        $showMonth = false;
        $showDepartment = true;
        include __DIR__ . "/../includes/filter-bar.php";
        ?>

    </div>


    <!-- =========================================================
         CARDS
    ========================================================== -->

    <div class="stat-cards">

        <div class="stat-card">

            <div class="stat-card-title">พนักงาน</div>

            <div class="stat-card-value"><?= $yearStats["employees"] ?></div>

            <div class="stat-card-note"><?= htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8") ?></div>

        </div>


        <div class="stat-card">

            <div class="stat-card-title">KPI ที่มอบหมาย</div>

            <div class="stat-card-value"><?= $yearStats["total_kpi"] ?></div>

            <div class="stat-card-note">ประเมินแล้ว <?= $yearStats["evaluated"] ?> KPI (อย่างน้อย 1 เดือน)</div>

        </div>


        <div class="stat-card">

            <div class="stat-card-title">คะแนนเฉลี่ยทั้งปี</div>

            <div class="stat-card-value">
                <?= managerFormat($yearStats["avg_score"]) ?>
                <small>/ 5</small>
            </div>

            <div class="stat-card-note">
                Performance <?= managerFormat($yearStats["avg_performance"]) ?>
                · Competency <?= managerFormat($yearStats["avg_competency"]) ?>
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-card-title">
                เดือนล่าสุด<?= $latestMonth > 0 ? " (" . $monthNames[$latestMonth] . ")" : "" ?>
            </div>

            <?php if ($latestStats): ?>

                <div class="stat-card-value">
                    <?= managerFormat($latestStats["avg_score"]) ?>
                    <small>/ 5</small>
                </div>

                <div class="stat-card-note">
                    ประเมินแล้ว <?= $latestStats["evaluated"] ?> / <?= $latestStats["total_kpi"] ?> KPI
                    · Feedback <?= $latestStats["feedback_count"] ?> คน
                </div>

            <?php else: ?>

                <div class="stat-card-value">-</div>

                <div class="stat-card-note">ยังไม่มีรอบประเมินในปีนี้</div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         CHARTS
    ========================================================== -->

    <?php if (!$hasAnyScore): ?>

        <div class="card">
            <div class="chart-empty">
                ยังไม่มีผลประเมินในปี <?= $filterYear ?> กราฟจะแสดงเมื่อพนักงานบันทึกผล KPI แล้ว
            </div>
        </div>

    <?php endif; ?>


    <div class="chart-grid">


        <!-- 1. แผนก vs แผนก -->

        <div class="chart-card">

            <h2>คะแนนเฉลี่ยรายเดือน · เปรียบเทียบแผนก</h2>
            <p>เส้นละ 1 แผนก (เต็ม 5) — ดูว่าแผนกไหนดีขึ้น/ลดลงในแต่ละเดือน</p>

            <div class="chart-container">
                <canvas id="chartDepartments"></canvas>
            </div>

        </div>


        <!-- 2. Performance vs Competency -->

        <div class="chart-card">

            <h2>Performance vs Competency · <?= htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8") ?></h2>
            <p>คะแนนเฉลี่ยรายเดือนแยกตามประเภท KPI (เต็ม 5)</p>

            <div class="chart-container">
                <canvas id="chartTypes"></canvas>
            </div>

        </div>


        <!-- 3. พนักงาน vs พนักงาน -->

        <div class="chart-card chart-card-wide">

            <h2>คะแนนเฉลี่ยรายเดือน · เปรียบเทียบพนักงาน (<?= htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8") ?>)</h2>
            <p>
                แสดงเฉพาะพนักงานที่มีผลประเมินแล้ว <?= count($employeeSeries) ?> คน
                <?php if ($filterDepartment === 0): ?>· เลือกแผนกด้านบนเพื่อดูเฉพาะทีม<?php endif; ?>
            </p>

            <div class="chart-container chart-container-tall">
                <canvas id="chartEmployees"></canvas>
            </div>

        </div>


        <!-- 4. ไตรมาส -->

        <div class="chart-card">

            <h2>คะแนนเฉลี่ยรายไตรมาส · เปรียบเทียบแผนก</h2>
            <p>Q1 (ม.ค.–มี.ค.) · Q2 (เม.ย.–มิ.ย.) · Q3 (ก.ค.–ก.ย.) · Q4 (ต.ค.–ธ.ค.)</p>

            <div class="chart-container">
                <canvas id="chartQuarters"></canvas>
            </div>

        </div>


        <!-- 5. ความคืบหน้าการประเมิน -->

        <div class="chart-card">

            <h2>% KPI ที่ประเมินแล้วรายเดือน · เปรียบเทียบแผนก</h2>
            <p>ดูว่าแต่ละแผนกบันทึกผล KPI ครบแค่ไหนในแต่ละเดือน (เฉพาะเดือนที่มีรอบประเมิน)</p>

            <div class="chart-container">
                <canvas id="chartCompletion"></canvas>
            </div>

        </div>


        <!-- 6. ปีนี้ vs ปีก่อน -->

        <div class="chart-card chart-card-wide">

            <h2>คะแนนเฉลี่ยรวม · ปี <?= $filterYear ?> เทียบกับปี <?= $filterYear - 1 ?></h2>
            <p><?= htmlspecialchars($scopeLabel, ENT_QUOTES, "UTF-8") ?> (เต็ม 5)</p>

            <div class="chart-container">
                <canvas id="chartYears"></canvas>
            </div>

        </div>

    </div>


</div>


<script>
    (function () {

        const monthLabels = <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>;
        const colors = <?= json_encode($colors) ?>;

        function datasets(seriesMap) {
            return Object.keys(seriesMap).map(function (name, index) {
                const color = colors[index % colors.length];
                return {
                    label: name,
                    // ปัดทศนิยม 2 ตำแหน่ง (กัน float ของ PHP เช่น 2.2999999)
                    data: seriesMap[name].map(function (v) {
                        return v === null ? null : Math.round(v * 100) / 100;
                    }),
                    borderColor: color,
                    backgroundColor: color,
                    tension: 0.3,
                    spanGaps: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2
                };
            });
        }

        function lineChart(id, labels, seriesMap, max, suffix) {
            const canvas = document.getElementById(id);
            if (!canvas || typeof Chart === "undefined") {
                return;
            }

            const sets = datasets(seriesMap);
            const hasData = sets.some(function (set) {
                return set.data.some(function (v) { return v !== null; });
            });

            if (!hasData) {
                const empty = document.createElement("div");
                empty.className = "chart-empty";
                empty.textContent = "ยังไม่มีข้อมูล";
                canvas.replaceWith(empty);
                return;
            }

            new Chart(canvas, {
                type: "line",
                data: { labels: labels, datasets: sets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: "index", intersect: false },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: max,
                            ticks: {
                                callback: function (value) { return value + suffix; }
                            }
                        }
                    },
                    plugins: {
                        legend: { position: "bottom" },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.dataset.label + ": " + (ctx.parsed.y === null ? "-" : ctx.parsed.y + suffix);
                                }
                            }
                        }
                    }
                }
            });
        }

        lineChart("chartDepartments", monthLabels, <?= json_encode($departmentSeries, JSON_UNESCAPED_UNICODE) ?>, 5, "");
        lineChart("chartTypes", monthLabels, <?= json_encode($typeSeries, JSON_UNESCAPED_UNICODE) ?>, 5, "");
        lineChart("chartEmployees", monthLabels, <?= json_encode($employeeSeries, JSON_UNESCAPED_UNICODE) ?>, 5, "");
        lineChart("chartQuarters", ["Q1", "Q2", "Q3", "Q4"], <?= json_encode($departmentQuarters, JSON_UNESCAPED_UNICODE) ?>, 5, "");
        lineChart("chartCompletion", monthLabels, <?= json_encode($departmentCompletion, JSON_UNESCAPED_UNICODE) ?>, 100, "%");
        lineChart("chartYears", monthLabels, <?= json_encode($yearCompareSeries, JSON_UNESCAPED_UNICODE) ?>, 5, "");

    })();
</script>
