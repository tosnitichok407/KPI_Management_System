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

$roles = $pdo
    ->query("SELECT id, role_name FROM roles ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Create Employee</title>

    <link
        rel="stylesheet"
        href="../../assets/css/employee.css"
    >

</head>

<body>

<div class="employee-container">

    <div class="page-header">

        <div>

            <h1>
                Create Employee
            </h1>

            <p>
                Add a new employee and create their account
            </p>

        </div>

        <a
            href="index.php"
            class="btn-secondary"
        >
            Back
        </a>

    </div>


    <div class="form-card">

        <form
            action="store.php"
            method="POST"
        >

            <h2>
                Employee Information
            </h2>


            <div class="form-grid">

                <div class="form-group">

                    <label>
                        Employee ID
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
                        First Name
                    </label>

                    <input
                        type="text"
                        name="first_name"
                        placeholder="First Name"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Last Name
                    </label>

                    <input
                        type="text"
                        name="last_name"
                        placeholder="Last Name"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        placeholder="employee@company.com"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Phone
                    </label>

                    <input
                        type="text"
                        name="phone"
                        placeholder="08xxxxxxxx"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Department
                    </label>

                    <input
                        type="text"
                        name="department"
                        placeholder="Sales"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Position
                    </label>

                    <input
                        type="text"
                        name="position"
                        placeholder="Sales Executive"
                    >

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

            </div>


            <h2 class="account-title">
                Login Account
            </h2>


            <div class="form-grid">

                <div class="form-group">

                    <label>
                        Username
                    </label>

                    <input
                        type="text"
                        name="username"
                        placeholder="EMP001"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Role
                    </label>

                    <select
                        name="role_id"
                        required
                    >

                        <?php foreach ($roles as $role): ?>

                            <option
                                value="<?= $role["id"] ?>"
                            >

                                <?= htmlspecialchars(
                                    ucfirst($role["role_name"])
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>


            <div class="account-info">

                <strong>
                    Temporary Password
                </strong>

                <p>
                    The system will automatically generate
                    a temporary password.
                </p>

                <p>
                    Employee must change the password
                    after the first login.
                </p>

            </div>


            <button
                type="submit"
                class="btn-primary"
            >
                Create Employee
            </button>

        </form>

    </div>

</div>

</body>

</html>