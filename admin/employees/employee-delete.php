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

/* ห้าม Admin ลบข้อมูลพนักงานของตัวเอง (บัญชีผู้ใช้จะถูกลบตาม → เข้าระบบไม่ได้) */
if ($employee_id === (int) ($_SESSION["employee_id"] ?? 0)) {

    header("Location: ../index.php?page=employees&error=self_delete");
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

    /*
    | ลำดับการล็อกเหมือนหน้าบันทึกผลงาน (Assignment ก่อน แล้วจึงพนักงาน) เพื่อไม่ให้เกิด deadlock:
    | หน้าบันทึกผลงานล็อก Assignment ด้วย FOR UPDATE แล้วจึงตรวจ FK ของพนักงานตอน INSERT
    */
    $stmt = $pdo->prepare("
        SELECT assignment_id
        FROM kpi_assignments
        WHERE employee_id = :employee_id
        FOR UPDATE
    ");
    $stmt->execute([":employee_id" => $employee_id]);
    $stmt->fetchAll();

    /* ล็อกแถวพนักงาน: การบันทึกผลงานใหม่ต้องตรวจ FK ของพนักงานคนนี้ จึงต้องรอจนเสร็จ */
    $stmt = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = :employee_id
        LIMIT 1
        FOR UPDATE
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
    | ห้ามลบพนักงานที่มีประวัติ KPI หรือ Feedback
    |--------------------------------------------------------------------------
    |
    | ผลงาน (kpi_performances) จะถูกลบตาม FK CASCADE และ Feedback (ไม่มี FK) จะค้าง
    | จึงปฏิเสธการลบ และให้ใช้การปิดใช้งานแทน
    | (ล็อก Assignment และพนักงานไว้แล้วด้านบน) และนับ Feedback ด้วย locking read
    | เพื่อกันข้อมูลใหม่ระหว่างตรวจกับลบ
    |
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM kpi_performances
        WHERE employee_id = :employee_id
        LOCK IN SHARE MODE
    ");
    $stmt->execute([":employee_id" => $employee_id]);
    $performance_count = (int) $stmt->fetchColumn();

    /* Feedback ทั้งในฐานะผู้รับ (employee_id) และผู้ให้ (manager_id = user_id ของพนักงานคนนี้) */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM manager_feedback f
        WHERE f.employee_id = :employee_id
           OR f.manager_id IN (
                SELECT u.user_id
                FROM users u
                WHERE u.employee_id = :employee_id_giver
           )
        LOCK IN SHARE MODE
    ");
    $stmt->execute([
        ":employee_id" => $employee_id,
        ":employee_id_giver" => $employee_id
    ]);
    $feedback_count = (int) $stmt->fetchColumn();

    if ($performance_count > 0 || $feedback_count > 0) {

        $pdo->rollBack();

        header("Location: ../index.php?page=employees&error=has_history");
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

    /* 1205 = lock wait timeout · 1213 = deadlock (มีการบันทึกข้อมูลของพนักงานคนนี้พร้อมกัน) */
    $error_code = in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true) ? "busy" : "delete";

    header("Location: ../index.php?page=employees&error=" . $error_code);
    exit;
}