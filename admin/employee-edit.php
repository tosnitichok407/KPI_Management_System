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


/*
|--------------------------------------------------------------------------
| Get Employee ID
|--------------------------------------------------------------------------
*/

$employee_id = (int) ($_GET["id"] ?? $_POST["employee_id"] ?? 0);

if ($employee_id <= 0) {
    header("Location: employees.php");
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
        SELECT
            department_id,
            department_name
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
        SELECT
            position_id,
            position_name
        FROM positions
        ORDER BY position_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Update Employee
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

    $status = $_POST["status"] ?? "Active";


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if (
        $employee_code === "" ||
        $first_name === "" ||
        $last_name === ""
    ) {

        $error = "Please fill in all required fields.";

    } elseif (!in_array($status, ["Active", "Inactive"])) {

        $error = "Invalid employee status.";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check Duplicate Employee Code
            |--------------------------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT employee_id
                FROM employees
                WHERE employee_code = :employee_code
                AND employee_id != :employee_id
                LIMIT 1
            ");

            $check->execute([
                ":employee_code" => $employee_code,
                ":employee_id" => $employee_id
            ]);

            if ($check->fetch()) {

                $error = "Employee ID already exists.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | Update
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE employees

                    SET
                        employee_code = :employee_code,
                        first_name = :first_name,
                        last_name = :last_name,
                        gender = :gender,
                        phone = :phone,
                        email = :email,
                        department_id = :department_id,
                        position_id = :position_id,
                        hire_date = :hire_date,
                        status = :status

                    WHERE employee_id = :employee_id
                ");

                $stmt->execute([

                    ":employee_code" => $employee_code,

                    ":first_name" => $first_name,

                    ":last_name" => $last_name,

                    ":gender" =>
                        $gender !== ""
                            ? $gender
                            : null,

                    ":phone" =>
                        $phone !== ""
                            ? $phone
                            : null,

                    ":email" =>
                        $email !== ""
                            ? $email
                            : null,

                    ":department_id" =>
                        $department_id !== ""
                            ? $department_id
                            : null,

                    ":position_id" =>
                        $position_id !== ""
                            ? $position_id
                            : null,

                    ":hire_date" =>
                        $hire_date !== ""
                            ? $hire_date
                            : null,

                    ":status" => $status,

                    ":employee_id" => $employee_id
                ]);


                /*
                |--------------------------------------------------------------------------
                | Success
                |--------------------------------------------------------------------------
                */

                header("Location: employees.php");
                exit;
            }

        } catch (PDOException $e) {

            $error = "Unable to update employee.";

        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Employee Data
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        employee_id,
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

    FROM employees

    WHERE employee_id = :employee_id

    LIMIT 1
");

$stmt->execute([
    ":employee_id" => $employee_id
]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Employee Not Found
|--------------------------------------------------------------------------
*/

if (!$employee) {

    header("Location: employees.php");
    exit;

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

    <title>Edit Employee</title>

</head>


<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                Edit Employee
            </h1>

            <p>
                Update employee information
            </p>

        </div>


        <a
            href="employees.php"
            class="btn btn-secondary"
        >
            Back
        </a>

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
            action="employee-edit.php?id=<?= $employee_id ?>"
        >

            <input
                type="hidden"
                name="employee_id"
                value="<?= $employee_id ?>"
            >


            <!-- Employee ID -->

            <div class="form-group">

                <label for="employee_code">
                    Employee ID *
                </label>

                <input
                    type="text"
                    id="employee_code"
                    name="employee_code"
                    value="<?= htmlspecialchars(
                        $employee["employee_code"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>


            <!-- First Name -->

            <div class="form-group">

                <label for="first_name">
                    First Name *
                </label>

                <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    value="<?= htmlspecialchars(
                        $employee["first_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>


            <!-- Last Name -->

            <div class="form-group">

                <label for="last_name">
                    Last Name *
                </label>

                <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    value="<?= htmlspecialchars(
                        $employee["last_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                >

            </div>


            <!-- Gender -->

            <div class="form-group">

                <label for="gender">
                    Gender
                </label>

                <select
                    id="gender"
                    name="gender"
                >

                    <option value="">
                        Select Gender
                    </option>

                    <option
                        value="Male"
                        <?= $employee["gender"] === "Male"
                            ? "selected"
                            : "" ?>
                    >
                        Male
                    </option>

                    <option
                        value="Female"
                        <?= $employee["gender"] === "Female"
                            ? "selected"
                            : "" ?>
                    >
                        Female
                    </option>

                    <option
                        value="Other"
                        <?= $employee["gender"] === "Other"
                            ? "selected"
                            : "" ?>
                    >
                        Other
                    </option>

                </select>

            </div>


            <!-- Phone -->

            <div class="form-group">

                <label for="phone">
                    Phone
                </label>

                <input
                    type="text"
                    id="phone"
                    name="phone"
                    value="<?= htmlspecialchars(
                        $employee["phone"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
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
                        $employee["email"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <!-- Department -->

            <div class="form-group">

                <label for="department_id">
                    Department
                </label>

                <select
                    id="department_id"
                    name="department_id"
                >

                    <option value="">
                        Select Department
                    </option>


                    <?php foreach ($departments as $department): ?>

                        <option
                            value="<?= $department["department_id"] ?>"
                            <?= (int) $employee["department_id"]
                                === (int) $department["department_id"]
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                $department["department_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Position -->

            <div class="form-group">

                <label for="position_id">
                    Position
                </label>

                <select
                    id="position_id"
                    name="position_id"
                >

                    <option value="">
                        Select Position
                    </option>


                    <?php foreach ($positions as $position): ?>

                        <option
                            value="<?= $position["position_id"] ?>"
                            <?= (int) $employee["position_id"]
                                === (int) $position["position_id"]
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars(
                                $position["position_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Hire Date -->

            <div class="form-group">

                <label for="hire_date">
                    Hire Date
                </label>

                <input
                    type="date"
                    id="hire_date"
                    name="hire_date"
                    value="<?= htmlspecialchars(
                        $employee["hire_date"] ?? "",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <!-- Status -->

            <div class="form-group">

                <label for="status">
                    Status
                </label>

                <select
                    id="status"
                    name="status"
                >

                    <option
                        value="Active"
                        <?= $employee["status"] === "Active"
                            ? "selected"
                            : "" ?>
                    >
                        Active
                    </option>

                    <option
                        value="Inactive"
                        <?= $employee["status"] === "Inactive"
                            ? "selected"
                            : "" ?>
                    >
                        Inactive
                    </option>

                </select>

            </div>


            <!-- Buttons -->

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
                    Save Changes
                </button>


                <a
                    href="employees.php"
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