<?php

/*
|--------------------------------------------------------------------------
| Manager: ผลงานรายพนักงาน
|--------------------------------------------------------------------------
|
| ตารางรายคน (กรองแผนก/เดือน) + เจาะดูคนเดียว (รายเดือน + KPI + Feedback)
| include จาก manager/index.php
|
*/

if (!isset($yearData)) {
    header("Location: index.php");
    exit;
}

$filterEmployee = (int) ($_GET["employee_id"] ?? 0);

$selectedEmployee = $yearData["employees"][$filterEmployee] ?? null;

if ($selectedEmployee === null) {
    $filterEmployee = 0;
}


/*
|--------------------------------------------------------------------------
| Employee Rows
|--------------------------------------------------------------------------
*/

$employeeRows = [];

foreach (managerEmployeeIds($yearData, $filterDepartment) as $employeeId) {

    $employee = $yearData["employees"][$employeeId];

    // แสดงเฉพาะคนที่มี KPI มอบหมายในปีนี้
    if (empty($yearData["assignments_by_employee"][$employeeId])) {
        continue;
    }

    $employeeRows[] = array_merge(
        managerStats($yearData, [$employeeId], $filterMonth),
        [
            "employee" => $employee,
            "feedback" => $filterMonth > 0 ? ($yearData["feedback"][$employeeId][$filterMonth] ?? null) : null
        ]
    );
}

usort($employeeRows, function ($a, $b) {
    if ($a["avg_score"] === $b["avg_score"]) {
        return strcmp($a["employee"]["first_name"], $b["employee"]["first_name"]);
    }
    if ($a["avg_score"] === null) return 1;
    if ($b["avg_score"] === null) return -1;
    return $b["avg_score"] <=> $a["avg_score"];
});


/*
|--------------------------------------------------------------------------
| Drill-down: พนักงานที่เลือก
|--------------------------------------------------------------------------
*/

$employeeMonthRows = [];
$employeeKpis = [];
$employeeYearStats = null;

if ($selectedEmployee !== null) {

    $employeeYearStats = managerStats($yearData, [$filterEmployee]);

    for ($month = 1; $month <= 12; $month++) {
        $employeeMonthRows[$month] = array_merge(
            managerStats($yearData, [$filterEmployee], $month),
            [
                "period" => $yearData["periods"][$month] ?? null,
                "feedback" => $yearData["feedback"][$filterEmployee][$month] ?? null
            ]
        );
    }

    foreach ($yearData["assignments_by_employee"][$filterEmployee] ?? [] as $assignmentId) {

        $assignment = $yearData["assignments"][$assignmentId];

        if ($filterMonth > 0 && !managerAssignmentInMonth($assignment, $filterYear, $filterMonth)) {
            continue;
        }

        $performances = $yearData["performances_by_assignment"][$assignmentId] ?? [];

        if ($filterMonth > 0) {
            $performances = isset($performances[$filterMonth]) ? [$filterMonth => $performances[$filterMonth]] : [];
        }

        $scores = array_map(fn($p) => (float) $p["score"], $performances);

        $employeeKpis[] = [
            "assignment" => $assignment,
            "performances" => $performances,
            "avg_score" => $scores ? array_sum($scores) / count($scores) : null,
            "latest" => $performances ? end($performances) : null
        ];
    }
}

/* Feedback ให้ได้เฉพาะเดือนที่มีรอบประเมิน */
$canFeedback = $filterMonth > 0 && $selectedPeriod !== null;

?>


