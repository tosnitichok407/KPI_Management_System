<?php

session_start();

require_once "config/database.php";

/*
|--------------------------------------------------------------------------
| Admin Access
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ((int)($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$error = "";
$success = "";

$edit_employee = null;


/*
|--------------------------------------------------------------------------
| Add Employee
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "add") {

    $employee_code = trim($_POST["employee_code"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $gender = $_POST["gender"] ?? null;
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department_id = !empty($_POST["department_id"])
        ? (int)$_POST["department_id"]
        : null;
    $position_id = !empty($_POST["position_id"])
        ? (int)$_POST["position_id"]
        : null;
    $hire_date = !empty($_POST["hire_date"])
        ? $_POST["hire_date"]
        : null;
    $status = $_POST["status"] ?? "Active";


    if ($employee_code === "" || $first_name === "" || $last_name === "") {

        $error = "กรุณากรอก Employee Code, First Name และ Last Name";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Employee Code
        |--------------------------------------------------------------------------
        */

        $check = $pdo->prepare("
            SELECT employee_id
            FROM employees
            WHERE employee_code = :employee_code
            LIMIT 1
        ");

        $check->execute([
            ":employee_code" => $employee_code
        ]);

        if ($check->fetch()) {

            $error = "Employee Code นี้มีอยู่ในระบบแล้ว";

        } else {

            /*
            |--------------------------------------------------------------------------
            | Insert Employee
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO employees (
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
                VALUES (
                    :employee_code,
                    :first_name,
                    :last_name,
                    :gender,
                    :phone,
                    :email,
                    :department_id,
                    :position_id,
                    :hire_date,
                    :status
                )
            ");

            $stmt->execute([
                ":employee_code" => $employee_code,
                ":first_name" => $first_name,
                ":last_name" => $last_name,
                ":gender" => $gender,
                ":phone" => $phone !== "" ? $phone : null,
                ":email" => $email !== "" ? $email : null,
                ":department_id" => $department_id,
                ":position_id" => $position_id,
                ":hire_date" => $hire_date,
                ":status" => $status
            ]);

            $success = "เพิ่มพนักงานเรียบร้อยแล้ว";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Update Employee
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "update") {

    $employee_id = (int)($_POST["employee_id"] ?? 0);

    $employee_code = trim($_POST["employee_code"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $gender = $_POST["gender"] ?? null;
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");

    $department_id = !empty($_POST["department_id"])
        ? (int)$_POST["department_id"]
        : null;

    $position_id = !empty($_POST["position_id"])
        ? (int)$_POST["position_id"]
        : null;

    $hire_date = !empty($_POST["hire_date"])
        ? $_POST["hire_date"]
        : null;

    $status = $_POST["status"] ?? "Active";


    if ($employee_id <= 0) {

        $error = "ไม่พบข้อมูลพนักงาน";

    } elseif ($employee_code === "" || $first_name === "" || $last_name === "") {

        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";

    } else {

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

            $error = "Employee Code นี้ถูกใช้งานแล้ว";

        } else {

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
                ":gender" => $gender,
                ":phone" => $phone !== "" ? $phone : null,
                ":email" => $email !== "" ? $email : null,
                ":department_id" => $department_id,
                ":position_id" => $position_id,
                ":hire_date" => $hire_date,
                ":status" => $status,
                ":employee_id" => $employee_id
            ]);

            $success = "แก้ไขข้อมูลพนักงานเรียบร้อยแล้ว";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Change Status
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "toggle_status") {

    $employee_id = (int)($_POST["employee_id"] ?? 0);

    $stmt = $pdo->prepare("
        UPDATE employees
        SET status =
            CASE
                WHEN status = 'Active' THEN 'Inactive'
                ELSE 'Active'
            END
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);

    $success = "เปลี่ยนสถานะพนักงานเรียบร้อยแล้ว";
}


/*
|--------------------------------------------------------------------------
| Edit Employee
|--------------------------------------------------------------------------
*/

if (isset($_GET["edit"])) {

    $employee_id = (int)$_GET["edit"];

    $stmt = $pdo->prepare("
        SELECT *
        FROM employees
        WHERE employee_id = :employee_id
        LIMIT 1
    ");

    $stmt->execute([
        ":employee_id" => $employee_id
    ]);

    $edit_employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$edit_employee) {
        $error = "ไม่พบข้อมูลพนักงาน";
    }
}


/*
|--------------------------------------------------------------------------
| Get Departments
|--------------------------------------------------------------------------
*/

$department_stmt = $pdo->query("
    SELECT
        department_id,
        department_name
    FROM departments
    ORDER BY department_name
");

$departments = $department_stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Positions
|--------------------------------------------------------------------------
*/

$position_stmt = $pdo->query("
    SELECT
        position_id,
        position_name
    FROM positions
    ORDER BY position_name
");

$positions = $position_stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Employees
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.gender,
        e.phone,
        e.email,
        e.department_id,
        e.position_id,
        e.hire_date,
        e.status,

        d.department_name,
        p.position_name

    FROM employees e

    LEFT JOIN departments d
        ON e.department_id = d.department_id

    LEFT JOIN positions p
        ON e.position_id = p.position_id

    ORDER BY e.employee_id DESC
");

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Employee Management - KPI System</title>

    <link
        rel="stylesheet"
        href="assets/css/employee.css"
    >

</head>

<body>

<div class="page-container">

    <!-- Header -->

    <div class="page-header">

        <div>

            <h1>Employee Management</h1>

            <p>
                จัดการข้อมูลพนักงาน
            </p>

        </div>

        <div>

            <a
                href="index.php"
                class="btn btn-secondary"
            >
                ← Dashboard
            </a>

            <a
                href="employee.php"
                class="btn btn-primary"
            >
                + Add Employee
            </a>

        </div>

    </div>


    <!-- Messages -->

    <?php if ($error !== ""): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($success !== ""): ?>

        <div class="alert alert-success">

            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <!-- Add / Edit Form -->

    <?php if ($edit_employee): ?>

        <div class="card">

            <h2>Edit Employee</h2>

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="update"
                >

                <input
                    type="hidden"
                    name="employee_id"
                    value="<?= (int)$edit_employee["employee_id"] ?>"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label>Employee Code *</label>

                        <input
                            type="text"
                            name="employee_code"
                            required
                            value="<?= htmlspecialchars(
                                $edit_employee["employee_code"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>First Name *</label>

                        <input
                            type="text"
                            name="first_name"
                            required
                            value="<?= htmlspecialchars(
                                $edit_employee["first_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Last Name *</label>

                        <input
                            type="text"
                            name="last_name"
                            required
                            value="<?= htmlspecialchars(
                                $edit_employee["last_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Gender</label>

                        <select name="gender">

                            <option value="">-- Select --</option>

                            <option
                                value="Male"
                                <?= $edit_employee["gender"] === "Male"
                                    ? "selected"
                                    : "" ?>
                            >
                                Male
                            </option>

                            <option
                                value="Female"
                                <?= $edit_employee["gender"] === "Female"
                                    ? "selected"
                                    : "" ?>
                            >
                                Female
                            </option>

                            <option
                                value="Other"
                                <?= $edit_employee["gender"] === "Other"
                                    ? "selected"
                                    : "" ?>
                            >
                                Other
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>Phone</label>

                        <input
                            type="text"
                            name="phone"
                            value="<?= htmlspecialchars(
                                $edit_employee["phone"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="email"
                            name="email"
                            value="<?= htmlspecialchars(
                                $edit_employee["email"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Department</label>

                        <select name="department_id">

                            <option value="">
                                -- Select Department --
                            </option>

                            <?php foreach ($departments as $department): ?>

                                <option
                                    value="<?= $department["department_id"] ?>"
                                    <?= (int)$edit_employee["department_id"]
                                        === (int)$department["department_id"]
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


                    <div class="form-group">

                        <label>Position</label>

                        <select name="position_id">

                            <option value="">
                                -- Select Position --
                            </option>

                            <?php foreach ($positions as $position): ?>

                                <option
                                    value="<?= $position["position_id"] ?>"
                                    <?= (int)$edit_employee["position_id"]
                                        === (int)$position["position_id"]
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


                    <div class="form-group">

                        <label>Hire Date</label>

                        <input
                            type="date"
                            name="hire_date"
                            value="<?= htmlspecialchars(
                                $edit_employee["hire_date"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Status</label>

                        <select name="status">

                            <option
                                value="Active"
                                <?= $edit_employee["status"] === "Active"
                                    ? "selected"
                                    : "" ?>
                            >
                                Active
                            </option>

                            <option
                                value="Inactive"
                                <?= $edit_employee["status"] === "Inactive"
                                    ? "selected"
                                    : "" ?>
                            >
                                Inactive
                            </option>

                        </select>

                    </div>

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Save Changes
                    </button>

                    <a
                        href="employee.php"
                        class="btn btn-secondary"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    <?php endif; ?>


    <!-- Employee Table -->

    <div class="card">

        <div class="table-header">

            <h2>Employees</h2>

            <span>
                <?= count($employees) ?> employees
            </span>

        </div>


        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Employee Code</th>

                        <th>Name</th>

                        <th>Department</th>

                        <th>Position</th>

                        <th>Email</th>

                        <th>Status</th>

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>

                <?php if (count($employees) === 0): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="empty"
                        >
                            ยังไม่มีข้อมูลพนักงาน
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($employees as $employee): ?>

                        <tr>

                            <td>
                                <?= (int)$employee["employee_id"] ?>
                            </td>


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $employee["employee_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </strong>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $employee["first_name"] . " " .
                                    $employee["last_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $employee["department_name"]
                                    ?? "-",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $employee["position_name"]
                                    ?? "-",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $employee["email"]
                                    ?? "-",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <td>

                                <?php if ($employee["status"] === "Active"): ?>

                                    <span class="status active">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status inactive">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="actions">

                                    <a
                                        href="employee.php?edit=<?= (int)$employee["employee_id"] ?>"
                                        class="btn btn-edit"
                                    >
                                        Edit
                                    </a>


                                    <form
                                        method="POST"
                                        class="inline-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="employee_id"
                                            value="<?= (int)$employee["employee_id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-status"
                                        >

                                            <?= $employee["status"] === "Active"
                                                ? "Deactivate"
                                                : "Activate" ?>

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>

</html>