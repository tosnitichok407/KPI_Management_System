<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role_id"] != 1) {
    die("Access denied.");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Admin Dashboard</title>

    <link
        rel="stylesheet"
        href="../assets/css/admin.css"
    >

</head>

<body>

    <div class="admin-container">

        <h1>Admin Dashboard</h1>

        <p>
            Welcome, <?= htmlspecialchars($_SESSION["employee_id"]) ?>
        </p>

        <div class="admin-menu">

            <a href="employees/index.php">
                Employee Management
            </a>

        </div>

    </div>

</body>

</html>