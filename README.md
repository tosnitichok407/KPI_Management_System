# KPI Management System

ระบบจัดการและประเมินผล KPI ของพนักงานรายเดือน พัฒนาด้วย PHP + MySQL/MariaDB (PDO) สำหรับใช้งานบน XAMPP
แยกการใช้งานเป็น 3 บทบาท คือ **Admin**, **Manager** และ **Employee** พร้อมส่งออกแบบฟอร์มประเมินตามรูปแบบของบริษัทเป็น **Excel** และ **PDF**

---

## สารบัญ

- [ภาพรวมระบบ](#ภาพรวมระบบ)
- [ความสามารถตามบทบาท](#ความสามารถตามบทบาท)
- [หลักการคิดคะแนนและเกรด](#หลักการคิดคะแนนและเกรด)
- [เทคโนโลยีที่ใช้](#เทคโนโลยีที่ใช้)
- [การติดตั้ง](#การติดตั้ง)
- [ฐานข้อมูล](#ฐานข้อมูล)
- [Migration และข้อมูล Demo](#migration-และข้อมูล-demo)
- [โครงสร้างโปรเจกต์](#โครงสร้างโปรเจกต์)
- [ความปลอดภัย](#ความปลอดภัย)
- [ข้อจำกัดและปัญหาที่ทราบ](#ข้อจำกัดและปัญหาที่ทราบ)

---

## ภาพรวมระบบ

```text
Admin   : ตั้งค่าพนักงาน / บัญชีผู้ใช้ / หมวด KPI / หัวข้อ KPI + เกณฑ์ / รอบประเมินรายเดือน / มอบหมาย KPI รายปี
   │
   ▼
Employee: บันทึกผลงานรายเดือน (Performance = Target/Actual, Competency = เลือกระดับ 1-5)
   │       ดูผลการปฏิบัติงาน · ส่งออกแบบฟอร์มประเมิน Excel/PDF · อ่าน Feedback จากหัวหน้า
   ▼
Manager : ดู Dashboard / ผลรายแผนก / ผลรายคน (กราฟเส้นเปรียบเทียบ) · ให้ Feedback รายเดือนถึงพนักงาน
```

แนวคิดหลักของข้อมูล

| แนวคิด | รายละเอียด |
| --- | --- |
| **รอบประเมิน = 1 เดือน** | `evaluation_periods` 1 แถวต่อ (ปี, เดือน) ระบบคำนวณ Quarter และวันเริ่ม-สิ้นสุดให้อัตโนมัติ |
| **การมอบหมาย KPI = รายปี** | `kpi_assignments` 1 แถวต่อ (KPI, พนักงาน, ปี) ใช้ซ้ำทุกเดือน กำหนดช่วงที่มีผลด้วย `start_date` / `end_date` ได้ |
| **ผลงาน = การมอบหมาย + เดือน** | `kpi_performances` 1 แถวต่อ (assignment, รอบประเมิน) |
| **น้ำหนักรวมต่อคน = 100%** | Performance + Competency ของพนักงานแต่ละคนในปีเดียวกันรวมกันไม่เกิน 100% (ระบบตรวจตอนมอบหมาย) |

การเข้าสู่ระบบส่งผู้ใช้ไปตาม `role_id`

| role_id | บทบาท | หน้าแรก |
| --- | --- | --- |
| 1 | Admin | `admin/index.php` |
| 2 | Manager | `manager/index.php` |
| 3 | Employee | `employee/index.php` |

---

## ความสามารถตามบทบาท

### Admin — `admin/index.php?page=...`

| เมนู (`page`) | ไฟล์ | ความสามารถ |
| --- | --- | --- |
| `home` | `admin/index.php` | Dashboard ภาพรวม |
| `employees` | `admin/employees/` | เพิ่ม / แก้ไข / เปิด-ปิด / ลบ ข้อมูลพนักงาน |
| `accounts` | `admin/user-accounts/` | สร้างและแก้ไขบัญชีผู้ใช้ กำหนดบทบาท เปิด-ปิดบัญชี |
| `kpi-categories` | `admin/kpi-categories/` | จัดการหมวดหมู่ KPI |
| `kpi-management` | `admin/kpi-management/` | จัดการหัวข้อ KPI (Performance / Competency) น้ำหนัก หน่วย และเกณฑ์คะแนน 5..1 |
| `evaluation` | `admin/evaluation/` | สร้าง / แก้ไข / ลบ รอบประเมินรายเดือน |
| `kpi-assignment` | `admin/kpi-assignment/` | มอบหมาย KPI รายปีให้พนักงาน (ตรวจน้ำหนักไม่เกิน 100%) ตารางจัดกลุ่มพร้อมตัวกรองเดือน |
| `summary` | `admin/kpi-summary/` | สรุปผลการประเมิน เลือกปี/เดือนด้วย Period Picker และส่งออก PDF |

### Manager — `manager/index.php?page=...`

| เมนู (`page`) | ไฟล์ | ความสามารถ |
| --- | --- | --- |
| `home` | `manager/dashboard/dashboard.php` | Dashboard กราฟเส้น (Chart.js): คะแนนเฉลี่ยรายเดือนเทียบแผนก, Performance vs Competency, เทียบรายพนักงาน, รายไตรมาส, % KPI ที่ประเมินแล้ว, เทียบปีก่อน |
| `departments` | `manager/departments/departments.php` | เปรียบเทียบผลรายแผนก (ประเมินแล้ว / คะแนนเฉลี่ย / ถ่วงน้ำหนัก / ระดับ) |
| `employees` | `manager/employees/employees.php` | จัดอันดับรายพนักงาน และเจาะดูรายคน (ผลรายเดือน + KPI ที่ได้รับมอบหมาย) |
| Feedback | `manager/feedback.php` | ปุ่ม Feedback เปิดฟอร์ม (Dialog) เลือกเดือน ให้คะแนน 0-100 และข้อความถึงพนักงาน — 1 รายการต่อ หัวหน้า + พนักงาน + เดือน (ส่งซ้ำ = แก้ไข) |

ข้อมูลของทุกหน้าโหลดครั้งเดียวต่อปีผ่าน `manager/includes/manager-data.php` แล้วสรุปรายแผนก / รายคน / รายเดือนใน PHP

### Employee

| หน้า | ไฟล์ | ความสามารถ |
| --- | --- | --- |
| หน้าแรก | `employee/index.php` | ข้อมูลส่วนตัว สถิติ กราฟ และ Feedback ล่าสุดจากหัวหน้า |
| KPI ของฉัน | `employee/kpi/kpi.php` | **Performance**: แสดงเป้าหมาย กรอกผลที่ทำได้ ระบบคิดคะแนนให้ · **Competency**: เลือกระดับผลงาน 1-5 พร้อมคำอธิบายเกณฑ์ · ทุก KPI แสดงกล่องระดับผลงาน 5..1 และไฮไลต์ระดับที่ได้ |
| ผลการปฏิบัติงาน | `employee/performance.php` | เลือกปี/เดือน ดูผลรายไตรมาส/รายเดือน Feedback ของเดือนนั้น และส่งออก **PDF / Excel** |
| รายละเอียด KPI | `employee/kpi-detail.php` | เปิดจากหน้าผลการปฏิบัติงาน: รายละเอียด KPI ฟอร์มบันทึกผลงาน และประวัติผลงานทุกเดือนของ KPI นั้น |
| Feedback จากหัวหน้า | `employee/feedback.php` | รายการ Feedback ทั้งปี (เดือน คะแนน ข้อความ ชื่อหัวหน้า) เลือกปีได้ |
| ข้อมูลส่วนตัว | `employee/profile.php` | ข้อมูลพนักงาน |

ทุกหน้าของพนักงานใช้โครงสร้างเดียวกับ Admin ผ่าน `employee/includes/layout.php`

### แบบฟอร์มประเมิน (Export)

| ไฟล์ | รูปแบบ | เนื้อหา |
| --- | --- | --- |
| `employee/performance-export-excel.php` | Excel (PhpSpreadsheet) | ส่วนที่ 1 Performance / ส่วนที่ 2 Competency, ระดับผลงาน 5..1, คะแนนเต็ม/คะแนนจริงเป็น **สูตร Excel**, ตารางเกณฑ์ตัดเกรด (ไฮไลต์เกรดด้วย Conditional Formatting), ช่องลงชื่อ และสรุปผลรายเดือน ม.ค.-ธ.ค. |
| `employee/performance-export-pdf.php` | PDF (Dompdf) | เนื้อหาเดียวกับ Excel ขนาด A4 แนวนอน ฟอนต์ TH Sarabun New พร้อมตัดบรรทัดภาษาไทย |
| `admin/kpi-summary/kpi-summary-export-pdf.php` | PDF | สรุปผลการประเมินของ Admin ตามปี/เดือนที่เลือก |

ข้อมูลของแบบฟอร์มมาจาก `employee/performance-export-data.php` (ใช้ร่วมกันทั้ง Excel และ PDF)

---

## หลักการคิดคะแนนและเกรด

### คะแนนต่อ KPI

| ประเภท | วิธีได้คะแนน (เกรด 1-5) |
| --- | --- |
| Performance | พนักงานกรอก Actual ระบบคำนวณ `Actual ÷ Target × 5` (ปัดเป็นจำนวนเต็ม สูงสุด 5) |
| Competency | พนักงาน/ผู้ประเมินเลือกระดับ 1-5 ตามเกณฑ์ของ KPI |

### คะแนนในแบบฟอร์ม

```text
คะแนนเต็ม  = น้ำหนัก × 5
คะแนนจริง  = น้ำหนัก × เกรด
น้ำหนักรวม 100%  →  คะแนนเต็ม 500
```

KPI ที่ยังไม่ประเมินนับคะแนนจริงเป็น 0

### เกณฑ์ตัดเกรด (สรุปผลการประเมิน)

| ช่วงคะแนน | เกรด | คำอธิบาย |
| --- | --- | --- |
| 451-500 | A+ | ผลงานโดยรวมสูงกว่าเป้าหมาย |
| 400-450 | A | ผลงานโดยรวมบรรลุเป้าหมาย |
| 300-399 | B | ผลงานโดยรวมต่ำกว่าเป้าหมายอยู่ในเกณฑ์ที่ยอมรับได้ |
| 200-299 | C | ผลงานโดยรวมต่ำกว่าเป้าหมายมาก |
| 100-199 | D | ผลงานโดยรวมไม่ผ่านเกณฑ์ที่กำหนด ต้องปรับปรุง |

- เดือนที่น้ำหนักรวมไม่ถึง 100% จะเทียบคะแนนเป็นฐาน 500 ก่อนตัดเกรด (เช่น 225/375 = 60% → 300 → B)
- ต่ำกว่า 100 คะแนนให้เกรด D
- นิยามเกณฑ์อยู่ใน `evaluationGradeScale()` ใน `employee/performance-export-data.php`

### แหล่งเกณฑ์ระดับผลงาน 5..1

`loadKpiScoreCriteria()` อ่านตามลำดับความสำคัญ และใช้ร่วมกันทั้งหน้า KPI ของฉันและแบบฟอร์ม Excel/PDF

1. `kpi_score_criteria` (หน้าเพิ่ม KPI ของ Admin บันทึกที่นี่)
2. `kpi_score_levels`
3. `kpi_indicators.score_5` … `score_1` (หน้าแก้ไข KPI ของ Admin บันทึกที่นี่)

---

## เทคโนโลยีที่ใช้

| ส่วน | เทคโนโลยี |
| --- | --- |
| Backend | PHP 8.2 (พัฒนาและทดสอบบน XAMPP PHP 8.2.4), PDO |
| Database | MySQL / MariaDB (utf8mb4) |
| Excel | [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) ^5.9 |
| PDF | [dompdf/dompdf](https://github.com/dompdf/dompdf) ^3.1 + ฟอนต์ TH Sarabun New (`assets/fonts/`) |
| Frontend | HTML / CSS / JavaScript, ฟอนต์ Kanit (Google Fonts), Chart.js (CDN) สำหรับกราฟของ Manager |

ไลบรารีใน `vendor/` ถูก commit มากับ repository แล้ว จึงใช้งานได้ทันทีโดยไม่ต้องรัน Composer

---

## การติดตั้ง

### ความต้องการ

- XAMPP (Apache + PHP 8.2 ขึ้นไป + MySQL/MariaDB) บน macOS, Windows หรือ Linux
- PHP extensions: `pdo_mysql`, `mbstring`, `dom`, `xml`, `gd`, `zip`, `iconv`, `fileinfo` (XAMPP เปิดไว้ให้แล้วโดยปกติ)
- โฟลเดอร์ชั่วคราวที่ PHP ของ Apache เขียนได้ (สำหรับ cache ฟอนต์ของ Dompdf) — ระบบเลือกให้อัตโนมัติจาก `upload_tmp_dir` → temp ของระบบ → `storage/`

### ขั้นตอน

1. วางโปรเจกต์ไว้ใน `htdocs` ของ XAMPP เช่น `/Applications/XAMPP/xamppfiles/htdocs/KPI-System`
2. Start **Apache** และ **MySQL** ใน XAMPP
3. สร้างฐานข้อมูล

   ```sql
   CREATE DATABASE kpi_management_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. นำเข้าโครงสร้างตารางและข้อมูลตั้งต้น (ดู [ฐานข้อมูล](#ฐานข้อมูล)) — **repository นี้ยังไม่มีไฟล์ schema ตั้งต้น** ต้องใช้ไฟล์ dump จากผู้พัฒนา
5. ตรวจสอบค่าการเชื่อมต่อใน [`config/database.php`](config/database.php)

   | ค่า | ค่าเริ่มต้น |
   | --- | --- |
   | Host | `localhost` |
   | Database | `kpi_management_system` |
   | Username | `root` |
   | Password | (ว่าง) |

6. (ถ้าต้องการ) ใส่ข้อมูล Demo — ดู [Migration และข้อมูล Demo](#migration-และข้อมูล-demo)
7. เปิด `http://localhost/KPI-System/login.php`

### ส่งออก schema จากเครื่องที่ใช้งานอยู่

```bash
# โครงสร้างอย่างเดียว
/Applications/XAMPP/xamppfiles/bin/mysqldump -u root --no-data kpi_management_system > schema.sql

# โครงสร้าง + ข้อมูล (สำรองข้อมูล)
/Applications/XAMPP/xamppfiles/bin/mysqldump -u root --single-transaction kpi_management_system > backup.sql
```

---

## ฐานข้อมูล

| ตาราง | หน้าที่ | คีย์/ข้อจำกัดสำคัญ |
| --- | --- | --- |
| `roles` | บทบาทผู้ใช้ (1 Admin, 2 Manager, 3 Employee) | |
| `users` | บัญชีเข้าสู่ระบบ (`password_hash`, `status`) ผูกกับพนักงาน | FK → `employees`, `roles` |
| `employees` | ข้อมูลพนักงาน แผนก ตำแหน่ง สถานะ | `employee_code` unique |
| `departments` / `positions` | แผนกและตำแหน่ง | |
| `login_logs` | ประวัติเข้าสู่ระบบ สำเร็จ/ล้มเหลว พร้อมเหตุผล IP และ User-Agent | |
| `kpi_categories` | หมวดหมู่ KPI | |
| `kpi_indicators` | หัวข้อ KPI: `kpi_type` (Performance/Competency), `weight`, `unit`, `max_score`, `score_5..score_1` | FK → `kpi_categories` |
| `kpi_score_criteria` | เกณฑ์ระดับผลงาน 1-5 ต่อ KPI | unique (`kpi_id`, `score_level`), ลบตาม KPI |
| `kpi_score_levels` | เกณฑ์ระดับผลงาน (รูปแบบเดิม) | |
| `evaluation_periods` | รอบประเมินรายเดือน: `period_year`, `period_month`, `quarter`, `start_date`, `end_date`, `status` | unique (ปี, เดือน) |
| `kpi_assignments` | การมอบหมาย KPI รายปี: `target_value`, `weight`, `start_date`, `end_date`, `status` (`period_id` เป็นคอลัมน์เดิม ไม่ใช้เป็นตัวระบุแล้ว) | unique (`kpi_id`, `employee_id`, `assignment_year`), ลบตาม KPI/พนักงาน |
| `kpi_performances` | ผลงานรายเดือน: `target`, `actual`, `score`, `comment`, `status` (Draft/Submitted/Approved/Rejected) | unique (`assignment_id`, `period_id`), ลบตามการมอบหมาย |
| `manager_feedback` | Feedback ของหัวหน้า: `evaluation_score` (0-100), `feedback` — `manager_id` เก็บ `user_id` ของหัวหน้า | unique (`manager_id`, `employee_id`, `period_id`) |
| `employee_kpi` | ผล KPI ต่อพนักงานต่อรอบ (target/actual/score/remark) | unique (พนักงาน, KPI, รอบ) |
| `performance_summary` | สรุปผลต่อพนักงานต่อรอบ (คะแนนรวม ร้อยละ ระดับ สถานะ) | unique (พนักงาน, รอบ) |

KPI ที่ "มีผล" ในเดือนใด ใช้เงื่อนไขเดียวกันทั้งระบบ

```sql
COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= :month_start
AND a.status = 'Active'
```

---

## Migration และข้อมูล Demo

### Migrations — `database/migrations/`

ใช้เมื่ออัปเกรดฐานข้อมูลจากโครงสร้างเดิม (รอบประเมินแบบช่วงวันที่) มาเป็นรอบรายเดือน **รันตามลำดับ และสำรองข้อมูลก่อนเสมอ**

| ลำดับ | ไฟล์ | สิ่งที่ทำ |
| --- | --- | --- |
| 1 | `20260911_monthly_evaluation_periods.sql` | เพิ่มปี/เดือน/Quarter ให้รอบประเมิน และบังคับ 1 รอบต่อเดือน |
| 2 | `20260911_annual_assignment_monthly_performance.sql` | การมอบหมายรายปี (unique ต่อปี) และผลงาน 1 แถวต่อการมอบหมายต่อเดือน |

ในไฟล์มี pre-check ที่ต้องผ่านก่อน และไม่มีการลบตาราง/คอลัมน์/ข้อมูลเดิม

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root kpi_management_system < database/migrations/20260911_monthly_evaluation_periods.sql
```

### Demo Seed — `database/seeds/`

`20260912_service_department_kpi_demo.php` สร้างข้อมูลทดสอบของ **ฝ่ายบริการ** (บริษัทกำจัดแมลง) เพื่อให้ Manager ดูภาพรวมได้

- KPI Performance 6 หัวข้อ (รวม 80%) + Competency 5 หัวข้อ (รวม 20%) พร้อมเกณฑ์ 5..1
- มอบหมายปี 2026 ให้พนักงานฝ่ายบริการ (ยกเว้นตำแหน่ง Administration Service)
- ผลงานรายเดือน ม.ค.-ก.ย. (สุ่มแบบกำหนด seed รันซ้ำได้ผลเดิม)

```bash
php database/seeds/20260912_service_department_kpi_demo.php                  # ใส่ข้อมูล
php database/seeds/20260912_service_department_kpi_demo.php --rollback       # ลบข้อมูลของสคริปต์นี้ทั้งหมด
php database/seeds/20260912_service_department_kpi_demo.php --db=ชื่อฐานทดสอบ  # รันกับฐานอื่น
```

สคริปต์รันได้เฉพาะ command line (เปิดผ่านเว็บจะได้ 404) และจะไม่ใส่ข้อมูลซ้ำหากเคยรันแล้ว

---

## โครงสร้างโปรเจกต์

```text
KPI-System/
├── admin/
│   ├── index.php                 # Layout + Dashboard + router (?page=)
│   ├── includes/sidebar.php
│   ├── employees/                # จัดการพนักงาน
│   ├── user-accounts/            # จัดการบัญชีผู้ใช้
│   ├── kpi-categories/           # หมวดหมู่ KPI
│   ├── kpi-management/           # หัวข้อ KPI + เกณฑ์คะแนน
│   ├── evaluation/               # รอบประเมินรายเดือน
│   ├── kpi-assignment/           # มอบหมาย KPI รายปี
│   └── kpi-summary/              # สรุปผล + Export PDF
├── manager/
│   ├── index.php                 # Layout + router (?page=) + ตัวกรองปี/เดือน/แผนก
│   ├── feedback.php              # บันทึก Feedback (POST)
│   ├── includes/                 # manager-data.php, filter-bar.php, sidebar.php
│   ├── dashboard/                # Dashboard กราฟ
│   ├── departments/              # ผลรายแผนก
│   └── employees/                # ผลรายคน + Feedback Dialog
├── employee/
│   ├── includes/                 # layout.php (โครงหน้าร่วม), feedback.php (โหลด/แสดง Feedback)
│   ├── index.php                 # หน้าแรก
│   ├── kpi/kpi.php               # KPI ของฉัน (บันทึกผลงาน)
│   ├── performance.php           # ผลการปฏิบัติงาน
│   ├── kpi-detail.php            # รายละเอียด KPI
│   ├── feedback.php              # Feedback จากหัวหน้า
│   ├── profile.php               # ข้อมูลส่วนตัว
│   ├── performance-export-data.php   # ข้อมูลแบบฟอร์ม + เกณฑ์เกรด (ใช้ร่วม Excel/PDF)
│   ├── performance-export-excel.php  # Export Excel
│   └── performance-export-pdf.php    # Export PDF
├── includes/
│   ├── security.php              # เริ่ม session (HttpOnly/SameSite/strict mode), CSRF token, redirect ตามบทบาท
│   ├── monthly-period-helper.php # ชื่อเดือน / รายละเอียดรอบรายเดือน / ค้นหารอบ
│   ├── quarter-helper.php        # Quarter จากเดือน/วันที่
│   ├── period-picker.php         # ตัวเลือกปี + เดือนแบบปุ่ม (Admin Summary, Employee)
│   └── pdf-helper.php            # Dompdf + ฟอนต์ไทย + ตัดบรรทัดไทย + เลขหน้า
├── assets/
│   ├── css/                      # admin.css (โครงหน้าหลัก), manager.css, employee-*.css, period-picker.css
│   │                             # + CSS เฉพาะหน้า: admin-dashboard / admin-kpi-assignment(-edit) / admin-kpi-summary /
│   │                             #   admin-kpi-add / admin-kpi-edit / admin-user-account-edit / employee-dashboard /
│   │                             #   employee-my-kpi / employee-kpi-detail / employee-performance / login-test
│   ├── js/                       # admin.js (คงตำแหน่ง scroll เมื่อเปลี่ยนหน้า/ส่งฟอร์ม) · dashboard.js (ยังไม่มีหน้าใดเรียกใช้)
│   ├── fonts/                    # TH Sarabun New สำหรับ PDF
│   └── images/
├── config/database.php           # การเชื่อมต่อฐานข้อมูล (PDO)
├── database/
│   ├── migrations/               # SQL migrations
│   └── seeds/                    # Demo seed (CLI เท่านั้น)
├── vendor/                       # Dompdf, PhpSpreadsheet (Composer)
├── login.php                     # เข้าสู่ระบบ + บันทึก login_logs + redirect ตามบทบาท
├── logout.php                    # ออกจากระบบ
├── login-test.php                # หน้า debug ทดสอบ Login (ลบก่อนขึ้นระบบจริง)
└── composer.json
```

### แนวทางโค้ด

- ทุกไฟล์ include ด้วย `__DIR__` (ไม่ขึ้นกับ working directory)
- ทุกหน้า `require_once includes/security.php` แทน `session_start()` — ฟอร์ม POST ใส่ `<?= csrfField() ?>` และตรวจด้วย `csrfVerify()` / ไฟล์ action (ลบ, เปิด-ปิด) ใช้ `csrfRequirePost()`
- ไม่มี `<style>` / `style="..."` ในหน้า PHP — CSS อยู่ใน `assets/css/` (CSS เฉพาะหน้า admin โหลดตาม `?page=` ใน `admin/index.php`; progress bar ที่ความกว้างมาจากข้อมูลยังเป็น inline style)
- ทุก query ใช้ PDO prepared statements
- Admin และ Manager ใช้ router `index.php?page=` ส่วน Employee เป็นหน้าแยกที่ใช้ `employeeLayoutStart()` / `employeeLayoutEnd()`
- โครงหน้าทุกบทบาทใช้ `section.page-container > div.page-container` จาก `admin.css` จึงมีขนาดและ padding เท่ากัน

---

## ความปลอดภัย

มีอยู่แล้ว

- รหัสผ่านเก็บด้วย `password_hash()` และตรวจด้วย `password_verify()`
- `session_regenerate_id()` หลังเข้าสู่ระบบสำเร็จ และ `logout.php` ล้าง session + cookie
- ตรวจสถานะบัญชีและสถานะพนักงานก่อนเข้าสู่ระบบ บันทึกทุกความพยายามลง `login_logs`
- หน้า Admin และ Manager ตรวจ `role_id` ก่อนแสดงผล ผู้ใช้บทบาทอื่นถูกส่งกลับหน้าแรกของบทบาทตัวเอง
- หน้าพนักงานและไฟล์ Export ใช้ `employee_id` จาก session จึงแสดงเฉพาะข้อมูลของผู้ที่ login อยู่ (หน้าแรกและข้อมูลส่วนตัวตรวจ `role_id = 3` เพิ่ม ส่วนหน้าอื่นตรวจเพียงว่า login และมี `employee_id`)
- Output ถูก escape ด้วย `htmlspecialchars()` และข้อมูลที่ฝังใน `<script>` ใช้ `json_encode` พร้อม `JSON_HEX_TAG`
- CSRF token ในทุกฟอร์ม POST (`includes/security.php`) และการลบ / เปิด-ปิดสถานะทำผ่าน POST เท่านั้น
- Session cookie เป็น `HttpOnly` + `SameSite=Lax` (+ `Secure` อัตโนมัติเมื่อใช้ HTTPS) และเปิด `session.use_strict_mode`
- Login ถูกจำกัดชั่วคราวเมื่อล้มเหลวเกิน 10 ครั้งใน 15 นาที (นับจาก `login_logs` ต่อ username / IP)
- ข้อความ error ของฐานข้อมูลไม่แสดงให้ผู้ใช้ (บันทึกลง error log ของ PHP แทน)
- `config/`, `includes/`, `database/`, `vendor/` มี `.htaccess` ปิดการเข้าถึงผ่าน URL โดยตรง
- `login-test.php` เปิดได้เฉพาะจาก localhost (127.0.0.1 / ::1)

ก่อนใช้งานจริง

- ลบ `login-test.php`
- เปลี่ยนบัญชีฐานข้อมูลใน `config/database.php` (ไม่ใช้ `root` ที่ไม่มีรหัสผ่าน)
- ปิด `display_errors` และบันทึก error ลง log, ใช้ HTTPS (session cookie จะเป็น `Secure` ให้อัตโนมัติ)
- สำรองฐานข้อมูลเป็นประจำ

---

## ข้อจำกัดและปัญหาที่ทราบ

| เรื่อง | รายละเอียด |
| --- | --- |
| ลบ KPI | ปุ่มลบในหน้าจัดการ KPI จะปฏิเสธเมื่อ KPI ถูกมอบหมายแล้ว (`kpi_assignments` / `employee_kpi`) เพราะการลบจะ CASCADE ไปถึงผลงานของพนักงาน |

| ไม่มี schema ตั้งต้นใน repository | ติดตั้งเครื่องใหม่ต้องใช้ไฟล์ dump จากผู้พัฒนา |
| เกณฑ์คะแนนบันทึกคนละที่ | หน้าเพิ่ม KPI บันทึกใน `kpi_score_criteria` แต่หน้าแก้ไข KPI อ่าน/บันทึกที่ `score_5..score_1` — KPI ที่มีเกณฑ์ใน `kpi_score_criteria` หรือ `kpi_score_levels` อยู่แล้ว จะแก้เกณฑ์ผ่านหน้าแก้ไขไม่มีผล |
| สูตรคะแนน Performance เป็นเชิงเส้น | `Actual ÷ Target × 5` ไม่ได้อ้างอิงช่วงในเกณฑ์ของ KPI และใช้ไม่ได้กับ KPI แบบ "ยิ่งน้อยยิ่งดี" (เช่น มูลค่าไม่ต่อสัญญา < 5%) |
| Feedback ยังไม่มีสถานะอ่านแล้ว | พนักงานไม่มีการแจ้งเตือน Feedback ใหม่ |
