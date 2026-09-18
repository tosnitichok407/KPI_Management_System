<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


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

    redirectToRoleHome("../../");

}


/*
|--------------------------------------------------------------------------
| Get Employee ID
|--------------------------------------------------------------------------
*/

csrfRequirePost("../index.php?page=employees");

$employee_id = (int) ($_POST["id"] ?? 0);

if ($employee_id <= 0) {

    header("Location: ../index.php?page=employees");
    exit;

}


try {

    /*
    |--------------------------------------------------------------------------
    | Start Transaction
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | Check Employee
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = :employee_id
        LIMIT 1
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$employee) {

        $pdo->rollBack();

        header("Location: ../index.php?page=employees");
        exit;

    }


    /*
    |--------------------------------------------------------------------------
    | Delete KPI Assignments
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM kpi_assignments
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete User Account
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM users
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete Employee
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM employees
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    header("Location: ../index.php?page=employees&deleted=1");
    exit;


} catch (PDOException $e) {


    /*
    |--------------------------------------------------------------------------
    | Rollback
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }


    /*
    |--------------------------------------------------------------------------
    | Debug Error
    |--------------------------------------------------------------------------
    */

    // ไม่แสดงข้อความ error ของฐานข้อมูลให้ผู้ใช้ (บันทึกลง error log แทน)
    error_log("Delete employee failed: " . $e->getMessage());

    header("Location: ../index.php?page=employees&error=delete");
    exit;
}