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

    if ($_SESSION["must_change_password"] == 1) {

        header("Location: change-password.php");
        exit;
    }

    header("Location: dashboard/index.php");
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

        e.employee_code,
        e.first_name,
        e.last_name,
        e.gender,
        e.phone,
        e.email AS employee_email,
        e.department_id,
        e.position_id,
        e.hire_date,
        e.status AS employee_status,

        r.role_name,

        d.department_name

    FROM users u

    INNER JOIN employees e
        ON u.employee_id = e.employee_id

    INNER JOIN roles r
        ON u.role_id = r.role_id

    LEFT JOIN departments d
        ON e.department_id = d.department_id

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

        elseif ($user["user_status"] !== "active") {

            $error = "Your account has been deactivated.";

        }

        /*
        |--------------------------------------------------------------------------
        | Check Employee Status
        |--------------------------------------------------------------------------
        */

        elseif ($user["employee_status"] !== "active") {

            $error = "Your employee account is inactive.";

        }

        /*
        |--------------------------------------------------------------------------
        | Check Password
        |--------------------------------------------------------------------------
        */

        elseif (
            password_verify(
            $password,
            $user["password_hash"]
            )
        ) {

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

                WHERE id = :user_id
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

            if ($user["role_name"] === "admin") {

                header(
                    "Location: admin/index.php"
                );

                exit;
            }

            elseif ($user["role_name"] === "manager") {

                header(
                    "Location: dashboard/index.php"
                );

                exit;
            }

            else {

                header(
                    "Location: dashboard/index.php"
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

        <form class="login-form">
          <div class="input-group">
            <label for="employee-id">
              Employee ID
            </label>
            <input
                type="text"
                id="employee-id"> 
          </div>

          <div class="input-group">
            <label for="password">
              Password
            </label>
            <input
                type="password"
                id="password">
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
