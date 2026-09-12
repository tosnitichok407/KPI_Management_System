<?php

/*
|--------------------------------------------------------------------------
| Employee Layout (โครงสร้างเดียวกับ Admin)
|--------------------------------------------------------------------------
|
| ใช้ในทุกหน้าของพนักงาน:
|
|   <body>
|       <?php employeeLayoutStart("performance", "../"); ?>
|           ... เนื้อหาหน้า ...
|       <?php employeeLayoutEnd("../"); ?>
|   </body>
|
| - Sidebar / Topbar / page-container ใช้ class และขนาดเดียวกับ admin/index.php (admin.css)
| - $rootPath = path กลับไป root ของโปรเจกต์ ("../" สำหรับ employee/*.php, "../../" สำหรับ employee/kpi/*.php)
|
*/

function employeeLayoutStart(string $activeNav, string $rootPath = "../"): void
{
    $firstName = (string) ($_SESSION["first_name"] ?? "");
    $lastName = (string) ($_SESSION["last_name"] ?? "");
    $fullName = trim($firstName . " " . $lastName) ?: "พนักงาน";
    $employeeCode = (string) ($_SESSION["employee_code"] ?? "-");
    $initial = mb_substr($firstName !== "" ? $firstName : "E", 0, 1, "UTF-8");

    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, "UTF-8");

    $navItems = [
        "home" => ["employee/index.php", "🏠", "หน้าแรก"],
        "kpi" => ["employee/kpi/kpi.php", "🎯", "KPI ของฉัน"],
        "performance" => ["employee/performance.php", "📊", "ผลการปฏิบัติงาน"],
        "feedback" => ["employee/feedback.php", "💬", "Feedback จากหัวหน้า"],
        "profile" => ["employee/profile.php", "👤", "ข้อมูลส่วนตัว"]
    ];

    ?>

    <!-- =========================================================
         MOBILE OVERLAY
    ========================================================== -->

    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="sidebar">

        <div class="sidebar-logo">

            <img
                src="<?= $e($rootPath) ?>assets/images/Advance-Logo.png"
                alt="Advance Asia Group Logo">

            <div>

                <h2>
                    KPI System
                </h2>

                <span>
                    สำหรับพนักงาน
                </span>

            </div>

        </div>

        <nav class="sidebar-nav">

            <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>

                <a
                    href="<?= $e($rootPath . $href) ?>"
                    class="nav-item <?= $key === $activeNav ? "active" : "" ?>">

                    <span class="nav-icon">
                        <?= $icon ?>
                    </span>

                    <span>
                        <?= $label ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </nav>

        <div class="sidebar-bottom">

            <a
                href="<?= $e($rootPath) ?>logout.php"
                class="logout-button">

                ออกจากระบบ

            </a>

        </div>

    </aside>


    <!-- =========================================================
         MAIN
    ========================================================== -->

    <main class="main-content">

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
                    <?= $e($initial) ?>
                </div>

                <div class="user-detail">

                    <strong>
                        <?= $e($fullName) ?>
                    </strong>

                    <span>
                        <?= $e($employeeCode) ?>
                    </span>

                </div>

            </div>

        </header>

        <section class="page-container">

            <div class="page-container">

    <?php
}


function employeeLayoutEnd(string $rootPath = "../"): void
{
    ?>

            </div>

        </section>

    </main>


    <!-- =========================================================
         MOBILE MENU (เหมือน admin/index.php)
    ========================================================== -->

    <script>
        (function () {

            const mobileMenuButton = document.getElementById("mobileMenuButton");
            const sidebar = document.querySelector(".sidebar");
            const mobileMenuOverlay = document.getElementById("mobileMenuOverlay");

            if (!mobileMenuButton || !sidebar || !mobileMenuOverlay) {
                return;
            }

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

            mobileMenuButton.addEventListener("click", function () {
                if (sidebar.classList.contains("mobile-open")) {
                    closeMobileMenu();
                } else {
                    openMobileMenu();
                }
            });

            mobileMenuOverlay.addEventListener("click", closeMobileMenu);

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

        })();
    </script>

    <script src="<?= htmlspecialchars($rootPath, ENT_QUOTES, "UTF-8") ?>assets/js/admin.js?v=scroll-3"></script>

    <?php
}
