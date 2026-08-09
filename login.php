<?php

session_start();
require_once "config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_id = trim($_POST["employee_id"]);
    $password = $_POST["password"];

    if (empty($employee_id) || empty($password)) {

        $error = "Please enter Employee ID and Password.";
    } else {

        $sql = "SELECT
                    id,
                    employee_id,
                    password_hash,
                    status,
                    role_id
                FROM users
                WHERE employee_id = :employee_id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":employee_id" => $employee_id
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password_hash"])) {

            if ($user["status"] !== "active") {

                $error = "Your account is inactive.";
            } else {

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["employee_id"] = $user["employee_id"];
                $_SESSION["role_id"] = $user["role_id"];

                header("Location: dashboard/index.php");
                exit;
            }
        } else {

            $error = "Invalid Employee ID or Password.";
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
        content="width=device-width, initial-scale=1.0">

    <title>Advance KPI System - Login</title>

    <link
        rel="stylesheet"
        href="assets/css/login.css">

</head>

<body>

    <div class="page-container">

        <div class="login-wrapper">

            <!-- LEFT SIDE -->
            <section class="login-left">

                <div class="illustration">

                    <div class="dashboard-card">

                        <div class="card-header">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>

                        <div class="chart-area">

                            <div class="chart-bar bar-1"></div>
                            <div class="chart-bar bar-2"></div>
                            <div class="chart-bar bar-3"></div>
                            <div class="chart-bar bar-4"></div>
                            <div class="chart-bar bar-5"></div>

                        </div>

                        <div class="card-footer">

                            <div></div>
                            <div></div>

                        </div>

                    </div>

                    <div class="target-icon">
                        ✓
                    </div>

                    <div class="floating-card">
                        <strong>92%</strong>
                        <small>KPI Performance</small>
                    </div>

                </div>

                <div class="left-text">

                    <h2>
                        Performance<br>
                        starts with <span>progress.</span>
                    </h2>

                    <p>
                        Manage employee performance,
                        track KPI results and improve
                        organizational efficiency.
                    </p>

                </div>

            </section>


            <!-- RIGHT SIDE -->
            <section class="login-right">

                <div class="top-link">

                    <span>
                        Don't have an account?
                    </span>

                    <a href="#">
                        CONTACT ADMIN
                    </a>

                </div>


                <div class="form-container">

                    <div class="form-header">

                        <div class="brand">

                            <div class="brand-icon">
                                <img src="assets/images/Advance-Logo.png"
                                    alt="Advance Group Asia Logo">
                            </div>

                        </div>

                        <h1>
                            Welcome!
                        </h1>

                        <p>
                            Sign in to your KPI Management System
                        </p>

                    </div>


                    <?php if (!empty($error)): ?>

                        <div class="error-message">
                            <?= htmlspecialchars($error) ?>
                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action="">

                        <!-- Employee ID -->

                        <div class="form-group">

                            <label for="employee_id">
                                Employee ID
                            </label>

                            <div class="input-wrapper">

                                <span class="input-icon">
                                    ID
                                </span>

                                <input
                                    type="text"
                                    id="employee_id"
                                    name="employee_id"
                                    placeholder="Enter your Employee ID"
                                    autocomplete="username"
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
                                    •
                                </span>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required>

                                <button
                                    type="button"
                                    class="show-password"
                                    onclick="togglePassword()">
                                    ◉
                                </button>

                            </div>

                        </div>


                        <!-- Options -->

                        <div class="form-options">

                            <label class="remember">

                                <input
                                    type="checkbox"
                                    name="remember">

                                <span>
                                    Remember me
                                </span>

                            </label>

                            <a href="#">
                                Forgot Password?
                            </a>

                        </div>


                        <!-- Login -->

                        <button
                            type="submit"
                            class="login-button">
                            LOGIN
                        </button>

                    </form>


                    <div class="login-footer">

                        <p>
                            © 2026 Advance Group Asia
                        </p>

                        <span>
                            KPI Management System
                        </span>

                    </div>

                </div>

            </section>

        </div>

    </div>


    <script>
        function togglePassword() {

            const password =
                document.getElementById("password");

            if (password.type === "password") {

                password.type = "text";

            } else {

                password.type = "password";

            }
        }
    </script>

</body>

</html>