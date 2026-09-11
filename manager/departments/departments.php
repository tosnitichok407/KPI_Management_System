<?php

/*
|--------------------------------------------------------------------------
| Manager: ผลงานรายแผนก
|--------------------------------------------------------------------------
|
| ตารางเปรียบเทียบทุกแผนก + เจาะดูแผนกเดียวแบบรายเดือน
| include จาก manager/index.php
|
*/

if (!isset($yearData)) {
    header("Location: index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Department Rows
|--------------------------------------------------------------------------
*/

$departmentRows = [];

foreach ($yearData["departments"] as $departmentId => $departmentName) {

    $ids = managerEmployeeIds($yearData, $departmentId);

    if (empty($ids)) {
        continue;
    }

    $stats = managerStats($yearData, $ids, $filterMonth);

    // แสดงเฉพาะแผนกที่มีพนักงาน Active
    $departmentRows[] = array_merge($stats, [
        "department_id" => $departmentId,
        "department_name" => $departmentName
    ]);
}

// เรียงคะแนนมาก → น้อย (ไม่มีคะแนนไว้ท้าย)
usort($departmentRows, function ($a, $b) {
    if ($a["avg_score"] === $b["avg_score"]) {
        return strcmp($a["department_name"], $b["department_name"]);
    }
    if ($a["avg_score"] === null) return 1;
    if ($b["avg_score"] === null) return -1;
    return $b["avg_score"] <=> $a["avg_score"];
});


/*
|--------------------------------------------------------------------------
| Drill-down: แผนกที่เลือก (รายเดือน + รายคน)
|--------------------------------------------------------------------------
*/

$selectedDepartmentName = $filterDepartment > 0
    ? ($yearData["departments"][$filterDepartment] ?? null)
    : null;

$monthRows = [];
$memberRows = [];

if ($selectedDepartmentName !== null) {

    $ids = managerEmployeeIds($yearData, $filterDepartment);

    for ($month = 1; $month <= 12; $month++) {
        $monthRows[$month] = array_merge(
            managerStats($yearData, $ids, $month),
            ["period" => $yearData["periods"][$month] ?? null]
        );
    }

    foreach ($ids as $employeeId) {
        $memberRows[] = array_merge(
            managerStats($yearData, [$employeeId], $filterMonth),
            ["employee" => $yearData["employees"][$employeeId]]
        );
    }
}

?>


<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                ผลงานรายแผนก
            </h1>

            <p>
                เปรียบเทียบผลการประเมิน KPI ของแต่ละแผนก · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?>
            </p>

        </div>

    </header>


    <!-- =========================================================
         FILTER
    ========================================================== -->

    <div class="card">

        <?php
        $showMonth = true;
        $showDepartment = true;
        include __DIR__ . "/../includes/filter-bar.php";
        ?>

        <?php if ($filterMonth > 0 && !$selectedPeriod): ?>
            <div class="filter-note">
                ยังไม่ได้สร้างรอบประเมินของเดือน<?= $monthNames[$filterMonth] ?> <?= $filterYear ?> จึงยังไม่มีผลประเมินของเดือนนี้
            </div>
        <?php endif; ?>

    </div>


    <!-- =========================================================
         DEPARTMENT TABLE
    ========================================================== -->

    <div class="table-card">

        <div class="table-header">

            <h2>เปรียบเทียบแผนก</h2>

            <span><?= count($departmentRows) ?> แผนก · เรียงตามคะแนนเฉลี่ย</span>

        </div>

        <div class="table-wrapper">

            <table class="manager-table">

                <thead>
                    <tr>
                        <th class="col-index">#</th>
                        <th>แผนก</th>
                        <th class="col-number">พนักงาน</th>
                        <th>ประเมินแล้ว</th>
                        <th class="col-number">Performance</th>
                        <th class="col-number">Competency</th>
                        <th>คะแนนเฉลี่ย</th>
                        <th>ระดับ</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($departmentRows)): ?>

                    <tr>
                        <td colspan="9" class="empty-state">ไม่พบข้อมูลแผนก</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($departmentRows as $index => $row): ?>

                        <?php
                        [$levelText, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]);
                        $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0;
                        $isSelected = $row["department_id"] === $filterDepartment;
                        ?>

                        <tr class="<?= $isSelected ? "row-selected" : "" ?>">

                            <td class="col-index"><?= $index + 1 ?></td>

                            <td>
                                <strong><?= htmlspecialchars($row["department_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <div class="cell-sub"><?= $row["total_kpi"] ?> KPI ที่มอบหมาย</div>
                            </td>

                            <td class="col-number"><?= $row["employees"] ?> คน</td>

                            <td>
                                <div class="stat-main"><?= $row["evaluated"] ?> / <?= $row["total_kpi"] ?> KPI</div>
                                <div class="progress <?= $ratio >= 1 && $row["total_kpi"] > 0 ? "done" : "" ?>">
                                    <span style="width: <?= round($ratio * 100) ?>%"></span>
                                </div>
                            </td>

                            <td class="col-number"><?= managerFormat($row["avg_performance"]) ?></td>

                            <td class="col-number"><?= managerFormat($row["avg_competency"]) ?></td>

                            <td>
                                <?php if ($row["avg_score"] === null): ?>
                                    <span class="cell-muted">ยังไม่ประเมิน</span>
                                <?php else: ?>
                                    <div class="stat-main"><?= number_format($row["avg_score"], 2) ?> / 5</div>
                                    <div class="cell-sub">ถ่วงน้ำหนัก <?= managerFormat($row["weighted_percent"], 1, "%") ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="level level-<?= $levelClass ?>"><?= $levelText ?></span>
                            </td>

                            <td>
                                <div class="action-buttons">
                                    <a href="<?= managerUrl("departments", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $row["department_id"]]) ?>#department-detail" class="btn-small edit">รายเดือน</a>
                                    <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $row["department_id"]]) ?>" class="btn-small">รายคน</a>
                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <?php if ($selectedDepartmentName !== null): ?>


        <!-- =====================================================
             DRILL-DOWN: รายเดือนของแผนกที่เลือก
        ====================================================== -->

        <div class="table-card" id="department-detail">

            <div class="table-header">

                <h2><?= htmlspecialchars($selectedDepartmentName, ENT_QUOTES, "UTF-8") ?> · รายเดือน ปี <?= $filterYear ?></h2>

                <span>เฉพาะเดือนที่มีรอบประเมิน</span>

            </div>

            <div class="table-wrapper">

                <table class="manager-table">

                    <thead>
                        <tr>
                            <th>เดือน</th>
                            <th>Quarter</th>
                            <th>รอบประเมิน</th>
                            <th>ประเมินแล้ว</th>
                            <th class="col-number">Performance</th>
                            <th class="col-number">Competency</th>
                            <th class="col-number">คะแนนเฉลี่ย</th>
                            <th class="col-number">Feedback</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($monthRows as $month => $row): ?>

                        <?php $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0; ?>

                        <tr class="<?= $row["period"] ? "" : "row-inactive" ?> <?= $month === $filterMonth ? "row-selected" : "" ?>">

                            <td><strong><?= $monthNames[$month] ?></strong></td>

                            <td><span class="badge badge-quarter"><?= getQuarterByMonth($month) ?></span></td>

                            <td>
                                <?php if ($row["period"]): ?>
                                    <span class="badge <?= $row["period"]["status"] === "Open" ? "badge-open" : "badge-closed" ?>">
                                        <?= htmlspecialchars($row["period"]["status"], ENT_QUOTES, "UTF-8") ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cell-muted">ยังไม่มีรอบ</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row["period"]): ?>
                                    <div class="stat-main"><?= $row["evaluated"] ?> / <?= $row["total_kpi"] ?> KPI</div>
                                    <div class="progress <?= $ratio >= 1 && $row["total_kpi"] > 0 ? "done" : "" ?>">
                                        <span style="width: <?= round($ratio * 100) ?>%"></span>
                                    </div>
                                <?php else: ?>
                                    <span class="cell-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="col-number"><?= managerFormat($row["avg_performance"]) ?></td>

                            <td class="col-number"><?= managerFormat($row["avg_competency"]) ?></td>

                            <td class="col-number">
                                <?php if ($row["avg_score"] === null): ?>
                                    <span class="cell-muted">-</span>
                                <?php else: ?>
                                    <strong><?= number_format($row["avg_score"], 2) ?></strong> / 5
                                <?php endif; ?>
                            </td>

                            <td class="col-number"><?= $row["period"] ? $row["feedback_count"] . " / " . $row["employees"] . " คน" : "-" ?></td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- =====================================================
             DRILL-DOWN: พนักงานในแผนก
        ====================================================== -->

        <div class="table-card">

            <div class="table-header">

                <h2>พนักงานใน<?= htmlspecialchars($selectedDepartmentName, ENT_QUOTES, "UTF-8") ?></h2>

                <span><?= count($memberRows) ?> คน · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?></span>

            </div>

            <div class="table-wrapper">

                <table class="manager-table">

                    <thead>
                        <tr>
                            <th>พนักงาน</th>
                            <th>ตำแหน่ง</th>
                            <th>ประเมินแล้ว</th>
                            <th class="col-number">Performance</th>
                            <th class="col-number">Competency</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>ระดับ</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($memberRows as $row): ?>

                        <?php
                        $employee = $row["employee"];
                        [$levelText, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]);
                        $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0;
                        ?>

                        <tr>

                            <td>
                                <div class="person">
                                    <span class="person-avatar"><?= htmlspecialchars(mb_substr($employee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($employee["first_name"] . " " . $employee["last_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                        <div class="cell-sub"><?= htmlspecialchars($employee["employee_code"], ENT_QUOTES, "UTF-8") ?></div>
                                    </div>
                                </div>
                            </td>

                            <td><?= htmlspecialchars($employee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?></td>

                            <td>
                                <div class="stat-main"><?= $row["evaluated"] ?> / <?= $row["total_kpi"] ?> KPI</div>
                                <div class="progress <?= $ratio >= 1 && $row["total_kpi"] > 0 ? "done" : "" ?>">
                                    <span style="width: <?= round($ratio * 100) ?>%"></span>
                                </div>
                            </td>

                            <td class="col-number"><?= managerFormat($row["avg_performance"]) ?></td>

                            <td class="col-number"><?= managerFormat($row["avg_competency"]) ?></td>

                            <td>
                                <?php if ($row["avg_score"] === null): ?>
                                    <span class="cell-muted">ยังไม่ประเมิน</span>
                                <?php else: ?>
                                    <div class="stat-main"><?= number_format($row["avg_score"], 2) ?> / 5</div>
                                    <div class="cell-sub">ถ่วงน้ำหนัก <?= managerFormat($row["weighted_percent"], 1, "%") ?></div>
                                <?php endif; ?>
                            </td>

                            <td><span class="level level-<?= $levelClass ?>"><?= $levelText ?></span></td>

                            <td>
                                <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment, "employee_id" => $employee["employee_id"]]) ?>" class="btn-small edit">ดูรายละเอียด</a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>


</div>
