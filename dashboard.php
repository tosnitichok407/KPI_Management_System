<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

?>

<h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>

<!doctype html>
<html lang="th">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>Advance Group Asia - KPI Dashboard</title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <!-- CSS -->
    <link rel="stylesheet" href="./static/css/variables.css" />
    <link rel="stylesheet" href="./static/css/base.css" />
    <link rel="stylesheet" href="./static/css/components.css" />
    <link rel="stylesheet" href="./static/css/responsive.css" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
  </head>

  <body>
    <div class="dashboard">
            
      <!-- =====================================
         SIDEBAR
    ====================================== -->

      <aside class="sidebar">
        <div class="logo">
          <!-- เปลี่ยน path เป็นตำแหน่งโลโก้จริง -->
          <img
            src="./static/images/advance-logo.png"
            alt="Advance Group Asia Logo"
          />
        </div>

        <nav class="nav">
          <a href="#" class="nav-item active">
            <i data-lucide="layout-dashboard"></i>

            <span>Dashboard</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="target"></i>

            <span>KPI</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="users"></i>

            <span>พนักงาน</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="building-2"></i>

            <span>แผนก</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="clipboard-list"></i>

            <span>การประเมิน</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="bar-chart-3"></i>

            <span>รายงาน</span>
          </a>
        </nav>

        <div class="sidebar-bottom">
          <div class="sidebar-divider"></div>

          <a href="#" class="nav-item">
            <i data-lucide="settings"></i>

            <span>ตั้งค่า</span>
          </a>

          <a href="#" class="nav-item">
            <i data-lucide="log-out"></i>

            <span>ออกจากระบบ</span>
          </a>
        </div>
      </aside>

      <!-- =====================================
         MAIN
    ====================================== -->

      <main class="main">
        <!-- HEADER -->

        <header class="header">
          <div class="header-left">
            <div>
              <h1 class="page-title">KPI Dashboard</h1>

              <p class="page-subtitle">ภาพรวมผลการประเมิน KPI ของพนักงาน</p>
            </div>

            <div class="filters">
              <select class="filter">
                <option>ปี 2026</option>
                <option>ปี 2025</option>
                <option>ปี 2024</option>
              </select>

              <select class="filter">
                <option>ไตรมาส 3</option>
                <option>ไตรมาส 2</option>
                <option>ไตรมาส 1</option>
              </select>
            </div>
          </div>

          <div class="user-area">
            <div class="user-avatar">AD</div>

            <div>
              <div class="user-name">Administrator</div>

              <div class="user-role">ผู้ดูแลระบบ</div>
            </div>
          </div>
        </header>

        <!-- =====================================
             KPI SUMMARY
        ====================================== -->

        <section class="kpi-grid">
          <!-- Total Employee -->

          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-title"> พนักงานทั้งหมด </span>

              <div class="kpi-icon blue">
                <i data-lucide="users"></i>
              </div>
            </div>

            <div class="kpi-value">128</div>

            <div class="kpi-target">พนักงานที่มีการกำหนด KPI</div>

            <div class="kpi-status success">
              <i data-lucide="trending-up"></i>

              +8.2% จากรอบก่อน
            </div>
          </div>

          <!-- KPI Achieved -->

          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-title"> KPI บรรลุเป้าหมาย </span>

              <div class="kpi-icon green">
                <i data-lucide="badge-check"></i>
              </div>
            </div>

            <div class="kpi-value">86.4%</div>

            <div class="kpi-target">เป้าหมาย ≥ 80%</div>

            <div class="kpi-status success">
              <i data-lucide="trending-up"></i>

              +4.6% จากรอบก่อน
            </div>
          </div>

          <!-- Average Score -->

          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-title"> คะแนน KPI เฉลี่ย </span>

              <div class="kpi-icon orange">
                <i data-lucide="gauge"></i>
              </div>
            </div>

            <div class="kpi-value">86.2</div>

            <div class="kpi-target">คะแนนเต็ม 100</div>

            <div class="kpi-status success">
              <i data-lucide="trending-up"></i>

              +5.2 คะแนน
            </div>
          </div>

          <!-- Pending -->

          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-title"> รอประเมิน </span>

              <div class="kpi-icon yellow">
                <i data-lucide="clock-3"></i>
              </div>
            </div>

            <div class="kpi-value">14</div>

            <div class="kpi-target">รายการที่ยังไม่ประเมิน</div>

            <div class="kpi-status warning">
              <i data-lucide="alert-circle"></i>

              ต้องดำเนินการ
            </div>
          </div>
        </section>

        <!-- =====================================
             CHART + SCORE
        ====================================== -->

        <section class="content-grid">
          <!-- KPI Performance -->

          <div class="panel">
            <div class="panel-header">
              <div>
                <div class="panel-title">ผลการดำเนินงาน KPI</div>

                <div class="panel-description">
                  เปรียบเทียบผลจริงกับเป้าหมาย
                </div>
              </div>

              <select class="filter">
                <option>ทุกแผนก</option>
                <option>ฝ่ายขาย</option>
                <option>ฝ่ายบัญชี</option>
                <option>ฝ่ายปฏิบัติการ</option>
              </select>
            </div>

            <div class="performance-list">
              <!-- KPI 1 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">บรรลุเป้าหมายการต่อสัญญา</div>

                  <div class="progress">
                    <div class="progress-bar success" style="width: 92%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย 90%</small>
                </div>

                <div class="performance-percent">92%</div>
              </div>

              <!-- KPI 2 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">
                    จำนวนรายการปรับเพิ่มมูลค่าสัญญา
                  </div>

                  <div class="progress">
                    <div class="progress-bar success" style="width: 87%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย 80%</small>
                </div>

                <div class="performance-percent">87%</div>
              </div>

              <!-- KPI 3 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">มูลค่าไม่ต่อสัญญา</div>

                  <div class="progress">
                    <div class="progress-bar warning" style="width: 72%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย ≤ 70%</small>
                </div>

                <div class="performance-percent">72%</div>
              </div>

              <!-- KPI 4 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">
                    ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม
                  </div>

                  <div class="progress">
                    <div class="progress-bar success" style="width: 95%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย 85%</small>
                </div>

                <div class="performance-percent">95%</div>
              </div>

              <!-- KPI 5 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">เข้าเยี่ยมลูกค้าตาม KPI</div>

                  <div class="progress">
                    <div class="progress-bar success" style="width: 89%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย 85%</small>
                </div>

                <div class="performance-percent">89%</div>
              </div>

              <!-- KPI 6 -->

              <div class="performance-item">
                <div>
                  <div class="performance-name">
                    อัตราคงค้างสัญญาตัวจริงนำกลับ
                  </div>

                  <div class="progress">
                    <div class="progress-bar danger" style="width: 64%"></div>
                  </div>
                </div>

                <div>
                  <small>เป้าหมาย 80%</small>
                </div>

                <div class="performance-percent">64%</div>
              </div>
            </div>
          </div>

          <!-- Overall Score -->

          <div class="panel score-card">
            <div class="panel-title">คะแนน KPI โดยรวม</div>

            <div class="score-circle">
              <div class="score-number">85%</div>
            </div>

            <div class="score-label">ผลการประเมินรอบปัจจุบัน</div>

            <div class="kpi-status success">
              <i data-lucide="trending-up"></i>

              สูงกว่ารอบก่อน 6.4%
            </div>
          </div>
        </section>

        <!-- =====================================
             SALES / KPI TREND
        ====================================== -->

        <section class="panel" style="margin-top: 18px">
          <div class="panel-header">
            <div>
              <div class="panel-title">แนวโน้มคะแนน KPI</div>

              <div class="panel-description">
                เปรียบเทียบผลการประเมินในแต่ละเดือน
              </div>
            </div>

            <select class="filter">
              <option>ปี 2026</option>
            </select>
          </div>

          <div class="chart-container">
            <canvas id="kpiChart"></canvas>
          </div>
        </section>

        <!-- =====================================
             EMPLOYEE TABLE
        ====================================== -->

        <section class="panel table-panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">ผลการประเมินพนักงาน</div>

              <div class="panel-description">
                รายชื่อพนักงานและสถานะ KPI ล่าสุด
              </div>
            </div>

            <button class="filter">ดูทั้งหมด</button>
          </div>

          <table class="employee-table">
            <thead>
              <tr>
                <th>พนักงาน</th>

                <th>แผนก</th>

                <th>คะแนน KPI</th>

                <th>สถานะ</th>

                <th>รอบประเมิน</th>
              </tr>
            </thead>

            <tbody>
              <tr>
                <td>
                  <div class="employee">
                    <div class="employee-avatar">KS</div>

                    สมชาย ใจดี
                  </div>
                </td>

                <td>ฝ่ายขาย</td>

                <td>
                  <strong>94.5</strong>
                </td>

                <td>
                  <span class="badge badge-success"> บรรลุเป้าหมาย </span>
                </td>

                <td>Q3 / 2026</td>
              </tr>

              <tr>
                <td>
                  <div class="employee">
                    <div class="employee-avatar">MP</div>

                    มานี พรชัย
                  </div>
                </td>

                <td>ฝ่ายบัญชี</td>

                <td>
                  <strong>88.2</strong>
                </td>

                <td>
                  <span class="badge badge-success"> บรรลุเป้าหมาย </span>
                </td>

                <td>Q3 / 2026</td>
              </tr>

              <tr>
                <td>
                  <div class="employee">
                    <div class="employee-avatar">AK</div>

                    อนุชา เก่งงาน
                  </div>
                </td>

                <td>ฝ่ายปฏิบัติการ</td>

                <td>
                  <strong>76.4</strong>
                </td>

                <td>
                  <span class="badge badge-warning"> ควรปรับปรุง </span>
                </td>

                <td>Q3 / 2026</td>
              </tr>

              <tr>
                <td>
                  <div class="employee">
                    <div class="employee-avatar">SN</div>

                    สุนันท์ นาคี
                  </div>
                </td>

                <td>ฝ่ายขาย</td>

                <td>
                  <strong>62.8</strong>
                </td>

                <td>
                  <span class="badge badge-danger"> ต่ำกว่าเป้าหมาย </span>
                </td>

                <td>Q3 / 2026</td>
              </tr>
            </tbody>
          </table>
        </section>
      </main>
    </div>
  </body>

  <script src="./static/js/main.js"></script>

  <script src="./static/js/dashboard.js"></script>
</html>
