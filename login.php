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

    if (($_SESSION["must_change_password"] ?? 0) == 1) {

        header("Location: change-password.php");
        exit;
    }

    if (($_SESSION["role_id"] ?? 0) == 1) {
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

    if (empty($username) || empty($password)) {

        $error = "Please enter Username and Password.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
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
        u.must_change_password,

        e.employee_code,
        e.first_name,
        e.last_name,
        e.phone,
        e.email,
        e.department,
        e.position,
        e.status AS employee_status,

        r.role_name

    FROM users u

    INNER JOIN employees e
        ON u.employee_id = e.id

    INNER JOIN roles r
        ON u.role_id = r.id

    WHERE u.username = :username

    LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":username" => $username
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        /*
        |--------------------------------------------------------------------------
        | Check Username
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            $error = "Invalid Username or Password.";

        }

        /*
        |--------------------------------------------------------------------------
        | Check User Status
        |--------------------------------------------------------------------------
        */

        elseif (strtolower($user["user_status"]) !== "active") {

            $error = "Your account has been deactivated.";

        }

        /*
        |--------------------------------------------------------------------------
        | Check Employee Status
        |--------------------------------------------------------------------------
        */

        elseif (strtolower($user["employee_status"]) !== "active") {

            $error = "Your employee account is inactive.";

        }

        /*
        |--------------------------------------------------------------------------
        | Check Password
        |--------------------------------------------------------------------------
        */

        elseif (!password_verify($password, $user["password_hash"])) {

            $error = "Invalid Username or Password.";

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
                $user["email"];

            $_SESSION["department"] =
                $user["department"];

            $_SESSION["position"] =
                $user["position"];

            $_SESSION["role_id"] =
                $user["role_id"];

            $_SESSION["role_name"] =
                $user["role_name"];

            $_SESSION["must_change_password"] =
                $user["must_change_password"];


            /*
            |--------------------------------------------------------------------------
            | Update Last Login
            |--------------------------------------------------------------------------
            */

            $update = $pdo->prepare("
                UPDATE users

                SET last_login = NOW()

                WHERE user_id = :user_id
            ");

            $update->execute([
                ":user_id" => $user["user_id"]
            ]);


            /*
            |--------------------------------------------------------------------------
            | Redirect
            |--------------------------------------------------------------------------
            */

            if ($user["must_change_password"] == 1) {

                header(
                    "Location: change-password.php"
                );

                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | Role-based Dashboard
            |--------------------------------------------------------------------------
            */

            if ((int) $user["role_id"] === 1) {

                header(
                    "Location: admin/index.php"
                );

                exit;
            }

            else {

                header(
                    "Location: dashboard.php"
                );

                exit;
            }

        }

    }

}

?>

<!doctype html>
<html lang="th">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link
      href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <link
      rel="stylesheet"
      href="assets/css/login.css"
    />
    
    <title>KPI Management System</title>
  </head>
  <body>
    <div class="login-container">
      <div class="login-card">
        <header class="login-header">
          <img class="login-logo" src="assets/images/Advance-Logo.png" alt="Logo">
          <h1 class="login-title">
            KPI Management System
          </h1>
          <p class="login-subtitle">
            Advance Asia Group
          </p>

        </header>

        <form class="login-form" method="POST" action="login.php">
          <?php if ($error !== ""): ?>
            <p class="login-error" role="alert">
              <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </p>
          <?php endif; ?>

          <div class="input-group">
            <label for="employee-id">
              Employee ID
            </label>
            <input
                type="text"
                id="employee-id"
                name="username"
                autocomplete="username"
                required>
          </div>

          <div class="input-group">
            <label for="password">
              Password
            </label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required>
          </div>
          <div class="remember-me">

              <div class="remember-me">
                <input
                    type="checkbox"
                    id="remember-me">
                  
                <label for="remember-me">
                    Remember Me
                </label>
              </div>

              <button 
                  type="submit"
                  class="login-button">

                  Login
              </button>
          </div>
        </form>

        <footer class="login-footer">
            Version 1.0
        </footer>
      </div>
    </div>
  </body>
</html>
