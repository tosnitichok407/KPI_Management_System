<?php

/*
|--------------------------------------------------------------------------
| แบบฟอร์มประเมิน (ใช้กับ PDF)
|--------------------------------------------------------------------------
|
| ส่วนที่ 1 = Performance, ส่วนที่ 2 = Competency
| คะแนนเต็ม = น้ำหนัก × 5, คะแนนจริง = น้ำหนัก × เกรด (สูตรเดียวกับไฟล์ Excel)
|
*/
function buildEvaluationForm(array $kpis, int $fullGrade = 5): array
{
    $definitions = [
        "Performance" => [
            "title" => "ส่วนที่ 1 : การประเมินผลงานที่สามารถวัดเป็นตัวเลขได้",
            "total_label" => "รวมคะแนนส่วนที่ 1",
            "summary_label" => "ส่วนที่ 1 : ผลงานที่วัดเป็นตัวเลขได้"
        ],
        "Competency" => [
            "title" => "ส่วนที่ 2 : การประเมินพฤติกรรมและสมรรถนะ (Competency)",
            "total_label" => "รวมคะแนนส่วนที่ 2",
            "summary_label" => "ส่วนที่ 2 : พฤติกรรมและสมรรถนะ"
        ]
    ];

    $sections = [];
    $totals = ["weight" => 0.0, "full" => 0.0, "actual" => 0.0];

    foreach ($definitions as $type => $definition) {

        $rows = [];
        $sum = ["weight" => 0.0, "full" => 0.0, "actual" => 0.0];

        foreach ($kpis as $kpi) {

            if ($kpi["kpi_type"] !== $type) {
                continue;
            }

            $weight = (float) $kpi["weight_value"];
            $grade = $kpi["score_value"] !== null ? (float) $kpi["score_value"] : null;
            $full = $weight * $fullGrade;
            $real = $grade === null ? null : $weight * $grade;

            $rows[] = [
                "kpi" => $kpi,
                "weight" => $weight,
                "full" => $full,
                "grade" => $grade,
                "real" => $real
            ];

            $sum["weight"] += $weight;
            $sum["full"] += $full;
            $sum["actual"] += $real ?? 0;
        }

        $sections[] = array_merge($definition, ["type" => $type, "rows" => $rows], $sum);

        foreach ($sum as $key => $value) {
            $totals[$key] += $value;
        }
    }

    $totals["percent"] = $totals["full"] > 0
        ? $totals["actual"] / $totals["full"] * 100
        : null;

    return [
        "sections" => $sections,
        "totals" => $totals,
        "full_grade" => $fullGrade
    ];
}


/* "ที่ทำได้" เช่น 107% หรือ 8 (ตามหน่วยของ KPI) */
function evaluationActualLabel(array $kpi): string
{
    if ($kpi["actual_value"] === null) {
        return "";
    }

    $actual = (float) $kpi["actual_value"];
    $text = floor($actual) == $actual ? number_format($actual) : number_format($actual, 2);

    return trim((string) $kpi["unit"]) === "%" ? $text . "%" : $text;
}


/*
|--------------------------------------------------------------------------
| เกณฑ์ตัดเกรด (สรุปผลการประเมิน)
|--------------------------------------------------------------------------
|
| คะแนนเต็ม 500 = น้ำหนักรวม 100 × เกรด 5
| ถ้าคะแนนเต็มไม่เท่ากับ 500 จะเทียบเป็นฐาน 500 ก่อนตัดเกรด
|
*/
function evaluationGradeScale(): array
{
    return [
        ["min" => 451, "range" => "451-500 คะแนน", "grade" => "A+", "description" => "ผลงานโดยรวมสูงกว่าเป้าหมาย"],
        ["min" => 400, "range" => "400-450 คะแนน", "grade" => "A", "description" => "ผลงานโดยรวมบรรลุเป้าหมาย"],
        ["min" => 300, "range" => "300-399 คะแนน", "grade" => "B", "description" => "ผลงานโดยรวมต่ำกว่าเป้าหมายอยู่ในเกณฑ์ที่ยอมรับได้"],
        ["min" => 200, "range" => "200-299 คะแนน", "grade" => "C", "description" => "ผลงานโดยรวมต่ำกว่าเป้าหมายมาก"],
        ["min" => 100, "range" => "100-199 คะแนน", "grade" => "D", "description" => "ผลงานโดยรวมไม่ผ่านเกณฑ์ที่กำหนด ต้องปรับปรุง"]
    ];
}


