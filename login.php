<?php

session_start();

require_once "config/database.php";

$error = "";


/*
|--------------------------------------------------------------------------
| Redirect if already logged in
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["user_id"])) {

    if ((int) ($_SESSION["role_id"] ?? 0) === 1) {
        header("Location: admin/index.php");
        exit;
    }

    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | Validate Input
    |--------------------------------------------------------------------------
    */

    if ($username === "" || $password === "") {

        $error = "Please enter Username and Password.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        |
        | users
        |   employee_id -> employees.employee_id
        |   role_id     -> roles.role_id
        |
        | employees
        |   department_id -> departments.department_id
        |   position_id   -> positions.position_id
        |
        */

        $sql = "
            SELECT
                u.user_id,
                u.username,
                u.password_hash,
                u.email AS user_email,
                u.employee_id,
                u.role_id,
                u.status AS user_status,

                e.employee_code,
                e.first_name,
                e.last_name,
                e.phone,
                e.email AS employee_email,
                e.department_id,
                e.position_id,
                e.status AS employee_status,

                d.department_name,
                p.position_name,

                r.role_name

            FROM users u

            INNER JOIN employees e
                ON u.employee_id = e.employee_id

            LEFT JOIN departments d
                ON e.department_id = d.department_id

            LEFT JOIN positions p
                ON e.position_id = p.position_id

            INNER JOIN roles r
                ON u.role_id = r.role_id

            WHERE u.username = :username

            LIMIT 1
        ";


        try {

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ":username" => $username
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);


        } catch (PDOException $e) {

            $error = "Database error. Please try again.";

            $user = false;
        }


        /*
        |--------------------------------------------------------------------------
        | Username Not Found
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            $error = "Invalid Username or Password.";


            /*
            | Log Failed Login
            */

            try {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (
                        user_id,
                        username,
                        ip_address,
                        user_agent,
                        login_status,
                        failure_reason
                    )
                    VALUES
                    (
                        NULL,
                        :username,
                        :ip_address,
                        :user_agent,
                        'Failed',
                        :failure_reason
                    )
                ");

                $log->execute([
                    ":username" => $username,
                    ":ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
                    ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                    ":failure_reason" => "Username not found"
                ]);

            } catch (PDOException $e) {

                // ไม่ให้ Login พัง หาก Login Logs มีปัญหา
            }


        }


        /*
        |--------------------------------------------------------------------------
        | Check User Status
        |--------------------------------------------------------------------------
        */

        elseif (strtolower($user["user_status"]) !== "active") {

            $error = "Your account has been deactivated.";


            /*
            | Log Failed Login
            */

            try {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (
                        user_id,
                        username,
                        ip_address,
                        user_agent,
                        login_status,
                        failure_reason
                    )
                    VALUES
                    (
                        :user_id,
                        :username,
                        :ip_address,
                        :user_agent,
                        'Failed',
                        :failure_reason
                    )
                ");

                $log->execute([
                    ":user_id" => $user["user_id"],
                    ":username" => $username,
                    ":ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
                    ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                    ":failure_reason" => "User account inactive"
                ]);

            } catch (PDOException $e) {

                // ไม่ให้ Login พัง หาก Login Logs มีปัญหา
            }


        }


        /*
        |--------------------------------------------------------------------------
        | Check Employee Status
        |--------------------------------------------------------------------------
        */

        elseif (strtolower($user["employee_status"]) !== "active") {

            $error = "Your employee account is inactive.";


            /*
            | Log Failed Login
            */

            try {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (
                        user_id,
                        username,
                        ip_address,
                        user_agent,
                        login_status,
                        failure_reason
                    )
                    VALUES
                    (
                        :user_id,
                        :username,
                        :ip_address,
                        :user_agent,
                        'Failed',
                        :failure_reason
                    )
                ");

                $log->execute([
                    ":user_id" => $user["user_id"],
                    ":username" => $username,
                    ":ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
                    ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                    ":failure_reason" => "Employee account inactive"
                ]);

            } catch (PDOException $e) {

                // ไม่ให้ Login พัง หาก Login Logs มีปัญหา
            }


        }


        /*
        |--------------------------------------------------------------------------
        | Check Password
        |--------------------------------------------------------------------------
        */

        elseif (!password_verify($password, $user["password_hash"])) {

            $error = "Invalid Username or Password.";


            /*
            | Log Failed Login
            */

            try {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (
                        user_id,
                        username,
                        ip_address,
                        user_agent,
                        login_status,
                        failure_reason
                    )
                    VALUES
                    (
                        :user_id,
                        :username,
                        :ip_address,
                        :user_agent,
                        'Failed',
                        :failure_reason
                    )
                ");

                $log->execute([
                    ":user_id" => $user["user_id"],
                    ":username" => $username,
                    ":ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
                    ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
                    ":failure_reason" => "Invalid password"
                ]);

            } catch (PDOException $e) {

                // ไม่ให้ Login พัง หาก Login Logs มีปัญหา
            }


        }


        /*
        |--------------------------------------------------------------------------
        | Login Success
        |--------------------------------------------------------------------------
        */

        else {


            /*
            |--------------------------------------------------------------------------
            | Regenerate Session ID
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);


            /*
            |--------------------------------------------------------------------------
            | Store User Information
            |--------------------------------------------------------------------------
            */

            $_SESSION["user_id"] =
                $user["user_id"];

            $_SESSION["username"] =
                $user["username"];

            $_SESSION["employee_id"] =
                $user["employee_id"];

            $_SESSION["employee_code"] =
                $user["employee_code"];

            $_SESSION["first_name"] =
                $user["first_name"];

            $_SESSION["last_name"] =
                $user["last_name"];

            $_SESSION["email"] =
                $user["employee_email"] ?? $user["user_email"];

            $_SESSION["department_id"] =
                $user["department_id"];

            $_SESSION["department"] =
                $user["department_name"];

            $_SESSION["position_id"] =
                $user["position_id"];

            $_SESSION["position"] =
                $user["position_name"];

            $_SESSION["role_id"] =
                $user["role_id"];

            $_SESSION["role_name"] =
                $user["role_name"];


            /*
            |--------------------------------------------------------------------------
            | Login Log - Success
            |--------------------------------------------------------------------------
            */

            try {

                $log = $pdo->prepare("
                    INSERT INTO login_logs
                    (
                        user_id,
                        username,
                        ip_address,
                        user_agent,
                        login_status,
                        failure_reason
                    )
                    VALUES
                    (
                        :user_id,
                        :username,
                        :ip_address,
                        :user_agent,
                        'Success',
                        NULL
                    )
                ");

                $log->execute([
                    ":user_id" => $user["user_id"],
                    ":username" => $user["username"],
                    ":ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
                    ":user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null
                ]);

            } catch (PDOException $e) {

                // ไม่ให้ Login พัง หาก Login Logs มีปัญหา
            }


            /*
            |--------------------------------------------------------------------------
            | Role-based Redirect
            |--------------------------------------------------------------------------
            */

            if ((int) $user["role_id"] === 1) {

                header("Location: admin/index.php");
                exit;
            }


            header("Location: dashboard.php");
            exit;
        }
    }
}

