<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


/* =========================
   CHECK LOGIN
========================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}


/* =========================
   CHECK ADMIN
========================= */

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
}


/* =========================
   GET ASSIGNMENT ID
========================= */

csrfRequirePost("../index.php?page=kpi-assignment");

$assignment_id = intval($_POST["id"] ?? 0);

if ($assignment_id <= 0) {

    header("Location: ../index.php?page=kpi-assignment");
    exit;

}


/* =========================
   CHECK ASSIGNMENT EXISTS
========================= */

$check_sql = "
    SELECT assignment_id
    FROM kpi_assignments
    WHERE assignment_id = ?
    LIMIT 1
";

$check_stmt = $pdo->prepare($check_sql);

$check_stmt->execute([
    $assignment_id
]);

$assignment = $check_stmt->fetch(PDO::FETCH_ASSOC);


/* =========================
   NOT FOUND
========================= */

if (!$assignment) {

    header(
        "Location: ../index.php?page=kpi-assignment&error=not_found"
    );

    exit;

}


/* =========================
   DELETE
========================= */

try {

    /*
    | ห้ามลบ Assignment ที่มีผลงาน (ผลงานจะถูกลบตาม FK CASCADE)
    | ล็อกแถว Assignment ก่อน: หน้าบันทึกผลงานล็อกแถวเดียวกัน และ INSERT ผลงาน
    | ต้องตรวจ FK ของแถวนี้ จึงไม่มีผลงานใหม่เกิดขึ้นระหว่างตรวจกับลบ
    */
    $pdo->beginTransaction();

    $lock_stmt = $pdo->prepare("
        SELECT assignment_id
        FROM kpi_assignments
        WHERE assignment_id = ?
        FOR UPDATE
    ");
    $lock_stmt->execute([$assignment_id]);

    if (!$lock_stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        header("Location: ../index.php?page=kpi-assignment&error=not_found");
        exit;
    }

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM kpi_performances
        WHERE assignment_id = ?
        LOCK IN SHARE MODE
    ");
    $count_stmt->execute([$assignment_id]);

    if ((int) $count_stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header("Location: ../index.php?page=kpi-assignment&error=has_performances");
        exit;
    }

    $delete_sql = "
        DELETE FROM kpi_assignments
        WHERE assignment_id = ?
    ";

    $delete_stmt = $pdo->prepare($delete_sql);

    $delete_stmt->execute([
        $assignment_id
    ]);

    $pdo->commit();


    /* =========================
       SUCCESS
    ========================= */

    header(
        "Location: ../index.php?page=kpi-assignment&success=deleted"
    );

    exit;

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /* =========================
       ERROR
    ========================= */

    error_log("Delete KPI assignment failed: " . $e->getMessage());

    /* 1205 = lock wait timeout · 1213 = deadlock (มีการบันทึกผลงานของ Assignment นี้พร้อมกัน) */
    $error_code = in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true) ? "busy" : "delete_failed";

    header(
        "Location: ../index.php?page=kpi-assignment&error=" . $error_code
    );

    exit;
}
