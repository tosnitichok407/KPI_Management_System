<?php

session_start();

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../../dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");

$status = $_GET["status"] ?? "";


/*
|--------------------------------------------------------------------------
| Get Employee Accounts
|--------------------------------------------------------------------------
|
| employees
|     ↓ LEFT JOIN
| users
|     ↓
| roles
|
| LEFT JOIN ทำให้พนักงานที่ยังไม่มี Account
| ก็สามารถแสดงออกมาได้
|
*/

$sql = "
    SELECT

        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.email AS employee_email,
        e.status AS employee_status,

        u.user_id,
        u.username,
        u.email AS user_email,
        u.status AS user_status,

        r.role_id,
        r.role_name

    FROM employees e

    LEFT JOIN users u
        ON e.employee_id = u.employee_id

    LEFT JOIN roles r
        ON u.role_id = r.role_id

    WHERE 1 = 1
";

$params = [];


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            e.employee_code LIKE :search
            OR e.first_name LIKE :search
            OR e.last_name LIKE :search
            OR u.username LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";
}


/*
|--------------------------------------------------------------------------
| Account Status Filter
|--------------------------------------------------------------------------
*/

if (
    $status !== ""
    && in_array($status, ["Active", "Inactive", "No Account"])
) {

    if ($status === "No Account") {

        $sql .= "
            AND u.employee_id IS NULL
        ";

    } else {

        $sql .= "
            AND u.status = :status
        ";

        $params[":status"] = $status;
    }
}


/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY e.employee_id DESC
";


/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $employees = [];

    $error = "Unable to load account data.";
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
        href="../../assets/css/user-account.css"
    >

    <title>User Account Management</title>

</head>

<body>

<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                User Account Management
            </h1>

            <p>
                Manage employee login accounts
            </p>

        </div>


        <div class="header-actions">

            <a
                href="../index.php"
                class="btn btn-secondary"
            >
                Dashboard
            </a>

            <a
                href="user-account-add.php"
                class="btn btn-primary"
            >
                + Create Account
            </a>

        </div>

    </header>


    <!-- =========================================================
         FILTER
    ========================================================== -->

    <section class="filter-card">

        <form
            method="GET"
            action="user-accounts.php"
            class="filter-form"
        >

            <div class="form-group">

                <label for="search">
                    Search
                </label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= htmlspecialchars(
                        $search,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    placeholder="Employee ID, name or username"
                >

            </div>


            <div class="form-group">

                <label for="status">
                    Account Status
                </label>

                <select
                    id="status"
                    name="status"
                >

                    <option value="">
                        All
                    </option>

                    <option
                        value="Active"
                        <?= $status === "Active"
                            ? "selected"
                            : "" ?>
                    >
                        Active
                    </option>

                    <option
                        value="Inactive"
                        <?= $status === "Inactive"
                            ? "selected"
                            : "" ?>
                    >
                        Inactive
                    </option>

                    <option
                        value="No Account"
                        <?= $status === "No Account"
                            ? "selected"
                            : "" ?>
                    >
                        No Account
                    </option>

                </select>

            </div>


            <div class="filter-buttons">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Search
                </button>

                <a
                    href="/user-accounts/user-accounts.php"
                    class="btn btn-secondary"
                >
                    Reset
                </a>

            </div>

        </form>

    </section>

    <!-- =========================================================
         ACCOUNT TABLE
    ========================================================== -->

    <section class="table-card">

        <div class="table-header">

            <h2>
                Employee Accounts
            </h2>

            <span>
                <?= count($employees) ?> employees
            </span>

        </div>


        <?php if (isset($error)): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>
                            Employee ID
                        </th>

                        <th>
                            Employee Name
                        </th>

                        <th>
                            Username
                        </th>

                        <th>
                            Role
                        </th>

                        <th>
                            Employee Status
                        </th>

                        <th>
                            Account Status
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($employees)): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="empty-state"
                        >
                            No employee found.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($employees as $employee): ?>

                        <tr>

                            <!-- Employee ID -->
                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $employee["employee_code"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </strong>

                            </td>

                            <!-- Employee Name -->
                            <td>

                                <?= htmlspecialchars(
                                    $employee["first_name"]
                                    . " "
                                    . $employee["last_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </td>


                            <!-- Username -->

                            <td>

                                <?php if ($employee["employee_id"]): ?>

                                    <?= htmlspecialchars(
                                        $employee["username"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                <?php else: ?>

                                    <span style="color:#9ca3af;">
                                        -
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Role -->

                            <td>

                                <?php if ($employee["role_name"]): ?>

                                    <?= htmlspecialchars(
                                        $employee["role_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                <?php else: ?>

                                    <span style="color:#9ca3af;">
                                        -
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Employee Status -->

                            <td>

                                <?php if (
                                    $employee["employee_status"]
                                    === "Active"
                                ): ?>

                                    <span class="status active">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status inactive">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Account Status -->

                            <td>

                                <?php if (!$employee["employee_id"]): ?>

                                    <span
                                        class="status"
                                        style="
                                            background:#f3f4f6;
                                            color:#6b7280;
                                        "
                                    >
                                        No Account
                                    </span>

                                <?php elseif (
                                    $employee["user_status"]
                                    === "Active"
                                ): ?>

                                    <span class="status active">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="status inactive">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Action -->

                            <td>

                                <div class="action-buttons">


                                    <?php if (!$employee["employee_id"]): ?>


                                        <a
                                            href="/user-accounts/user-account-add.php?employee_id=<?= (int) $employee["employee_id"] ?>"
                                            class="btn-small activate"
                                        >
                                            Create Account
                                        </a>


                                    <?php else: ?>


                                        <a
                                            href="/user-accounts/user-account-edit.php?id=<?= (int) $employee["user_id"] ?>"
                                            class="btn-small edit"
                                        >
                                            Edit
                                        </a>


                                        <?php if (
                                            $employee["user_status"]
                                            === "Active"
                                        ): ?>

                                            <a
                                                href="/user-accounts/user-account-toggle.php?id=<?= (int) $employee["user_id"] ?>&action=deactivate"
                                                class="btn-small danger"
                                                onclick="return confirm('Deactivate this account?');"
                                            >
                                                Deactivate
                                            </a>

                                        <?php else: ?>

                                            <a
                                                href="/user-accounts/user-account-toggle.php?id=<?= (int) $employee["user_id"] ?>&action=activate"
                                                class="btn-small activate"
                                                onclick="return confirm('Activate this account?');"
                                            >
                                                Activate
                                            </a>

                                        <?php endif; ?>


                                    <?php endif; ?>

                                </div>

                            </td>


                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </section>


</div>

</body>

</html>
