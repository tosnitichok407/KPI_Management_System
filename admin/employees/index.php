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

$sql = "
    SELECT
        e.id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.email,
        e.department,
        e.position,
        e.status,
        u.username,
        r.role_name
    FROM employees e

    LEFT JOIN users u
        ON e.id = u.employee_id

    LEFT JOIN roles r
        ON u.role_id = r.id

    ORDER BY e.id DESC
";

$stmt = $pdo->query($sql);

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Employee Management</title>

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
                Employee Management
            </h1>

            <p>
                Manage employee information and accounts
            </p>

        </div>

        <a
            href="create.php"
            class="btn-primary"
        >
            + Add Employee
        </a>

    </div>


    <div class="employee-card">

        <table>

            <thead>

                <tr>

                    <th>Employee ID</th>

                    <th>Name</th>

                    <th>Department</th>

                    <th>Position</th>

                    <th>Role</th>

                    <th>Status</th>

                    <th>Action</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach ($employees as $employee): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars(
                            $employee["employee_code"]
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee["first_name"]
                        ) ?>

                        <?= htmlspecialchars(
                            $employee["last_name"]
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee["department"] ?? "-"
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee["position"] ?? "-"
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee["role_name"] ?? "-"
                        ) ?>
                    </td>

                    <td>

                        <span class="status">

                            <?= htmlspecialchars(
                                $employee["status"]
                            ) ?>

                        </span>

                    </td>

                    <td>

                        <button>
                            View
                        </button>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>

</html>