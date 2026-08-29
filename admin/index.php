<?php
session_start();
/*
|--------------------------------------------------------------------------
| Authentication Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Admin Access Check
|--------------------------------------------------------------------------
*/

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| User Information
|--------------------------------------------------------------------------
*/

$firstName = $_SESSION["first_name"] ?? "Administrator";
$lastName = $_SESSION["last_name"] ?? "";
$employeeCode = $_SESSION["employee_code"] ?? "-";
$roleName = $_SESSION["role_name"] ?? "admin";
$department = $_SESSION["department"] ?? "-";

$fullName = trim($firstName . " " . $lastName);

?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <title>
        Admin Dashboard | KPI Management System
    </title>

    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Dashboard CSS -->
    <link
        rel="stylesheet"
        href="../assets/css/admin.css">

</head>

<body>
    <!-- Mobile Menu Overlay -->
    <div
        class="mobile-menu-overlay"
        id="mobileMenuOverlay">
    </div>

    <!-- === SIDEBAR === -->
    <aside class="sidebar">

        <!-- Logo -->
        <div class="sidebar-logo">
            <img
                src="../assets/images/Advance-Logo.png"
                alt="Advance Asia Group Logo">

            <div>
                <h2>
                    KPI System
                </h2>

                <span>
                    Admin Panel
                </span>

            </div>
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav">
            <a
                href="index.php"
                class="nav-item active">

                <span class="nav-icon">
                    🏠
                </span>

                <span>
                    Dashboard
                </span>
            </a>

            <a
                href="employees/employees.php"
                class="nav-item">

                <span class="nav-icon">
                    👥
                </span>

                <span>
                    Employee Management
                </span>
            </a>

            <a
                href="user-accounts/user-accounts.php"
                class="nav-item">
                <span class="nav-icon">
                    🔐
                </span>

                <span>
                    User Account Management
                </span>
            </a>

            <a
                href="kpi-categories/kpi-categories.php"
                class="nav-item">

                <span class="nav-icon">
                    📂
                </span>

                <span>
                    KPI Categories
                </span>
            </a>

            <a
                href="kpi/kpi-management.php"
                class="nav-item">

                <span class="nav-icon">
                    🎯
                </span>

                <span>
                    KPI Management
                </span>
            </a>

            <a
                href="evaluation/evaluation-periods.php"
                class="nav-item">

                <span class="nav-icon">
                    📅
                </span>

                <span>
                    Evaluation Periods
                </span>
            </a>


            <a
                href="kpi-assignment/kpi-assignment.php"
                class="nav-item">

                <span class="nav-icon">
                    📋
                </span>

                <span>
                    KPI Assignments
                </span>
            </a>


            <a
                href="performance/index.php"
                class="nav-item">

                <span class="nav-icon">
                    📊
                </span>

                <span>
                    Performance Summary
                </span>

            </a>


            <div class="nav-divider"></div>

            <a
                href="login-logs/index.php"
                class="nav-item">

                <span class="nav-icon">
                    📝
                </span>

                <span>
                    Login Logs
                </span>
            </a>

        </nav>

        <!-- Sidebar Bottom -->

        <div class="sidebar-bottom">

            <a
                href="../logout.php"
                class="logout-button">

                <span>
                    🚪
                </span>
                Logout
            </a>
        </div>
    </aside>

    <!-- === MAIN CONTENT === -->
    <main class="main-content">

        <!-- === TOP BAR === -->
        <header class="topbar">

            <!-- Mobile Menu Button -->
            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open navigation menu"
                aria-expanded="false">
                ☰
            </button>

            <div>
                <h1>
                    Admin Dashboard
                </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?= htmlspecialchars(
                            strtoupper(substr($firstName, 0, 1)),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </div>

                    <div class="user-detail">
                        <strong>
                            <?= htmlspecialchars(
                                $fullName,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </strong>

                        <span>
                            <?= htmlspecialchars(
                                $employeeCode,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>
                    </div>
                </div>
        </header>

        <!-- === WELCOME === -->
        <section class="welcome-section">
            <div>
                <h2>
                    Welcome, <?= htmlspecialchars(
                                    $firstName,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                </h2>

                <p>
                    ยินดีต้อนรับเข้าสู่ระบบบริหารและติดตาม KPI
                </p>
            </div>

        </section>

        <!-- === USER INFORMATION === -->
        <section class="user-card">
            <div class="user-card-item">
                <span>
                    Employee ID
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $employeeCode,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </strong>
            </div>

            <div class="user-card-item">
                <span>
                    Department
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $department,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </strong>
            </div>

            <div class="user-card-item">

                <span>
                    Role
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $roleName,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </strong>
            </div>
        </section>

        <!-- === MANAGEMENT MENU === -->
        <section class="dashboard-section">
            <div class="section-header">
                <div>
                    <h2>
                        System Management
                    </h2>

                    <p>
                        จัดการข้อมูลหลักของระบบ KPI
                    </p>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Employee -->
                <a
                    href="employees/employees.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        👥
                    </div>

                    <div>
                        <h3>
                            Employee Management
                        </h3>

                        <p>
                            จัดการข้อมูลพนักงาน
                        </p>
                    </div>
                </a>

                <!-- KPI -->
                <a
                    href="kpi/kpi-management.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        🎯
                    </div>

                    <div>
                        <h3>
                            KPI Management
                        </h3>

                        <p>
                            จัดการตัวชี้วัด KPI
                        </p>
                    </div>
                </a>

                <!-- Category -->
                <a
                    href="kpi-categories/kpi-categories.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        📂
                    </div>

                    <div>
                        <h3>
                            KPI Categories
                        </h3>

                        <p>
                            จัดการหมวดหมู่ KPI
                        </p>
                    </div>
                </a>

                <!-- Evaluation Period -->
                <a
                    href="evaluation/evaluation-periods.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        📅
                    </div>

                    <div>
                        <h3>
                            Evaluation Periods
                        </h3>

                        <p>
                            จัดการรอบการประเมิน
                        </p>
                    </div>

                </a>

                <!-- Assignment -->
                <a
                    href="kpi-assignment/kpi-assignment.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        📋
                    </div>

                    <div>
                        <h3>
                            KPI Assignments
                        </h3>

                        <p>
                            กำหนด KPI ให้พนักงาน
                        </p>
                    </div>

                </a>

                <!-- Performance -->

                <a
                    href="performance/index.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        📊
                    </div>

                    <div>
                        <h3>
                            Performance Summary
                        </h3>

                        <p>
                            สรุปผลการปฏิบัติงาน
                        </p>
                    </div>
                </a>

                <!-- Users -->
                <a
                    href="user-accounts/user-accounts.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        🔐
                    </div>

                    <div>
                        <h3>
                            User Management
                        </h3>

                        <p>
                            จัดการบัญชีผู้ใช้งาน
                        </p>
                    </div>

                </a>

                <!-- Login Logs -->
                <a
                    href="login-logs/index.php"
                    class="dashboard-card">

                    <div class="card-icon">
                        📝
                    </div>

                    <div>
                        <h3>
                            Login Logs
                        </h3>

                        <p>
                            ตรวจสอบประวัติการเข้าสู่ระบบ
                        </p>
                    </div>
                </a>
            </div>
        </section>

        <!-- === FOOTER === -->
        <footer class="dashboard-footer">
            <span>
                KPI Management System
            </span>

            <span>
                Version 1.0
            </span>
        </footer>
    </main>
    <script>
        const mobileMenuButton =
            document.getElementById("mobileMenuButton");

        const sidebar =
            document.querySelector(".sidebar");

        const mobileMenuOverlay =
            document.getElementById("mobileMenuOverlay");


        function openMobileMenu() {

            sidebar.classList.add("mobile-open");

            mobileMenuOverlay.classList.add("active");

            mobileMenuButton.setAttribute(
                "aria-expanded",
                "true"
            );

            mobileMenuButton.textContent = "✕";

        }


        function closeMobileMenu() {

            sidebar.classList.remove("mobile-open");

            mobileMenuOverlay.classList.remove("active");

            mobileMenuButton.setAttribute(
                "aria-expanded",
                "false"
            );

            mobileMenuButton.textContent = "☰";

        }


        mobileMenuButton.addEventListener(
            "click",
            function() {

                if (
                    sidebar.classList.contains(
                        "mobile-open"
                    )
                ) {

                    closeMobileMenu();

                } else {

                    openMobileMenu();

                }

            }
        );


        mobileMenuOverlay.addEventListener(
            "click",
            closeMobileMenu
        );


        window.addEventListener(
            "resize",
            function() {

                if (window.innerWidth > 650) {

                    closeMobileMenu();

                }

            }
        );
    </script>
</body>

</html>