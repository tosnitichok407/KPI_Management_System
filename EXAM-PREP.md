# คู่มือเตรียมสอบโปรเจกต์ — KPI Management System

> จัดทำจากการอ่าน source code จริงทั้งโปรเจกต์ และ **อัปเดต 29 ก.ย. 2026 หลังแก้ไขปัญหาที่ตรวจพบ**
> เลขบรรทัดอ้างอิงไฟล์ปัจจุบัน ถ้าแก้โค้ดแล้วเลขจะขยับ ให้ค้นด้วยชื่อฟังก์ชันแทน
>
> โครงสร้างตารางในเอกสารนี้ยืนยันกับฐานข้อมูลจริงแล้ว (MariaDB 10.4.28) และบันทึกไว้ที่ `database/schema.sql`
>
> **โค้ดที่แก้ทั้งหมดยังไม่ได้ commit** ดูรายการไฟล์ด้วย `git status`

---

## 0. อ่านก่อน — สิ่งที่แก้ไปแล้ว และสิ่งที่ยังเป็นข้อจำกัด

### 0.1 ปัญหาที่แก้แล้ว (ทดสอบกับสำเนาฐานข้อมูลแล้วทุกข้อ)

| # | ปัญหาเดิม | สิ่งที่แก้ | ไฟล์ |
|---|---|---|---|
| 1 | Dashboard ของ Admin และหน้าแรกของ Employee อ่านจากตาราง `employee_kpi` / `performance_summary` ที่ว่างเปล่า ตัวเลขจึงเป็น 0 | เขียน query ใหม่ให้อ่านจาก `kpi_assignments` + `kpi_performances` ตามรอบประเมินที่เลือก ค่าเริ่มต้นคือรอบของเดือนปัจจุบัน | `admin/index.php:152-383`, `employee/index.php:130-330`, `defaultEvaluationPeriodId()` |
| 2 | ฟอนต์ไทยของ PDF ถูกลบจากโปรเจกต์ | นำ `assets/fonts/THSarabunNew*.ttf` กลับมาจาก git | `assets/fonts/` |
| 3 | กฎ KPI "มูลค่าไม่ต่อสัญญา" มีเฉพาะหน้าเดียว หน้าอื่นคิดคะแนนไม่ตรงกัน | ย้ายกฎไปไว้ที่ไฟล์กลาง ทุกหน้า ไฟล์ Export และสคริปต์คำนวณย้อนหลังใช้เกณฑ์ชุดเดียวกัน | `includes/kpi-score-helper.php:51-107` |
| 4 | KPI ที่สร้างผ่านหน้า "เพิ่ม KPI" ตั้งเกณฑ์ไม่ได้จริง | หน้าเพิ่มและหน้าแก้ไขมีช่องเกณฑ์ 5..1 และบันทึกลง `kpi_score_criteria` ผ่าน `saveKpiScoreCriteria()` | `kpi-add.php`, `kpi-edit.php` |
| 5 | หน้าจอไม่มีช่องเลือกประเภท KPI | เพิ่มตัวเลือก Performance / Competency ทั้งหน้าเพิ่มและแก้ไข | `kpi-add.php:98`, `kpi-edit.php:124` |
| 6 | น้ำหนัก 100% ตรวจแยกประเภท รวมได้ถึง 200% | ตรวจยอดรวม Performance + Competency | `kpi-assignment.php:294-452`, `kpi-assignment-edit.php` |
| 7 | รอบประเมินที่ Closed ยังบันทึกได้ | ตรวจ `status === "Open"` ก่อนบันทึก ทั้งสองเส้นทาง | `kpi.php:149`, `kpi-detail.php:188` |
| 8 | Login เปิดเผยว่า username มีอยู่จริง และมีช่อง Remember Me ที่ไม่ทำงาน | ตรวจรหัสผ่านก่อนสถานะบัญชี และลบช่อง Remember Me | `login.php:167-192` |
| 9 | ไม่มี session timeout และไม่ตรวจสถานะบัญชีซ้ำ | หมดอายุเมื่อไม่ใช้งาน 30 นาที และตรวจสถานะบัญชีทุก request | `security.php:50-122`, `database.php:32` |
| 10 | Logout ไม่มี CSRF token | ลิงก์ออกจากระบบสร้างด้วย `logoutUrl()` และ `logout.php` ตรวจ token | `security.php:200`, `logout.php:10` |
| 11 | หน้าเพิ่ม KPI ไม่มี transaction | ครอบ INSERT ทั้งหมดด้วย transaction | `kpi-add.php:121-190` |
| 12 | หน้าแก้ไข KPI รับ `max_score` เกิน 5 ได้ | จำกัด 1-5 เหมือนหน้าเพิ่ม | `kpi-edit.php:133` |
| 13 | `catch` 15 จุดไม่บันทึก error | เพิ่ม `error_log()` | หลายไฟล์ |
| 14 | ไม่มีไฟล์ schema ใน repo | เพิ่ม `database/schema.sql` | `database/schema.sql` |

### 0.2 ข้อจำกัดที่ยังอยู่ (ไม่ได้แก้ เพราะเป็นการตัดสินใจเชิงออกแบบ)

| เรื่อง | สถานะ | วิธีตอบถ้าถูกถาม |
|---|---|---|
| **ไม่มีขั้นอนุมัติคะแนน** | คอลัมน์ `status` มีค่า Draft / Submitted / Approved / Rejected แต่ไม่มีหน้าอนุมัติ และรายงานไม่กรองตาม status | "เป็นการประเมินตนเอง ฐานข้อมูลรองรับ status ไว้แล้ว ถ้าพัฒนาต่อจะเพิ่มหน้าอนุมัติของหัวหน้า" |
| **Manager เห็นทุกแผนก** | `manager/index.php:48` อ่าน `department_id` แต่ไม่ได้กรอง | "ออกแบบให้ผู้บริหารเปรียบเทียบข้ามแผนกได้ ถ้าต้องจำกัดจะกรองด้วยแผนกใน session" |
| Partial ของ Admin เปิดผ่าน URL ตรงได้ | ได้ HTML ไม่ครบหน้า แต่ยังตรวจ role | ไม่ใช่ช่องโหว่ข้ามสิทธิ์ |

### 0.3 เรื่องที่คุณต้องตัดสินใจเอง

**ทิศทางของ KPI "มูลค่าไม่ต่อสัญญา"** ผมคงกฎตามโค้ดล่าสุดของคุณ คือ `>20% = 5` และ `<5% = 1`
แต่ข้อมูลเดิมในฐานข้อมูล (`kpi_score_levels`) เขียนว่า `5 : <5%` ซึ่งเป็นทิศตรงข้าม
ถ้าอาจารย์ถามว่า "มูลค่าไม่ต่อสัญญาสูงทำไมได้คะแนนสูง" คุณต้องมีคำตอบ
ถ้าต้องกลับทิศ แก้ที่เดียวคือ `NON_RENEWAL_KPI_CRITERIA` และ `nonRenewalKpiGrade()` ใน `includes/kpi-score-helper.php`

ผลงานเดิมของ KPI นี้ในฐานข้อมูลยังมีคะแนนจากกฎเก่าค้างอยู่ ปรับให้ตรงได้ด้วย

```bash
php database/migrations/20260921_recalculate_kpi_scores.php            # ดูผลก่อน
php database/migrations/20260921_recalculate_kpi_scores.php --apply    # เขียนจริง
```

---

## 1. PROJECT ARCHITECTURE

### 1.1 ลักษณะโดยรวม

- PHP 8.2 ล้วน **ไม่มี framework ไม่ใช่ MVC** แต่ละหน้าเป็นไฟล์ PHP ที่ทำครบในไฟล์เดียว: ตรวจสิทธิ์ → รับค่า → query → แสดง HTML
- ส่วนที่ใช้ร่วมกันแยกเป็น helper ใน `includes/`
- ไลบรารีภายนอกมี 2 ตัว (`composer.json`): `dompdf/dompdf` สำหรับ PDF และ `phpoffice/phpspreadsheet` สำหรับ Excel
- Chart.js โหลดจาก CDN

### 1.2 โฟลเดอร์และไฟล์สำคัญ

| ที่อยู่ | หน้าที่ |
|---|---|
| `login.php` / `logout.php` | เข้า-ออกระบบ |
| `config/database.php` | สร้างตัวแปร `$pdo` (การเชื่อมต่อฐานข้อมูล) |
| `includes/security.php` | เริ่ม session, idle timeout, ตรวจสถานะบัญชี, security headers, CSRF, redirect ตามบทบาท |
| `includes/kpi-score-helper.php` | **กฎตัดเกรด 1-5** และโหลดเกณฑ์จากฐานข้อมูล |
| `includes/monthly-period-helper.php` | ชื่อเดือน, คำนวณรอบรายเดือน, หารอบจากปี+เดือน |
| `includes/quarter-helper.php` | แปลงเดือนเป็นไตรมาส |
| `includes/period-picker.php` | ปุ่มเลือกปี/เดือน |
| `includes/pdf-helper.php` | ตั้งค่า Dompdf, ฟอนต์ไทย, ตัดบรรทัดไทย |
| `admin/index.php` | โครงหน้า + router `?page=` + Dashboard ของ Admin |
| `admin/employees, user-accounts, kpi-categories, kpi-management, evaluation, kpi-assignment, kpi-summary` | หน้าจัดการของ Admin |
| `manager/index.php` | โครงหน้า + router ของ Manager |
| `manager/includes/manager-data.php` | โหลดข้อมูลทั้งปีและฟังก์ชันสรุปผล |
| `manager/dashboard, departments, employees` | หน้าเนื้อหาของ Manager |
| `manager/feedback.php` | รับ POST บันทึก Feedback |
| `employee/kpi/kpi.php` | **หน้าบันทึกผลงาน (เส้นทางหลักของระบบ)** |
| `employee/kpi-detail.php` | บันทึกผลงานอีกเส้นทางหนึ่ง + ประวัติ |
| `employee/performance.php` | ดูผลรายเดือน + ปุ่ม Export |
| `employee/performance-export-*.php` | ข้อมูลและไฟล์ Excel / PDF |
| `database/schema.sql` | โครงสร้างตารางทั้งหมด |
| `database/migrations`, `database/seeds` | สคริปต์ปรับฐานข้อมูลและข้อมูลตัวอย่าง (รันผ่าน command line) |
| `assets/fonts/` | ฟอนต์ TH Sarabun New สำหรับ PDF |
| `config/ includes/ database/ vendor/` มี `.htaccess` | `Require all denied` กันเปิดผ่าน URL |

