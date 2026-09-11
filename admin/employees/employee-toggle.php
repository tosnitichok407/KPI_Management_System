<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

/*
|--------------------------------------------------------------------------
| Admin Access
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


$id = (int) ($_GET["id"] ?? 0);
$action = $_GET["action"] ?? "";


if ($id <= 0) {
    header("Location: ../index.php?page=employees");
    exit;
}


if ($action === "activate") {

    $newStatus = "Active";

} elseif ($action === "deactivate") {

    $newStatus = "Inactive";

} else {

    header("Location: ../index.php?page=employees");
    exit;
}


try {

    $stmt = $pdo->prepare("
        UPDATE employees
        SET status = :status
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ":status" => $newStatus,
        ":employee_id" => $id
    ]);

} catch (PDOException $e) {

    // กลับหน้ารายการหากเกิดข้อผิดพลาด
}


header("Location: ../index.php?page=employees");
exit;
