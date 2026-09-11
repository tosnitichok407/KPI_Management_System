<?php

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
               k.kpi_type, c.category_name, k.kpi_name, k.unit,
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
    $performanceScore = 0;
    $competencyScore = 0;
    foreach ($kpis as &$kpi) {
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
        "period" => $periodId ? ["period_id" => $periodId, "period_name" => $months[$month] . " " . $year, "start_date" => $start, "end_date" => $end, "year" => $year, "month" => $month] : null,
        "kpis" => $kpis,
        "summary" => ["performance_score" => $performanceScore, "competency_score" => $competencyScore, "total_score" => $performanceScore + $competencyScore, "percentage" => null]
    ];
}