### 1.3 Router ของ Admin / Manager

`admin/index.php:58-107`

```php
$page = $_GET["page"] ?? "home";
$pages = [ "home" => null, "employees" => "employees/employees.php", ... ];
if (!array_key_exists($page, $pages)) { $page = "home"; }
```

- ค่า `page` จาก URL **ไม่ได้ถูกนำไปต่อเป็นชื่อไฟล์โดยตรง** แต่ใช้เป็น key ค้นใน array ที่กำหนดไว้ (whitelist)
- ถ้าไม่มีใน array จะกลับไปหน้า home
- ผลคือป้องกันการ include ไฟล์ตามใจผู้ใช้ (Local File Inclusion)
- Employee ไม่มี router แต่ละหน้าเรียก `employeeLayoutStart()` / `employeeLayoutEnd()` ครอบเนื้อหา

### 1.4 Request flow ตัวอย่าง: พนักงานบันทึกผลงาน

```text
Browser  POST employee/kpi/kpi.php?tab=performance&year=2026&month=9
   │
   ├─ includes/security.php     เริ่ม session, ส่ง header
   ├─ config/database.php       สร้าง $pdo
   ├─ ตรวจ idle timeout และสถานะบัญชี                    (security.php, database.php:32)
   ├─ ตรวจ $_SESSION["user_id"] และ employee_id       (kpi.php:20-43)
   ├─ หา period จากปี+เดือน  findEvaluationPeriodByMonth()  (kpi.php:85)
   ├─ csrfVerify()                                       (kpi.php:135)
   ├─ รอบประเมินต้องมีอยู่และสถานะ Open                  (kpi.php:144-154)
   ├─ SELECT assignment ที่เป็นของพนักงานคนนี้            (kpi.php:164-198)
   ├─ ตรวจรูปแบบ Actual → ตัดเกรด                         (kpi.php:229-284)
   ├─ มีแถวเดิมไหม → UPDATE หรือ INSERT kpi_performances  (kpi.php:348-496)
   ├─ SELECT รายการ KPI ของเดือนนั้นมาแสดงใหม่            (kpi.php:524-618)
   └─ HTML (escape ด้วย htmlspecialchars) → Browser
```

---

## 2. LOGIN / AUTHENTICATION

ไฟล์: `login.php`

### 2.1 ลำดับการทำงาน

| ลำดับ | บรรทัด | ทำอะไร |
|---|---|---|
| 1 | 50-63 | ถ้า login อยู่แล้ว ส่งไปหน้าตามบทบาท |
| 2 | 69-70 | รับ `username` (trim) และ `password` (ไม่ trim) |
| 3 | 75 | ตรวจ CSRF token |
| 4 | 78-83 | ค่าว่าง / ยาวเกิน (username > 50, password > 1024) |
| 5 | 84 | `loginAttemptsExceeded()` ผิดครบ 10 ครั้งใน 15 นาที ต่อ username หรือ IP → ปฏิเสธ |
| 6 | 92-144 | `SELECT` ผู้ใช้ด้วย prepared statement |
| 7 | 173 | ไม่พบ username |
| 8 | 178 | `password_verify($password, $user["password_hash"])` |
| 9 | 183 | `users.status` ต้องเป็น active |
| 10 | 188 | `employees.status` ต้องเป็น active |
| 11 | 198-234 -| ถ้าล้มเหลว บันทึก `login_logs` พร้อมเหตุผลจริง |
| 12 | 241 | `session_regenerate_id(true)` |
| 13 | 245-285 | เขียนข้อมูลผู้ใช้และ `last_activity` ลง `$_SESSION` |
| 14 | 328-341 | redirect ตาม `role_id` |

**ทำไมตรวจรหัสผ่านก่อนสถานะบัญชี** คนที่ไม่รู้รหัสผ่านจะได้ข้อความ `Invalid Employee ID or Password.` เสมอ
ไม่ว่าจะพิมพ์ username ผิด รหัสผิด หรือบัญชีถูกปิด จึงเดาไม่ได้ว่า username ใดมีอยู่จริง
ข้อความ "บัญชีถูกปิด" แสดงเฉพาะกับคนที่ใส่รหัสผ่านถูก

### 2.2 Query หาผู้ใช้

```sql
SELECT u.user_id, u.password_hash, u.role_id, u.status AS user_status, e.status AS employee_status, ...
FROM users u
INNER JOIN employees e ON u.employee_id = e.employee_id
LEFT  JOIN departments d ON e.department_id = d.department_id
LEFT  JOIN positions  p ON e.position_id  = p.position_id
INNER JOIN roles r ON u.role_id = r.role_id
WHERE u.username = :username
LIMIT 1
```

- `INNER JOIN employees` และ `roles`: บัญชีต้องผูกกับพนักงานและบทบาท ไม่งั้นหาไม่เจอ
- `LEFT JOIN departments / positions`: พนักงานที่ยังไม่มีแผนกหรือตำแหน่งก็ login ได้

### 2.3 Password

- สร้าง/เปลี่ยนรหัส: `password_hash($password, PASSWORD_DEFAULT)` ที่ `admin/user-accounts/user-account-add.php:318` และ `user-account-edit.php:246`
- `PASSWORD_DEFAULT` ใน PHP 8.2 คือ **bcrypt** ใส่ salt ให้อัตโนมัติ และเก็บ salt รวมอยู่ในค่า hash
- ตรวจรหัส: `password_verify()` ที่ `login.php:178`
- ฐานข้อมูลเก็บเฉพาะ hash ในคอลัมน์ `users.password_hash` ไม่เก็บรหัสจริง
- ความยาวขั้นต่ำ 6 ตัวอักษร (`user-account-add.php:162`) ไม่มีกฎความซับซ้อน
- ไม่มีการเรียก `password_needs_rehash()`
- พนักงานเปลี่ยนรหัสเองไม่ได้ ต้องให้ Admin เปลี่ยน

### 2.4 Session

`includes/security.php:18-37`

| การตั้งค่า | ผล |
|---|---|
| `session.use_strict_mode = 1` | ไม่รับ session id ที่เซิร์ฟเวอร์ไม่ได้ออกเอง |
| `session.use_only_cookies = 1` | ไม่รับ session id จาก URL |
| `httponly => true` | JavaScript อ่าน cookie ไม่ได้ |
| `samesite => Lax` | ลดโอกาสส่ง cookie จากเว็บอื่น |
| `secure => $isHttps` | บน HTTPS จะส่ง cookie เฉพาะ HTTPS |
| `lifetime => 0` | cookie หมดอายุเมื่อปิดเบราว์เซอร์ |

- `session_regenerate_id(true)` หลัง login สำเร็จ เปลี่ยนเลข session ใหม่และลบของเดิม ป้องกัน session fixation
- **Idle timeout 30 นาที** (`security.php:50-62`): ทุก request เทียบเวลาปัจจุบันกับ `$_SESSION["last_activity"]` ถ้าเกิน 1800 วินาทีจะล้าง session
- **ตรวจสถานะบัญชีทุก request** (`securityRecheckAccount()` บรรทัด 88-122): query สถานะของ `users` และ `employees` ถ้าถูกปิดจะล้าง session ทันที และอัปเดต `role_id` ตามฐานข้อมูล
- ฟังก์ชันนี้ถูกเรียกจาก `config/database.php:32` เพราะทุกหน้า require `security.php` ก่อน `database.php` อยู่แล้ว จึงไม่ต้องแก้ทุกไฟล์

### 2.5 Logout

`logout.php`

1. ตรวจ CSRF token จาก URL ถ้าไม่ตรงจะไม่ logout และส่งกลับหน้าแรก (บรรทัด 10)
2. `$_SESSION = [];` ล้างข้อมูลในหน่วยความจำ (บรรทัด 16)
3. `setcookie(session_name(), "", time() - 42000, ...)` สั่งเบราว์เซอร์ลบ cookie (บรรทัด 24)
4. `session_destroy();` ลบข้อมูล session ฝั่งเซิร์ฟเวอร์ (บรรทัด 37)
5. redirect ไป `login.php` (บรรทัด 41)

ลิงก์ออกจากระบบในเมนูสร้างด้วย `logoutUrl()` ซึ่งต่อ token ให้อัตโนมัติ

---

## 3. AUTHORIZATION / ROLE

### 3.1 บทบาท

| role_id | บทบาท | ทำอะไรได้ |
|---|---|---|
| 1 | Admin | จัดการพนักงาน บัญชี หมวด KPI หัวข้อ KPI รอบประเมิน มอบหมาย KPI ดูสรุปและ Export PDF และเข้าหน้า Manager ได้ด้วย |
| 2 | Manager | ดู Dashboard ผลรายแผนก รายคน และให้ Feedback |
| 3 | Employee | บันทึกผลงานของตัวเอง ดูผล Export อ่าน Feedback |

### 3.2 จุดตรวจสิทธิ์