<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div class="page-title-block">

            <h1>
                ผลงานรายพนักงาน
            </h1>

            <p>
                ผลการประเมิน KPI และ Feedback ของหัวหน้า · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?>
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
        $extraHidden = $filterEmployee > 0 ? ["employee_id" => $filterEmployee] : [];
        include __DIR__ . "/../includes/filter-bar.php";
        ?>

        <div class="filter-note">

            <?php if ($filterMonth > 0 && !$selectedPeriod): ?>
                ยังไม่ได้สร้างรอบประเมินของเดือน<?= $monthNames[$filterMonth] ?> <?= $filterYear ?> จึงยังไม่มีผลประเมินและให้ Feedback ไม่ได้
            <?php elseif ($filterMonth === 0): ?>
                เลือก <strong>เดือน</strong> เพื่อดูผลของเดือนนั้นและให้ Feedback ประจำเดือน
            <?php else: ?>
                รอบประเมิน: <strong><?= htmlspecialchars($selectedPeriod["period_name"], ENT_QUOTES, "UTF-8") ?> <?= $filterYear ?></strong>
                · <?= htmlspecialchars($selectedPeriod["quarter"], ENT_QUOTES, "UTF-8") ?>
                · <?= date("d/m/Y", strtotime($selectedPeriod["start_date"])) ?> - <?= date("d/m/Y", strtotime($selectedPeriod["end_date"])) ?>
            <?php endif; ?>

        </div>

    </div>


    <?php if ($selectedEmployee !== null): ?>


        <!-- =====================================================
             DRILL-DOWN: พนักงานที่เลือก
        ====================================================== -->

        <?php
        [$levelText, $levelClass] = managerScoreLevel($employeeYearStats["avg_score"], $employeeYearStats["evaluated"], $employeeYearStats["total_kpi"]);
        ?>

        <div class="card employee-profile">

            <div class="person person-large">

                <span class="person-avatar"><?= htmlspecialchars(mb_substr($selectedEmployee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>

                <div>
                    <h2><?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?></h2>
                    <div class="cell-sub">
                        <?= htmlspecialchars($selectedEmployee["employee_code"], ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($selectedEmployee["department_name"] ?? "ไม่ระบุแผนก", ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($selectedEmployee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?>
                    </div>
                </div>

            </div>

            <div class="employee-profile-stats">

                <div>
                    <span>KPI ปี <?= $filterYear ?></span>
                    <strong><?= $employeeYearStats["total_kpi"] ?></strong>
                </div>

                <div>
                    <span>ประเมินแล้ว</span>
                    <strong><?= $employeeYearStats["evaluated"] ?> / <?= $employeeYearStats["total_kpi"] ?></strong>
                </div>

                <div>
                    <span>คะแนนเฉลี่ยทั้งปี</span>
                    <strong><?= managerFormat($employeeYearStats["avg_score"]) ?> <small>/ 5</small></strong>
                </div>

                <div>
                    <span>ระดับ</span>
                    <strong><span class="level level-<?= $levelClass ?>"><?= $levelText ?></span></strong>
                </div>

            </div>

            <div class="employee-profile-actions">

                <?php if ($canFeedback): ?>
                    <button
                        type="button"
                        class="btn btn-primary feedback-btn"
                        data-id="<?= $filterEmployee ?>"
                        data-name="<?= htmlspecialchars($selectedEmployee["first_name"] . " " . $selectedEmployee["last_name"], ENT_QUOTES, "UTF-8") ?>"
                        data-score="<?= htmlspecialchars((string) ($yearData["feedback"][$filterEmployee][$filterMonth]["evaluation_score"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                        data-feedback="<?= htmlspecialchars((string) ($yearData["feedback"][$filterEmployee][$filterMonth]["feedback"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                        ✎ Feedback เดือน<?= $monthNames[$filterMonth] ?>
                    </button>
                <?php endif; ?>

                <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment]) ?>" class="btn btn-secondary">
                    ดูทุกคน
                </a>

            </div>

        </div>


        <!-- รายเดือน -->

        <div class="table-card">

            <div class="table-header">
                <h2>ผลรายเดือน ปี <?= $filterYear ?></h2>
                <span>เฉพาะเดือนที่มีรอบประเมิน</span>
            </div>

            <div class="table-wrapper">

                <table class="manager-table">

                    <thead>
                        <tr>
                            <th>เดือน</th>
                            <th>Quarter</th>
                            <th>ประเมินแล้ว</th>
                            <th class="col-number">Performance</th>
                            <th class="col-number">Competency</th>
                            <th class="col-number">คะแนนเฉลี่ย</th>
                            <th>Feedback หัวหน้า</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($employeeMonthRows as $month => $row): ?>

                        <?php $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0; ?>

                        <tr class="<?= $row["period"] ? "" : "row-inactive" ?> <?= $month === $filterMonth ? "row-selected" : "" ?>">

                            <td><strong><?= $monthNames[$month] ?></strong></td>

                            <td><span class="badge badge-quarter"><?= getQuarterByMonth($month) ?></span></td>

                            <td>
                                <?php if ($row["period"]): ?>
                                    <div class="stat-main"><?= $row["evaluated"] ?> / <?= $row["total_kpi"] ?> KPI</div>
                                    <div class="progress <?= $ratio >= 1 && $row["total_kpi"] > 0 ? "done" : "" ?>">
                                        <span style="width: <?= round($ratio * 100) ?>%"></span>
                                    </div>
                                <?php else: ?>
                                    <span class="cell-muted">ยังไม่มีรอบ</span>
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

                            <td>
                                <?php if ($row["feedback"]): ?>
                                    <div class="stat-main"><?= number_format((float) $row["feedback"]["evaluation_score"], 1) ?> / 100</div>
                                    <div class="cell-sub feedback-text"><?= htmlspecialchars($row["feedback"]["feedback"], ENT_QUOTES, "UTF-8") ?></div>
                                <?php elseif ($row["period"]): ?>
                                    <span class="cell-muted">ยังไม่ให้ Feedback</span>
                                <?php else: ?>
                                    <span class="cell-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row["period"]): ?>
                                    <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $month, "department_id" => $filterDepartment, "employee_id" => $filterEmployee]) ?>" class="btn-small edit">ดูเดือนนี้</a>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- KPI ของพนักงาน -->

        <div class="table-card">

            <div class="table-header">
                <h2>KPI ที่ได้รับมอบหมาย</h2>
                <span><?= count($employeeKpis) ?> KPI · <?= htmlspecialchars($filterLabel, ENT_QUOTES, "UTF-8") ?></span>
            </div>

            <div class="table-wrapper">

                <table class="manager-table">

                    <thead>
                        <tr>
                            <th class="col-index">#</th>
                            <th>KPI</th>
                            <th>ประเภท</th>
                            <th class="col-number">Target</th>
                            <th class="col-number">Weight</th>
                            <th>ผลประเมิน</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($employeeKpis)): ?>

                        <tr>
                            <td colspan="6" class="empty-state">ไม่มี KPI ที่มีผลในช่วงเวลาที่เลือก</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employeeKpis as $index => $item): ?>

                            <?php $assignment = $item["assignment"]; ?>

                            <tr>

                                <td class="col-index"><?= $index + 1 ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($assignment["kpi_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                    <div class="cell-sub">หน่วย: <?= htmlspecialchars($assignment["unit"] ?? "-", ENT_QUOTES, "UTF-8") ?></div>
                                </td>

                                <td>
                                    <span class="badge badge-type <?= strtolower($assignment["kpi_type"]) === "performance" ? "performance" : "" ?>">
                                        <?= htmlspecialchars($assignment["kpi_type"], ENT_QUOTES, "UTF-8") ?>
                                    </span>
                                </td>

                                <td class="col-number"><?= number_format((float) $assignment["target_value"]) ?>%</td>

                                <td class="col-number"><strong><?= number_format((float) $assignment["weight"]) ?>%</strong></td>

                                <td>
                                    <?php if ($item["avg_score"] === null): ?>
                                        <span class="cell-muted">ยังไม่ประเมิน</span>
                                    <?php elseif ($filterMonth > 0): ?>
                                        <div class="stat-main"><?= number_format($item["avg_score"], 2) ?> / <?= number_format((float) $assignment["max_score"]) ?></div>
                                        <div class="cell-sub">
                                            <?php if ($item["latest"]["actual"] !== null): ?>Actual <?= number_format((float) $item["latest"]["actual"]) ?> · <?php endif; ?>
                                            <?= htmlspecialchars($item["latest"]["status"], ENT_QUOTES, "UTF-8") ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="stat-main"><?= number_format($item["avg_score"], 2) ?> / <?= number_format((float) $assignment["max_score"]) ?> <small class="cell-muted">เฉลี่ย</small></div>
                                        <div class="cell-sub">ประเมินแล้ว <?= count($item["performances"]) ?> เดือน</div>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


    <?php else: ?>


        <!-- =====================================================
             EMPLOYEE TABLE
        ====================================================== -->

        <div class="table-card">

            <div class="table-header">

                <h2>รายชื่อพนักงาน</h2>

                <span><?= count($employeeRows) ?> คน · เรียงตามคะแนนเฉลี่ย</span>

            </div>

            <div class="table-wrapper">

                <table class="manager-table">

                    <thead>
                        <tr>
                            <th class="col-index">#</th>
                            <th>พนักงาน</th>
                            <th>แผนก / ตำแหน่ง</th>
                            <th>ประเมินแล้ว</th>
                            <th class="col-number">Performance</th>
                            <th class="col-number">Competency</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>ระดับ</th>
                            <th>Feedback</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($employeeRows)): ?>

                        <tr>
                            <td colspan="10" class="empty-state">ไม่พบพนักงานที่มี KPI ในตัวกรองนี้</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($employeeRows as $index => $row): ?>

                            <?php
                            $employee = $row["employee"];
                            [$levelText, $levelClass] = managerScoreLevel($row["avg_score"], $row["evaluated"], $row["total_kpi"]);
                            $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0;
                            ?>

                            <tr>

                                <td class="col-index"><?= $index + 1 ?></td>

                                <td>
                                    <div class="person">
                                        <span class="person-avatar"><?= htmlspecialchars(mb_substr($employee["first_name"], 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?></span>
                                        <div>
                                            <strong><?= htmlspecialchars($employee["first_name"] . " " . $employee["last_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                            <div class="cell-sub"><?= htmlspecialchars($employee["employee_code"], ENT_QUOTES, "UTF-8") ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?= htmlspecialchars($employee["department_name"] ?? "ไม่ระบุแผนก", ENT_QUOTES, "UTF-8") ?>
                                    <div class="cell-sub"><?= htmlspecialchars($employee["position_name"] ?: "ไม่ระบุตำแหน่ง", ENT_QUOTES, "UTF-8") ?></div>
                                </td>

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
                                    <?php if ($filterMonth > 0): ?>

                                        <?php if ($row["feedback"]): ?>
                                            <div class="stat-main"><?= number_format((float) $row["feedback"]["evaluation_score"], 1) ?> / 100</div>
                                            <div class="cell-sub">ให้แล้ว</div>
                                        <?php else: ?>
                                            <span class="cell-muted">ยังไม่ให้</span>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <div class="stat-main"><?= $row["feedback_count"] ?> เดือน</div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <a href="<?= managerUrl("employees", ["year" => $filterYear, "month" => $filterMonth, "department_id" => $filterDepartment, "employee_id" => $employee["employee_id"]]) ?>" class="btn-small edit">รายละเอียด</a>

                                        <?php if ($canFeedback): ?>
                                            <button
                                                type="button"
                                                class="btn-small feedback-btn"
                                                data-id="<?= (int) $employee["employee_id"] ?>"
                                                data-name="<?= htmlspecialchars($employee["first_name"] . " " . $employee["last_name"], ENT_QUOTES, "UTF-8") ?>"
                                                data-score="<?= htmlspecialchars((string) ($row["feedback"]["evaluation_score"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                                                data-feedback="<?= htmlspecialchars((string) ($row["feedback"]["feedback"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                                                Feedback
                                            </button>
                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>


</div>


<?php if ($canFeedback): ?>

    <!-- =========================================================
         FEEDBACK DIALOG
    ========================================================== -->

    <dialog id="feedbackDialog" class="feedback-dialog">

        <form method="POST" action="feedback.php">

            <button type="button" class="dialog-close" onclick="feedbackDialog.close()" aria-label="ปิด">×</button>

            <h2>Feedback ประจำเดือน<?= $monthNames[$filterMonth] ?> <?= $filterYear ?></h2>

            <p class="dialog-person" id="dialogPerson"></p>

            <input type="hidden" name="employee_id" id="feedbackEmployeeId">
            <input type="hidden" name="period_id" value="<?= (int) $selectedPeriod["period_id"] ?>">
            <input type="hidden" name="year" value="<?= $filterYear ?>">
            <input type="hidden" name="month" value="<?= $filterMonth ?>">
            <input type="hidden" name="department_id" value="<?= $filterDepartment ?>">
            <input type="hidden" name="return_employee_id" value="<?= $filterEmployee ?>">

            <label>
                คะแนนประเมินจากหัวหน้า (0-100)
                <input type="number" name="evaluation_score" id="feedbackScore" min="0" max="100" step="0.1" required>
            </label>

            <label>
                Feedback ถึงพนักงาน
                <textarea name="feedback" id="feedbackText" rows="5" placeholder="จุดแข็ง สิ่งที่ควรพัฒนา และข้อเสนอแนะ" required></textarea>
            </label>

            <div class="dialog-actions">
                <button type="button" class="btn btn-secondary" onclick="feedbackDialog.close()">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึก Feedback</button>
            </div>

        </form>

    </dialog>

    <script>
        document.querySelectorAll(".feedback-btn").forEach(function (button) {
            button.addEventListener("click", function () {
                document.getElementById("feedbackEmployeeId").value = button.dataset.id;
                document.getElementById("dialogPerson").textContent = button.dataset.name;
                document.getElementById("feedbackScore").value = button.dataset.score || "";
                document.getElementById("feedbackText").value = button.dataset.feedback || "";
                document.getElementById("feedbackDialog").showModal();
            });
        });
    </script>

<?php endif; ?>
