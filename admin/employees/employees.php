<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";

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
| Get Employees
|--------------------------------------------------------------------------
*/

$sql = "
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

    WHERE 1 = 1
";

$params = [];


/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            e.employee_code LIKE :search
            OR e.first_name LIKE :search
            OR e.last_name LIKE :search
            OR e.email LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";
}


/*
|--------------------------------------------------------------------------
| Status Filter
|--------------------------------------------------------------------------
*/

if ($status !== "" && in_array($status, ["Active", "Inactive"])) {

    $sql .= "
        AND e.status = :status
    ";

    $params[":status"] = $status;
}


/*
|--------------------------------------------------------------------------
| Order
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY e.employee_id DESC
";


try {

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

    $employees = [];

    $error = "Unable to load employee data.";
}

?>



    <div class="page-container">

        <!-- === HEADER === -->
        <header class="page-header">

            <div class="page-title-block">
                <h1>
                    Employee Management
                </h1>

                <p>
                    จัดการข้อมูลพนักงานทั้งหมดในระบบ
                </p>

            </div>

            <div class="header-actions">

                <a
                    href="employees/employee-add.php"
                    class="btn btn-primary">
                    + เพิ่มพนักงาน
                </a>

            </div>

        </header>

        <!-- === SEARCH / FILTER === -->
        <section class="filter-card">

            <form
                method="GET"
                action="index.php?page=employees"
                class="filter-form">

                <div class="form-group">

                    <label for="search">
                        Search Employee
                    </label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Employee ID, name or email">

                </div>


                <div class="form-group">

                    <label for="status">
                        สถานะ
                    </label>

                    <select
                        id="status"
                        name="status">

                        <option value="">
                            สถานะทั้งหมด
                        </option>

                        <option
                            value="Active"
                            <?= $status === "Active" ? "selected" : "" ?>>
                            Active
                        </option>

                        <option
                            value="Inactive"
                            <?= $status === "Inactive" ? "selected" : "" ?>>
                            Inactive
                        </option>

                    </select>

                </div>


                <div class="filter-buttons">

                    <button
                        type="submit"
                        class="btn btn-primary">
                        ค้นหา
                    </button>

                    <a
                        href="index.php?page=employees"
                        class="btn btn-secondary">
                        ล้าง
                    </a>

                </div>

            </form>

        </section>


        <!-- === EMPLOYEE TABLE === -->
        <section class="table-card">

            <div class="table-header">

                <h2>
                    รายชื่อพนักงาน
                </h2>

                <span>
                    <?= count($employees) ?> พนักงาน
                </span>

            </div>


            <?php if (isset($_GET["delete"]) && $_GET["deleted"] === "1"): ?>

                <div class="alert alert-success">
                    ลบพนักงานเรียบร้อยแล้ว
                </div>

            <?php endif; ?>


            <?php if (isset($_GET["error"]) && $_GET["error"] === "delete"): ?>

                <div class="alert alert-error">
                    ไม่สามารถลบพนักงานได้ เนื่องจากพนักงานคนนี้มีข้อมูลที่เกี่ยวข้องกับระบบอื่น
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
                                แผนก
                            </th>

                            <th>
                                ตำแหน่ง
                            </th>

                            <th>
                                อีเมล
                            </th>

                            <th>
                                วันเริ่มงาน
                            </th>

                            <th>
                                สถานะ
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
                                    colspan="8"
                                    class="empty-state">
                                    ไม่พบข้อมูลพนักงาน
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($employees as $employee): ?>

                                <tr>
                                    <td>

                                        <strong>
                                            <?= htmlspecialchars(
                                                $employee["employee_code"]
                                            ) ?>
                                        </strong>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars(
                                            $employee["first_name"]
                                                . " "
                                                . $employee["last_name"]
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars(
                                            $employee["department_name"]
                                                ?? "-"
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $employee["position_name"]
                                                ?? "-"
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars(
                                            $employee["email"]
                                                ?? "-"
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= !empty($employee["hire_date"])
                                            ? htmlspecialchars(
                                                $employee["hire_date"]
                                            )
                                            : "-"
                                        ?>

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
                                        <div class="action-buttons">

                                            <!-- Edit -->
                                            <a
                                                href="employees/employee-edit.php?id=<?= (int) $employee["employee_id"] ?>"
                                                class="btn-small edit">
                                                แก้ไข
                                            </a>


                                            <!-- Activate / Deactivate -->
                                            <?php if ($employee["status"] === "Active"): ?>

                                                <a
                                                    href="employees/employee-toggle.php?id=<?= (int) $employee["employee_id"] ?>&action=deactivate"
                                                    class="btn-small danger"
                                                    onclick="return confirm('Deactivate this employee?');">
                                                    Deactivate
                                                </a>

                                            <?php else: ?>

                                                <a
                                                    href="employees/employee-toggle.php?id=<?= (int) $employee["employee_id"] ?>&action=activate"
                                                    class="btn-small activate"
                                                    onclick="return confirm('Activate this employee?');">
                                                    Activate
                                                </a>

                                            <?php endif; ?>


                                            <!-- Delete -->
                                            <a
                                                href="employees/employee-delete.php?id=<?= (int) $employee["employee_id"] ?>"
                                                class="btn-small danger"
                                                onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบพนักงานคนนี้? ข้อมูลจะถูกลบถาวร');">
                                                ลบ
                                            </a>

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