| หน้า | เงื่อนไข | ตำแหน่ง |
|---|---|---|
| ทุกไฟล์ใน `admin/` ที่รับ request | `role_id === 1` | เช่น `admin/index.php:31`, `kpi-delete.php:21` |
| `manager/index.php`, `manager/feedback.php` | `role_id` อยู่ใน `[1, 2]` | `index.php:32`, `feedback.php:15` |
| `employee/index.php`, `profile.php` | `role_id === 3` | `index.php:36` |
| `employee/kpi/kpi.php`, `performance.php`, `kpi-detail.php`, `feedback.php`, ไฟล์ Export | login แล้ว และมี `employee_id` ใน session | `kpi.php:20-43`, `performance-export-data.php:229-245` |

โค้ดตัวอย่างที่ต้องจำ

```php
// admin/index.php:18-34
if (!isset($_SESSION["user_id"])) { header("Location: ../login.php"); exit; }
if ((int) ($_SESSION["role_id"] ?? 0) !== 1) { redirectToRoleHome("../"); }
```

- ต้องมี `exit` หลัง `header("Location")` เสมอ ไม่งั้น PHP จะทำงานต่อและส่งเนื้อหาหน้านั้นออกไป
- `redirectToRoleHome()` มี `exit` ในตัว (`security.php:205`)
- ไฟล์ของ Admin ตรวจสิทธิ์ซ้ำในทุกไฟล์ ไม่ได้พึ่ง `index.php` อย่างเดียว เพราะไฟล์ย่อยเปิดผ่าน URL ตรงได้

### 3.3 ขอบเขตข้อมูลของพนักงาน (สำคัญมาก)

```php
$employeeId = (int) ($_SESSION["employee_id"] ?? 0);      // kpi.php:32
...
WHERE a.assignment_id = :assignment_id
  AND a.employee_id   = :employee_id                       // kpi.php:184-185
```

- `assignment_id` มาจากฟอร์ม ผู้ใช้แก้เป็นเลขของคนอื่นได้
- แต่ `employee_id` มาจาก session เท่านั้น ถ้า assignment นั้นไม่ใช่ของตัวเอง query จะไม่พบ
- ผลคือพนักงานแก้หรือดูข้อมูลของคนอื่นไม่ได้ ช่องโหว่ประเภทนี้เรียกว่า IDOR (Insecure Direct Object Reference)
- คำสั่ง `UPDATE` ก็มี `AND employee_id = :employee_id` ซ้ำอีกชั้น (`kpi.php:380-392`)

### 3.4 จุดอ่อนที่ยังอยู่

- Manager เห็นพนักงานทุกแผนก `manager/index.php:48` อ่าน `department_id` แล้วไม่ได้ใช้กรอง
- `manager/feedback.php:74-85` ตรวจแค่ว่าพนักงาน Active ไม่ตรวจว่าอยู่ในทีมของหัวหน้าคนนั้น
- Feedback มี unique key (`manager_id`, `employee_id`, `period_id`) หัวหน้าแต่ละคนจึงมีแถวของตัวเอง ไม่เขียนทับกัน

---

## 4. DATABASE

### 4.1 การเชื่อมต่อ

`config/database.php`

```php
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
```

| ส่วน | ความหมาย |
|---|---|
| DSN | บอกชนิดฐานข้อมูล ที่อยู่ พอร์ต ชื่อฐาน และ charset |
| `charset=utf8mb4` | รองรับภาษาไทยและ emoji |
| `ERRMODE_EXCEPTION` | เมื่อ SQL ผิดพลาดจะโยน `PDOException` ให้ `try/catch` จับได้ |
| `FETCH_ASSOC` | ผลลัพธ์เป็น array ที่ key คือชื่อคอลัมน์ |
| `catch` (บรรทัด 21-26) | เขียน error ลง log ตอบ HTTP 500 และข้อความกลาง ๆ ไม่เปิดเผยรายละเอียด |

ท้ายไฟล์ (บรรทัด 32) เรียก `securityRecheckAccount($pdo)` เพื่อตรวจสถานะบัญชีของผู้ที่ login อยู่

ข้อสังเกต

- บรรทัด 17-20 ตั้ง `ERRMODE` ซ้ำอีกรอบ ไม่ผิด แต่ซ้ำซ้อน
- ใช้ `root` รหัสว่าง และไฟล์อยู่ใน git เหมาะกับเครื่องพัฒนาเท่านั้น
- ไม่ได้ตั้ง `PDO::ATTR_EMULATE_PREPARES` จึงใช้ค่าเริ่มต้นของ MySQL driver คือ PDO จำลอง prepared statement ฝั่ง PHP (ยังปลอดภัย เพราะ PDO escape ค่าตาม charset ใน DSN)

### 4.2 PDO คืออะไร

PDO (PHP Data Objects) คือชุดคำสั่งมาตรฐานของ PHP สำหรับติดต่อฐานข้อมูล จุดเด่นคือรองรับ prepared statement, จัดการ error ด้วย exception, รองรับ transaction และเปลี่ยนชนิดฐานข้อมูลได้โดยแก้ DSN

```php
$stmt = $pdo->prepare("SELECT ... WHERE u.username = :username");  // 1. เตรียมโครง SQL
$stmt->execute([":username" => $username]);                        // 2. ส่งค่าแยกต่างหาก
$user = $stmt->fetch();                                            // 3. อ่านผล 1 แถว
```

| คำสั่ง | ใช้เมื่อ |
|---|---|
| `fetch()` | ต้องการ 1 แถว |
| `fetchAll()` | ต้องการทุกแถว |
| `fetchColumn()` | ต้องการค่าเดียว เช่น `COUNT(*)` |
| `lastInsertId()` | ต้องการ id ของแถวที่เพิ่ง INSERT (`kpi-add.php:175`) |
| `query()` | SQL คงที่ ไม่มีค่าจากผู้ใช้ |

### 4.3 ตารางและความสัมพันธ์

```text
roles ──< users >── employees >── departments
                        │    └──> positions
                        │
kpi_categories ──< kpi_indicators ──< kpi_score_criteria
                        │
                        └──< kpi_assignments >── employees
                                   │
                                   └──< kpi_performances >── evaluation_periods
                                                                   │
                        manager_feedback (manager_id = users.user_id) ─┘
login_logs
```

| ตาราง | 1 แถวคือ | ข้อจำกัดสำคัญ (ยืนยันจากฐานข้อมูลจริง) |
|---|---|---|
| `users` | บัญชีผู้ใช้ | UNIQUE `username`, UNIQUE `employee_id` (พนักงาน 1 คนมีได้ 1 บัญชี) |
| `employees` | พนักงาน | UNIQUE `employee_code`, `status` เป็น ENUM Active/Inactive |
| `kpi_indicators` | หัวข้อ KPI | `kpi_type` ENUM('Performance','Competency') ค่าเริ่มต้น Performance |
| `kpi_score_criteria` | เกณฑ์ของ KPI 1 ระดับ | UNIQUE (`kpi_id`, `score_level`), CHECK `score_level` 1-5 |
| `evaluation_periods` | รอบประเมิน 1 เดือน | UNIQUE (`period_year`, `period_month`), `status` ENUM Open/Closed |
| `kpi_assignments` | KPI 1 ตัวของพนักงาน 1 คนใน 1 ปี | UNIQUE (`kpi_id`, `employee_id`, `assignment_year`) |
| `kpi_performances` | ผลงานของ assignment ใน 1 เดือน | UNIQUE (`assignment_id`, `period_id`), `score` decimal(5,2), `status` ENUM 4 ค่า |
| `manager_feedback` | Feedback ของหัวหน้า 1 คนถึงพนักงาน 1 คนใน 1 เดือน | UNIQUE (`manager_id`, `employee_id`, `period_id`) |

ทุกตารางใช้ InnoDB จึงรองรับ foreign key และ transaction

ประโยคเดียวที่อธิบายโมเดล: **มอบหมายรายปี × รอบประเมินรายเดือน = ผลงานรายเดือน**

การลบแบบต่อเนื่อง (CASCADE): ลบ KPI → การมอบหมายหาย → ผลงานหาย จึงมีการตรวจก่อนลบที่ `kpi-delete.php:51-69`

### 4.4 Query สำคัญ

**SELECT + JOIN + subquery** — รายการ KPI ของเดือนที่เลือก (`kpi.php:524-589`)

```sql
FROM kpi_assignments a
INNER JOIN kpi_indicators k ON a.kpi_id = k.kpi_id
LEFT JOIN kpi_performances kp
    ON kp.performance_id = (
        SELECT kp2.performance_id FROM kpi_performances kp2
        WHERE kp2.assignment_id = a.assignment_id
          AND kp2.employee_id   = a.employee_id
          AND kp2.period_id     = :period_id
        ORDER BY kp2.performance_id DESC LIMIT 1 )
WHERE a.employee_id = :employee_id
  AND a.status = 'Active'
  AND COALESCE(a.start_date, CONCAT(a.assignment_year,'-01-01')) <= :month_end
  AND COALESCE(a.end_date,   CONCAT(a.assignment_year,'-12-31')) >= :month_start
```

- `INNER JOIN`: การมอบหมายต้องมี KPI คู่กันเสมอ
- `LEFT JOIN`: KPI ที่ยังไม่กรอกผลก็ต้องแสดง คอลัมน์ฝั่งผลงานจะเป็น NULL
- subquery + `LIMIT 1`: รับประกันว่าได้ผลงานไม่เกิน 1 แถวต่อ KPI
- `COALESCE`: ถ้าไม่ได้กำหนดวันเริ่ม/สิ้นสุด ให้ถือว่าใช้ทั้งปี
- เงื่อนไข 2 บรรทัดสุดท้ายคือ "ช่วงของ assignment ซ้อนทับกับเดือนที่เลือก"

**INSERT** — `kpi.php:435-495` (ผลงาน), `user-account-add.php:343-383` (บัญชี), `login.php:198-234` (log)

