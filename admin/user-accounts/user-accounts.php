<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


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
    redirectToRoleHome("../../");
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


/*
|--------------------------------------------------------------------------
| Account Summary
|--------------------------------------------------------------------------
|
| สรุปจำนวนพนักงานและบัญชีผู้ใช้ของทั้งระบบ
| ตั้งใจให้ไม่ขึ้นกับตัวกรองด้านบน
| เพื่อให้เห็นภาพรวมเสมอแม้กำลังค้นหาอยู่
|
*/

$summary = [
    "employee_total"  => 0,
    "employee_active" => 0,
    "account_total"   => 0,
    "account_active"  => 0,
    "no_account"      => 0,
];

$role_summary = [];


try {

    /*
    | จำนวนพนักงานทั้งหมด
    */

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status = 'Active' THEN 1
                    ELSE 0
                END
            ) AS active

        FROM employees
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $summary["employee_total"]  = (int) ($row["total"] ?? 0);

    $summary["employee_active"] = (int) ($row["active"] ?? 0);


    /*
    | พนักงานที่ยังไม่มีบัญชีผู้ใช้
    */

    $stmt = $pdo->query("
        SELECT COUNT(*) AS total

        FROM employees e

        LEFT JOIN users u
            ON e.employee_id = u.employee_id

        WHERE u.user_id IS NULL
    ");

    $summary["no_account"] = (int) $stmt->fetchColumn();


    /*
    | จำนวนบัญชีผู้ใช้ แยกตามบทบาท
    */

    $stmt = $pdo->query("
        SELECT
            r.role_name,

            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN u.status = 'Active' THEN 1
                    ELSE 0
                END
            ) AS active

        FROM users u

        JOIN roles r
            ON u.role_id = r.role_id

        GROUP BY
            r.role_id,
            r.role_name

        ORDER BY r.role_id
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $role_summary[strtolower($row["role_name"])] = [
            "total"  => (int) $row["total"],
            "active" => (int) $row["active"],
        ];

        $summary["account_total"]  += (int) $row["total"];

        $summary["account_active"] += (int) $row["active"];
    }

} catch (PDOException $e) {

    $error = $error ?? "Unable to load account summary.";
}

/* ข้อความหลังเปิด-ปิด / แก้ไขบัญชี (ตั้งไว้ใน session โดย toggle / edit) */

$flashSuccess = $_SESSION["user_success"] ?? "";
$flashError = $_SESSION["user_error"] ?? "";

unset($_SESSION["user_success"], $_SESSION["user_error"]);

?>



