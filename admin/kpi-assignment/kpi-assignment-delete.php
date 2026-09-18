<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


/* =========================
   CHECK LOGIN
========================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


/* =========================
   CHECK ADMIN
========================= */

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../");
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

    $delete_sql = "
        DELETE FROM kpi_assignments
        WHERE assignment_id = ?
    ";

    $delete_stmt = $pdo->prepare($delete_sql);

    $delete_stmt->execute([
        $assignment_id
    ]);


    /* =========================
       SUCCESS
    ========================= */

    header(
        "Location: ../index.php?page=kpi-assignment&success=deleted"
    );

    exit;


} catch (PDOException $e) {

    /* =========================
       ERROR
    ========================= */

    error_log("Delete KPI assignment failed: " . $e->getMessage());

    header(
        "Location: ../index.php?page=kpi-assignment&error=delete_failed"
    );

    exit;

}