**UPDATE** — `kpi.php:380-424`, `kpi-edit.php:151-209`, `employee-toggle.php:52-61`

**DELETE** — `kpi-delete.php:77-84`, `evaluation-periods-delete.php:85-92`, `employee-delete.php:95-134`

**INSERT ... ON DUPLICATE KEY UPDATE** (upsert) — `manager/feedback.php:87-93` และ `saveKpiScoreCriteria()` ถ้าชน unique key ให้แก้ไขแถวเดิมแทน

**GROUP BY + aggregate + derived table** — `admin/kpi-summary/kpi-summary-data.php:154-168`

```sql
LEFT JOIN (
    SELECT kp.assignment_id, COUNT(kp.performance_id) AS eval_count, AVG(kp.score) AS avg_score
    FROM kpi_performances kp
    INNER JOIN evaluation_periods ep ON ep.period_id = kp.period_id
    WHERE kp.score IS NOT NULL AND ep.period_year = ?
    GROUP BY kp.assignment_id
) perf ON perf.assignment_id = a.assignment_id
```

**LEFT JOIN หา "สิ่งที่ยังไม่มี"** — พนักงานที่ยังไม่มีบัญชี (`user-account-add.php:44-62`)

```sql
FROM employees e LEFT JOIN users u ON e.employee_id = u.employee_id
WHERE u.user_id IS NULL
```

**Query แบบต่อเงื่อนไขตามตัวกรอง** — `kpi-assignment.php:700-839` เริ่มด้วย `WHERE 1 = 1` แล้วต่อ `AND ... = ?` พร้อมเพิ่มค่าใน `$params` ทีละตัว ตัว SQL ไม่มีค่าจากผู้ใช้ปนอยู่

### 4.5 Transaction

| ที่ | ทำไมต้องใช้ |
|---|---|
| `admin/kpi-assignment/kpi-assignment.php:261-539` | มอบหมายให้หลายคนพร้อมกัน ถ้าคนใดคนหนึ่งน้ำหนักเกิน 100% จะ `throw Exception` แล้ว `rollBack()` ทั้งชุด |
| `admin/kpi-management/kpi-add.php:121-190` | INSERT KPI 1 แถว + เกณฑ์สูงสุด 5 แถว |
| `admin/kpi-management/kpi-edit.php:148-221` | UPDATE KPI + บันทึกเกณฑ์ |
| `admin/employees/employee-delete.php:56-143` | ลบ 3 ตารางต่อกัน (`kpi_assignments`, `users`, `employees`) |
| สคริปต์ migration และ seed | เขียนข้อมูลจำนวนมาก |

---

## 5. KPI BUSINESS LOGIC

### 5.1 สร้าง KPI

1. Admin สร้างหมวด (`kpi-categories/kpi-category-add.php`) ตรวจชื่อซ้ำก่อน INSERT
2. Admin สร้างหัวข้อ KPI (`kpi-management/kpi-add.php`) กรอก หมวด, **ประเภท (Performance / Competency)**, ชื่อ, รายละเอียด, น้ำหนัก, หน่วย, คะแนนสูงสุด และ **เกณฑ์ระดับ 5..1**
3. ระบบตรวจค่า (บรรทัด 85-112) แล้วเปิด transaction
4. INSERT ลง `kpi_indicators` แล้วเรียก `saveKpiScoreCriteria()` บันทึกเกณฑ์ลง `kpi_score_criteria`
5. หน้าแก้ไข (`kpi-edit.php`) โหลดเกณฑ์ที่ระบบใช้อยู่จริงมาแสดง และบันทึกด้วยฟังก์ชันเดียวกัน

`saveKpiScoreCriteria()` (`kpi-score-helper.php:587`): ระดับที่กรอกจะ INSERT หรือ UPDATE ระดับที่เว้นว่างจะถูกลบแถว

### 5.2 มอบหมาย KPI

`admin/kpi-assignment/kpi-assignment.php`

1. Admin เลือกปี แผนก พนักงาน (หลายคนได้) KPI เป้าหมาย น้ำหนัก
2. ระบบกำหนด `start_date = ปี-01-01` และ `end_date = ปี-12-31` (บรรทัด 84-90)
3. ตรวจค่า: น้ำหนัก 0-100, เป้าหมาย ≥ 0, สถานะ Active/Inactive (บรรทัด 123-167)
4. เริ่ม transaction (261) แล้ววนทีละคน
   - พนักงานต้อง Active
   - ถ้ามี KPI นี้ในปีนี้แล้ว ให้ข้าม
   - รวมน้ำหนัก Active **ทุกประเภท** ของพนักงานในปีนั้น + น้ำหนักใหม่ ถ้าเกิน 100 → `throw` (294-452)
   - INSERT (459)
5. `commit()` (480)

### 5.3 พนักงานกรอก Actual

`employee/kpi/kpi.php` — ดู flow ในหัวข้อ 1.4

เงื่อนไขก่อนบันทึก: ต้องมีรอบประเมินของเดือนนั้น (บรรทัด 144) และรอบต้องมีสถานะ `Open` (บรรทัด 149)

การตรวจค่า Actual (บรรทัด 234)

```php
preg_match('/^\d{1,13}(\.\d{1,2})?$/', $actual)
```

แปลว่า: ตัวเลข 1-13 หลัก ตามด้วยทศนิยมไม่เกิน 2 ตำแหน่ง ไม่รับค่าติดลบ ไม่รับตัวอักษร ตรงกับคอลัมน์ `decimal(15,2)`

### 5.4 คำนวณคะแนน Performance

จุดเข้าใช้งานเดียว: `kpiPerformanceGrade()` (`includes/kpi-score-helper.php:452-456`)

```php
return kpiGradeFromCriteria($levels, $actual, $target)   // 1. ใช้เกณฑ์ก่อน
    ?? kpiGradeFromTargetRatio($actual, $target);         // 2. ไม่มีเกณฑ์ ใช้สัดส่วน
```

ขั้นตอนของ `kpiGradeFromCriteria()` (บรรทัด 389-423)

| ขั้น | ฟังก์ชัน | ทำอะไร |
|---|---|---|
| 1 | `parseKpiCriteria()` | แปลงข้อความเกณฑ์เป็นกฎ เช่น `">100%"` → `gt 100`, `"99-90%"` → `range 90..99`, `"98% ขึ้นไป"` → `gte 98` |
| 2 | `kpiCriteriaCompareValue()` | เลือกว่าจะเทียบด้วยค่า Actual ตรง ๆ หรือ `Actual ÷ Target × 100` |
| 3 | วนจากระดับ 5 ลง 1 | ระดับแรกที่เข้าเกณฑ์คือเกรด |
| 4 | ถ้าไม่เข้าช่วงใด | ดูทิศทางเกณฑ์ด้วย `kpiCriteriaHigherIsBetter()` แล้ววางตามขอบ แย่กว่าทุกระดับได้ 1 |

ตัวอย่างคำนวณมือ

| KPI | Target | Actual | เกณฑ์ | วิธีเทียบ | เกรด |
|---|---|---|---|---|---|
| ตรงเวลา | 98 | 96 | 5: 98% ขึ้นไป, 4: 95%-97% | Target อยู่ในช่วงตัวเลขเกณฑ์ → เทียบค่าดิบ 96 | **4** |
| เข้าเยี่ยมลูกค้า | 20 ครั้ง | 20 | 5: >100%, 4: 100% | Target ต่ำกว่าช่วงเกณฑ์ → 20÷20×100 = 100 | **4** |
| ไม่มีเกณฑ์ตัวเลข | 50 | 40 | ข้อความล้วน | สูตรสำรอง round(40÷50×5) = 4 | **4** |

**กฎเฉพาะ KPI "มูลค่าไม่ต่อสัญญา"** (บรรทัด 51-107): `loadKpiScoreCriteria()` คืนเกณฑ์ `>20%`, `15% - 20%`, `10% - 14%`, `5% - 9%`, `<5%` แทนเกณฑ์ในฐานข้อมูลเสมอ และใช้เป้าหมาย 100% ผมทดสอบแล้วว่าเครื่องมือตัดเกรดทั่วไปให้ผลตรงกับ `nonRenewalKpiGrade()` ทุกค่า

### 5.5 คำนวณคะแนน Competency

`kpi.php:292-326` — ไม่มีสูตร พนักงานเลือกระดับ 1-5 จากปุ่ม radio ระบบตรวจว่าเป็นตัวเลขและอยู่ในช่วง 1-5 แล้วบันทึก `actual = NULL`

### 5.6 Weight

| ที่ | สูตร |
|---|---|
| แบบฟอร์ม Excel/PDF (`performance-export-data.php:44-47`) | คะแนนเต็ม = น้ำหนัก × 5, คะแนนจริง = น้ำหนัก × เกรด |
| Manager (`manager-data.php:291-292, 319`), Admin Summary (`kpi-summary-data.php:277-278`) และ Admin Dashboard (`admin/index.php:305-306`) | `Σ(score ÷ max_score × weight) ÷ Σweight × 100` |

ตัวอย่าง: น้ำหนัก 20 เกรด 4 และ น้ำหนัก 30 เกรด 5
- คะแนนจริง = 20×4 + 30×5 = 230, คะแนนเต็ม = 50×5 = 250, ร้อยละ = 92%
- สูตร Manager = (4/5×20 + 5/5×30) ÷ 50 × 100 = 92% ได้เท่ากัน

เกรดตัวอักษร (`evaluationGrade()` บรรทัด 117-133): เทียบเป็นฐาน 500 ก่อน แล้วตัด 451 A+, 400 A, 300 B, 200 C, ต่ำกว่านั้น D

### 5.7 คะแนนสูงสุด 5 ถูกควบคุมตรงไหน