<div class="page-container">


    <!-- === HEADER === -->

    <header class="page-header">
        <div>
            <h1>
                User Account Management
            </h1>

            <p>
                จัดการบัญชีผู้ใช้ของพนักงาน
            </p>

        </div>


        <div class="header-actions">

            <a
                href="user-accounts/user-account-add.php"
                class="btn btn-primary"
            >
                + สร้างบัญชีผู้ใช้
            </a>

        </div>

    </header>


        <?php if ($flashSuccess !== ""): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ""): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <!-- === SUMMARY === -->

    <section class="account-stats">

        <article class="account-stat-card">

            <h3>
                พนักงานในระบบ
            </h3>

            <strong>
                <?= number_format($summary["employee_total"]) ?>
                <small>คน</small>
            </strong>

            <p>
                Active <?= number_format($summary["employee_active"]) ?>
                ·
                Inactive <?= number_format(
                    $summary["employee_total"]
                    - $summary["employee_active"]
                ) ?>
            </p>

        </article>


        <article class="account-stat-card">

            <h3>
                บัญชีผู้ใช้ทั้งหมด
            </h3>

            <strong>
                <?= number_format($summary["account_total"]) ?>
                <small>บัญชี</small>
            </strong>

            <p>
                ใช้งานอยู่ <?= number_format($summary["account_active"]) ?>
                ·
                ยังไม่มีบัญชี <?= number_format($summary["no_account"]) ?> คน
                ·
                ผู้ดูแลระบบ <?= number_format(
                    $role_summary["admin"]["total"] ?? 0
                ) ?>
            </p>

        </article>


        <article class="account-stat-card">

            <h3>
                บัญชี Manager
            </h3>

            <strong>
                <?= number_format(
                    $role_summary["manager"]["total"] ?? 0
                ) ?>
                <small>บัญชี</small>
            </strong>

            <p>
                ใช้งานอยู่ <?= number_format(
                    $role_summary["manager"]["active"] ?? 0
                ) ?>
            </p>

        </article>


        <article class="account-stat-card">

            <h3>
                บัญชี Employee
            </h3>

            <strong>
                <?= number_format(
                    $role_summary["employee"]["total"] ?? 0
                ) ?>
                <small>บัญชี</small>
            </strong>

            <p>
                ใช้งานอยู่ <?= number_format(
                    $role_summary["employee"]["active"] ?? 0
                ) ?>
            </p>

        </article>

    </section>


    <!-- === FILTER === -->

    <section class="filter-card">

        <form
            method="GET"
            action="index.php?page=accounts"
            class="filter-form"
        >

            <div class="form-group">

                <label for="search">
                    ค้นหา
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
                    placeholder="รหัสพนักงาน, ชื่อ, นามสกุล, อีเมล"
                >

            </div>

            <div class="form-group">

                <label for="status">
                    สถานะบัญชีผู้ใช้
                </label>

                <select
                    id="status"
                    name="status"
                >

                    <option value="">
                        ทั้งหมด
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
                        ไม่มีบัญชีผู้ใช้
                    </option>
                </select>
            </div>

            <div class="filter-buttons">
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                ค้นหา
                </button>

                <a
                    href="index.php?page=accounts"
                    class="btn btn-secondary"
                >
                    ล้าง
                </a>
            </div>
        </form>
    </section>

    <!-- === ACCOUNT TABLE === -->

    <section class="table-card">

        <div class="table-header">

            <h2>
                จัดการบัญชีผู้ใช้
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
                            รหัสพนักงาน
                        </th>

                        <th>
                            ชื่อ-นามสกุล
                        </th>

                        <th>
                            ชื่อผู้ใช้
                        </th>

                        <th>
                            บทบาท
                        </th>

                        <th>
                            สถานะพนักงาน
                        </th>

                        <th>
                            สถานะบัญชีผู้ใช้
                        </th>

                        <th>
                            จัดการ
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
                            ไม่พบข้อมูลบัญชีผู้ใช้
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

                                <?php if ($employee["user_id"]): ?>

                                    <?= htmlspecialchars(
                                        $employee["username"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                <?php else: ?>

                                    <span class="text-muted">
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

                                    <span class="text-muted">
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

                                <?php if (!$employee["user_id"]): ?>

                                    <span class="status none">
                                        ไม่มีบัญชีผู้ใช้
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


                                    <?php if (!$employee["user_id"]): ?>


                                        <a
                                            href="user-accounts/user-account-add.php?employee_id=<?= (int) $employee["employee_id"] ?>"
                                            class="btn-small activate"
                                        >
                                            เพิ่มบัญชีผู้ใช้
                                        </a>


                                    <?php else: ?>


                                        <a
                                            href="user-accounts/user-account-edit.php?id=<?= (int) $employee["user_id"] ?>"
                                            class="btn-small edit"
                                        >
                                            แก้ไข
                                        </a>


                                        <?php if (
                                            $employee["user_status"]
                                            === "Active"
                                        ): ?>

                                            <form
                                                method="POST"
                                                action="user-accounts/user-account-toggle.php"
                                                class="inline-form"
                                                onsubmit="return confirm('Deactivate this account?');"
                                            >
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= (int) $employee["user_id"] ?>">
                                                <input type="hidden" name="action" value="deactivate">
                                                <button type="submit" class="btn-small danger">
                                                    Deactivate
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <form
                                                method="POST"
                                                action="user-accounts/user-account-toggle.php"
                                                class="inline-form"
                                                onsubmit="return confirm('Activate this account?');"
                                            >
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= (int) $employee["user_id"] ?>">
                                                <input type="hidden" name="action" value="activate">
                                                <button type="submit" class="btn-small activate">
                                                    Activate
                                                </button>
                                            </form>

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


