<?php

/*
|--------------------------------------------------------------------------
| KPI Summary Data
|--------------------------------------------------------------------------
|
| ใช้ร่วมกันระหว่างหน้า Summary (kpi-summary.php) และ Export PDF
| เพื่อให้ตัวเลขในหน้าเว็บกับ PDF ตรงกันเสมอ
|
| - Assignment ใช้ได้ทั้งปี (1 แถว = KPI 1 ตัวของพนักงาน 1 คน)
| - ผลประเมิน (kpi_performances) ผูกกับรอบประเมินรายเดือน
|   เลือกเดือน  -> ผลของเดือนนั้น
|   ไม่เลือก    -> รวมทุกเดือนในปี (จำนวนเดือนที่ประเมิน + คะแนนเฉลี่ย)
|
*/

require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";
require_once __DIR__ . "/../../includes/kpi-score-helper.php";


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

function kpiSummaryFilters(array $input): array
{
    $currentYear = (int) date("Y");

    $year = (int) ($input["year"] ?? $currentYear);
    $month = (int) ($input["month"] ?? 0);
    $type = (string) ($input["kpi_type"] ?? "");
    $status = (string) ($input["status"] ?? "Active");

    if ($year < 2000 || $year > 2100) {
        $year = $currentYear;
    }

    if ($month < 1 || $month > 12) {
        $month = 0;
    }

    if (!in_array($type, ["Competency", "Performance"], true)) {
        $type = "";
    }

    if (!in_array($status, ["", "Active", "Inactive"], true)) {
        $status = "Active";
    }

    return [
        "year" => $year,
        "month" => $month,
        "employee_id" => max(0, (int) ($input["employee_id"] ?? 0)),
        "kpi_type" => $type,
        "status" => $status
    ];
}


/* query string ของ filter (ใช้ทำลิงก์ปี/เดือน และลิงก์ Export) */
function kpiSummaryQuery(array $filters, array $overrides = []): string
{
    $filters = array_merge($filters, $overrides);

    $query = ["year" => $filters["year"]];

    if ($filters["month"] > 0) {
        $query["month"] = $filters["month"];
    }

    if ($filters["employee_id"] > 0) {
        $query["employee_id"] = $filters["employee_id"];
    }

    if ($filters["kpi_type"] !== "") {
        $query["kpi_type"] = $filters["kpi_type"];
    }

    // ส่งเสมอ เพราะ status ว่าง = "ทั้งหมด" (ค่าเริ่มต้นคือ Active)
    $query["status"] = $filters["status"];

    return http_build_query($query);
}




/*
|--------------------------------------------------------------------------
| Report
|--------------------------------------------------------------------------
*/

