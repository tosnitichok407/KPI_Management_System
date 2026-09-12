<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/includes/manager-data.php";


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
| Manager / Admin Access Check
|--------------------------------------------------------------------------
*/

$roleId = (int) ($_SESSION["role_id"] ?? 0);

if (!in_array($roleId, [1, 2], true)) {

    header("Location: ../employee/index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| User Information
|--------------------------------------------------------------------------
*/

$firstName = $_SESSION["first_name"] ?? "Manager";
$lastName = $_SESSION["last_name"] ?? "";
$employeeCode = $_SESSION["employee_code"] ?? "-";
$managerDepartmentId = (int) ($_SESSION["department_id"] ?? 0);

$fullName = trim($firstName . " " . $lastName);


/*
|--------------------------------------------------------------------------
| Page Routing
|--------------------------------------------------------------------------
|
| หน้าที่แสดงใน Content ด้านขวา (โครงสร้างเดียวกับ admin/index.php)
|
*/

$page = $_GET["page"] ?? "home";

$pages = [

    "home" =>
    "dashboard/dashboard.php",

    "departments" =>
    "departments/departments.php",

    "employees" =>
    "employees/employees.php"

];

if (!array_key_exists($page, $pages)) {

    $page = "home";
}

$contentPage = $pages[$page];


/*
|--------------------------------------------------------------------------
| Common Filters (ปี / เดือน) ใช้ร่วมกันทุกหน้า
|--------------------------------------------------------------------------
*/

$currentYear = (int) date("Y");

$filterYear = (int) ($_GET["year"] ?? $currentYear);

if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = $currentYear;
}

$filterMonth = (int) ($_GET["month"] ?? 0);

if ($filterMonth < 1 || $filterMonth > 12) {
    $filterMonth = 0;
}

$filterDepartment = (int) ($_GET["department_id"] ?? 0);

$monthNames = monthlyPeriodMonths();


/* ปีที่มีข้อมูล (สำหรับ dropdown) */

$availableYears = array_map("intval", $pdo->query("
    SELECT DISTINCT assignment_year FROM kpi_assignments
    UNION
    SELECT DISTINCT period_year FROM evaluation_periods
")->fetchAll(PDO::FETCH_COLUMN));

$availableYears[] = $currentYear;
$availableYears[] = $filterYear;
$availableYears = array_unique($availableYears);
rsort($availableYears);


/* ข้อมูลของปีที่เลือก */

$yearData = managerLoadYearData($pdo, $filterYear);

$selectedPeriod = $filterMonth > 0
    ? ($yearData["periods"][$filterMonth] ?? null)
    : null;

$filterLabel = $filterMonth > 0
    ? $monthNames[$filterMonth] . " " . $filterYear . " (" . getQuarterByMonth($filterMonth) . ")"
    : "ปี " . $filterYear . " (ทุกเดือน)";


/* Flash message (หลังบันทึก Feedback) */

$flash = $_GET["saved"] ?? "";

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Manager | KPI Management System
    </title>


    <!-- Kanit -->

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">


    <!-- Shared CSS (โครงสร้างเดียวกับ Admin) -->

    <link rel="stylesheet" href="../assets/css/admin.css?v=layout-20260911-2">
    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/forms.css">
    <link rel="stylesheet" href="../assets/css/tables.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/manager.css?v=manager-v3">


    <?php if ($page === "home"): ?>

        <!-- Chart.js -->

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <?php endif; ?>

</head>


<body>


    <!-- =========================================================
     MOBILE OVERLAY
========================================================= -->

    <div
        class="mobile-menu-overlay"
        id="mobileMenuOverlay">
    </div>


    <!-- =========================================================
     SIDEBAR
========================================================= -->

    <?php include __DIR__ . "/includes/sidebar.php"; ?>


    <!-- =========================================================
     MAIN
========================================================= -->

    <main class="main-content">


        <!-- =====================================================
         TOPBAR
    ====================================================== -->

        <header class="topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open navigation menu"
                aria-expanded="false">

                ☰

            </button>

            <div class="user-info">

                <div class="user-avatar">
                    <?= htmlspecialchars(mb_substr($firstName, 0, 1, "UTF-8"), ENT_QUOTES, "UTF-8") ?>
                </div>

                <div class="user-detail">

                    <strong>
                        <?= htmlspecialchars($fullName, ENT_QUOTES, "UTF-8") ?>
                    </strong>

                    <span>
                        <?= htmlspecialchars($employeeCode, ENT_QUOTES, "UTF-8") ?>
                    </span>

                </div>

            </div>

        </header>


        <!-- =====================================================
         CONTENT
    ====================================================== -->

        <section class="page-content">

            <?php if ($flash !== ""): ?>

                <div class="page-container">

                    <div class="alert <?= $flash === "1" ? "alert-success" : "alert-error" ?>">
                        <?= $flash === "1"
                            ? "ส่ง Feedback เรียบร้อยแล้ว · พนักงานจะเห็นในเมนู \"Feedback จากหัวหน้า\""
                            : "ไม่สามารถบันทึก Feedback ได้ กรุณาตรวจสอบข้อมูลอีกครั้ง" ?>
                    </div>

                </div>

            <?php endif; ?>

            <?php include __DIR__ . "/" . $contentPage; ?>

        </section>


    </main>


    <!-- =========================================================
     MOBILE MENU
========================================================= -->

    <script>
        const mobileMenuButton = document.getElementById("mobileMenuButton");
        const sidebar = document.querySelector(".sidebar");
        const mobileMenuOverlay = document.getElementById("mobileMenuOverlay");

        function openMobileMenu() {
            sidebar.classList.add("mobile-open");
            mobileMenuOverlay.classList.add("active");
            mobileMenuButton.setAttribute("aria-expanded", "true");
            mobileMenuButton.textContent = "✕";
        }

        function closeMobileMenu() {
            sidebar.classList.remove("mobile-open");
            mobileMenuOverlay.classList.remove("active");
            mobileMenuButton.setAttribute("aria-expanded", "false");
            mobileMenuButton.textContent = "☰";
        }

        if (mobileMenuButton) {
            mobileMenuButton.addEventListener("click", function () {
                if (sidebar.classList.contains("mobile-open")) {
                    closeMobileMenu();
                } else {
                    openMobileMenu();
                }
            });
        }

        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener("click", closeMobileMenu);
        }

        window.addEventListener("resize", function () {
            if (window.innerWidth > 650) {
                closeMobileMenu();
            }
        });

        document.querySelectorAll(".nav-item").forEach(function (item) {
            item.addEventListener("click", function () {
                if (window.innerWidth <= 650) {
                    closeMobileMenu();
                }
            });
        });
    </script>

    <script src="../assets/js/admin.js?v=scroll-3"></script>

</body>

</html>
