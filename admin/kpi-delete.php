<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
    exit;
}


$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {
    header("Location: kpi-management.php");
    exit;
}


try {

    /*
    |--------------------------------------------------------------------------
    | Delete KPI
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM kpi_indicators
        WHERE kpi_id = :id
    ");

    $stmt->execute([
        ":id" => $id
    ]);
} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | If KPI is being used
    |--------------------------------------------------------------------------
    */

    $_SESSION["kpi_error"] =
        "ไม่สามารถลบ KPI นี้ได้ เนื่องจาก KPI ถูกใช้งานอยู่ในระบบ";
}


header("Location: kpi-management.php");
exit;
