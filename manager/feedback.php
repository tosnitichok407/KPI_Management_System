<?php

session_start();
require_once __DIR__ . "/../config/database.php";


/*
|--------------------------------------------------------------------------
| Authentication (Manager / Admin)
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"]) || !in_array((int) ($_SESSION["role_id"] ?? 0), [1, 2], true)) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Form Data
|--------------------------------------------------------------------------
*/

$employeeId = (int) ($_POST["employee_id"] ?? 0);
$periodId = (int) ($_POST["period_id"] ?? 0);
$score = (float) ($_POST["evaluation_score"] ?? -1);
$feedback = trim($_POST["feedback"] ?? "");


/* กลับไปหน้ารายพนักงานพร้อม filter เดิม */

$returnQuery = http_build_query(array_filter([
    "page" => "employees",
    "year" => (int) ($_POST["year"] ?? 0),
    "month" => (int) ($_POST["month"] ?? 0),
    "department_id" => (int) ($_POST["department_id"] ?? 0),
    "employee_id" => (int) ($_POST["return_employee_id"] ?? 0)
]));

$redirect = "index.php?" . $returnQuery;


/*
|--------------------------------------------------------------------------
| Validate
|--------------------------------------------------------------------------
*/

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST" || $employeeId <= 0 || $periodId <= 0 || $score < 0 || $score > 100 || $feedback === "") {
    header("Location: {$redirect}&saved=0");
    exit;
}


/*
|--------------------------------------------------------------------------
| Save (1 Feedback ต่อ หัวหน้า + พนักงาน + รอบประเมิน)
|--------------------------------------------------------------------------
*/

try {

    /* ต้องเป็นรอบประเมินที่มีอยู่จริง และพนักงานที่ยัง Active */
    $checkStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM evaluation_periods WHERE period_id = :period_id) AS period_ok,
            (SELECT COUNT(*) FROM employees WHERE employee_id = :employee_id AND status = 'Active') AS employee_ok
    ");
    $checkStmt->execute([":period_id" => $periodId, ":employee_id" => $employeeId]);
    $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!(int) $check["period_ok"] || !(int) $check["employee_ok"]) {
        header("Location: {$redirect}&saved=0");
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO manager_feedback (manager_id, employee_id, period_id, evaluation_score, feedback)
        VALUES (:manager_id, :employee_id, :period_id, :evaluation_score, :feedback)
        ON DUPLICATE KEY UPDATE
            evaluation_score = VALUES(evaluation_score),
            feedback = VALUES(feedback)
    ");

    $stmt->execute([
        ":manager_id" => (int) $_SESSION["user_id"],
        ":employee_id" => $employeeId,
        ":period_id" => $periodId,
        ":evaluation_score" => $score,
        ":feedback" => $feedback
    ]);

    header("Location: {$redirect}&saved=1");

} catch (PDOException $exception) {

    header("Location: {$redirect}&saved=0");
}

exit;