/* เกรดจากคะแนนที่ได้ → แถวของเกณฑ์ หรือ null ถ้ายังไม่มีคะแนน (ต่ำกว่า 100 = D) */
function evaluationGrade(?float $actual, ?float $full): ?array
{
    if ($actual === null || $full === null || $full <= 0) {
        return null;
    }

    $score = round($actual / $full * 500, 6);
    $scale = evaluationGradeScale();

    foreach ($scale as $row) {
        if ($score >= $row["min"]) {
            return $row;
        }
    }

    return end($scale);
}


function evaluationShortMonths(): array
{
    return [
        1 => "ม.ค.", 2 => "ก.พ.", 3 => "มี.ค.", 4 => "เม.ย.", 5 => "พ.ค.", 6 => "มิ.ย.",
        7 => "ก.ค.", 8 => "ส.ค.", 9 => "ก.ย.", 10 => "ต.ค.", 11 => "พ.ย.", 12 => "ธ.ค."
    ];
}


/*
| คะแนนรายเดือนทั้งปีของพนักงาน (ตารางสรุปท้ายแบบฟอร์ม)
| [1..12 => full, actual, percent, grade] — สูตรเดียวกับแบบฟอร์มรายเดือน:
| คะแนนเต็ม = Σ น้ำหนัก × 5, คะแนนที่ทำได้ = Σ น้ำหนัก × เกรด (เฉพาะ KPI ที่มีผลในเดือนนั้น)
| เดือนที่ยังไม่มีผลประเมินเลย → actual/percent/grade = null
*/
function loadEmployeeYearScores(PDO $pdo, int $employeeId, int $year, int $fullGrade = 5): array
{
    $assignmentStmt = $pdo->prepare("
        SELECT
            a.assignment_id,
            a.weight,
            COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) AS start_date,
            COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) AS end_date
        FROM kpi_assignments a
        WHERE a.employee_id = :employee_id
          AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :year_end
          AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :year_start
          AND a.status = 'Active'
    ");
    $assignmentStmt->execute([
        ":employee_id" => $employeeId,
        ":year_start" => sprintf("%04d-01-01", $year),
        ":year_end" => sprintf("%04d-12-31", $year)
    ]);
    $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

    $scoreStmt = $pdo->prepare("
        SELECT kp.assignment_id, ep.period_month, kp.score
        FROM kpi_performances kp
        INNER JOIN evaluation_periods ep ON ep.period_id = kp.period_id
        WHERE kp.employee_id = :employee_id
          AND ep.period_year = :year
          AND kp.score IS NOT NULL
    ");
    $scoreStmt->execute([":employee_id" => $employeeId, ":year" => $year]);

    $scores = [];
    foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scores[(int) $row["period_month"]][(int) $row["assignment_id"]] = (float) $row["score"];
    }

    $months = [];

    for ($month = 1; $month <= 12; $month++) {

        $monthStart = sprintf("%04d-%02d-01", $year, $month);
        $monthEnd = date("Y-m-t", strtotime($monthStart));

        $full = 0.0;
        $actual = 0.0;
        $hasKpi = false;
        $hasResult = false;

        foreach ($assignments as $assignment) {

            if ($assignment["start_date"] > $monthEnd || $assignment["end_date"] < $monthStart) {
                continue;
            }

            $weight = (float) $assignment["weight"];
            $hasKpi = true;
            $full += $weight * $fullGrade;

            if (isset($scores[$month][(int) $assignment["assignment_id"]])) {
                $actual += $weight * $scores[$month][(int) $assignment["assignment_id"]];
                $hasResult = true;
            }
        }

        $grade = $hasResult ? evaluationGrade($actual, $full) : null;

        $months[$month] = [
            "full" => $hasKpi ? $full : null,
            "actual" => $hasResult ? $actual : null,
            "percent" => $hasResult && $full > 0 ? $actual / $full * 100 : null,
            "grade" => $grade["grade"] ?? null
        ];
    }

    return $months;
}


function requireEmployeeExportAccess(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION["user_id"])) {
        http_response_code(401);
        exit("Unauthorized");
    }
    $employeeId = (int) ($_SESSION["employee_id"] ?? 0);
    if ($employeeId <= 0) {
        http_response_code(403);
        exit("Employee account is required");
    }
    return ["employee_id" => $employeeId];
}