?>

<!doctype html>

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
        href="assets/css/login.css"
    >

    <title>KPI Management System</title>

</head>


<body>

    <div class="login-container">

        <div class="login-card">


            <!-- =========================================================
                 LOGIN HEADER
            ========================================================== -->

            <header class="login-header">

                <img
                    class="login-logo"
                    src="assets/images/Advance-Logo.png"
                    alt="Advance Asia Group Logo"
                >

                <h1 class="login-title">
                    KPI Management System
                </h1>

                <p class="login-subtitle">
                    Advance Asia Group
                </p>

            </header>


            <!-- =========================================================
                 LOGIN FORM
            ========================================================== -->

            <form
                class="login-form"
                method="POST"
                action="login.php"
            >


                <!-- Error Message -->

                <?php if ($error !== ""): ?>

                    <p
                        class="login-error"
                        role="alert"
                    >
                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </p>

                <?php endif; ?>


                <!-- Username -->

                <div class="input-group">

                    <label for="employee-id">
                        Employee ID
                    </label>

                    <input
                        type="text"
                        id="employee-id"
                        name="username"
                        autocomplete="username"
                        placeholder="Enter Employee ID"
                        required
                    >

                </div>


                <!-- Password -->

                <div class="input-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Enter Password"
                        required
                    >

                </div>


                <!-- Remember Me + Login -->

                <div class="remember-login-row">

                    <label
                        class="remember-me"
                        for="remember-me"
                    >

                        <input
                            type="checkbox"
                            id="remember-me"
                            name="remember-me"
                        >

                        <span>
                            Remember Me
                        </span>

                    </label>


                    <button
                        type="submit"
                        class="login-button"
                    >
                        Login
                    </button>

                </div>


            </form>


            <!-- =========================================================
                 FOOTER
            ========================================================== -->

            <footer class="login-footer">

                Version 1.0

            </footer>


        </div>

    </div>

</body>

</html>