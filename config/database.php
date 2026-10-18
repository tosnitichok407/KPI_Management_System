<?php

$host = "localhost";
$dbname = "kpi_management_system";
$username = "root";
$password = "";
$port = 3306;

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

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

/*
| ตรวจสถานะบัญชีของผู้ที่ login อยู่ (เฉพาะหน้าเว็บที่โหลด includes/security.php มาก่อน
| สคริปต์ command line ไม่มีฟังก์ชันนี้ จึงข้ามไป)
*/
if (function_exists("securityRecheckAccount")) {
    securityRecheckAccount($pdo);
}
