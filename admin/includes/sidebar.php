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
                สำหรับผู้ดูแลระบบ
            </span>

        </div>


    </div>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <nav class="sidebar-nav">


        <!-- หน้าแรก -->

        <a
            href="index.php?page=home"
            class="nav-item
                <?= $currentPage === "home"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                🏠
            </span>

            <span>
                หน้าแรก
            </span>

        </a>


        <!-- พนักงาน -->

        <a
            href="index.php?page=employees"
            class="nav-item
                <?= $currentPage === "employees"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                👥
            </span>

            <span>
                จัดการพนักงาน
            </span>

        </a>


        <!-- บัญชีผู้ใช้ -->

        <a
            href="index.php?page=accounts"
            class="nav-item
                <?= $currentPage === "accounts"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                🔐
            </span>

            <span>
                จัดการบัญชีผู้ใช้
            </span>

        </a>


        <!-- หมวดหมู่ KPI -->

        <a
            href="index.php?page=kpi-categories"
            class="nav-item
                <?= $currentPage === "kpi-categories"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                📂
            </span>

            <span>
                หมวดหมู่ KPI
            </span>

        </a>


        <!-- จัดการ KPI -->

        <a
            href="index.php?page=kpi-management"
            class="nav-item
                <?= $currentPage === "kpi-management"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                🎯
            </span>

            <span>
                จัดการ KPI
            </span>

        </a>


        <!-- Evaluation -->

        <a
            href="index.php?page=evaluation"
            class="nav-item
                <?= $currentPage === "evaluation"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                📅
            </span>

            <span>
                ช่วงเวลาประเมิน
            </span>

        </a>


        <!-- KPI Assignment -->

        <a
            href="index.php?page=kpi-assignment"
            class="nav-item
                <?= $currentPage === "kpi-assignment"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                📋
            </span>

            <span>
                มอบหมาย KPI
            </span>

        </a>


        <!-- Summary -->

        <a
            href="index.php?page=summary"
            class="nav-item
                <?= $currentPage === "summary"
                    ? "active"
                    : "" ?>"
        >

            <span class="nav-icon">
                📊
            </span>

            <span>
                สรุปผลการประเมิน
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