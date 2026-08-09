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
                    u.userid,
                    u.employee_id,
                    u.password_hash,
                    u.status,
                    u.role_id
                FROM users u
                WHERE u.employee_id = :employee_id
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>KPI Management System - Login</title>

    <link rel="stylesheet" href="assets/css/login.css">
</head>

<body>

    <main class="login-container">

        <section class="login-card">

            <div class="login-header">

                <div class="logo">
                    KPI
                </div>

                <h1>KPI Management System</h1>

                <p>Employee Performance Management</p>

            </div>

            <?php if (!empty($error)): ?>

                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="">

                <div class="form-group">

                    <label for="employee_id">
                        Employee ID
                    </label>

                    <input
                        type="text"
                        id="employee_id"
                        name="employee_id"
                        placeholder="Enter your Employee ID"
                        required>

                </div>

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required>

                </div>

                <div class="form-options">

                    <label>
                        <input
                            type="checkbox"
                            name="remember">

                        Remember me
                    </label>

                    <a href="#">
                        Forgot Password?
                    </a>

                </div>

                <button type="submit">
                    Login
                </button>

            </form>

            <footer>
                <p>© 2026 KPI Management System</p>
            </footer>

        </section>

    </main>

</body>

</html>