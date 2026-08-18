<?php

session_start();

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Access
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
    exit;
}


$error = "";


/*
|--------------------------------------------------------------------------
| Get Departments
|--------------------------------------------------------------------------
*/

$departments = $pdo
    ->query("
        SELECT department_id, department_name
        FROM departments
        ORDER BY department_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Positions
|--------------------------------------------------------------------------
*/

$positions = $pdo
    ->query("
        SELECT position_id, position_name
        FROM positions
        ORDER BY position_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Add Employee
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_code = trim($_POST["employee_code"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $gender = $_POST["gender"] ?? null;
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department_id = $_POST["department_id"] ?? null;
    $position_id = $_POST["position_id"] ?? null;
    $hire_date = $_POST["hire_date"] ?? null;


    if (
        $employee_code === "" ||
        $first_name === "" ||
        $last_name === ""
    ) {

        $error = "Please fill in all required fields.";

    } else {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO employees
                (
                    employee_code,
                    first_name,
                    last_name,
                    gender,
                    phone,
                    email,
                    department_id,
                    position_id,
                    hire_date,
                    status
                )
                VALUES
                (
                    :employee_code,
                    :first_name,
                    :last_name,
                    :gender,
                    :phone,
                    :email,
                    :department_id,
                    :position_id,
                    :hire_date,
                    'Active'
                )
            ");

            $stmt->execute([
                ":employee_code" => $employee_code,
                ":first_name" => $first_name,
                ":last_name" => $last_name,
                ":gender" => $gender ?: null,
                ":phone" => $phone ?: null,
                ":email" => $email ?: null,
                ":department_id" => $department_id ?: null,
                ":position_id" => $position_id ?: null,
                ":hire_date" => $hire_date ?: null
            ]);


            header("Location: employees.php");
            exit;

        } catch (PDOException $e) {

            if ($e->getCode() === "23000") {
                $error = "Employee ID already exists.";
            } else {
                $error = "Unable to add employee.";
            }

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
        href="../assets/css/employee.css"
    >

    <title>Add Employee</title>

</head>


<body>

<div class="page-container">

    <header class="page-header">

        <div>

            <h1>
                Add Employee
            </h1>

            <p>
                Create a new employee
            </p>

        </div>

        <a
            href="employees.php"
            class="btn btn-secondary"
        >
            Back
        </a>

    </header>


    <?php if ($error !== ""): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <section class="filter-card">

        <form method="POST">

            <div class="form-group">

                <label>
                    Employee ID *
                </label>

                <input
                    type="text"
                    name="employee_code"
                    placeholder="EMP001"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    First Name *
                </label>

                <input
                    type="text"
                    name="first_name"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Last Name *
                </label>

                <input
                    type="text"
                    name="last_name"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Gender
                </label>

                <select name="gender">

                    <option value="">
                        Select Gender
                    </option>

                    <option value="Male">
                        Male
                    </option>

                    <option value="Female">
                        Female
                    </option>

                    <option value="Other">
                        Other
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                >

            </div>


            <div class="form-group">

                <label>
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                >

            </div>


            <div class="form-group">

                <label>
                    Department
                </label>

                <select name="department_id">

                    <option value="">
                        Select Department
                    </option>

                    <?php foreach ($departments as $department): ?>

                        <option
                            value="<?= $department["department_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $department["department_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Position
                </label>

                <select name="position_id">

                    <option value="">
                        Select Position
                    </option>

                    <?php foreach ($positions as $position): ?>

                        <option
                            value="<?= $position["position_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $position["position_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Hire Date
                </label>

                <input
                    type="date"
                    name="hire_date"
                >

            </div>


            <div style="margin-top: 20px;">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Employee
                </button>

            </div>

        </form>

    </section>

</div>

</body>

</html>