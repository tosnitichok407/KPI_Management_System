<?php

session_start();
require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$roleId = (int) ($_SESSION["role_id"] ?? 0);

if (!in_array($roleId, [1, 2], true)) {
    header("Location: ../employee/index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}


/*
|--------------------------------------------------------------------------
| Get Periods / Departments
|--------------------------------------------------------------------------
*/

$periods = $pdo->query("
    SELECT
        period_id,
        period_name,
        start_date,
        end_date,
        status
    FROM evaluation_periods
    ORDER BY start_date DESC, period_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("
    SELECT
        department_id,
        department_name
    FROM departments
    ORDER BY department_name
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$periodId = (int) (
    $_GET["period_id"]
    ?? ($periods[0]["period_id"] ?? 0)
);

$departmentId = (int) (
    $_GET["department_id"]
    ?? ($_SESSION["department_id"] ?? 0)
);

if ($roleId === 2 && $departmentId <= 0) {
    $departmentId = (int) ($_SESSION["department_id"] ?? 0);
}


/*
|--------------------------------------------------------------------------
| Selected Period
|--------------------------------------------------------------------------
*/

$selectedPeriod = null;

foreach ($periods as $period) {

    if ((int) $period["period_id"] === $periodId) {

        $selectedPeriod = $period;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| Employee Filter
|--------------------------------------------------------------------------
*/

$where = "e.status = 'Active'";

$params = [];

if ($departmentId > 0) {

    $where .= " AND e.department_id = :department_id";

    $params[":department_id"] = $departmentId;
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = [
    "employees" => 0,
    "assigned" => 0,
    "evaluated" => 0,
    "average_score" => 0
];

if ($periodId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT e.employee_id) AS employees,

            COUNT(DISTINCT ka.assignment_id) AS assigned,

            COUNT(
                DISTINCT CASE
                    WHEN ek.score IS NOT NULL
                    THEN ek.employee_kpi_id
                END
            ) AS evaluated,

            COALESCE(AVG(ek.score), 0) AS average_score

        FROM employees e

        LEFT JOIN kpi_assignments ka
            ON ka.employee_id = e.employee_id
            AND ka.period_id = :period_id
            AND ka.status = 'Active'

        LEFT JOIN employee_kpi ek
            ON ek.employee_id = e.employee_id
            AND ek.kpi_id = ka.kpi_id
            AND ek.period_id = ka.period_id

        WHERE {$where}
    ");

    $stmt->execute(
        array_merge(
            [":period_id" => $periodId],
            $params
        )
    );

    $summary = array_merge(
        $summary,
        $stmt->fetch(PDO::FETCH_ASSOC) ?: []
    );
}


/*
|--------------------------------------------------------------------------
| Employee Performance
|--------------------------------------------------------------------------
*/

$employeeStmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,

        p.position_name,

        COALESCE(AVG(ek.score), 0) AS average_score,

        COUNT(
            DISTINCT ka.assignment_id
        ) AS total_kpi,

        COUNT(
            DISTINCT CASE
                WHEN ek.score IS NOT NULL
                THEN ek.employee_kpi_id
            END
        ) AS evaluated_kpi

    FROM employees e

    LEFT JOIN positions p
        ON p.position_id = e.position_id

    LEFT JOIN kpi_assignments ka
        ON ka.employee_id = e.employee_id
        AND ka.period_id = :period_id
        AND ka.status = 'Active'

    LEFT JOIN employee_kpi ek
        ON ek.employee_id = e.employee_id
        AND ek.kpi_id = ka.kpi_id
        AND ek.period_id = ka.period_id

    WHERE {$where}

    GROUP BY
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        p.position_name

    ORDER BY
        average_score DESC,
        e.first_name ASC
");

$employeeStmt->execute(
    array_merge(
        [":period_id" => $periodId],
        $params
    )
);

$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Department Performance
|--------------------------------------------------------------------------
*/

$departmentRows = [];

if ($periodId > 0) {

    $departmentStmt = $pdo->prepare("
        SELECT
            d.department_id,
            d.department_name,

            COUNT(
                DISTINCT e.employee_id
            ) AS employee_count,

            COALESCE(
                AVG(ek.score),
                0
            ) AS average_score,

            COUNT(
                DISTINCT CASE
                    WHEN ek.score IS NOT NULL
                    THEN ek.employee_kpi_id
                END
            ) AS evaluated_count

        FROM departments d

        LEFT JOIN employees e
            ON e.department_id = d.department_id
            AND e.status = 'Active'

        LEFT JOIN kpi_assignments ka
            ON ka.employee_id = e.employee_id
            AND ka.period_id = :period_id
            AND ka.status = 'Active'

        LEFT JOIN employee_kpi ek
            ON ek.employee_id = e.employee_id
            AND ek.kpi_id = ka.kpi_id
            AND ek.period_id = ka.period_id

        GROUP BY
            d.department_id,
            d.department_name

        ORDER BY
            average_score DESC,
            d.department_name
    ");

    $departmentStmt->execute([
        ":period_id" => $periodId
    ]);

    $departmentRows =
        $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
}


/*
|--------------------------------------------------------------------------
| Calculations
|--------------------------------------------------------------------------
*/

$completion =
    (int) $summary["assigned"] > 0
    ? round(
        (
            (int) $summary["evaluated"]
            /
            (int) $summary["assigned"]
        ) * 100
    )
    : 0;

$managerName = trim(
    ($_SESSION["first_name"] ?? "")
        . " "
        . ($_SESSION["last_name"] ?? "")
);

$managerInitial =
    mb_strtoupper(
        mb_substr(
            $managerName ?: "M",
            0,
            1
        )
    );

$flash = $_GET["saved"] ?? "";


/*
|--------------------------------------------------------------------------
| Employees Need Follow-up
|--------------------------------------------------------------------------
*/

$employeesWithoutEvaluation = count(
    array_filter(
        $employees,
        fn($employee) =>
        (int) $employee["evaluated_kpi"] === 0
    )
);

$lowPerformance = count(
    array_filter(
        $employees,
        fn($employee) =>
        (float) $employee["average_score"] > 0
            &&
            (float) $employee["average_score"] < 60
    )
);

$highPerformance = count(
    array_filter(
        $employees,
        fn($employee) =>
        (float) $employee["average_score"] >= 80
    )
);

?>

<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Manager Dashboard | KPI System</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../assets/css/manager.css?v=layout-20260911-2">

</head>


<body>


    <!-- =========================================================
     SIDEBAR
========================================================= -->

    <aside class="sidebar" id="sidebar">

        <div class="sidebar-logo">

            <img
                src="../assets/images/Advance-Logo.png"
                alt="Advance Asia Group">

            <div>

                <h2>KPI System</h2>

                <span>Manager Workspace</span>

            </div>

        </div>


        <nav class="sidebar-nav">

            <a class="nav-item active" href="#overview">
                <span class="nav-icon">◉</span>
                <span>ภาพรวมผลงาน</span>
            </a>

            <a class="nav-item" href="#departments">
                <span class="nav-icon">▦</span>
                <span>ผลรายแผนก</span>
            </a>

            <a class="nav-item" href="#employees">
                <span class="nav-icon">♙</span>
                <span>ผลงานพนักงาน</span>
            </a>

        </nav>
        <div class="sidebar-bottom">

            <a
                href="../logout.php"
                class="logout-button">

                <span>↪</span>

                <span>ออกจากระบบ</span>

            </a>

        </div>

    </aside>

    <!-- =========================================================
     MOBILE OVERLAY
========================================================= -->

    <div
        class="mobile-menu-overlay"
        id="mobileOverlay"></div>

    <!-- =========================================================
     MAIN CONTENT
========================================================= -->

    <main class="main-content">
        <section id="overview">

            <!-- เนื้อหาภาพรวม -->

        </section>

        <section id="departments">

            <!-- ผลรายแผนก -->

        </section>

        <section id="employees">

            <!-- ผลงานพนักงาน -->

        </section>

        <!-- =====================================================
         TOPBAR
    ====================================================== -->

        <div class="topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton">
                ☰
            </button>

            <div class="topbar-title">

                <h1>
                    ภาพรวมผลการปฏิบัติงาน
                </h1>

                <p>
                    ติดตามผลการประเมินและผลงานของทีม
                </p>

            </div>

            <div class="user-info">

                <div class="user-avatar">

                    <?= e($managerInitial) ?>

                </div>

                <div class="user-detail">

                    <strong>
                        <?= e($managerName ?: "ผู้จัดการ") ?>
                    </strong>

                    <span>
                        Manager
                    </span>

                </div>

            </div>

        </div>


        <!-- =====================================================
         WELCOME
    ====================================================== -->

        <section class="welcome-section">

            <h2>
                <?= e($managerName ?: "ผู้จัดการ") ?>
            </h2>

            <p>
                ดูภาพรวมผลการปฏิบัติงานของพนักงาน
                และติดตามความคืบหน้าของการประเมิน KPI
            </p>

        </section>


        <?php if ($flash === "1"): ?>

            <div class="alert success">

                บันทึก Feedback
                และผลการประเมินเรียบร้อยแล้ว

            </div>

        <?php endif; ?>


        <!-- =====================================================
         FILTER
    ====================================================== -->

        <section class="dashboard-section">

            <div class="section-header">

                <h2>
                    ตัวกรองข้อมูล
                </h2>

                <p>
                    เลือกรอบการประเมินและแผนกที่ต้องการดู
                </p>

            </div>


            <form
                class="filter-card"
                method="get">

                <div class="filter-group">

                    <label>
                        รอบการประเมิน
                    </label>

                    <select
                        name="period_id"
                        onchange="this.form.submit()">

                        <?php foreach ($periods as $period): ?>

                            <option
                                value="<?= (int) $period["period_id"] ?>"
                                <?= (int) $period["period_id"] === $periodId
                                    ? "selected"
                                    : ""
                                ?>>

                                <?= e($period["period_name"]) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-group">

                    <label>
                        แผนก
                    </label>

                    <select
                        name="department_id"
                        onchange="this.form.submit()">

                        <option value="0">
                            ทุกแผนก
                        </option>

                        <?php foreach ($departments as $department): ?>

                            <option
                                value="<?= (int) $department["department_id"] ?>"
                                <?= (int) $department["department_id"] === $departmentId
                                    ? "selected"
                                    : ""
                                ?>>

                                <?= e($department["department_name"]) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="period-info">

                    <span>ช่วงเวลา</span>

                    <strong>

                        <?=
                        $selectedPeriod
                            ? e($selectedPeriod["start_date"])
                            . " - "
                            . e($selectedPeriod["end_date"])
                            : "ยังไม่มีรอบประเมิน"
                        ?>

                    </strong>

                </div>

            </form>

        </section>


        <!-- =====================================================
         SUMMARY
    ====================================================== -->

        <section class="dashboard-section">

            <div class="section-header">

                <h2>
                    สรุปผลการประเมิน
                </h2>

                <p>
                    ข้อมูลภาพรวมของทีมในรอบการประเมินที่เลือก
                </p>

            </div>


            <div class="dashboard-grid">


                <!-- Average -->

                <article class="dashboard-card">

                    <div class="card-icon">
                        ↗
                    </div>

                    <div>

                        <h3>
                            คะแนนเฉลี่ยทีม
                        </h3>

                        <strong class="summary-number">

                            <?= number_format(
                                (float) $summary["average_score"],
                                1
                            ) ?>

                        </strong>

                        <p>
                            จากคะแนนเต็ม 100
                        </p>

                    </div>

                </article>


                <!-- Employees -->

                <article class="dashboard-card">

                    <div class="card-icon">
                        ♙
                    </div>

                    <div>

                        <h3>
                            พนักงานในมุมมอง
                        </h3>

                        <strong class="summary-number">

                            <?= number_format(
                                (int) $summary["employees"]
                            ) ?>

                        </strong>

                        <p>
                            คนที่ยังปฏิบัติงาน
                        </p>

                    </div>

                </article>


                <!-- Completion -->

                <article class="dashboard-card">

                    <div class="card-icon">
                        ✓
                    </div>

                    <div>

                        <h3>
                            ประเมินแล้ว
                        </h3>

                        <strong class="summary-number">

                            <?= $completion ?>%

                        </strong>

                        <p>

                            <?= (int) $summary["evaluated"] ?>

                            จาก

                            <?= (int) $summary["assigned"] ?>

                            KPI

                        </p>

                    </div>

                </article>


                <!-- Need Follow Up -->

                <article class="dashboard-card warning-card">

                    <div class="card-icon">
                        !
                    </div>

                    <div>

                        <h3>
                            ต้องติดตาม
                        </h3>

                        <strong class="summary-number">

                            <?= $employeesWithoutEvaluation ?>

                        </strong>

                        <p>
                            พนักงานที่ยังไม่มีผลประเมิน
                        </p>

                    </div>

                </article>


            </div>

        </section>


        <!-- =====================================================
         DEPARTMENT
    ====================================================== -->

        <section
            class="dashboard-section"
            id="departments">

            <div class="section-header">

                <h2>
                    ภาพรวมแต่ละแผนก
                </h2>

                <p>
                    เปรียบเทียบคะแนนเฉลี่ยของแต่ละแผนก
                </p>

            </div>


            <div class="content-grid">


                <div class="dashboard-panel department-panel">

                    <?php foreach ($departmentRows as $department): ?>

                        <?php

                        $score = min(
                            100,
                            (float) $department["average_score"]
                        );

                        ?>

                        <div class="department-row">


                            <div class="department-info">

                                <strong>

                                    <?= e(
                                        $department["department_name"]
                                    ) ?>

                                </strong>

                                <span>

                                    <?= (int) $department["employee_count"] ?>

                                    คน

                                    ·

                                    ประเมินแล้ว

                                    <?= (int) $department["evaluated_count"] ?>

                                    รายการ

                                </span>

                            </div>


                            <div class="department-bar">

                                <span
                                    style="width: <?= $score ?>%"></span>

                            </div>


                            <strong class="department-score">

                                <?= number_format(
                                    $score,
                                    1
                                ) ?>

                            </strong>


                        </div>

                    <?php endforeach; ?>


                    <?php if (!$departmentRows): ?>

                        <p class="empty">
                            ยังไม่มีข้อมูลผลการประเมินในรอบนี้
                        </p>

                    <?php endif; ?>

                </div>


                <!-- =================================================
                 INSIGHT
            ================================================== -->

                <div class="dashboard-panel insight-panel">

                    <div class="panel-title">

                        <h2>
                            จุดที่ควรใส่ใจ
                        </h2>

                        <span>
                            Team Signal
                        </span>

                    </div>


                    <div class="signal">

                        <span class="signal-icon pending">
                            !
                        </span>

                        <div>

                            <strong>

                                <?=
                                max(
                                    0,
                                    (int) $summary["assigned"]
                                        -
                                        (int) $summary["evaluated"]
                                )
                                ?>

                                KPI

                            </strong>

                            <p>
                                รอการประเมินจากทีม
                            </p>

                        </div>

                    </div>


                    <div class="signal">

                        <span class="signal-icon warning">
                            !
                        </span>

                        <div>

                            <strong>
                                <?= $lowPerformance ?> คน
                            </strong>

                            <p>
                                ควรนัดพูดคุยเพิ่มเติม
                            </p>

                        </div>

                    </div>


                    <div class="signal">

                        <span class="signal-icon success">
                            ✓
                        </span>

                        <div>

                            <strong>
                                <?= $highPerformance ?> คน
                            </strong>

                            <p>
                                ผลงานโดดเด่น
                            </p>

                        </div>

                    </div>

                </div>


            </div>

        </section>


        <!-- =====================================================
         EMPLOYEE PERFORMANCE
    ====================================================== -->

        <section
            class="dashboard-section"
            id="employees">

            <div class="section-header">

                <h2>
                    ผลงานของพนักงาน
                </h2>

                <p>
                    รายละเอียดคะแนนและความคืบหน้าของพนักงาน
                </p>

            </div>


            <div class="dashboard-panel employee-panel">


                <div class="panel-header">

                    <div>

                        <h2>
                            รายชื่อพนักงาน
                        </h2>

                        <p>
                            <?= count($employees) ?> คน
                        </p>

                    </div>

                </div>


                <div class="table-container">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    พนักงาน
                                </th>

                                <th>
                                    ตำแหน่ง
                                </th>

                                <th>
                                    ความคืบหน้า
                                </th>

                                <th>
                                    คะแนนเฉลี่ย
                                </th>

                                <th>
                                    สถานะ
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($employees as $employee): ?>

                                <?php

                                $score =
                                    (float) $employee["average_score"];

                                $progress =
                                    (int) $employee["total_kpi"] > 0
                                    ? round(
                                        (
                                            (int) $employee["evaluated_kpi"]
                                            /
                                            (int) $employee["total_kpi"]
                                        ) * 100
                                    )
                                    : 0;


                                $status =
                                    $score >= 80
                                    ? ["โดดเด่น", "good"]
                                    : (
                                        $score > 0 && $score < 60
                                        ? ["ควรติดตาม", "needs"]
                                        : (
                                            $progress < 100
                                            ? ["รอประเมิน", "pending"]
                                            : ["อยู่ในเกณฑ์", "steady"]
                                        )
                                    );

                                ?>

                                <tr>


                                    <!-- Employee -->

                                    <td>

                                        <div class="person">

                                            <span class="person-avatar">

                                                <?= e(
                                                    mb_strtoupper(
                                                        mb_substr(
                                                            $employee["first_name"],
                                                            0,
                                                            1
                                                        )
                                                    )
                                                ) ?>

                                            </span>


                                            <div>

                                                <strong>

                                                    <?= e(
                                                        $employee["first_name"]
                                                            . " "
                                                            . $employee["last_name"]
                                                    ) ?>

                                                </strong>

                                                <small>

                                                    <?= e(
                                                        $employee["employee_code"]
                                                    ) ?>

                                                </small>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- Position -->

                                    <td>

                                        <?= e(
                                            $employee["position_name"]
                                                ?: "ไม่ระบุตำแหน่ง"
                                        ) ?>

                                    </td>


                                    <!-- Progress -->

                                    <td>

                                        <div class="progress-info">

                                            <span>
                                                <?= $progress ?>%
                                            </span>

                                            <small>

                                                <?= (int) $employee["evaluated_kpi"] ?>

                                                /

                                                <?= (int) $employee["total_kpi"] ?>

                                                KPI

                                            </small>

                                        </div>


                                        <div class="mini-progress">

                                            <span
                                                style="width: <?= $progress ?>%"></span>

                                        </div>

                                    </td>


                                    <!-- Score -->

                                    <td>

                                        <strong
                                            class="table-score <?= e($status[1]) ?>">

                                            <?= number_format(
                                                $score,
                                                1
                                            ) ?>

                                        </strong>

                                    </td>


                                    <!-- Status -->

                                    <td>

                                        <span
                                            class="status <?= e($status[1]) ?>">

                                            <?= e($status[0]) ?>

                                        </span>

                                    </td>


                                    <!-- Feedback -->

                                    <td>

                                        <button
                                            type="button"
                                            class="feedback-btn"
                                            data-id="<?= (int) $employee["employee_id"] ?>"
                                            data-name="<?= e(
                                                            $employee["first_name"]
                                                                . " "
                                                                . $employee["last_name"]
                                                        ) ?>">

                                            Feedback

                                        </button>

                                    </td>


                                </tr>

                            <?php endforeach; ?>


                        </tbody>

                    </table>


                    <?php if (!$employees): ?>

                        <p class="empty">
                            ไม่พบพนักงานในตัวกรองนี้
                        </p>

                    <?php endif; ?>


                </div>

            </div>

        </section>


        <!-- =====================================================
         FOOTER
    ====================================================== -->

        <footer class="dashboard-footer">

            <span>
                KPI Management System
            </span>

            <span>
                Advance Asia Group
            </span>

        </footer>


    </main>


    <!-- =========================================================
     FEEDBACK DIALOG
========================================================= -->

    <dialog id="feedbackDialog">

        <form
            method="post"
            action="feedback.php">

            <button
                type="button"
                class="dialog-close"
                onclick="feedbackDialog.close()">
                ×
            </button>


            <h2>
                Feedback & การประเมิน
            </h2>


            <p
                class="dialog-person"
                id="dialogPerson"></p>


            <input
                type="hidden"
                name="employee_id"
                id="employeeId">


            <input
                type="hidden"
                name="period_id"
                value="<?= $periodId ?>">


            <label>

                คะแนนประเมิน (0-100)

                <input
                    type="number"
                    name="evaluation_score"
                    min="0"
                    max="100"
                    step="0.1"
                    required>

            </label>


            <label>

                Feedback ถึงพนักงาน

                <textarea
                    name="feedback"
                    rows="5"
                    placeholder="เขียนข้อเสนอแนะ จุดแข็ง และสิ่งที่ควรพัฒนา"
                    required></textarea>

            </label>


            <button
                class="primary-btn"
                type="submit">

                บันทึกผลการประเมิน

            </button>

        </form>

    </dialog>


    <!-- =========================================================
     JAVASCRIPT
========================================================= -->

    <script>
        const sidebar =
            document.getElementById("sidebar");

        const mobileMenuButton =
            document.getElementById("mobileMenuButton");

        const mobileOverlay =
            document.getElementById("mobileOverlay");


        function openMobileMenu() {

            sidebar.classList.add("mobile-open");

            mobileOverlay.classList.add("active");

        }


        function closeMobileMenu() {

            sidebar.classList.remove("mobile-open");

            mobileOverlay.classList.remove("active");

        }


        mobileMenuButton.addEventListener(
            "click",
            openMobileMenu
        );


        mobileOverlay.addEventListener(
            "click",
            closeMobileMenu
        );


        document
            .querySelectorAll(".sidebar-nav .nav-item")
            .forEach((item) => {

                item.addEventListener(
                    "click",
                    closeMobileMenu
                );

            });


        /*
        |--------------------------------------------------------------------------
        | Feedback Dialog
        |--------------------------------------------------------------------------
        */

        const feedbackDialog =
            document.getElementById("feedbackDialog");


        document
            .querySelectorAll(".feedback-btn")
            .forEach((button) => {

                button.addEventListener(
                    "click",
                    () => {

                        document.getElementById(
                            "employeeId"
                        ).value = button.dataset.id;


                        document.getElementById(
                                "dialogPerson"
                            ).textContent =
                            button.dataset.name;


                        feedbackDialog.showModal();

                    }
                );

            });

        
    </script>

</body>

</html>
