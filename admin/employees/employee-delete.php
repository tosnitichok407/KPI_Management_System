<?php

session_start();

require_once "../../config/database.php";


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
| Get Employee ID
|--------------------------------------------------------------------------
*/

$employee_id = (int) ($_GET["id"] ?? 0);

if ($employee_id <= 0) {

    header("Location: employees.php");
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

        header("Location: employees.php");
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

    header("Location: employees.php?deleted=1");
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

    // สำหรับตรวจสอบปัญหาในช่วงพัฒนา
    die(
        "ไม่สามารถลบพนักงานได้<br><br>" .
        htmlspecialchars($e->getMessage())
    );
}