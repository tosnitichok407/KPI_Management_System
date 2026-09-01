<?php

session_start();

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
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


$error = "";


/*
|--------------------------------------------------------------------------
| Get Employee ID
|--------------------------------------------------------------------------
*/

$selected_employee_id = (int) ($_GET["employee_id"] ?? $_POST["employee_id"] ?? 0);


/*
|--------------------------------------------------------------------------
| Get Employees Without Account
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.email,
        e.status

    FROM employees e

    LEFT JOIN users u
        ON e.employee_id = u.employee_id

    WHERE u.user_id IS NULL
      AND e.status = 'Active'

    ORDER BY e.employee_code
");

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Roles
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        role_id,
        role_name,
        description

    FROM roles

    ORDER BY role_id
");

$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Create Account
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_id = (int) ($_POST["employee_id"] ?? 0);

    $username = trim($_POST["username"] ?? "");

    $password = $_POST["password"] ?? "";

    $confirm_password = $_POST["confirm_password"] ?? "";

    $email = trim($_POST["email"] ?? "");

    $role_id = (int) ($_POST["role_id"] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | Validate Employee
    |--------------------------------------------------------------------------
    */

    if ($employee_id <= 0) {

        $error = "Please select an employee.";

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Username
    |--------------------------------------------------------------------------
    */

    elseif ($username === "") {

        $error = "Please enter username.";

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Password
    |--------------------------------------------------------------------------
    */

    elseif ($password === "") {

        $error = "Please enter password.";

    }

    elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters.";

    }

    elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    }

    /*
    |--------------------------------------------------------------------------
    | Validate Role
    |--------------------------------------------------------------------------
    */

    elseif ($role_id <= 0) {

        $error = "Please select a role.";

    }

    else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check Employee
            |--------------------------------------------------------------------------
            */

            $checkEmployee = $pdo->prepare("
                SELECT
                    employee_id,
                    employee_code,
                    first_name,
                    last_name,
                    email,
                    status

                FROM employees

                WHERE employee_id = :employee_id

                LIMIT 1
            ");

            $checkEmployee->execute([
                ":employee_id" => $employee_id
            ]);

            $employee = $checkEmployee->fetch(PDO::FETCH_ASSOC);


            if (!$employee) {

                $error = "Employee not found.";

            }

            elseif ($employee["status"] !== "Active") {

                $error = "This employee is inactive.";

            }

            else {


                /*
                |--------------------------------------------------------------------------
                | Check Existing Account
                |--------------------------------------------------------------------------
                */

                $checkAccount = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE employee_id = :employee_id
                    LIMIT 1
                ");

                $checkAccount->execute([
                    ":employee_id" => $employee_id
                ]);

                if ($checkAccount->fetch()) {

                    $error = "This employee already has an account.";

                }

                else {


                    /*
                    |--------------------------------------------------------------------------
                    | Check Username
                    |--------------------------------------------------------------------------
                    */

                    $checkUsername = $pdo->prepare("
                        SELECT user_id
                        FROM users
                        WHERE username = :username
                        LIMIT 1
                    ");

                    $checkUsername->execute([
                        ":username" => $username
                    ]);

                    if ($checkUsername->fetch()) {

                        $error = "Username already exists.";

                    }

                    else {


                        /*
                        |--------------------------------------------------------------------------
                        | Check Role
                        |--------------------------------------------------------------------------
                        */

                        $checkRole = $pdo->prepare("
                            SELECT role_id
                            FROM roles
                            WHERE role_id = :role_id
                            LIMIT 1
                        ");

                        $checkRole->execute([
                            ":role_id" => $role_id
                        ]);

                        if (!$checkRole->fetch()) {

                            $error = "Invalid role.";

                        }

                        else {


                            /*
                            |--------------------------------------------------------------------------
                            | Password Hash
                            |--------------------------------------------------------------------------
                            */

                            $password_hash = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );


                            /*
                            |--------------------------------------------------------------------------
                            | Email
                            |--------------------------------------------------------------------------
                            */

                            if ($email === "") {

                                $email = $employee["email"];

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Insert User
                            |--------------------------------------------------------------------------
                            */

                            $insert = $pdo->prepare("
                                INSERT INTO users
                                (
                                    username,
                                    password_hash,
                                    email,
                                    employee_id,
                                    role_id,
                                    status
                                )

                                VALUES
                                (
                                    :username,
                                    :password_hash,
                                    :email,
                                    :employee_id,
                                    :role_id,
                                    'Active'
                                )
                            ");


                            $insert->execute([

                                ":username" => $username,

                                ":password_hash" => $password_hash,

                                ":email" =>
                                    $email !== ""
                                        ? $email
                                        : null,

                                ":employee_id" =>
                                    $employee_id,

                                ":role_id" =>
                                    $role_id

                            ]);


                            /*
                            |--------------------------------------------------------------------------
                            | Success
                            |--------------------------------------------------------------------------
                            */

                            header(
                                "Location: /user-accounts/user-accounts.php"
                            );

                            exit;

                        }
                    }
                }
            }

        } catch (PDOException $e) {

            $error = "Unable to create account.";

        }
    }
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

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/user-account.css"
    >

    <title>Create User Account</title>

</head>


<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                Create User Account
            </h1>

            <p>
                Create a login account for an employee
            </p>

        </div>

    </header>


    <!-- =========================================================
         ERROR
    ========================================================== -->

    <?php if ($error !== ""): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         FORM
    ========================================================== -->

    <section class="filter-card">

        <form
            method="POST"
            action="/user-accounts/user-account-add.php"
        >


            <!-- =================================================
                 EMPLOYEE
            ================================================== -->

            <div class="form-group">

                <label for="employee_id">
                    พนักงาน *
                </label>

                <select
                    id="employee_id"
                    name="employee_id"
                    required
                >

                    <option value="">
                        เลือกพนักงาน
                    </option>


                    <?php foreach ($employees as $employee): ?>
                        <option
                            value="<?= (int) $employee["employee_id"] ?>"
                            <?= $selected_employee_id
                                === (int) $employee["employee_id"]
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                $employee["employee_code"]
                                . " - "
                                . $employee["first_name"]
                                . " "
                                . $employee["last_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- =================================================
                 USERNAME
            ================================================== -->

            <div class="form-group">

                <label for="username">
                    ชื่อผู้ใช้ *
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars(
                        $_POST["username"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    placeholder="กรอกชื่อผู้ใช้"
                    maxlength="50"
                    required
                >

            </div>

            <!-- =================================================
                 EMAIL
            ================================================== -->

            <div class="form-group">

                <label for="email">
                    อีเมล
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars(
                        $_POST["email"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    placeholder="กรอกอีเมล"
                    maxlength="150"
                >

            </div>

            <!-- =================================================
                 ROLE
            ================================================== -->

            <div class="form-group">

                <label for="role_id">
                    บทบาท *
                </label>

                <select
                    id="role_id"
                    name="role_id"
                    required
                >

                    <option value="">
                        เลือกบทบาท
                    </option>


                    <?php foreach ($roles as $role): ?>

                        <option
                            value="<?= (int) $role["role_id"] ?>"
                            <?= (int) ($_POST["role_id"] ?? 0)
                                === (int) $role["role_id"]
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                $role["role_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- =================================================
                 PASSWORD
            ================================================== -->

            <div class="form-group">

                <label for="password">
                    รหัสผ่าน *
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="กรอกรหัสผ่าน"
                    required
                >

                <small>
                    รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร
                </small>

            </div>


            <!-- =================================================
                 CONFIRM PASSWORD
            ================================================== -->

            <div class="form-group">

                <label for="confirm_password">
                    ยืนยันรหัสผ่าน *
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="ยืนยันรหัสผ่าน"
                    required
                >

            </div>

            <!-- =================================================
                 BUTTONS
            ================================================== -->

            <div
                style="
                    margin-top: 25px;
                    display: flex;
                    gap: 10px;
                "
            >
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    สร้างบัญชี
                </button>


                <a
                    href="../user-accounts/user-accounts.php"
                    class="btn btn-secondary"
                >
                    Cancel
                </a>

            </div>


        </form>

    </section>


</div>

</body>

</html>
