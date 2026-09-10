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

    return [
        "employee_id" => $employeeId,
        "first_name" => $_SESSION["first_name"] ?? "",
        "last_name" => $_SESSION["last_name"] ?? "",
        "employee_code" => $_SESSION["employee_code"] ?? "-"
    ];
}

function loadEmployeePerformanceReport(PDO $pdo, int $employeeId, int $requestedPeriodId): array
{
    $employeeStmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.employee_code,
            e.first_name,
            e.last_name,
            p.position_name,
            d.department_name
        FROM employees e
        LEFT JOIN positions p ON p.position_id = e.position_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        WHERE e.employee_id = :employee_id
        LIMIT 1
    ");
    $employeeStmt->execute([":employee_id" => $employeeId]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        http_response_code(404);
        exit("Employee not found");
    }

    $periodsStmt = $pdo->prepare("
        SELECT DISTINCT
            ep.period_id,
            ep.period_name,
            ep.start_date,
            ep.end_date,
            ep.status
        FROM evaluation_periods ep
        INNER JOIN kpi_assignments a ON a.period_id = ep.period_id
        WHERE a.employee_id = :employee_id
          AND a.status = 'Active'
        ORDER BY ep.start_date DESC, ep.period_id DESC
    ");
    $periodsStmt->execute([":employee_id" => $employeeId]);
    $periods = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);

    $selectedPeriodId = $requestedPeriodId;
    $period = null;

    foreach ($periods as $availablePeriod) {
        if ((int) $availablePeriod["period_id"] === $selectedPeriodId) {
            $period = $availablePeriod;
            break;
        }
    }

    if ($requestedPeriodId > 0 && !$period) {
        http_response_code(403);
        exit("This evaluation period is not assigned to the employee");
    }

    if (!$period && !empty($periods)) {
        $period = $periods[0];
        $selectedPeriodId = (int) $period["period_id"];
    }

    if (!$period) {
        return [
            "employee" => $employee,
            "period" => null,
            "periods" => [],
            "kpis" => [],
            "summary" => [
                "performance_score" => 0,
                "competency_score" => 0,
                "total_score" => 0,
                "percentage" => null
            ]
        ];
    }

    $kpiStmt = $pdo->prepare("
        SELECT
            a.assignment_id,
            a.target_value AS assignment_target,
            a.weight AS assignment_weight,
            k.kpi_type,
            c.category_name,
            k.kpi_name,
            k.unit,
            k.max_score,
            p.target,
            p.actual,
            p.score,
            p.status AS performance_status
        FROM kpi_assignments a
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        LEFT JOIN kpi_categories c ON c.category_id = k.category_id
        LEFT JOIN kpi_performances p ON p.performance_id = (
            SELECT latest.performance_id
            FROM kpi_performances latest
            WHERE latest.assignment_id = a.assignment_id
              AND latest.employee_id = a.employee_id
            ORDER BY latest.performance_date DESC, latest.performance_id DESC
            LIMIT 1
        )
        WHERE a.employee_id = :employee_id
          AND a.period_id = :period_id
          AND a.status = 'Active'
        ORDER BY k.kpi_type, a.assignment_id
    ");
    $kpiStmt->execute([
        ":employee_id" => $employeeId,
        ":period_id" => $selectedPeriodId
    ]);
    $kpis = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

    $performanceScore = 0;
    $competencyScore = 0;

    foreach ($kpis as &$kpi) {
        $kpi["target_value"] = $kpi["target"] !== null
            ? $kpi["target"]
            : $kpi["assignment_target"];
        $kpi["actual_value"] = $kpi["actual"];
        $kpi["score_value"] = $kpi["score"];
        $kpi["weight_value"] = (float) ($kpi["assignment_weight"] ?? 0);
        $kpi["status_value"] = $kpi["performance_status"] ?? "ยังไม่มีผลงาน";

        if ($kpi["score"] !== null) {
            if ($kpi["kpi_type"] === "Performance") {
                $performanceScore += (float) $kpi["score"];
            } elseif ($kpi["kpi_type"] === "Competency") {
                $competencyScore += (float) $kpi["score"];
            }
        }
    }
    unset($kpi);

    $summaryStmt = $pdo->prepare("
        SELECT percentage
        FROM performance_summary
        WHERE employee_id = :employee_id
          AND period_id = :period_id
        LIMIT 1
    ");
    $summaryStmt->execute([
        ":employee_id" => $employeeId,
        ":period_id" => $selectedPeriodId
    ]);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    return [
        "employee" => $employee,
        "period" => $period,
        "periods" => $periods,
        "kpis" => $kpis,
        "summary" => [
            "performance_score" => $performanceScore,
            "competency_score" => $competencyScore,
            "total_score" => $performanceScore + $competencyScore,
            "percentage" => $summaryRow["percentage"] ?? null
        ]
    ];
}