| เส้นทาง | จุดควบคุม |
|---|---|
| Performance จากเกณฑ์ | เกรดคือเลขระดับ 1-5 ของเกณฑ์ วนด้วยค่าคงที่ `KPI_MIN_LEVEL = 1`, `KPI_MAX_LEVEL = 5` (`kpi-score-helper.php:41-42`) |
| Performance จากสูตรสำรอง | `max(1, min(5, $grade))` (บรรทัด 443) |
| Competency | `if ($scoreValue < 1 || $scoreValue > 5)` (`kpi.php:313-316`) |
| `max_score` ของ KPI | หน้าเพิ่มและหน้าแก้ไขจำกัด 1-5 (`kpi-add.php:110`, `kpi-edit.php:133`) |
| ระดับของเกณฑ์ | ฐานข้อมูลมี CHECK `score_level between 1 and 5` ในตาราง `kpi_score_criteria` |

คอลัมน์ `kpi_performances.score` เป็น decimal(5,2) ไม่มี CHECK การจำกัดคะแนน 1-5 จึงทำใน PHP

### 5.8 Evaluation Period

- Admin เลือกปี+เดือน ระบบคำนวณชื่อรอบ ไตรมาส วันแรก วันสุดท้ายเองด้วย `monthlyPeriodDetails()` (`monthly-period-helper.php:12-27`)
- ไตรมาส = `ceil(เดือน ÷ 3)` วันสุดท้าย = `date("Y-m-t")`
- กันเดือนซ้ำด้วย UNIQUE key ถ้าซ้ำฐานข้อมูลตอบ error รหัส `23000` โค้ดแปลงเป็นข้อความ "มีรอบประเมินสำหรับปีและเดือนนี้แล้ว" (`evaluation-periods-add.php:120-125`)
- ลบไม่ได้ถ้ามีข้อมูลอ้างอิง (`evaluationPeriodUsage()`)
- ย้ายปี/เดือนไม่ได้ถ้ามีผลงานแล้ว (`evaluation-periods-edit.php:167-176`)
- ถ้าเดือนนั้นไม่มีรอบ พนักงานบันทึกไม่ได้ (`kpi.php:144`)
- ถ้ารอบมีสถานะ `Closed` พนักงานบันทึกและแก้ไขไม่ได้ (`kpi.php:149`, `kpi-detail.php:188`) Admin เปิด-ปิดรอบได้จากหน้าแก้ไขรอบประเมิน

---

## 6. DASHBOARD

### 6.1 Manager Dashboard

1. `manager/index.php:126` เรียก `managerLoadYearData($pdo, $filterYear)` ครั้งเดียว
2. ฟังก์ชันนี้ (`manager-data.php:26-179`) รัน 6 query: พนักงาน, แผนก, การมอบหมาย, รอบประเมิน, ผลงาน, Feedback แล้วจัดเป็น array
3. การคำนวณทั้งหมดทำใน PHP ไม่ query ซ้ำ

| ค่าบนจอ | ฟังก์ชัน | สูตร |
|---|---|---|
| คะแนนเฉลี่ย | `managerStats()` บรรทัด 306-308 | ผลรวมคะแนน ÷ จำนวนรายการ |
| เฉลี่ยแยกประเภท | บรรทัด 310-316 | เหมือนกัน แยก Performance / Competency |
| % ถ่วงน้ำหนัก | บรรทัด 318-320 | ดูหัวข้อ 5.6 |
| จำนวน KPI / ประเมินแล้ว | บรรทัด 254-275 | นับ assignment และนับตัวที่มีผลงาน |
| กราฟรายเดือน | `managerMonthlyScores()` | เฉลี่ยคะแนนต่อเดือน 1-12 |
| กราฟ % ประเมินแล้ว | `managerMonthlyCompletion()` | ประเมินแล้ว ÷ ทั้งหมด × 100 |
| กราฟไตรมาส | `managerQuarterScores()` | รวมผลรวมและจำนวนของ 3 เดือนแล้วหาร |

### 6.2 Chart.js รับข้อมูลอย่างไร

`manager/dashboard/dashboard.php:384, 456-461`

```php
lineChart("chartDepartments", monthLabels,
    <?= json_encode($departmentSeries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, 5, "");
```

- PHP คำนวณเป็น array แล้ว `json_encode` ฝังลงใน `<script>` ของหน้าเดียวกัน
- **ไม่มี AJAX ไม่มี API** ข้อมูลมาพร้อมหน้าเว็บ เปลี่ยนตัวกรองคือโหลดหน้าใหม่
- `JSON_HEX_TAG` แปลง `<` `>` เป็นรหัส กันชื่อพนักงานที่มี `</script>` ทำให้เกิด XSS
- ค่า `null` คือเดือนที่ไม่มีข้อมูล Chart.js ตั้ง `spanGaps: true` เพื่อลากเส้นข้าม

### 6.3 Admin Dashboard

`admin/index.php:152-383` — ต่างจาก Manager ตรงที่ **ให้ฐานข้อมูลคำนวณ** ด้วย SQL aggregate

| ส่วน | Query |
|---|---|
| พนักงาน Active | `COUNT(*) FROM employees WHERE status = 'Active'` |
| KPI ที่มอบหมาย | นับ assignment ที่ Active และมีผลในเดือนของรอบที่เลือก |
| ประเมินแล้ว / คะแนนเฉลี่ย | `COUNT(kp.performance_id)`, `AVG(kp.score)` จาก `kpi_performances` ของรอบที่เลือก |
| กราฟรายพนักงาน | `SUM(score ÷ max_score × weight) ÷ SUM(weight) × 100` แล้ว `GROUP BY` พนักงาน |
| กราฟตามประเภท | `AVG(kp.score) GROUP BY ki.kpi_type` |
| กราฟราย KPI | `AVG(kp.score) GROUP BY ki.kpi_id ORDER BY ... LIMIT 10` |
| การ์ดไตรมาส | ดึงคะแนนพร้อม `period_year`, `quarter` แล้วเฉลี่ยใน PHP |

- เงื่อนไข "KPI ที่มีผลในเดือนนี้" เก็บในตัวแปร `$activeInMonth` (บรรทัด 252) แล้วใช้ซ้ำทุก query เป็นข้อความคงที่ ไม่มีค่าจากผู้ใช้
- รอบเริ่มต้นเลือกด้วย `defaultEvaluationPeriodId()`: เดือนปัจจุบัน ถ้าไม่มีใช้รอบล่าสุดที่เริ่มแล้ว
- ครอบด้วย `try/catch` ถ้า query ผิดพลาดจะแสดงข้อความและบันทึก log

### 6.4 หน้าแรกของ Employee

`employee/index.php:130-330` ใช้ query เดียวกับหน้า "KPI ของฉัน" (LEFT JOIN + subquery) แล้วนับจำนวน KPI, จำนวนที่ประเมินแล้ว และคะแนนเฉลี่ยใน PHP

### 6.5 เปรียบเทียบสองแนวทาง (คำถามที่อาจารย์ชอบถาม)

| | คำนวณใน SQL (Admin) | คำนวณใน PHP (Manager) |
|---|---|---|
| จำนวน query | หลาย query ต่อหน้า | 6 query ต่อปี ใช้ร่วมทุกหน้า |
| เหมาะกับ | ข้อมูลมาก ต้องการผลสรุปชุดเดียว | ต้องสรุปหลายมุมจากข้อมูลชุดเดียว |
| ข้อเสีย | เพิ่มมุมมองใหม่ต้องเขียน query เพิ่ม | โหลดข้อมูลทั้งปีเข้าหน่วยความจำ |

---

## 7. EXPORT

### 7.1 Excel — `employee/performance-export-excel.php`

1. `requireEmployeeExportAccess()` ตรวจ login และดึง `employee_id` จาก session
2. `loadEmployeePerformanceReport()` ดึง KPI + ผลงาน + เกณฑ์ของเดือนนั้น
3. `loadEmployeeYearScores()` ดึงคะแนน 12 เดือน
4. สร้าง `Spreadsheet` เขียนทีละเซลล์
5. ส่ง header แล้ว `$writer->save("php://output")` (บรรทัด 632-637)

จุดเด่น: เขียน **สูตร Excel** ลงเซลล์ ไม่ใช่ค่าที่คำนวณแล้ว

```php
$sheet->setCellValue("I{$row}", "=C{$row}*" . FULL_GRADE);                  // คะแนนเต็ม
$sheet->setCellValue("L{$row}", "=IF(K{$row}=\"\",\"\",C{$row}*K{$row})");  // คะแนนจริง
```

- ผู้ใช้แก้เกรดในไฟล์แล้วคะแนนรวมเปลี่ยนตาม
- เกรดตัวอักษรใช้ `IF` ซ้อนกันใน `excelGradeFormula()` (บรรทัด 67-76)
- ไฮไลต์แถวเกรดด้วย Conditional Formatting (บรรทัด 462-469)

### 7.2 PDF — `employee/performance-export-pdf.php`

1. ดึงข้อมูลชุดเดียวกับ Excel
2. `buildEvaluationForm()` คำนวณคะแนนใน PHP
3. ประกอบเป็น HTML + CSS
4. `createThaiPdf()` → `loadHtml()` → `setPaper("A4","landscape")` → `render()` → `stream()` (บรรทัด 341-352)

- Dompdf ตัดบรรทัดได้เฉพาะตรงช่องว่าง ภาษาไทยไม่เว้นวรรค จึงมี `thaiPdfWrap()` วัดความกว้างและแทรก `<br>` เอง โดยไม่ตัดก่อนสระ/วรรณยุกต์
- ตั้งค่าความปลอดภัย: `setIsRemoteEnabled(false)` และ `setChroot()` (`pdf-helper.php:25-29`)
- ฟอนต์ TH Sarabun New อยู่ใน `assets/fonts/` (Dompdf อ่านไฟล์ได้เฉพาะในโฟลเดอร์โปรเจกต์)
- ถ้าเกิด error `thaiPdfExceptionHandler()` จะแสดงข้อความแทนหน้าขาว

