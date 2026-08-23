<?php

session_start();

require_once "config/database.php";

$error = "";


/* ---Redirect if already logged in ---*/

if (isset($_SESSION["user_id"])) {

    if ((int) ($_SESSION["role_id"] ?? 0) === 1) {
        header("Location: admin/index.php");
        exit;
    }

    header("Location: dashboard.php");
    exit;
}


/* --- Login ---*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";


    /* --- Validate Input ---*/

    if ($username === "" || $password === "") {

        $error = "Please enter Employee ID and Password.";
    } else {


        /* --- Find User ---*/
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


        /* --- Username Not Found --- */
        if (!$user) {

            $error = "Invalid Employee ID or Password.";


            /* --- Failed Login Log --- */
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

        /* --- Check User Status ---*/ elseif (strtolower($user["user_status"]) !== "active") {

            $error = "Your account has been deactivated.";

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

        /* --- Check Employee Status --- */ elseif (strtolower($user["employee_status"]) !== "active") {

            $error = "Your employee account is inactive.";


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

        /* --- Check Password --- */ elseif (!password_verify($password, $user["password_hash"])) {

            $error = "Invalid Employee ID or Password.";


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

        /* --- Login Success --- */ else {
            /* --- Regenerate Session ID --- */
            session_regenerate_id(true);

            /* --- Store User Information --- */

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


            /* --- Login Log - Success --- */

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

            /* --- Role-based Redirect --- */

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

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        KPI Management System
    </title>

    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Login CSS -->
    <link
        rel="stylesheet"
        href="assets/css/login.css">
</head>

<body>

    <div class="page-container">
        <div class="login-wrapper">

            <!-- === LEFT SIDE === -->
            <section class="login-left">
                <img
                    src="assets/images/analytics_login.png"
                    alt="KPI Performance Analytics"
                    class="analytics_login">
            </section>

            <!-- === RIGHT SIDE === -->
            <section class="login-right">

                <!-- Top Link -->
                <div class="top-link">
                    <span>
                        Advance Asia Group
                    </span>
                </div>

                <!-- Form -->
                <div class="form-container">

                    <!-- Header -->
                    <div class="form-header">
                        <div class="brand">
                            <div class="brand-icon">
                                <img
                                    src="assets/images/Advance-Logo.png"
                                    alt="Advance Asia Group Logo">
                            </div>
                        </div>

                        <h1>
                            Welcome Back
                        </h1>

                        <p>
                            Sign in to your KPI Management System
                        </p>

                    </div>

                    <!-- Error -->
                    <?php if ($error !== ""): ?>

                        <div
                            class="error-message"
                            role="alert">

                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>

                    <?php endif; ?>

                    <!-- Login Form -->
                    <form
                        method="POST"
                        action="login.php">

                        <!-- Employee ID -->
                        <div class="form-group">

                            <label for="username">
                                Employee ID
                            </label>

                            <div class="input-wrapper">

                                <span class="input-icon">
                                    ID
                                </span>

                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    placeholder="Enter Employee ID"
                                    autocomplete="username"
                                    value="<?= htmlspecialchars(
                                                $_POST["username"] ?? "",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>"
                                    required>
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="form-group">

                            <label for="password">
                                Password
                            </label>

                            <div class="input-wrapper">

                                <span class="input-icon">
                                    •••
                                </span>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Enter Password"
                                    autocomplete="current-password"
                                    required>

                                <button
                                    type="button"
                                    class="show-password"
                                    id="togglePassword"
                                    aria-label="Show password">
                                    👁
                                </button>
                            </div>
                        </div>

                        <!-- Options -->
                        <div class="form-options">

                            <label class="remember">

                                <input
                                    type="checkbox"
                                    name="remember-me"
                                    id="remember-me">

                                <span>
                                    Remember Me
                                </span>
                            </label>
                        </div>

                        <!-- Login -->
                        <button
                            type="submit"
                            class="login-button">

                            Login

                        </button>

                    </form>

                    <!-- Footer -->
                    <div class="login-footer">

                        KPI Management System

                        <span>
                            Version 1.0
                        </span>

                    </div>
                </div>
            </section>
        </div>
    </div>

    <!===SHOW / HIDE PASSWORD===>

        <script>
            const passwordInput =
                document.getElementById("password");

            const togglePassword =
                document.getElementById("togglePassword");

            togglePassword.addEventListener(
                "click",
                function() {

                    if (
                        passwordInput.type === "password"
                    ) {

                        passwordInput.type = "text";

                        togglePassword.textContent = "🙈";

                        togglePassword.setAttribute(
                            "aria-label",
                            "Hide password"
                        );

                    } else {

                        passwordInput.type = "password";

                        togglePassword.textContent = "👁";

                        togglePassword.setAttribute(
                            "aria-label",
                            "Show password"
                        );
                    }

                }
            );
        </script>
</body>

</html>