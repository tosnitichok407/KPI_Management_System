<?php
session_start();
require_once "../../config/database.php";

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
    header("Location: ../../dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Get User ID
|--------------------------------------------------------------------------
*/

$user_id = (int) ($_GET["id"] ?? 0);

if ($user_id <= 0) {
    header("Location: ../../user-accounts/user-accounts.php");
    exit;
}

try {
    /*
    |--------------------------------------------------------------------------
    | Get Current Status
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
        
            user_id,
            status
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$user) {

        $_SESSION["user_error"] =
            "ไม่พบ Account ที่ต้องการ";

        header("Location: /user-accounts/user-accounts.php");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle Status
    |--------------------------------------------------------------------------
    */

    if (strtolower($user["status"]) === "active") {

        $new_status = "Inactive";

    } else {

        $new_status = "Active";
    }

    /*
    |--------------------------------------------------------------------------
    | Update Status
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE users
        SET status = :status
        WHERE user_id = :user_id
    ");

    $stmt->execute([
        ":status" => $new_status,
        ":user_id" => $user_id
    ]);


    /*
    |--------------------------------------------------------------------------
    | Success Message
    |--------------------------------------------------------------------------
    */

    if ($new_status === "Active") {

        $_SESSION["user_success"] =
            "เปิดใช้งาน Account เรียบร้อยแล้ว";

    } else {

        $_SESSION["user_success"] =
            "ปิดใช้งาน Account เรียบร้อยแล้ว";
    }


} catch (PDOException $e) {

    $_SESSION["user_error"] =
        "ไม่สามารถเปลี่ยนสถานะ Account ได้";
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

header("Location: /user-accounts/user-accounts.php");
exit;
