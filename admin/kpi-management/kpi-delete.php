<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


/*
|--------------------------------------------------------------------------
| Admin Check
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
| รับเฉพาะ POST + CSRF token (เดิมเป็นลิงก์ GET และอ่าน kpi_id ผิดชื่อพารามิเตอร์)
*/

csrfRequirePost("../index.php?page=kpi-management");

$id = (int) ($_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: ../index.php?page=kpi-management");
    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | If KPI is being used
    |--------------------------------------------------------------------------
    |
    | kpi_assignments / kpi_performances เป็น ON DELETE CASCADE
    | จึงตรวจก่อนเพื่อไม่ให้ผลงานของพนักงานถูกลบตามไปโดยไม่ตั้งใจ
    |
    */

    $usage = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM kpi_assignments WHERE kpi_id = :kpi_id_a)
            + (SELECT COUNT(*) FROM employee_kpi WHERE kpi_id = :kpi_id_b)
    ");

    $usage->execute([
        ":kpi_id_a" => $id,
        ":kpi_id_b" => $id
    ]);

    if ((int) $usage->fetchColumn() > 0) {

        $_SESSION["kpi_error"] =
            "ไม่สามารถลบ KPI นี้ได้ เนื่องจาก KPI ถูกใช้งานอยู่ในระบบ";

        header("Location: ../index.php?page=kpi-management");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Delete KPI
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM kpi_indicators
        WHERE kpi_id = :kpi_id
    ");

    $stmt->execute([
        ":kpi_id" => $id
    ]);

    $_SESSION["kpi_success"] = "ลบ KPI เรียบร้อยแล้ว";

} catch (PDOException $e) {

    error_log("Delete KPI failed: " . $e->getMessage());

    $_SESSION["kpi_error"] =
        "ไม่สามารถลบ KPI นี้ได้ เนื่องจาก KPI ถูกใช้งานอยู่ในระบบ";
}


header("Location: ../index.php?page=kpi-management");
exit;