/*
| เกณฑ์ระดับผลงาน 5..1 ของแต่ละ KPI  → [kpi_id => [5 => "...", 4 => "...", ...]]
| ใช้แหล่งเดียวต่อ KPI ตามลำดับ: kpi_score_criteria → kpi_score_levels → score_5..score_1 (ข้อมูลเก่า)
*/
function loadKpiScoreCriteria(PDO $pdo, array $kpis): array
{
    $ids = array_values(array_unique(array_map(fn($kpi) => (int) $kpi["kpi_id"], $kpis)));
    if (empty($ids)) {
        return [];
    }
    $in = implode(",", array_fill(0, count($ids), "?"));

    $fromCriteria = [];
    $stmt = $pdo->prepare("SELECT kpi_id, score_level, criteria FROM kpi_score_criteria WHERE kpi_id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fromCriteria[(int) $row["kpi_id"]][(int) $row["score_level"]] = trim($row["criteria"]);
    }

    $fromLevels = [];
    $stmt = $pdo->prepare("SELECT kpi_id, score, criteria FROM kpi_score_levels WHERE kpi_id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fromLevels[(int) $row["kpi_id"]][(int) round((float) $row["score"])] = trim($row["criteria"]);
    }

    $result = [];
    foreach ($kpis as $kpi) {
        $kpiId = (int) $kpi["kpi_id"];
        if (!empty($fromCriteria[$kpiId])) {
            $result[$kpiId] = $fromCriteria[$kpiId];
        } elseif (!empty($fromLevels[$kpiId])) {
            $result[$kpiId] = $fromLevels[$kpiId];
        } else {
            for ($level = 5; $level >= 1; $level--) {
                $text = trim((string) ($kpi["score_" . $level] ?? ""));
                if ($text !== "") {
                    $result[$kpiId][$level] = $text;
                }
            }
        }
    }

    return $result;
}

function loadEmployeePerformanceReport(PDO $pdo, int $employeeId, int $year, int $month): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        http_response_code(400);
        exit("Invalid month");
    }
    $employeeStmt = $pdo->prepare("SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, p.position_name, d.department_name FROM employees e LEFT JOIN positions p ON p.position_id = e.position_id LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = :employee_id LIMIT 1");
    $employeeStmt->execute([":employee_id" => $employeeId]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        http_response_code(404);
        exit("Employee not found");
    }

    $start = sprintf("%04d-%02d-01", $year, $month);
    $end = date("Y-m-t", strtotime($start));
    $months = [1 => "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $periodStmt = $pdo->prepare("SELECT period_id FROM evaluation_periods WHERE period_year = :year AND period_month = :month LIMIT 1");
    $periodStmt->execute([":year" => $year, ":month" => $month]);
    $periodId = (int) ($periodStmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare("
        SELECT a.assignment_id, a.target_value AS assignment_target, a.weight AS assignment_weight,
               k.kpi_id, k.kpi_type, c.category_name, k.kpi_name, k.description, k.unit,
               COALESCE(k.max_score, 5) AS max_score,
               k.score_5, k.score_4, k.score_3, k.score_2, k.score_1,
               p.target, p.actual, p.score, p.status AS performance_status
        FROM kpi_assignments a
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        LEFT JOIN kpi_categories c ON c.category_id = k.category_id
        LEFT JOIN kpi_performances p ON p.performance_id = (
            SELECT latest.performance_id FROM kpi_performances latest
            WHERE latest.assignment_id = a.assignment_id
              AND latest.employee_id = a.employee_id
              AND latest.period_id = :period_id
            ORDER BY latest.performance_date DESC, latest.performance_id DESC LIMIT 1
        )
        WHERE a.employee_id = :employee_id
          AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
          AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :month_start
          AND a.status = 'Active'
        ORDER BY k.kpi_type, a.assignment_id
    ");
    $stmt->execute([":period_id" => $periodId, ":employee_id" => $employeeId, ":month_start" => $start, ":month_end" => $end]);
    $kpis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $criteria = loadKpiScoreCriteria($pdo, $kpis);
    $performanceScore = 0;
    $competencyScore = 0;
    foreach ($kpis as &$kpi) {
        $kpi["criteria"] = $criteria[(int) $kpi["kpi_id"]] ?? [];
        $kpi["target_value"] = $kpi["assignment_target"];
        $kpi["actual_value"] = $kpi["actual"];
        $kpi["score_value"] = $kpi["score"];
        $kpi["weight_value"] = (float) $kpi["assignment_weight"];
        $kpi["status_value"] = $kpi["performance_status"] ?? "ยังไม่มีผลงาน";
        if ($kpi["score"] !== null) {
            if ($kpi["kpi_type"] === "Performance") $performanceScore += (float) $kpi["score"];
            if ($kpi["kpi_type"] === "Competency") $competencyScore += (float) $kpi["score"];
        }
    }
    unset($kpi);
    return [
        "employee" => $employee,
        "period" => $periodId ? ["period_id" => $periodId, "period_name" => $months[$month] . " " . $year, "month_name" => $months[$month], "start_date" => $start, "end_date" => $end, "year" => $year, "month" => $month] : null,
        "kpis" => $kpis,
        "summary" => ["performance_score" => $performanceScore, "competency_score" => $competencyScore, "total_score" => $performanceScore + $competencyScore, "percentage" => null]
    ];
}