function loadKpiSummaryReport(PDO $pdo, array $filters): array
{
    $year = $filters["year"];
    $month = $filters["month"];
    $hasMonth = $month > 0;
    $monthNames = monthlyPeriodMonths();


    /* ผลประเมินของเดือน/ปีที่เลือก */

    $perfWhere = " ep.period_year = ? ";
    $perfParams = [$year];

    if ($hasMonth) {
        $perfWhere .= " AND ep.period_month = ? ";
        $perfParams[] = $month;
    }

    /* เงื่อนไขช่วงเวลาเดียวกัน ใช้กับ subquery อื่น (alias ต่างกัน) */
    $perfWhereRows = str_replace("ep.", "ex.", $perfWhere);
    $perfWhereHistory = str_replace("ep.", "ey.", $perfWhere);

    $sql = "
        SELECT

            a.assignment_id,
            a.employee_id,
            a.kpi_id,
            a.assignment_year,
            a.target_value,
            a.weight,
            COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) AS start_date,
            COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) AS end_date,
            a.status,
            e.status AS employee_status,
            /* แถวผลงานจริงในช่วงที่เลือก (ใช้แยกประวัติของ Assignment / พนักงานที่ปิดใช้งาน) */
            (
                SELECT COUNT(*)
                FROM kpi_performances kx
                INNER JOIN evaluation_periods ex ON ex.period_id = kx.period_id
                WHERE kx.assignment_id = a.assignment_id
                  AND {$perfWhereRows}
            ) AS perf_rows,
            e.employee_code,
            e.first_name,
            e.last_name,
            d.department_name,

            k.kpi_name,
            k.kpi_type,
            k.unit,
            COALESCE(k.max_score, 5) AS max_score,

            COALESCE(perf.eval_count, 0) AS eval_count,
            perf.avg_score,
            perf.last_actual,
            perf.last_status

        FROM kpi_assignments a

        INNER JOIN employees e
            ON a.employee_id = e.employee_id

        LEFT JOIN departments d
            ON e.department_id = d.department_id

        INNER JOIN kpi_indicators k
            ON a.kpi_id = k.kpi_id

        LEFT JOIN (
            SELECT
                kp.assignment_id,
                COUNT(kp.performance_id) AS eval_count,
                AVG(kp.score) AS avg_score,
                MAX(kp.actual) AS last_actual,
                MAX(kp.status) AS last_status
            FROM kpi_performances kp
            INNER JOIN evaluation_periods ep
                ON ep.period_id = kp.period_id
            WHERE kp.score IS NOT NULL
              AND {$perfWhere}
            GROUP BY kp.assignment_id
        ) perf
            ON perf.assignment_id = a.assignment_id

        WHERE a.assignment_year = ?
    ";

    /* ลำดับ parameter ตามตำแหน่งใน SQL: perf_rows (SELECT) → perf (JOIN) → ปี */
    $params = array_merge($perfParams, $perfParams, [$year]);


    /* เดือนที่เลือก: เฉพาะ KPI ที่มีผลในเดือนนั้น */

    if ($hasMonth) {

        $monthStart = sprintf("%04d-%02d-01", $year, $month);
        $monthEnd = date("Y-m-t", strtotime($monthStart));

        $sql .= "
            AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= ?
            AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= ?
        ";

        $params[] = $monthEnd;
        $params[] = $monthStart;
    }

    if ($filters["employee_id"] > 0) {
        $sql .= " AND a.employee_id = ? ";
        $params[] = $filters["employee_id"];
    }

    if ($filters["kpi_type"] !== "") {
        $sql .= " AND k.kpi_type = ? ";
        $params[] = $filters["kpi_type"];
    }

    if ($filters["status"] === "Active") {
        /*
        | ค่าเริ่มต้น Active: รวม Assignment ที่ปิดใช้งานเฉพาะเมื่อมีผลงานจริงในช่วงที่เลือก
        | (แสดงเป็นประวัติพร้อมป้าย · ไม่นับน้ำหนักในยอดน้ำหนักรวม)
        */
        $sql .= "
            AND (
                a.status = 'Active'
                OR EXISTS (
                    SELECT 1
                    FROM kpi_performances ky
                    INNER JOIN evaluation_periods ey ON ey.period_id = ky.period_id
                    WHERE ky.assignment_id = a.assignment_id
                      AND {$perfWhereHistory}
                )
            )
        ";
        $params = array_merge($params, $perfParams);
    } elseif ($filters["status"] !== "") {
        /* Inactive / ทั้งหมด: แสดงตามสถานะที่เลือกเหมือนเดิม (รวมรายการที่ไม่มีผลงาน) */
        $sql .= " AND a.status = ? ";
        $params[] = $filters["status"];
    }

    $sql .= "
        ORDER BY
            e.first_name ASC,
            e.last_name ASC,
            a.employee_id ASC,
            FIELD(k.kpi_type, 'Performance', 'Competency'),
            k.kpi_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /* จัดกลุ่มตามพนักงาน */

    /*
    |--------------------------------------------------------------------------
    | แยกข้อมูลปัจจุบัน / ประวัติ (กติกาเดียวกันทั้งหน้าจอและ PDF)
    |--------------------------------------------------------------------------
    |
    | - แถวที่นับ (counted): Assignment Active หรือ Assignment ที่ปิดใช้งานแต่มีผลงานจริงในช่วงที่เลือก
    |   ตัวนับ ตัวหาร และค่าเฉลี่ยทุกตัวใช้เฉพาะแถวที่นับ
    | - น้ำหนักรวม (total_weight) นับเฉพาะ Assignment Active · Assignment ที่ปิดใช้งานแยกเป็น history_weight
    | - พนักงาน Active → ตารางหลัก (employees) · พนักงานที่ปิดใช้งานและมีผลงาน → history_employees
    | - แถวที่ไม่มีผลงานของพนักงานที่ปิดใช้งาน → other_rows (แสดงอ้างอิง ไม่นับ)
    |
    */
    $employees = [];
    $historyEmployees = [];
    $otherRows = [];

    foreach ($details as &$row) {

        $row["period_label"] = $hasMonth
            ? $monthNames[$month]
            : assignmentMonthRangeLabel($row["start_date"], $row["end_date"]);

        $row["quarter_label"] = $hasMonth
            ? getQuarterByMonth($month)
            : assignmentQuarterRangeLabel($row["start_date"], $row["end_date"]);

        $row["is_inactive_assignment"] = $row["status"] !== "Active";
        $row["has_perf"] = (int) $row["perf_rows"] > 0;
        $row["counted"] = !$row["is_inactive_assignment"] || $row["has_perf"];
        $row["employee_inactive"] = $row["employee_status"] !== "Active";

        $employeeId = (int) $row["employee_id"];

        if ($row["employee_inactive"] && !$row["has_perf"]) {
            $row["counted"] = false;
            $otherRows[] = $row;
            continue;
        }

        if ($row["employee_inactive"]) {
            $bucket = &$historyEmployees;
        } else {
            $bucket = &$employees;
        }

        if (!isset($bucket[$employeeId])) {

            $bucket[$employeeId] = [
                "employee_id" => $employeeId,
                "employee_code" => $row["employee_code"],
                "first_name" => $row["first_name"],
                "last_name" => $row["last_name"],
                "department_name" => $row["department_name"],
                "employee_inactive" => $row["employee_inactive"],
                "kpis" => [],
                "count" => ["Performance" => 0, "Competency" => 0],
                "weight" => ["Performance" => 0.0, "Competency" => 0.0],
                "history_weight" => 0.0,
                "counted_total" => 0,
                "evaluated" => 0,
                "score_sum" => 0.0,
                "weighted_sum" => 0.0,
                "weighted_weight" => 0.0
            ];
        }

        $summary = &$bucket[$employeeId];

        $type = $row["kpi_type"];
        $weight = (float) $row["weight"];

        $summary["kpis"][] = $row;

        if ($row["counted"]) {

            $summary["counted_total"]++;

            if (isset($summary["count"][$type])) {

                $summary["count"][$type]++;

                if ($row["is_inactive_assignment"]) {
                    $summary["history_weight"] += $weight;
                } else {
                    $summary["weight"][$type] += $weight;
                }
            }

            if ($row["avg_score"] !== null) {

                $score = (float) $row["avg_score"];
                $maxScore = (float) $row["max_score"] ?: 5;

                $summary["evaluated"]++;
                $summary["score_sum"] += $score;

                // ถ่วงน้ำหนัก: สูตรเดียวกับ Dashboard (score / max × weight)
                $summary["weighted_sum"] += ($score / $maxScore) * $weight;
                $summary["weighted_weight"] += $weight;
            }
        }

        unset($summary, $bucket);
    }
    unset($row);

    foreach ([&$employees, &$historyEmployees] as &$group) {
        foreach ($group as &$summary) {

            $summary["kpi_total"] = $summary["counted_total"];
            $summary["total_weight"] = $summary["weight"]["Performance"] + $summary["weight"]["Competency"];

            $summary["evaluated_ratio"] = $summary["kpi_total"] > 0
                ? $summary["evaluated"] / $summary["kpi_total"]
                : 0;

            $summary["avg_score"] = $summary["evaluated"] > 0
                ? $summary["score_sum"] / $summary["evaluated"]
                : null;

            $summary["weighted_percent"] = $summary["weighted_weight"] > 0
                ? $summary["weighted_sum"] / $summary["weighted_weight"] * 100
                : null;
        }
        unset($summary);
    }
    unset($group);

    /* ตัวเลขปัจจุบัน: เฉพาะพนักงาน Active และแถวที่นับ */
    $totals = [
        "employees" => count($employees),
        "kpi" => 0,
        "performance" => 0,
        "competency" => 0,
        "evaluated" => 0,
        "avg_score" => null
    ];

    $scoreSum = 0.0;

    foreach ($employees as $summary) {
        $totals["kpi"] += $summary["kpi_total"];
        $totals["performance"] += $summary["count"]["Performance"];
        $totals["competency"] += $summary["count"]["Competency"];
        $totals["evaluated"] += $summary["evaluated"];
        $scoreSum += $summary["score_sum"];
    }

    if ($totals["evaluated"] > 0) {
        $totals["avg_score"] = $scoreSum / $totals["evaluated"];
    }

    return [
        "filters" => $filters,
        "details" => $details,
        "employees" => $employees,
        "history_employees" => $historyEmployees,
        "other_rows" => $otherRows,
        "totals" => $totals,
        "period" => $hasMonth ? findEvaluationPeriodByMonth($pdo, $year, $month) : null,
        "label" => $hasMonth
            ? $monthNames[$month] . " " . $year . " (" . getQuarterByMonth($month) . ")"
            : "ปี " . $year . " (ทุกเดือน)"
    ];
}


/*
|--------------------------------------------------------------------------
| Display Helpers
|--------------------------------------------------------------------------
*/

function summaryTypeClass(string $type): string
{
    return strtolower($type) === "performance" ? "performance" : "";
}

function summaryWeightClass(float $total): string
{
    if ($total > 100) {
        return "over";
    }

    return $total == 100 ? "full" : "";
}
