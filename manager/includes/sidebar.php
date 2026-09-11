<?php

$currentPage =
    $_GET["page"] ?? "home";

?>


<aside class="sidebar">


    <!-- =====================================================
         LOGO
    ====================================================== -->

    <div class="sidebar-logo">

        <img
            src="../assets/images/Advance-Logo.png"
            alt="Advance Asia Group Logo">

        <div>

            <h2>
                KPI System
            </h2>

            <span>
                สำหรับหัวหน้างาน
            </span>

        </div>

    </div>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <nav class="sidebar-nav">


        <!-- หน้าแรก / Dashboard -->

        <a
            href="index.php?page=home"
            class="nav-item <?= $currentPage === "home" ? "active" : "" ?>"
        >

            <span class="nav-icon">
                🏠
            </span>

            <span>
                หน้าแรก
            </span>

        </a>


        <!-- รายแผนก -->

        <a
            href="index.php?page=departments"
            class="nav-item <?= $currentPage === "departments" ? "active" : "" ?>"
        >

            <span class="nav-icon">
                🏢
            </span>

            <span>
                ผลงานรายแผนก
            </span>

        </a>


        <!-- รายพนักงาน -->

        <a
            href="index.php?page=employees"
            class="nav-item <?= $currentPage === "employees" ? "active" : "" ?>"
        >

            <span class="nav-icon">
                👥
            </span>

            <span>
                ผลงานรายพนักงาน
            </span>

        </a>


    </nav>


    <!-- =====================================================
         LOGOUT
    ====================================================== -->

    <div class="sidebar-bottom">

        <a
            href="../logout.php"
            class="logout-button">

            ออกจากระบบ

        </a>

    </div>


</aside>
