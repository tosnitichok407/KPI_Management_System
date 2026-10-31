<?php

if (!isset($pages)) {
    // ไฟล์นี้เป็น partial ต้อง include จาก manager/index.php เท่านั้น
    http_response_code(404);
    exit;
}

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
                <?= icon("house") ?>
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
                <?= icon("building-2") ?>
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
                <?= icon("users") ?>
            </span>

            <span>
                ผลงานรายพนักงาน
            </span>

        </a>


        <!-- คำขอแก้ไขข้อมูล (แสดงเมื่อรัน Migration 20261026 แล้ว) -->

        <?php if (function_exists("kpiCorrectionEnabled") && isset($pdo) && kpiCorrectionEnabled($pdo)): ?>
        <a
            href="index.php?page=corrections"
            class="nav-item <?= $currentPage === "corrections" ? "active" : "" ?>"
        >

            <span class="nav-icon">
                <?= icon("square-pen") ?>
            </span>

            <span>
                คำขอแก้ไขข้อมูล
            </span>

        </a>
        <?php endif; ?>


        <!-- KPI12 รับคืนสัญญาตัวจริง (แสดงเมื่อรัน Migration 20261028 แล้ว) -->

        <?php if (function_exists("kpiContractReturnEnabled") && isset($pdo) && kpiContractReturnEnabled($pdo)): ?>
        <a
            href="index.php?page=contract-returns"
            class="nav-item <?= $currentPage === "contract-returns" ? "active" : "" ?>"
        >

            <span class="nav-icon">
                <?= icon("file-text") ?>
            </span>

            <span>
                KPI12 รับคืนสัญญา
            </span>

        </a>
        <?php endif; ?>


    </nav>


    <!-- =====================================================
         LOGOUT
    ====================================================== -->

    <div class="sidebar-bottom">

        <a
            href="<?= htmlspecialchars(logoutUrl("../"), ENT_QUOTES, "UTF-8") ?>"
            class="logout-button">

            <?= icon("log-out") ?>ออกจากระบบ

        </a>

    </div>


</aside>
