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

require_once __DIR__ . "/../includes/table-tools.php";

/* ค่าจาก manager/index.php (ประกาศค่าเริ่มต้นให้ editor/static analyzer เห็นว่าตัวแปรถูกกำหนด) */
$filterYear = (int) ($filterYear ?? date("Y"));
$filterMonth = (int) ($filterMonth ?? 0);
$filterDepartment = (int) ($filterDepartment ?? 0);
$filterLabel = (string) ($filterLabel ?? "");
$monthNames = $monthNames ?? monthlyPeriodMonths();
$selectedPeriod = $selectedPeriod ?? null;



/*
|--------------------------------------------------------------------------
| Department Rows
|--------------------------------------------------------------------------
*/

$departmentRows = [];

/*
| แสดงเฉพาะแผนกที่มี KPI มอบหมายในปีที่เลือก (นับทั้งปี ไม่ขึ้นกับเดือนที่กรอง)
| แผนกที่ไม่มี KPI ให้ประเมิน (เช่น ผู้ดูแลระบบ / ฝ่ายบริหาร) ไม่แสดงในหน้านี้ และไม่อยู่ในตัวเลือกแผนก
*/
$kpiDepartments = [];

foreach ($yearData["departments"] as $departmentId => $departmentName) {
    $ids = managerEmployeeIds($yearData, $departmentId);
    if ($ids && managerStats($yearData, $ids, 0)["total_kpi"] > 0) {
        $kpiDepartments[$departmentId] = $departmentName;
    }
}

if ($filterDepartment > 0 && !isset($kpiDepartments[$filterDepartment])) {
    $filterDepartment = 0;
}

foreach ($kpiDepartments as $departmentId => $departmentName) {

    $ids = managerEmployeeIds($yearData, $departmentId);

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
        $departmentOptions = $kpiDepartments;
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
            <span>เรียงตามคะแนนเฉลี่ย · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?></span>
        </div>

        <?php managerTableTools("departmentTable", "ค้นหาแผนก", ["level" => ["ระดับ", managerLevelOptions()]]); ?>

        <div class="table-wrapper">

            <table class="manager-table manager-table-compact" id="departmentTable">

                <thead>
                    <tr>
                        <th class="col-index">#</th>
                        <th>แผนก</th>
                        <th>ประเมินแล้ว</th>
                        <th>คะแนนเฉลี่ย</th>
                        <th class="col-actions"><span class="visually-hidden">ดูรายละเอียด</span></th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($departmentRows)): ?>

                    <tr>
                        <td colspan="5" class="empty-state">ไม่พบข้อมูลแผนก</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($departmentRows as $index => $row): ?>

                        <?php [, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]); ?>

                        <tr class="<?= $row["department_id"] === $filterDepartment ? "row-selected" : "" ?>"
                            data-search="<?= managerSearchText($row["department_name"]) ?>"
                            data-level="<?= $levelClass ?>">

                            <td class="col-index"><?= $index + 1 ?></td>

                            <td>
                                <strong><?= htmlspecialchars($row["department_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <div class="cell-sub"><?= $row["employees"] ?> คน · <?= $row["total_kpi"] ?> KPI ที่มอบหมาย</div>
                            </td>

                            <td><?php managerProgressCell($row); ?></td>

                            <td><?php managerScoreCell($row); ?></td>

                            <td class="col-actions">
                                <div class="action-buttons">
                                    <a href="<?= managerUrl("departments", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $row["department_id"]]) ?>#department-detail" class="btn-small edit"><?= icon("calendar") ?>รายเดือน</a>
                                    <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $row["department_id"]]) ?>" class="btn-small"><?= icon("users") ?>รายคน</a>
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

        <?php $visibleMonths = array_filter($monthRows, fn($row, $month) => managerMonthVisible($row, $filterYear, $month), ARRAY_FILTER_USE_BOTH); ?>

        <!-- =====================================================
             DRILL-DOWN: รายเดือนของแผนกที่เลือก
        ====================================================== -->

        <div class="table-card" id="department-detail">

            <div class="table-header">
                <h2><?= htmlspecialchars($selectedDepartmentName, ENT_QUOTES, "UTF-8") ?> · รายเดือน ปี <?= $filterYear ?></h2>
                <span>เฉพาะเดือนที่ถึงรอบประเมินแล้ว</span>
            </div>

            <div class="table-wrapper">

                <table class="manager-table manager-table-compact">

                    <thead>
                        <tr>
                            <th>เดือน</th>
                            <th>ประเมินแล้ว</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (!$visibleMonths): ?>
                        <tr><td colspan="4" class="empty-state">ยังไม่มีรอบประเมินที่ถึงกำหนดในปีนี้</td></tr>
                    <?php endif; ?>

                    <?php foreach ($visibleMonths as $month => $row): ?>

                        <tr class="<?= $month === $filterMonth ? "row-selected" : "" ?>">

                            <td>
                                <strong><?= $monthNames[$month] ?></strong>
                                <div class="cell-sub">
                                    <?= getQuarterByMonth($month) ?> ·
                                    <span class="<?= $row["period"]["status"] === "Open" ? "text-open" : "text-closed" ?>"><?= htmlspecialchars($row["period"]["status"], ENT_QUOTES, "UTF-8") ?></span>
                                </div>
                            </td>

                            <td><?php managerProgressCell($row); ?></td>

                            <td><?php managerScoreCell($row); ?></td>

                            <td><?= $row["feedback_count"] ?> / <?= $row["employees"] ?> <small class="cell-muted">คน</small></td>

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
                <span><?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?></span>
            </div>

            <?php managerTableTools("memberTable", "ค้นหาชื่อ รหัส หรือตำแหน่ง", ["level" => ["ระดับ", managerLevelOptions()]]); ?>

            <div class="table-wrapper">

                <table class="manager-table manager-table-compact" id="memberTable">

                    <thead>
                        <tr>
                            <th>พนักงาน</th>
                            <th>ประเมินแล้ว</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th class="col-actions"><span class="visually-hidden">ดูรายละเอียด</span></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($memberRows as $row): ?>

                        <?php
                        $employee = $row["employee"];
                        [, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]);
                        $fullName = $employee["first_name"] . " " . $employee["last_name"];
                        ?>

                        <tr data-search="<?= managerSearchText($fullName, $employee["employee_code"], $employee["position_name"] ?? "") ?>" data-level="<?= $levelClass ?>">

                            <td>
                                <div class="person">
                                    <span class="person-avatar"><?= htmlspecialchars(mb_substr($employee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?></strong>
                                        <div class="cell-sub"><?= htmlspecialchars($employee["employee_code"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($employee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?></div>
                                    </div>
                                </div>
                            </td>

                            <td><?php managerProgressCell($row); ?></td>

                            <td><?php managerScoreCell($row); ?></td>

                            <td class="col-actions">
                                <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment, "employee_id" => $employee["employee_id"]]) ?>" class="btn-small edit"><?= icon("eye") ?>ดูรายละเอียด</a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>


</div>
