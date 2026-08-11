<?php

session_start();

require_once "../../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ($_SESSION["role_id"] != 1) {
    die("Access denied.");
}


/*
|--------------------------------------------------------------------------
| Receive Form Data
|--------------------------------------------------------------------------
*/

$employee_code = trim($_POST["employee_code"]);
$first_name = trim($_POST["first_name"]);
$last_name = trim($_POST["last_name"]);
$email = trim($_POST["email"]);
$phone = trim($_POST["phone"]);
$department = trim($_POST["department"]);
$position = trim($_POST["position"]);
$hire_date = $_POST["hire_date"] ?: null;

$username = trim($_POST["username"]);
$role_id = (int) $_POST["role_id"];


/*
|--------------------------------------------------------------------------
| Generate Temporary Password
|--------------------------------------------------------------------------
*/

$temporary_password =
    bin2hex(random_bytes(4));


$password_hash =
    password_hash(
        $temporary_password,
        PASSWORD_DEFAULT
    );


/*
|--------------------------------------------------------------------------
| Create Employee + User
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    | Check Employee ID
    */

    $check = $pdo->prepare("
        SELECT id
        FROM employees
        WHERE employee_code = :employee_code
    ");

    $check->execute([
        ":employee_code" => $employee_code
    ]);

    if ($check->fetch()) {

        throw new Exception(
            "Employee ID already exists."
        );
    }


    /*
    | Check Username
    */

    $check = $pdo->prepare("
        SELECT id
        FROM users
        WHERE username = :username
    ");

    $check->execute([
        ":username" => $username
    ]);

    if ($check->fetch()) {

        throw new Exception(
            "Username already exists."
        );
    }


    /*
    | Insert Employee
    */

    $sql = "
        INSERT INTO employees (
            employee_code,
            first_name,
            last_name,
            email,
            phone,
            department,
            position,
            hire_date
        )

        VALUES (
            :employee_code,
            :first_name,
            :last_name,
            :email,
            :phone,
            :department,
            :position,
            :hire_date
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([

        ":employee_code" => $employee_code,

        ":first_name" => $first_name,

        ":last_name" => $last_name,

        ":email" => $email,

        ":phone" => $phone,

        ":department" => $department,

        ":position" => $position,

        ":hire_date" => $hire_date

    ]);


    /*
    | Get Employee ID
    */

    $employee_id =
        $pdo->lastInsertId();


    /*
    | Create Login Account
    */

    $sql = "
        INSERT INTO users (
            employee_id,
            username,
            password_hash,
            role_id,
            must_change_password,
            status
        )

        VALUES (
            :employee_id,
            :username,
            :password_hash,
            :role_id,
            1,
            'active'
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([

        ":employee_id" => $employee_id,

        ":username" => $username,

        ":password_hash" => $password_hash,

        ":role_id" => $role_id

    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | Show Temporary Password
    |--------------------------------------------------------------------------
    */

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Employee Created</title>

</head>

<body>

    <h1>
        Employee Created Successfully
    </h1>

    <p>
        Employee ID:
        <strong>
            <?= htmlspecialchars($employee_code) ?>
        </strong>
    </p>

    <p>
        Username:
        <strong>
            <?= htmlspecialchars($username) ?>
        </strong>
    </p>

    <p>
        Temporary Password:
        <strong>
            <?= htmlspecialchars($temporary_password) ?>
        </strong>
    </p>

    <p>
        ⚠️ Please provide this temporary password
        to the employee.
    </p>

    <p>
        The employee must change the password
        after the first login.
    </p>

    <br>

    <a href="index.php">
        Back to Employee Management
    </a>

</body>

</html>

<?php

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die(
        "Error: " .
        htmlspecialchars($e->getMessage())
    );
}