### 7.3 PDF ของ Admin — `admin/kpi-summary/kpi-summary-export-pdf.php`

ใช้ `kpiSummaryFilters()` และ `loadKpiSummaryReport()` ชุดเดียวกับหน้าเว็บ ตัวเลขใน PDF จึงตรงกับหน้าจอ

---

## 8. SECURITY

| หัวข้อ | มีไหม | ทำตรงไหน | ข้อจำกัด |
|---|---|---|---|
| Prepared Statement | มี | ทุก query ที่มีค่าจากผู้ใช้ เช่น `login.php:138-142` | `query()` ใช้กับ SQL คงที่เท่านั้น |
| SQL Injection | ป้องกัน | ค่าส่งผ่าน placeholder ส่วนที่ต่อสตริงเป็นค่าที่โค้ดกำหนดเอง เช่น `{$in}` คือเครื่องหมาย `?`, `{$table}` มาจากรายการตายตัว, `LIMIT` ถูก cast เป็น int | - |
| Password Hashing | มี | `password_hash` / `password_verify` | ขั้นต่ำ 6 ตัว |
| Session protection | มี | cookie flags, regenerate id, idle timeout 30 นาที, ตรวจสถานะบัญชีทุก request | - |
| CSRF | มี | `csrfField()` ในฟอร์ม, `csrfVerify()` ตอนรับ, `csrfRequirePost()` ในไฟล์ลบ/เปิด-ปิด, `logoutUrl()` | - |
| Brute-force | มี | `login.php:22-45` | ใช้ล็อกบัญชีคนอื่นได้โดยจงใจใส่รหัสผิด |
| User enumeration | ป้องกัน | ตรวจรหัสผ่านก่อนสถานะบัญชี | - |
| Authorization | มี | หัวข้อ 3 | Manager ไม่จำกัดแผนก |
| Input validation | มี | `(int)` cast, `in_array(..., true)`, `preg_match`, `filter_var(FILTER_VALIDATE_EMAIL)`, `mb_strlen` | ตรวจฝั่ง server เป็นหลัก |
| XSS | ป้องกัน | `htmlspecialchars(..., ENT_QUOTES, "UTF-8")` ตอนแสดงผล และ `json_encode` + `JSON_HEX_TAG` ใน script | ไม่มี Content-Security-Policy |
| Security headers | มี | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` (`security.php:128-132`) | - |
| ปิดโฟลเดอร์ | มี | `.htaccess` ใน config, includes, database, vendor | ใช้ได้กับ Apache เท่านั้น |

CSRF token (`security.php:140-167`)

```php
$_SESSION["csrf_token"] = bin2hex(random_bytes(32));   // สุ่ม 64 ตัวอักษร
return hash_equals($_SESSION["csrf_token"], $token);   // เทียบแบบใช้เวลาคงที่
```

---

## 9. ERROR HANDLING

| ประเภท | วิธีจัดการ | ตัวอย่าง |
|---|---|---|
| เชื่อมฐานข้อมูลไม่ได้ | `catch` → `error_log` → HTTP 500 → ข้อความกลาง | `database.php:21-26` |
| Query ผิดพลาด | `try/catch (PDOException)` → `error_log` → ข้อความภาษาไทยให้ผู้ใช้ | `kpi.php:503-511` |
| ข้อมูลซ้ำ | ตรวจรหัส `23000` | `evaluation-periods-add.php:122`, `employee-add.php:164` |
| กฎธุรกิจ | `throw new Exception` แล้ว catch แยกจาก PDOException | `kpi-assignment.php:445, 525` |
| Validation | โซ่ `if / elseif` เก็บข้อความใน `$error` หยุดที่ข้อแรกที่ผิด | `kpi.php:135-154` |
| Login | ข้อความกลาง + บันทึกเหตุผลจริงลง `login_logs` | `login.php:167-234` |
| ไฟล์ action | redirect พร้อมรหัสใน URL หรือข้อความใน session | `?error=in_use`, `$_SESSION["kpi_error"]` |
| PDF | `set_exception_handler("thaiPdfExceptionHandler")` | `pdf-helper.php:211-229` |
| Export | HTTP status: 401, 403, 400, 404 | `performance-export-data.php:235-260` |

หลักที่ใช้ทั้งระบบ: **รายละเอียด error ลง log ผู้ใช้เห็นแค่ข้อความทั่วไป**

ทุก `catch (PDOException)` ในหน้าเว็บเขียน `error_log()` แล้ว

---

## 10. MOST IMPORTANT CODE — 15 จุด

| # | ไฟล์ : บรรทัด | ทำอะไร | ทำไมต้องมี | ถ้าไม่มี | ประโยคที่ใช้อธิบาย |
|---|---|---|---|---|---|
| 1 | `config/database.php:10-15` | สร้าง PDO | ทุกหน้าใช้ `$pdo` | ระบบติดต่อฐานข้อมูลไม่ได้ | "ผมใช้ PDO ตั้งให้โยน exception และคืนผลเป็น associative array" |
| 2 | `includes/security.php:18-37` | เริ่ม session แบบปลอดภัย | กันขโมย/ฝัง session | cookie ถูกอ่านด้วย JavaScript ได้ | "ทุกหน้า require ไฟล์นี้แทนการเรียก session_start เอง" |
| 3 | `includes/security.php:50-122` | idle timeout + ตรวจสถานะบัญชี | ตัดผู้ใช้ที่ทิ้งเครื่องไว้หรือถูกปิดบัญชี | ปิดบัญชีแล้วยังใช้ต่อได้ | "ทุก request ตรวจเวลาใช้งานล่าสุดและสถานะบัญชีจากฐานข้อมูล" |
| 4 | `includes/security.php:140-176` | CSRF | กันเว็บอื่นส่งฟอร์มแทนผู้ใช้ | หลอกให้ Admin ลบข้อมูลได้ | "ฟอร์ม POST ทุกฟอร์มมี token สุ่มที่ผูกกับ session" |
| 5 | `login.php:92-144` | หาผู้ใช้ด้วย prepared statement | กัน SQL Injection | พิมพ์ `' OR '1'='1` เข้าระบบได้ | "SQL กับข้อมูลถูกส่งแยกกัน ข้อมูลจึงไม่ถูกตีความเป็นคำสั่ง" |
| 6 | `login.php:167-192` | โซ่ตรวจ: ผู้ใช้ → รหัสผ่าน → สถานะ | ไม่เปิดเผยว่า username มีจริง | เดารายชื่อบัญชีได้ | "ตรวจรหัสผ่านก่อนสถานะ คนไม่รู้รหัสได้ข้อความเดียวกันเสมอ" |
| 7 | `login.php:241-285` | regenerate + เขียน session | กัน session fixation | ใช้เลข session ที่เตรียมไว้ได้ | "หลัง login สำเร็จผมเปลี่ยนเลข session ใหม่ทันที" |
| 8 | `admin/index.php:18-34` | ตรวจ login และ role | แยกสิทธิ์ | พนักงานเปิดหน้า Admin ได้ | "ตรวจ 2 ชั้น คือ login หรือยัง และ role ตรงไหม" |
| 9 | `admin/index.php:70-107` | router แบบ whitelist | กัน include ไฟล์ตามใจ | เปิดไฟล์อื่นในเครื่องได้ | "ค่า page เป็นแค่ key ของ array ไม่ได้เป็นชื่อไฟล์" |
| 10 | `employee/kpi/kpi.php:164-198` | ตรวจเจ้าของ assignment | กัน IDOR | แก้คะแนนคนอื่นได้ | "employee_id มาจาก session ไม่เคยมาจากฟอร์ม" |
| 11 | `includes/kpi-score-helper.php:389-456` | ตัดเกรด | กฎธุรกิจหลัก | คะแนนไม่ตรงเกณฑ์ | "แปลงเกณฑ์เป็นกฎ แล้วไล่จากระดับ 5 ลงมา" |
| 12 | `employee/kpi/kpi.php:348-495` | มีแล้ว UPDATE ไม่มี INSERT | 1 KPI 1 เดือนมี 1 แถว | ข้อมูลซ้ำ | "กุญแจของผลงานคือ assignment กับ period" |
| 13 | `admin/kpi-assignment/kpi-assignment.php:261-539` | transaction + ตรวจน้ำหนักรวม | ข้อมูลครบหรือไม่ทำเลย | มอบหมายค้างครึ่งเดียว | "ถ้าใครน้ำหนักรวมเกิน 100 ผม rollback ทั้งชุด" |
| 14 | `manager/includes/manager-data.php:223-323` | `managerStats()` | ตัวสรุปกลาง | ตัวเลขแต่ละหน้าไม่ตรงกัน | "ฟังก์ชันเดียวใช้ได้ทั้งรายคนและรายแผนก แค่เปลี่ยนชุดรหัสพนักงาน" |
| 15 | `admin/index.php:252-375` และ `manager/dashboard/dashboard.php:456-461` | query สรุป และส่งข้อมูลให้ Chart.js | เชื่อม backend กับกราฟ | กราฟไม่มีข้อมูล | "PHP แปลง array เป็น JSON ฝังในหน้า" |

---

## 11. POSSIBLE EXAM QUESTIONS — 39 ข้อ

### ระดับง่าย

| # | คำถาม | คำตอบสั้น | จุดที่ต้องจำ | ไฟล์ |
|---|---|---|---|---|
| 1 | ระบบมีกี่บทบาท | 3 คือ Admin, Manager, Employee แยกด้วย `role_id` 1, 2, 3 | เก็บใน session ตอน login | `login.php:352` |
| 2 | ใช้เทคโนโลยีอะไร | PHP 8.2, MySQL/MariaDB ผ่าน PDO, Chart.js, Dompdf, PhpSpreadsheet | ไม่มี framework | `composer.json` |
| 3 | เชื่อมฐานข้อมูลตรงไหน | `config/database.php` สร้าง `$pdo` | DSN, utf8mb4 | `database.php` |
| 4 | PDO คืออะไร | ชุดคำสั่งมาตรฐานของ PHP สำหรับฐานข้อมูล รองรับ prepared statement | prepare → execute → fetch | 4.2 |
| 5 | รหัสผ่านเก็บอย่างไร | เก็บ hash ด้วย `password_hash` แบบ bcrypt | ถอดกลับไม่ได้ | `user-account-add.php:318` |
| 6 | Session คืออะไร ใช้ทำอะไร | ข้อมูลผู้ใช้ที่เก็บฝั่งเซิร์ฟเวอร์ ใช้จำว่าใคร login และมีสิทธิ์อะไร | browser เก็บแค่เลข session ใน cookie | `security.php` |
| 7 | Logout ทำอะไรบ้าง | ล้างตัวแปร ลบ cookie ทำลาย session แล้วกลับหน้า login | 3 ขั้น | `logout.php` |
| 8 | KPI มีกี่ประเภท | Performance วัดจาก Actual และ Competency เลือกระดับ 1-5 Admin เลือกประเภทตอนสร้าง KPI | `kpi_type` เป็น ENUM | `kpi.php:229, 292`, `kpi-add.php:98` |
| 9 | คะแนนเต็มเท่าไร | 5 ต่อ KPI และ 500 ต่อคนเมื่อน้ำหนักรวม 100 | น้ำหนัก × 5 | 5.6 |
| 10 | `htmlspecialchars` ใช้ทำอะไร | แปลงอักขระพิเศษของ HTML ป้องกัน XSS | ใช้ตอนแสดงผล | ทุกหน้า |
| 11 | ทำไมต้อง `exit` หลัง `header` | ไม่งั้นโค้ดด้านล่างยังทำงานต่อ | - | `admin/index.php:20-21` |
| 12 | `require_once` ต่างจาก `include` อย่างไร | `require` หาไฟล์ไม่เจอจะหยุดทำงาน `_once` โหลดครั้งเดียว | `__DIR__` ทำให้ path ไม่ขึ้นกับตำแหน่งที่เรียก | ต้นทุกไฟล์ |

### ระดับปานกลาง

| # | คำถาม | คำตอบสั้น | จุดที่ต้องจำ | ไฟล์ |
|---|---|---|---|---|
| 13 | ป้องกัน SQL Injection อย่างไร | ใช้ prepared statement ส่งค่าแยกจากคำสั่ง | placeholder `:name` หรือ `?` | `login.php:138` |
| 14 | CSRF คืออะไร ป้องกันอย่างไร | การหลอกให้ผู้ใช้ส่งคำสั่งโดยไม่รู้ตัว ป้องกันด้วย token สุ่มในฟอร์ม | `random_bytes`, `hash_equals` | `security.php:140-167` |
| 15 | ทำไมใช้ `session_regenerate_id` | กัน session fixation | เรียกหลังตรวจรหัสผ่าน | `login.php:241` |
| 16 | ป้องกันเดารหัสผ่านอย่างไร | นับ login ที่ล้มเหลวจาก `login_logs` ครบ 10 ครั้งใน 15 นาทีจะปฏิเสธ | ต่อ username หรือ IP | `login.php:22-45` |
| 17 | INNER JOIN กับ LEFT JOIN ต่างกันอย่างไร ใช้ตรงไหน | INNER ได้เฉพาะที่จับคู่ได้ LEFT ได้ฝั่งซ้ายครบ | KPI ที่ยังไม่กรอกต้องแสดง จึงใช้ LEFT | `kpi.php:560-578` |
| 18 | ความสัมพันธ์ assignment, period, performance | มอบหมายรายปี รอบรายเดือน ผลงาน 1 แถวต่อคู่ | UNIQUE (assignment_id, period_id) | migration |
| 19 | KPI มีผลในเดือนไหน รู้ได้อย่างไร | ช่วง start-end ของ assignment ซ้อนกับเดือน | `COALESCE` | `kpi.php:584-585` |
| 20 | น้ำหนักตรวจอย่างไร | รวมน้ำหนัก Active ทุกประเภทของพนักงานในปีนั้น บวกของใหม่ ต้องไม่เกิน 100 | Performance + Competency รวมกัน | `kpi-assignment.php:294-452` |
| 21 | Transaction ใช้ตรงไหน ทำไม | มอบหมาย KPI หลายคน เพิ่ม/แก้ไข KPI พร้อมเกณฑ์ และลบพนักงาน เพื่อให้สำเร็จทั้งหมดหรือไม่ทำเลย | begin, commit, rollBack | 4.5 |
| 22 | กรอกผลซ้ำเดือนเดิมจะเป็นอย่างไร | ตรวจว่ามีแถวเดิมไหม มีก็ UPDATE | ไม่มีแถวซ้ำ | `kpi.php:348-377` |
| 23 | Chart.js ได้ข้อมูลจากไหน | PHP `json_encode` ฝังใน script ไม่มี AJAX | `JSON_HEX_TAG` | `dashboard.php:456` |
| 24 | Excel กับ PDF ต่างกันอย่างไร | Excel ใส่สูตรให้คำนวณในไฟล์ PDF คำนวณใน PHP แล้วแปลง HTML | ข้อมูลชุดเดียวกัน | 7 |
| 25 | ตรวจ Actual อย่างไร | regex รับตัวเลขไม่ติดลบ ทศนิยมไม่เกิน 2 ตำแหน่ง | ตรงกับ decimal(15,2) | `kpi.php:234` |
| 26 | ทำไมลบ KPI ที่มอบหมายแล้วไม่ได้ | ฐานข้อมูลลบต่อเนื่อง ผลงานจะหายตาม จึงตรวจก่อน | CASCADE | `kpi-delete.php:51-69` |

### ระดับเจาะลึก

| # | คำถาม | คำตอบสั้น | จุดที่ต้องจำ | ไฟล์ |
|---|---|---|---|---|
| 27 | ระบบรู้ได้อย่างไรว่า KPI ไหนยิ่งน้อยยิ่งดี | ไม่มีคอลัมน์บอก ดูจากตัวเลขเกณฑ์ไล่ระดับ 5→1 ถ้าตัวเลขเพิ่มขึ้นคือยิ่งน้อยยิ่งดี | เป็นการอนุมาน | `kpi-score-helper.php:317-334` |
| 28 | เทียบค่าดิบหรือ % ของเป้า ตัดสินอย่างไร | ถ้า Target อยู่ในช่วงตัวเลขเกณฑ์ (เผื่อบน 1.5 เท่า) เทียบค่าดิบ ไม่งั้นเทียบสัดส่วน | เป็น heuristic ผิดได้ในกรณีขอบ | บรรทัด 349-379 |
| 29 | ทำไมเลิกใช้สูตร Actual÷Target×5 | ทำได้เท่าเป้าได้ 5 ทั้งที่เกณฑ์ให้ 4 และใช้กับ KPI ยิ่งน้อยยิ่งดีไม่ได้ | ยังเป็นตัวสำรอง | บรรทัด 435-444 |
| 30 | พนักงานแก้ `assignment_id` เป็นของคนอื่นได้ไหม | ส่งได้ แต่ query มี `employee_id` จาก session จึงไม่พบ | IDOR | `kpi.php:184-185` |
| 31 | เกณฑ์เก็บกี่ที่ เลือกอย่างไร | 3 ที่ด้วยเหตุผลทางประวัติ เลือกแหล่งเดียวต่อ KPI ตามลำดับ criteria → levels → คอลัมน์ หน้าจอ Admin เขียนลง criteria เสมอ | แถวว่างไม่นับเป็นเกณฑ์ | บรรทัด 468-570 |
| 32 | ระบบมีขั้นอนุมัติไหม | ไม่มี มีคอลัมน์ status แต่ไม่มี query กรอง | ข้อจำกัดที่ทราบ | 0 ข้อ 8 |
| 33 | ปิดบัญชีแล้วผู้ใช้ที่ login ค้างอยู่เป็นอย่างไร | ถูกตัดใน request ถัดไป เพราะ `securityRecheckAccount()` ตรวจสถานะจากฐานข้อมูลทุกครั้ง | แลกกับ query เพิ่ม 1 ครั้งต่อ request | `security.php:88-122` |
| 34 | ถ้าสองคนบันทึกพร้อมกันเกิดอะไร | โค้ดตรวจก่อนแล้วค่อย INSERT มีช่องว่างเวลา แต่ UNIQUE key กันแถวซ้ำ ฝ่ายหลังจะได้ error | ฐานข้อมูลเป็นด่านสุดท้าย | migration |
| 35 | Manager ดูได้ทุกแผนกไหม | ได้ โค้ดไม่ได้กรองตามแผนกของหัวหน้า | ข้อจำกัดที่ทราบ | `manager/index.php:48` |
| 36 | ถ้าพัฒนาต่อจะทำอะไร | ขั้นอนุมัติคะแนนโดยหัวหน้า จำกัดขอบเขต Manager ตามแผนก ย้ายข้อมูลเกณฑ์เก่าให้เหลือตารางเดียว ให้พนักงานเปลี่ยนรหัสผ่านเอง | ตอบจากข้อจำกัดจริง | 0.2 |
| 37 | รอบประเมินที่ปิดแล้วยังบันทึกได้ไหม | ไม่ได้ โค้ดตรวจ `status` ต้องเป็น `Open` | Admin เป็นผู้เปิด-ปิดรอบ | `kpi.php:149` |
| 38 | Session หมดอายุเมื่อไร | ไม่ใช้งาน 30 นาที หรือปิดเบราว์เซอร์ หรือบัญชีถูกปิด | `SESSION_IDLE_TIMEOUT = 1800` | `security.php:50` |
| 39 | Dashboard ของ Admin กับ Manager คำนวณต่างกันอย่างไร | Admin ใช้ SQL aggregate ต่อรอบ Manager โหลดทั้งปีแล้วคำนวณใน PHP | ดูตารางเปรียบเทียบ | 6.5 |

---

## 12. CODE EXPLANATION — อธิบายแบบเข้าใจง่าย

### 12.1 Prepared statement

```php
$stmt = $pdo->prepare("SELECT ... FROM users u WHERE u.username = :username LIMIT 1");
$stmt->execute([":username" => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
```

- บรรทัด 1: ส่ง "แบบฟอร์มคำสั่ง" ที่มีช่องว่างชื่อ `:username`
- บรรทัด 2: เติมค่าลงช่อง ค่านี้ถูกมองเป็นข้อมูลล้วน ๆ ต่อให้มีเครื่องหมาย `'` ก็ไม่กลายเป็นคำสั่ง
- บรรทัด 3: อ่านผล ถ้าไม่พบได้ `false`

เปรียบเทียบ: เหมือนแบบฟอร์มที่มีช่องให้กรอกชื่อ ต่อให้เขียนคำสั่งลงในช่องชื่อ เจ้าหน้าที่ก็อ่านเป็นชื่อ

### 12.2 โซ่ตรวจสอบตอน login

```php
if ($error !== "") { /* ฐานข้อมูลมีปัญหา */ }
elseif (!$user) { ... "Username not found" }
elseif (!password_verify($password, $user["password_hash"])) { ... "Invalid password" }
elseif (strtolower($user["user_status"]) !== "active") { ... }
elseif (strtolower($user["employee_status"]) !== "active") { ... }

if ($error === "") { /* สำเร็จ */ }
```

ทำงานแบบด่านตรวจเรียงกัน ติดด่านไหนหยุดที่ด่านนั้น
ตัวแปร `$failureReason` เก็บเหตุผลจริงไว้เขียน log ส่วน `$error` คือข้อความที่ผู้ใช้เห็น สองอย่างนี้จงใจให้ไม่เหมือนกัน

### 12.3 ตรวจเจ้าของข้อมูล

```php
$employeeId = (int) ($_SESSION["employee_id"] ?? 0);
```

- `??` แปลว่า "ถ้าไม่มีค่า ให้ใช้ 0"
- `(int)` บังคับเป็นตัวเลข
- ค่านี้ถูกใส่ใน `WHERE` ของทุก query ฝั่งพนักงาน

### 12.4 ตัดเกรด

```php
foreach ($rules as $level => $rule) {          // $rules เรียงจาก 5 ลง 1
    if (kpiCriterionMatches($rule, $value)) {
        return $level;                          // เจอระดับแรกที่เข้าเกณฑ์ หยุดทันที
    }
}
```

เปรียบเทียบ: เหมือนเทียบส่วนสูงกับขีดบนผนังจากขีดบนสุดลงมา ขีดแรกที่ถึงคือระดับที่ได้

`KPI_SCORE_EPSILON = 0.0001` คือค่าเผื่อเล็กน้อยตอนเทียบทศนิยม เพราะคอมพิวเตอร์เก็บทศนิยมไม่แม่น 100%

### 12.5 สูตรสำรอง

```php
$grade = (int) round($actual / $target * KPI_MAX_LEVEL);
return max(KPI_MIN_LEVEL, min(KPI_MAX_LEVEL, $grade));
```

- `min(5, $grade)` กันไม่ให้เกิน 5
- `max(1, ...)` กันไม่ให้ต่ำกว่า 1
- รวมกันเรียกว่า clamp

### 12.6 Transaction

```php
$pdo->beginTransaction();
try {
    foreach ($employee_ids as $employee_id) {
        if ($new_total_weight > 100) { throw new Exception("..."); }
        $insert_stmt->execute([...]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
}
```

เปรียบเทียบ: เหมือนโอนเงิน ถ้าหักเงินแล้วแต่เข้าบัญชีปลายทางไม่ได้ ต้องคืนทั้งหมด

### 12.7 ส่งข้อมูลให้กราฟ

```php
const monthLabels = <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
```

PHP ทำงานบนเซิร์ฟเวอร์ก่อน พิมพ์ผลเป็นข้อความ JavaScript เบราว์เซอร์ได้รับเป็น `const monthLabels = ["ม.ค.","ก.พ.",...];` แล้ว Chart.js นำไปวาด

---

## 13. FINAL CHEAT SHEET — อ่านก่อนเข้าห้องสอบ

### Architecture
- PHP ล้วน ไม่มี framework 1 หน้า = 1 ไฟล์ helper อยู่ใน `includes/`
- Admin / Manager ใช้ `index.php?page=` แบบ whitelist Employee เป็นหน้าแยกใช้ layout function
- ทุกหน้าเริ่มด้วย `security.php` แล้ว `database.php`

### Database
- PDO, `utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`
- **มอบหมายรายปี × รอบรายเดือน = ผลงานรายเดือน**
- UNIQUE: รอบ (ปี, เดือน) / มอบหมาย (KPI, พนักงาน, ปี) / ผลงาน (assignment, period)
- LEFT JOIN ใช้เมื่อ "ยังไม่มีข้อมูลก็ต้องแสดง"
- Transaction: มอบหมาย KPI และลบพนักงาน

### Login
- CSRF → ตรวจค่าว่าง → throttle 10 ครั้ง/15 นาที → SELECT → `password_verify` → สถานะบัญชี → สถานะพนักงาน → `session_regenerate_id` → เขียน session → log → redirect
- bcrypt ผ่าน `PASSWORD_DEFAULT`
- Cookie: HttpOnly, SameSite=Lax, Secure บน HTTPS
- Idle timeout 30 นาที และตรวจสถานะบัญชีทุก request
- Logout ต้องมี CSRF token

### Role
- 1 Admin, 2 Manager, 3 Employee
- Admin ตรวจ `=== 1` ทุกไฟล์ Manager ตรวจ `in [1,2]`
- ฝั่งพนักงาน: `employee_id` มาจาก session เสมอ

### KPI calculation
- Performance: Actual → เทียบเกณฑ์ 5→1 → เกรด ไม่มีเกณฑ์ใช้ `round(Actual÷Target×5)` แล้ว clamp 1-5
- Competency: เลือก 1-5 เอง
- คะแนนจริง = น้ำหนัก × เกรด คะแนนเต็ม = น้ำหนัก × 5
- เกรดรวม: เทียบฐาน 500 → 451 A+ / 400 A / 300 B / 200 C / D
- น้ำหนักรวม Performance + Competency ไม่เกิน 100
- บันทึกได้เฉพาะรอบประเมินที่ `Open`
- KPI "มูลค่าไม่ต่อสัญญา" ใช้กฎเฉพาะในไฟล์กลาง

### Dashboard
- Manager: โหลดทั้งปีครั้งเดียวด้วย `managerLoadYearData()` คำนวณใน PHP ด้วย `managerStats()`
- ส่งให้ Chart.js ด้วย `json_encode` ไม่มี AJAX
- Admin: SQL aggregate (`COUNT`, `AVG`, `SUM`, `GROUP BY`) ตามรอบที่เลือก

### Security
| ภัย | วิธีป้องกัน |
|---|---|
| SQL Injection | Prepared statement |
| XSS | `htmlspecialchars`, `JSON_HEX_TAG` |
| CSRF | token ในฟอร์ม + `hash_equals` |
| Session fixation | `session_regenerate_id(true)`, strict mode |
| Brute force | นับจาก `login_logs` |
| IDOR | `employee_id` จาก session |
| File inclusion | router แบบ whitelist |
| เปิดไฟล์ตรง | `.htaccess` Require all denied |

### ข้อจำกัดที่ต้องยอมรับตรง ๆ ถ้าถูกถาม
1. ไม่มีขั้นอนุมัติคะแนน (เป็นการประเมินตนเอง)
2. Manager ไม่จำกัดแผนก
3. เกณฑ์ KPI ของข้อมูลเก่ายังกระจายอยู่ 3 ตาราง (หน้าจอเขียนที่เดียวแล้ว)
4. พนักงานเปลี่ยนรหัสผ่านเองไม่ได้
5. รหัสฐานข้อมูลเป็น `root` รหัสว่าง เหมาะกับเครื่องพัฒนาเท่านั้น

วิธีตอบเมื่อถูกถามถึงจุดอ่อน: **ยอมรับ → อธิบายสาเหตุ → บอกวิธีแก้**
ตัวอย่าง "ระบบยังไม่มีขั้นอนุมัติครับ ตอนนี้มีคอลัมน์ status รองรับไว้แล้ว ถ้าพัฒนาต่อจะเพิ่มหน้าอนุมัติของหัวหน้า และให้รายงานกรองเฉพาะ status ที่อนุมัติแล้ว"

### เช็กลิสต์ก่อนสอบ
- [ ] เปิด Apache และ MySQL ใน XAMPP แล้ว login ให้ครบ 3 บทบาท
- [ ] เปิด Dashboard ของ Admin ดูว่าตัวเลขและกราฟขึ้น
- [ ] ทดสอบบันทึกผลงาน 1 รายการ ทั้ง Performance และ Competency
- [ ] ทดสอบ Export PDF และ Excel
- [ ] ทดสอบเพิ่ม KPI ใหม่พร้อมเกณฑ์ แล้วลบทิ้ง
- [ ] ตัดสินใจเรื่องทิศทางของกฎ "มูลค่าไม่ต่อสัญญา" (หัวข้อ 0.3)
- [ ] ตรวจ `git status` แล้ว commit โค้ดที่แก้ ถ้าต้องการ
- [ ] ฝึกพูดหัวข้อ 10 ทั้ง 15 จุดด้วยภาษาตัวเอง
