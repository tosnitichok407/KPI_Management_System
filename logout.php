<?php

require_once __DIR__ . "/includes/security.php";

/*
| ออกจากระบบได้เฉพาะลิงก์ที่มี CSRF token ถูกต้อง (สร้างด้วย logoutUrl())
| กันเว็บอื่นฝังลิงก์ logout.php เพื่อบังคับให้ผู้ใช้หลุดจากระบบ
*/

if (isset($_SESSION["user_id"]) && !csrfVerify((string) ($_GET["token"] ?? ""))) {
    redirectToRoleHome("");
}

/* Clear All Session Data */

$_SESSION = [];

/* Delete Session Cookie */

if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* Destroy Session */

session_destroy();

/* Redirect to Login */

header("Location: login.php");
exit;
