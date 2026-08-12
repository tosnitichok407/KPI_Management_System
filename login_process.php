<?php
session_start();

require_once 'config/database.php';

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    die("กรุณากรอก Username และ Password");
}

$sql = "SELECT user_id, username, password_hash, employee_id, role_id, status
        FROM users
        WHERE username = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();

    if ($user['status'] !== 'Active') {
        die("บัญชีถูกปิดใช้งาน");
    }

    if (password_verify($password, $user['password_hash'])) {

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['employee_id'] = $user['employee_id'];
        $_SESSION['role_id'] = $user['role_id'];

        header("Location: dashboard.php");
        exit();

    } else {
        die("Username หรือ Password ไม่ถูกต้อง");
    }

} else {
    die("Username หรือ Password ไม่ถูกต้อง");
}

$stmt->close();
$conn->close();
?>