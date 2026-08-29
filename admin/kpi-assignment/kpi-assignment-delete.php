<?php

session_start();

require_once "../../config/database.php";


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

if (!isset($_SESSION["role_id"]) || $_SESSION["role_id"] != 1) {
    header("Location: ../dashboard.php");
    exit;
}


/* =========================
   GET ASSIGNMENT ID
========================= */

$assignment_id = intval($_GET["id"] ?? 0);

if ($assignment_id <= 0) {

    header("Location: kpi-assignment.php");
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
        "Location: kpi-assignment.php?error=not_found"
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
        "Location: kpi-assignment.php?success=deleted"
    );

    exit;


} catch (PDOException $e) {

    /* =========================
       ERROR
    ========================= */

    header(
        "Location: kpi-assignment.php?error=delete_failed"
    );

    exit;

}