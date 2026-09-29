<?php

/*
|--------------------------------------------------------------------------
| Security Helpers (Session / CSRF / Role Redirect)
|--------------------------------------------------------------------------
|
| require_once ไฟล์นี้แทน session_start() ในทุกหน้า
|
| - เริ่ม session พร้อม cookie HttpOnly / SameSite=Lax / Secure (เมื่อเป็น HTTPS)
|   และ session.use_strict_mode เพื่อกัน session fixation
| - ส่ง security headers พื้นฐาน (กัน clickjacking / MIME sniffing)
| - CSRF token สำหรับทุกฟอร์ม POST:  csrfField() ในฟอร์ม, csrfVerify() ตอนรับค่า
| - redirectToRoleHome() ส่งผู้ใช้กลับหน้าแรกของบทบาทตัวเอง (แทน dashboard.php ที่ไม่มีอยู่)
|
*/

if (session_status() === PHP_SESSION_NONE) {

    $isHttps =
        (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off")
        || (int) ($_SERVER["SERVER_PORT"] ?? 80) === 443;

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => ini_get("session.cookie_path") ?: "/",
        "domain" => "",
        "secure" => $isHttps,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| Idle Timeout
|--------------------------------------------------------------------------
|
| ไม่มีการใช้งานเกิน SESSION_IDLE_TIMEOUT วินาที → ล้าง session (ต้อง login ใหม่)
| หน้าแต่ละหน้าตรวจ $_SESSION["user_id"] อยู่แล้ว จึง redirect ไปหน้า login เอง
|
*/

const SESSION_IDLE_TIMEOUT = 1800; // 30 นาที

if (isset($_SESSION["user_id"])) {

    $lastActivity = (int) ($_SESSION["last_activity"] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT) {

        securityClearSession();
    } else {

        $_SESSION["last_activity"] = time();
    }
}


/* ล้างข้อมูลผู้ใช้ใน session และเปลี่ยนเลข session (ใช้ตอนหมดเวลา / บัญชีถูกปิด) */
function securityClearSession(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}


/*
|--------------------------------------------------------------------------
| ตรวจสถานะบัญชีซ้ำทุก request
|--------------------------------------------------------------------------
|
| config/database.php เรียกฟังก์ชันนี้หลังเชื่อมต่อสำเร็จ (ทุกหน้า require security.php
| ก่อน database.php) · ถ้า Admin ปิดบัญชีหรือปิดพนักงานระหว่างที่ผู้ใช้ login ค้างอยู่
| session จะถูกล้างใน request ถัดไป · role_id ใน session ถูกอัปเดตตามฐานข้อมูลเสมอ
|
*/

function securityRecheckAccount(PDO $pdo): void
{
    if (!isset($_SESSION["user_id"])) {
        return;
    }

    try {

        $stmt = $pdo->prepare("
            SELECT
                u.role_id,
                u.status AS user_status,
                e.status AS employee_status
            FROM users u
            INNER JOIN employees e ON e.employee_id = u.employee_id
            WHERE u.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([":user_id" => (int) $_SESSION["user_id"]]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$account
            || strtolower((string) $account["user_status"]) !== "active"
            || strtolower((string) $account["employee_status"]) !== "active"
        ) {
            securityClearSession();
            return;
        }

        $_SESSION["role_id"] = $account["role_id"];

    } catch (PDOException $e) {

        // ตรวจไม่ได้ (ฐานข้อมูลมีปัญหาชั่วคราว) ไม่ตัดผู้ใช้ออก แต่บันทึกไว้
        error_log("Session account recheck failed: " . $e->getMessage());
    }
}

if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: same-origin");
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function csrfToken(): string
{
    if (empty($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

/* <input type="hidden"> สำหรับใส่ในฟอร์ม POST */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8")
        . '">';
}

/* ตรวจ token ที่ส่งมากับ POST (คืน false ถ้าไม่มี / ไม่ตรง) */
function csrfVerify(?string $token = null): bool
{
    $token = $token ?? ($_POST["csrf_token"] ?? null);

    if (!is_string($token) || $token === "" || empty($_SESSION["csrf_token"])) {
        return false;
    }

    return hash_equals($_SESSION["csrf_token"], $token);
}

/* ต้องเป็น POST + token ถูกต้อง ไม่เช่นนั้น redirect กลับ (ใช้กับไฟล์ action เช่น ลบ / เปิด-ปิด) */
function csrfRequirePost(string $redirectTo): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST" || !csrfVerify()) {
        header("Location: " . $redirectTo);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Role Redirect
|--------------------------------------------------------------------------
*/

/* path หน้าแรกของบทบาทที่ login อยู่ ($root = path กลับไป root ของโปรเจกต์ เช่น "../") */
function roleHomeUrl(string $root = ""): string
{
    switch ((int) ($_SESSION["role_id"] ?? 0)) {
        case 1:
            return $root . "admin/index.php";
        case 2:
            return $root . "manager/index.php";
        case 3:
            return $root . "employee/index.php";
        default:
            return $root . "login.php";
    }
}

/* ลิงก์ออกจากระบบพร้อม CSRF token (logout.php จะปฏิเสธถ้า token ไม่ตรง) */
function logoutUrl(string $root = ""): string
{
    return $root . "logout.php?token=" . urlencode(csrfToken());
}

function redirectToRoleHome(string $root = ""): void
{
    header("Location: " . roleHomeUrl($root));
    exit;
}
