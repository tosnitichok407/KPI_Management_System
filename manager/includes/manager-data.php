<?php

/*
|--------------------------------------------------------------------------
| Manager Data Helpers
|--------------------------------------------------------------------------
|
| โหลดข้อมูลของปีที่เลือกครั้งเดียว แล้วสรุปเป็นรายแผนก / รายคน / รายเดือน ใน PHP
|
| - Assignment  = KPI ที่มอบหมาย (ใช้ได้ทั้งปี)
| - Performance = ผลประเมิน ผูกกับรอบประเมินรายเดือน (kpi_performances + evaluation_periods)
| - คะแนนเต็ม 5 ต่อ KPI, ถ่วงน้ำหนักด้วย weight ของ Assignment (สูตรเดียวกับ Dashboard ของ Admin)
|
*/

require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


/*
|--------------------------------------------------------------------------
| Load Year Data
|--------------------------------------------------------------------------
*/

function managerLoadYearData(PDO $pdo, int $year): array
{
    $data = [
        "year" => $year,
        "employees" => [],
        "departments" => [],
        "assignments" => [],
        "assignments_by_employee" => [],
        "performances" => [],
        "performances_by_assignment" => [],
        "periods" => [],
        "feedback" => []
    ];


    /* พนักงาน Active + แผนก + ตำแหน่ง */

    $employeeRows = $pdo->query("
        SELECT
            e.employee_id,
            e.employee_code,
            e.first_name,
            e.last_name,
            e.department_id,
            d.department_name,
            p.position_name
        FROM employees e
        LEFT JOIN departments d ON d.department_id = e.department_id
        LEFT JOIN positions p ON p.position_id = e.position_id
        WHERE e.status = 'Active'
        ORDER BY d.department_name ASC, e.first_name ASC, e.last_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($employeeRows as $row) {
        $data["employees"][(int) $row["employee_id"]] = $row;
    }


    /* แผนกทั้งหมด */

    $departmentRows = $pdo->query("
        SELECT department_id, department_name
        FROM departments
        ORDER BY department_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($departmentRows as $row) {
        $data["departments"][(int) $row["department_id"]] = $row["department_name"];
    }


    /* Assignment ของปี (Active) */

    $assignmentStmt = $pdo->prepare("
        SELECT
            a.assignment_id,
            a.employee_id,
            a.kpi_id,
            a.weight,
            a.target_value,
            COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) AS start_date,
            COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) AS end_date,
            k.kpi_name,
            k.kpi_type,
            k.unit,
            COALESCE(k.max_score, 5) AS max_score
        FROM kpi_assignments a
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        WHERE a.assignment_year = :year
          AND a.status = 'Active'
        ORDER BY FIELD(k.kpi_type, 'Performance', 'Competency'), k.kpi_name ASC
    ");
    $assignmentStmt->execute([":year" => $year]);

    foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assignmentId = (int) $row["assignment_id"];
        $data["assignments"][$assignmentId] = $row;
        $data["assignments_by_employee"][(int) $row["employee_id"]][] = $assignmentId;
    }


    /* รอบประเมินของปี (เดือน => รอบ) */

    $periodStmt = $pdo->prepare("
        SELECT period_id, period_name, period_year, period_month, quarter, start_date, end_date, status
        FROM evaluation_periods
        WHERE period_year = :year
        ORDER BY period_month ASC
    ");
    $periodStmt->execute([":year" => $year]);

    foreach ($periodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data["periods"][(int) $row["period_month"]] = $row;
    }


    /* ผลประเมินของปี (เฉพาะที่มีคะแนน) */

    $performanceStmt = $pdo->prepare("
        SELECT
            kp.performance_id,
            kp.assignment_id,
            kp.employee_id,
            kp.period_id,
            ep.period_month,
            ep.quarter,
            kp.actual,
            kp.score,
            kp.status
        FROM kpi_performances kp
        INNER JOIN evaluation_periods ep ON ep.period_id = kp.period_id
        WHERE ep.period_year = :year
          AND kp.score IS NOT NULL
    ");
    $performanceStmt->execute([":year" => $year]);

    foreach ($performanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assignmentId = (int) $row["assignment_id"];

        // ข้าม Performance ของ Assignment ที่ไม่ Active / ไม่ใช่ปีนี้
        if (!isset($data["assignments"][$assignmentId])) {
            continue;
        }

        $data["performances"][] = $row;
        $data["performances_by_assignment"][$assignmentId][(int) $row["period_month"]] = $row;
    }


    /* Feedback ของหัวหน้า (พนักงาน => เดือน => feedback) */

    $feedbackStmt = $pdo->prepare("
        SELECT
            f.feedback_id,
            f.manager_id,
            f.employee_id,
            f.period_id,
            f.evaluation_score,
            f.feedback,
            f.updated_at,
            ep.period_month
        FROM manager_feedback f
        INNER JOIN evaluation_periods ep ON ep.period_id = f.period_id
        WHERE ep.period_year = :year
        ORDER BY f.updated_at ASC
    ");
    $feedbackStmt->execute([":year" => $year]);

    foreach ($feedbackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data["feedback"][(int) $row["employee_id"]][(int) $row["period_month"]] = $row;
    }

    return $data;
}


/*
|--------------------------------------------------------------------------
| Employee Selection
|--------------------------------------------------------------------------
*/

/* รหัสพนักงานในแผนก (0 = ทุกแผนก) */
function managerEmployeeIds(array $data, int $departmentId = 0): array
{
    $ids = [];

    foreach ($data["employees"] as $employeeId => $employee) {
        if ($departmentId === 0 || (int) $employee["department_id"] === $departmentId) {
            $ids[] = $employeeId;
        }
    }

    return $ids;
}


/* Assignment มีผลในเดือนนั้นหรือไม่ */
function managerAssignmentInMonth(array $assignment, int $year, int $month): bool
{
    $monthStart = sprintf("%04d-%02d-01", $year, $month);
    $monthEnd = date("Y-m-t", strtotime($monthStart));

    return $assignment["start_date"] <= $monthEnd && $assignment["end_date"] >= $monthStart;
}


/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
|
| $month = 0 → ทั้งปี (ประเมินแล้ว = Assignment ที่มีคะแนนอย่างน้อย 1 เดือน)
| $month > 0 → เฉพาะเดือนนั้น
|
*/

function managerStats(array $data, array $employeeIds, int $month = 0, string $type = ""): array
{
    $stats = [
        "employees" => count($employeeIds),
        "total_kpi" => 0,
        "evaluated" => 0,
        "avg_score" => null,
        "avg_performance" => null,
        "avg_competency" => null,
        "weighted_percent" => null,
        "feedback_count" => 0
    ];

    $scoreSum = 0.0;
    $scoreCount = 0;
    $typeSums = ["Performance" => [0.0, 0], "Competency" => [0.0, 0]];
    $weightedSum = 0.0;
    $weightedWeight = 0.0;

    foreach ($employeeIds as $employeeId) {

        foreach ($data["assignments_by_employee"][$employeeId] ?? [] as $assignmentId) {

            $assignment = $data["assignments"][$assignmentId];

            if ($type !== "" && $assignment["kpi_type"] !== $type) {
                continue;
            }

            $performances = $data["performances_by_assignment"][$assignmentId] ?? [];

            if ($month > 0) {

                if (!managerAssignmentInMonth($assignment, $data["year"], $month)) {
                    continue;
                }

                $stats["total_kpi"]++;

                $performances = isset($performances[$month]) ? [$performances[$month]] : [];

                if ($performances) {
                    $stats["evaluated"]++;
                }

            } else {

                $stats["total_kpi"]++;

                if ($performances) {
                    $stats["evaluated"]++;
                }
            }

            foreach ($performances as $performance) {

                $score = (float) $performance["score"];
                $weight = (float) $assignment["weight"];
                $maxScore = (float) $assignment["max_score"] ?: 5;

                $scoreSum += $score;
                $scoreCount++;

                if (isset($typeSums[$assignment["kpi_type"]])) {
                    $typeSums[$assignment["kpi_type"]][0] += $score;
                    $typeSums[$assignment["kpi_type"]][1]++;
                }

                $weightedSum += ($score / $maxScore) * $weight;
                $weightedWeight += $weight;
            }
        }

        // Feedback
        $feedbackMonths = $data["feedback"][$employeeId] ?? [];

        if ($month > 0) {
            $stats["feedback_count"] += isset($feedbackMonths[$month]) ? 1 : 0;
        } else {
            $stats["feedback_count"] += count($feedbackMonths);
        }
    }

    if ($scoreCount > 0) {
        $stats["avg_score"] = $scoreSum / $scoreCount;
    }

    if ($typeSums["Performance"][1] > 0) {
        $stats["avg_performance"] = $typeSums["Performance"][0] / $typeSums["Performance"][1];
    }

    if ($typeSums["Competency"][1] > 0) {
        $stats["avg_competency"] = $typeSums["Competency"][0] / $typeSums["Competency"][1];
    }

    if ($weightedWeight > 0) {
        $stats["weighted_percent"] = $weightedSum / $weightedWeight * 100;
    }

    return $stats;
}


/*
|--------------------------------------------------------------------------
| Series (สำหรับกราฟเส้น)
|--------------------------------------------------------------------------
*/

/* คะแนนเฉลี่ยรายเดือน [1..12 => avg|null] */
function managerMonthlyScores(array $data, array $employeeIds, string $type = ""): array
{
    $sums = array_fill(1, 12, [0.0, 0]);
    $lookup = array_flip($employeeIds);

    foreach ($data["performances"] as $performance) {

        if (!isset($lookup[(int) $performance["employee_id"]])) {
            continue;
        }

        $assignment = $data["assignments"][(int) $performance["assignment_id"]];

        if ($type !== "" && $assignment["kpi_type"] !== $type) {
            continue;
        }

        $month = (int) $performance["period_month"];
        $sums[$month][0] += (float) $performance["score"];
        $sums[$month][1]++;
    }

    $series = [];

    foreach ($sums as $month => [$sum, $count]) {
        $series[$month] = $count > 0 ? round($sum / $count, 2) : null;
    }

    return $series;
}


/* % KPI ที่ประเมินแล้วรายเดือน [1..12 => percent|null] (null = ยังไม่มีรอบประเมินเดือนนั้น) */
function managerMonthlyCompletion(array $data, array $employeeIds): array
{
    $series = [];

    for ($month = 1; $month <= 12; $month++) {

        if (!isset($data["periods"][$month])) {
            $series[$month] = null;
            continue;
        }

        $stats = managerStats($data, $employeeIds, $month);

        $series[$month] = $stats["total_kpi"] > 0
            ? round($stats["evaluated"] / $stats["total_kpi"] * 100, 1)
            : null;
    }

    return $series;
}


/* คะแนนเฉลี่ยรายไตรมาส [Q1..Q4 => avg|null] */
function managerQuarterScores(array $data, array $employeeIds): array
{
    $monthly = managerMonthlyScoreSums($data, $employeeIds);
    $series = [];

    foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter) {

        [$first, $last] = getQuarterMonths($quarter);
        $sum = 0.0;
        $count = 0;

        for ($month = $first; $month <= $last; $month++) {
            $sum += $monthly[$month][0];
            $count += $monthly[$month][1];
        }

        $series[$quarter] = $count > 0 ? round($sum / $count, 2) : null;
    }

    return $series;
}


/* ผลรวม/จำนวนคะแนนรายเดือน (ใช้ภายใน) */
function managerMonthlyScoreSums(array $data, array $employeeIds): array
{
    $sums = array_fill(1, 12, [0.0, 0]);
    $lookup = array_flip($employeeIds);

    foreach ($data["performances"] as $performance) {

        if (!isset($lookup[(int) $performance["employee_id"]])) {
            continue;
        }

        $month = (int) $performance["period_month"];
        $sums[$month][0] += (float) $performance["score"];
        $sums[$month][1]++;
    }

    return $sums;
}


/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

/* ระดับผลงานจากคะแนนเฉลี่ย (เต็ม 5) → [ข้อความ, class] */
function managerScoreLevel(?float $avgScore, int $evaluated, int $totalKpi): array
{
    if ($avgScore === null) {
        return $totalKpi > 0 ? ["รอประเมิน", "pending"] : ["ไม่มี KPI", "none"];
    }

    if ($avgScore >= 4) {
        return ["โดดเด่น", "good"];
    }

    if ($avgScore >= 3) {
        return ["อยู่ในเกณฑ์", "steady"];
    }

    return ["ควรติดตาม", "needs"];
}


/* ชื่อเดือนแบบสั้นสำหรับแกนกราฟ */
function managerShortMonths(): array
{
    return [
        1 => "ม.ค.", 2 => "ก.พ.", 3 => "มี.ค.", 4 => "เม.ย.", 5 => "พ.ค.", 6 => "มิ.ย.",
        7 => "ก.ค.", 8 => "ส.ค.", 9 => "ก.ย.", 10 => "ต.ค.", 11 => "พ.ย.", 12 => "ธ.ค."
    ];
}


/* สีเส้นกราฟ */
function managerChartColors(): array
{
    return [
        "#244397", "#ed4924", "#3eaa65", "#e7a51c", "#7c3aed",
        "#0891b2", "#db2777", "#65a30d", "#f97316", "#6b7280"
    ];
}


/* ตัวเลขทศนิยม หรือ "-" */
function managerFormat(?float $value, int $decimals = 2, string $suffix = ""): string
{
    return $value === null ? "-" : number_format($value, $decimals) . $suffix;
}


/* สร้าง query string ของหน้า manager (คง filter เดิม) */
function managerUrl(string $page, array $params = []): string
{
    $query = array_filter(array_merge(["page" => $page], $params), fn($v) => $v !== null && $v !== "" && $v !== 0);

    return "index.php?" . http_build_query($query);
}
