<?php

$host = "localhost";
$dbname = "kpi_management_system";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE, 
        PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $e) {
    // ไม่แสดงรายละเอียดการเชื่อมต่อ (host / user / ข้อความ error) ให้ผู้ใช้ — บันทึกลง error log แทน
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed. Please contact the system administrator.");
}
