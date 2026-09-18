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
|--------------------------------------------------------------------------
| Get User ID
|--------------------------------------------------------------------------
*/

$user_id = (int) ($_GET["id"] ?? 0);

if ($user_id <= 0) {
    header("Location: ../index.php?page=accounts");
    exit;
}


$error = "";
$success = "";


/*
|--------------------------------------------------------------------------
| Get User
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        SELECT
            user_id,
            username,
            email,
            employee_id,
            role_id,
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
        header("Location: ../index.php?page=accounts");
        exit;
    }


} catch (PDOException $e) {

    error_log("Load user account failed: " . $e->getMessage());
    http_response_code(500);
    die("Database Error");
}


/*
|--------------------------------------------------------------------------
| Get Employees
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        employee_id,
        employee_code,
        first_name,
        last_name
    FROM employees
    ORDER BY employee_code ASC
");

$stmt->execute();

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Roles
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        role_id,
        role_name
    FROM roles
    ORDER BY role_id ASC
");

$stmt->execute();

$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Update User
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $employee_id = (int) ($_POST["employee_id"] ?? 0);
    $role_id = (int) ($_POST["role_id"] ?? 0);
    $status = $_POST["status"] ?? "Inactive";
    $password = $_POST["password"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

        if (!csrfVerify()) {

        $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";

    } elseif (
        $username === "" ||
        $email === "" ||
        $employee_id <= 0 ||
        $role_id <= 0
    ) {

        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";

    } elseif (mb_strlen($username) > 50 || mb_strlen($email) > 150) {

        $error = "ข้อมูลยาวเกินกำหนด";

    } elseif ($password !== "" && strlen($password) < 6) {

        $error = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "รูปแบบ Email ไม่ถูกต้อง";

    } elseif (!in_array($status, ["Active", "Inactive"], true)) {

        $error = "สถานะไม่ถูกต้อง";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Username
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE username = :username
            AND user_id != :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ":username" => $username,
            ":user_id" => $user_id
        ]);

        if ($stmt->fetch()) {

            $error = "Username นี้ถูกใช้งานแล้ว";

        } else {


            /*
            |--------------------------------------------------------------------------
            | Check Duplicate Employee Account
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE employee_id = :employee_id
                AND user_id != :user_id
                LIMIT 1
            ");

            $stmt->execute([
                ":employee_id" => $employee_id,
                ":user_id" => $user_id
            ]);

            if ($stmt->fetch()) {

                $error = "พนักงานคนนี้มี Account อยู่แล้ว";

            } else {


                /*
                |--------------------------------------------------------------------------
                | Update
                |--------------------------------------------------------------------------
                */

                try {

                    if ($password !== "") {

                        /*
                        | Password ถูกเปลี่ยน
                        */

                        $password_hash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $sql = "
                            UPDATE users
                            SET
                                username = :username,
                                password_hash = :password_hash,
                                email = :email,
                                employee_id = :employee_id,
                                role_id = :role_id,
                                status = :status
                            WHERE user_id = :user_id
                        ";

                        $stmt = $pdo->prepare($sql);

                        $stmt->execute([
                            ":username" => $username,
                            ":password_hash" => $password_hash,
                            ":email" => $email,
                            ":employee_id" => $employee_id,
                            ":role_id" => $role_id,
                            ":status" => $status,
                            ":user_id" => $user_id
                        ]);

                    } else {

                        /*
                        | ไม่เปลี่ยน Password
                        */

                        $sql = "
                            UPDATE users
                            SET
                                username = :username,
                                email = :email,
                                employee_id = :employee_id,
                                role_id = :role_id,
                                status = :status
                            WHERE user_id = :user_id
                        ";

                        $stmt = $pdo->prepare($sql);

                        $stmt->execute([
                            ":username" => $username,
                            ":email" => $email,
                            ":employee_id" => $employee_id,
                            ":role_id" => $role_id,
                            ":status" => $status,
                            ":user_id" => $user_id
                        ]);
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Success
                    |--------------------------------------------------------------------------
                    */

                    $_SESSION["user_success"] =
                        "แก้ไข Account เรียบร้อยแล้ว";

                    header("Location: ../index.php?page=accounts");
                    exit;


                } catch (PDOException $e) {

                    $error =
                        "ไม่สามารถแก้ไข Account ได้";
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Keep Form Values
    |--------------------------------------------------------------------------
    */

    $user["username"] = $username;
    $user["email"] = $email;
    $user["employee_id"] = $employee_id;
    $user["role_id"] = $role_id;
    $user["status"] = $status;
}

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Edit User Account</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/user-account.css"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/admin-user-account-edit.css"
    >

</head>


<body>

<div class="container">


    <div class="header">

        <h1>
            Edit User Account
        </h1>

        <p>
            แก้ไขข้อมูล Account สำหรับเข้าสู่ระบบ
        </p>

    </div>

    <div class="card">

        <?php if ($error !== ""): ?>

            <div class="error">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

        <?php endif; ?>

                <form
            method="POST"
            action=""
        >

            <?= csrfField() ?>

            <!-- Username -->
            <div class="form-group">
                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars(
                        $user["username"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >
            </div>

            <!-- Email -->

            <div class="form-group">

                <label for="email">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars(
                        $user["email"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>

            <!-- Employee -->

            <div class="form-group">

                <label for="employee_id">
                    Employee
                </label>

                <select
                    id="employee_id"
                    name="employee_id"
                    required
                >

                    <option value="">
                        -- Select Employee --
                    </option>

                    <?php foreach ($employees as $employee): ?>

                        <option
                            value="<?= (int) $employee["employee_id"] ?>"
                            <?= (
                                (int) $user["employee_id"]
                                ===
                                (int) $employee["employee_id"]
                            )
                                ? "selected"
                                : ""
                            ?>
                        >

                            <?= htmlspecialchars(
                                $employee["employee_code"]
                            ) ?>

                            -
                            <?= htmlspecialchars(
                                $employee["first_name"]
                            ) ?>

                            <?= htmlspecialchars(
                                $employee["last_name"]
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- Role -->

            <div class="form-group">

                <label for="role_id">
                    Role
                </label>

                <select
                    id="role_id"
                    name="role_id"
                    required
                >

                    <option value="">
                        -- Select Role --
                    </option>

                    <?php foreach ($roles as $role): ?>

                        <option
                            value="<?= (int) $role["role_id"] ?>"
                            <?= (
                                (int) $user["role_id"]
                                ===
                                (int) $role["role_id"]
                            )
                                ? "selected"
                                : ""
                            ?>
                        >

                            <?= htmlspecialchars(
                                $role["role_name"]
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- Password -->

            <div class="form-group">

                <label for="password">
                    New Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    placeholder="เว้นว่างหากไม่ต้องการเปลี่ยน"
                >

                <div class="hint">
                    หากไม่กรอก ระบบจะใช้ Password เดิม
                </div>

            </div>

            <!-- Status -->

            <div class="form-group">

                <label for="status">
                    Status
                </label>

                <select
                    id="status"
                    name="status"
                    required
                >

                    <option
                        value="Active"
                        <?= $user["status"] === "Active"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Active
                    </option>

                    <option
                        value="Inactive"
                        <?= $user["status"] === "Inactive"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Inactive
                    </option>

                </select>

            </div>

            <!-- Buttons -->

            <div class="actions">

                <a
                    href="../index.php?page=accounts"
                    class="btn btn-secondary"
                >
                    ยกเลิก
                </a>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    บันทึกการแก้ไข
                </button>
            </div>
        </form>
    </div>
</div>
<script src="../../assets/js/admin.js"></script>

</body>
</html>
