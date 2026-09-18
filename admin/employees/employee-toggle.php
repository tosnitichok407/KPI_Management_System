<?php
require_once __DIR__ . "/../../includes/security.php";
require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */

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
    redirectToRoleHome("../../");
}


csrfRequirePost("../index.php?page=employees");

$id = (int) ($_POST["id"] ?? 0);
$action = $_POST["action"] ?? "";


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
    error_log("Toggle employee status failed: " . $e->getMessage());
}


header("Location: ../index.php?page=employees");
exit;
