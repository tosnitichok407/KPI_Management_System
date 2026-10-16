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
        p.position_name,
        /* ประวัติ KPI + Feedback (ผู้รับและผู้ให้) · ใช้ปิดปุ่มลบ (ฝั่ง server ตรวจซ้ำใน employee-delete.php) */
        (
            (SELECT COUNT(*) FROM kpi_performances kp WHERE kp.employee_id = e.employee_id)
            + (SELECT COUNT(*) FROM manager_feedback f
               WHERE f.employee_id = e.employee_id
                  OR f.manager_id IN (SELECT u.user_id FROM users u WHERE u.employee_id = e.employee_id))
        ) AS history_count

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

        error_log("admin/employees/employees: " . $e->getMessage());

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
                    <?= icon("plus") ?>เพิ่มพนักงาน
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
                        ค้นหา
                    </label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="รหัสพนักงาน, ชื่อ, นามสกุล, อีเมล">

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
                        <?= icon("search") ?>ค้นหา
                    </button>

                    <a
                        href="index.php?page=employees"
                        class="btn btn-secondary">
                        <?= icon("rotate-ccw") ?>ล้าง
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


            <?php if (($_GET["deleted"] ?? "") === "1"): ?>

                <div class="alert alert-success">
                    ลบพนักงานเรียบร้อยแล้ว
                </div>

            <?php endif; ?>


            <?php if (isset($_GET["error"]) && $_GET["error"] === "delete"): ?>
                <div class="alert alert-error">
                    ไม่สามารถลบพนักงานได้ เนื่องจากพนักงานคนนี้มีข้อมูลที่เกี่ยวข้องกับระบบอื่น
                </div>
            <?php endif; ?>

            <?php
            $employeeErrorMessages = [
                "has_history" => "ไม่สามารถลบพนักงานได้ เนื่องจากมีประวัติผลงาน KPI หรือ Feedback อยู่ในระบบ · ใช้ \"ปิดใช้งาน\" แทนเพื่อเก็บประวัติไว้",
                "self_delete" => "ไม่สามารถลบข้อมูลพนักงานของบัญชีที่กำลังใช้งานอยู่ได้",
                "self_deactivate" => "ไม่สามารถปิดใช้งานบัญชีที่กำลังใช้งานอยู่ได้",
                "busy" => "มีการบันทึกข้อมูลของพนักงานคนนี้อยู่ในขณะนี้ ระบบยังไม่ได้ลบ กรุณาลองใหม่อีกครั้ง",
            ];
            $employeeError = (string) ($_GET["error"] ?? "");
            ?>
            <?php if (isset($employeeErrorMessages[$employeeError])): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($employeeErrorMessages[$employeeError], ENT_QUOTES, "UTF-8") ?>
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
                                                <?= icon("square-pen") ?>แก้ไข
                                            </a>


                                            <!-- Activate / Deactivate -->
                                            <?php $isSelf = (int) $employee["employee_id"] === (int) ($_SESSION["employee_id"] ?? 0); ?>

                                            <?php if ($employee["status"] === "Active" && $isSelf): ?>

                                                <button type="button" class="btn-small danger" disabled
                                                    title="ไม่สามารถปิดใช้งานบัญชีที่กำลังใช้งานอยู่ได้">
                                                    <?= icon("user-x") ?>Deactivate
                                                </button>

                                            <?php elseif ($employee["status"] === "Active"): ?>

                                                <form
                                                    method="POST"
                                                    action="employees/employee-toggle.php"
                                                    class="inline-form"
                                                    onsubmit="return confirm('Deactivate this employee?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="id" value="<?= (int) $employee["employee_id"] ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn-small danger">
                                                        <?= icon("user-x") ?>Deactivate
                                                    </button>
                                                </form>

                                            <?php else: ?>

                                                <form
                                                    method="POST"
                                                    action="employees/employee-toggle.php"
                                                    class="inline-form"
                                                    onsubmit="return confirm('Activate this employee?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="id" value="<?= (int) $employee["employee_id"] ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn-small activate">
                                                        <?= icon("user-check") ?>Activate
                                                    </button>
                                                </form>

                                            <?php endif; ?>

                                            <!-- Delete: เฉพาะพนักงานที่ไม่มีประวัติ (server ตรวจซ้ำ) -->
                                            <?php if ((int) $employee["history_count"] > 0 || $isSelf): ?>

                                                <button type="button" class="btn-small danger" disabled
                                                    title="<?= $isSelf
                                                        ? "ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้"
                                                        : "มีประวัติผลงาน KPI หรือ Feedback · ใช้ปิดใช้งานแทนเพื่อเก็บประวัติ" ?>">
                                                    <?= icon("trash-2") ?>ลบ
                                                </button>

                                            <?php else: ?>

                                            <form
                                                method="POST"
                                                action="employees/employee-delete.php"
                                                class="inline-form"
                                                onsubmit="return confirm('ลบพนักงานคนนี้ถาวรหรือไม่? บัญชีผู้ใช้และการมอบหมาย KPI ที่ยังไม่มีผลงานจะถูกลบด้วย (ระบบไม่อนุญาตให้ลบพนักงานที่มีประวัติ KPI หรือ Feedback)');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= (int) $employee["employee_id"] ?>">
                                                <button type="submit" class="btn-small danger">
                                                    <?= icon("trash-2") ?>ลบ
                                                </button>
                                            </form>

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
