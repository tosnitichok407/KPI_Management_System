<?php

session_start();

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../../dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| POST Only
|--------------------------------------------------------------------------
*/

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    header("Location: ../index.php?page=evaluation");
    exit;
}

$id = (int) ($_POST["id"] ?? 0);


/*
|--------------------------------------------------------------------------
| Check Period Exists
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT period_id
    FROM evaluation_periods
    WHERE period_id = :period_id
    LIMIT 1
");

$stmt->execute([
    ":period_id" => $id
]);

if (!$stmt->fetchColumn()) {
    header("Location: ../index.php?page=evaluation&error=not_found");
    exit;
}


/*
|--------------------------------------------------------------------------
| Check Usage
|--------------------------------------------------------------------------
|
| ห้ามลบถ้ามีข้อมูลใช้รอบนี้อยู่ เพราะ kpi_assignments / employee_kpi /
| performance_summary จะถูกลบตาม (ON DELETE CASCADE)
|
*/

if (!empty(evaluationPeriodUsage($pdo, $id))) {
    header("Location: ../index.php?page=evaluation&error=in_use");
    exit;
}


/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

try {

    $deleteStmt = $pdo->prepare("
        DELETE FROM evaluation_periods
        WHERE period_id = :period_id
    ");

    $deleteStmt->execute([
        ":period_id" => $id
    ]);

    header("Location: ../index.php?page=evaluation&success=deleted");
    exit;

} catch (PDOException $e) {

    header("Location: ../index.php?page=evaluation&error=delete_failed");
    exit;
}
