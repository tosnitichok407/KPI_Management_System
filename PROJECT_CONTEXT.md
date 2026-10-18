# PROJECT_CONTEXT — KPI Management System

บริบทสำหรับการพัฒนาต่อ · ศึกษาจากโค้ดใน branch `fix/review-findings-20260929` (ตุลาคม 2026)
แหล่งอ้างอิงเชิงลึก: `ARCHITECTURE.md`, `README.md`, `database/schema.sql`

---

## 1. ภาพรวมและเทคโนโลยี

- ระบบประเมินผล KPI รายเดือนของพนักงาน 3 บทบาท: Admin, Manager, Employee
- PHP แบบ procedural (ไม่มี framework) + MySQL/MariaDB ผ่าน PDO · รันบน XAMPP
- Composer: `dompdf/dompdf` (PDF), `phpoffice/phpspreadsheet` (Excel) · กราฟใช้ Chart.js
- ไม่มีชุดทดสอบอัตโนมัติ

## 2. โครงสร้างระบบ

| ส่วน | ตำแหน่ง | หมายเหตุ |
|---|---|---|
| Login / Logout | `login.php`, `logout.php` | logout ต้องมี CSRF token |
| Admin | `admin/<module>/` | ทุกหน้าตรวจ `role_id === 1` เอง |
| Manager | `manager/index.php?page=...` | router เดียว รับ role 1, 2 · หน้าย่อย redirect กลับถ้าเปิดตรง |
| Employee | `employee/` | ส่วนใหญ่ตรวจแค่ `user_id` + `employee_id` |
| Helper กลาง | `includes/` | security, คะแนน, รอบประเมิน, PDF, period picker |
| DB config | `config/database.php` | สร้าง `$pdo` แล้วเรียก `securityRecheckAccount()` |
| Migration / Seed | `database/migrations/`, `database/seeds/` | สคริปต์ PHP รันได้เฉพาะ CLI |

ลำดับ require ทุกหน้า: `includes/security.php` → `config/database.php` → helper อื่น

Role: `1 = Admin`, `2 = Manager`, `3 = Employee` (hard-code ในโค้ด)

## 3. ตารางฐานข้อมูลสำคัญ

| ตาราง | หน้าที่ | Key / ความสัมพันธ์ |
|---|---|---|
| `users` | บัญชีผู้ใช้ | FK → `employees` (1:1), `roles` |
| `employees` | พนักงาน | FK → `departments`, `positions` |
| `kpi_indicators` | นิยาม KPI: `kpi_type` (Performance/Competency), `weight`, `max_score`, เกณฑ์เก่า `score_5..score_1` | FK → `kpi_categories` |
| `kpi_score_criteria` | เกณฑ์ระดับ 1..5 ที่ใช้จริง | UNIQUE (`kpi_id`, `score_level`) |
| `kpi_assignments` | มอบหมาย KPI รายปี: `target_value`, `weight`, `start_date`/`end_date`, `status` | UNIQUE (`kpi_id`, `employee_id`, `assignment_year`) |
| `evaluation_periods` | รอบประเมินรายเดือน `Open`/`Closed` | UNIQUE (`period_year`, `period_month`) |
| `kpi_performances` | ผลงานรายเดือน: `target`, `actual`, `score`, `status` | UNIQUE (`assignment_id`, `period_id`) · CASCADE จาก assignment |
| `manager_feedback` | Feedback หัวหน้า คะแนน 0–100 + ข้อความ | UNIQUE (`manager_id`, `employee_id`, `period_id`) · **ไม่มี FK** · `manager_id` เก็บ `users.user_id` |
| `login_logs` | ประวัติ login · ใช้ทำ rate limit | FK → `users` |

ตารางเก่า (ไม่มีโค้ดเขียนแล้ว): `employee_kpi`, `performance_summary` — อ้างถึงเฉพาะตอนตรวจก่อนลบ
`kpi_score_levels` — ยังถูกอ่านเป็นแหล่งเกณฑ์สำรอง

## 4. Workflow หลัก

1. **Admin** สร้างรอบประเมินรายเดือน (`admin/evaluation/`)
2. **Admin** สร้าง KPI + เกณฑ์ระดับ 5..1 (`admin/kpi-management/`)
3. **Admin** มอบหมาย KPI ให้พนักงานรายปี กำหนด Target + Weight · น้ำหนักรวมต่อคนต่อปี ≤ 100% (`admin/kpi-assignment/`)
4. **Employee** นำเข้าผลงานจาก **ไฟล์ Excel จริงของบริษัท** (`employee/kpi-import.php`) เดือนที่รอบยัง `Open` → Preview → ยืนยัน → บันทึกเป็น `Draft`
   - รับเฉพาะ KPI ที่ยืนยัน Mapping แล้ว: ปัจจุบันมี **KPI 14 "บรรลุเป้าหมายการต่อสัญญา"** ตัวเดียว (Actual = Total R ÷ Budget × 100 ตัด 2 ตำแหน่ง · Target 100 · คะแนนจาก `kpiCalculateScore()`)
   - ปิดแล้ว: หน้ากรอกเอง (`employee/kpi/kpi.php`, ฟอร์มใน `employee/kpi-detail.php`) และ System-generated Template (`employee/kpi-import-template.php` + Template upload/Preview/Commit ถูกปฏิเสธฝั่ง Server)
   - Budget > 0 แต่ Total R = 0: เดือนยังไม่สิ้นสุด → ไม่บันทึก · สิ้นสุดแล้ว → เตือน + ติ๊กยืนยันรายแถว (ไม่ติ๊ก = ไม่บันทึก)
5. **Manager** ดู Dashboard / แผนก / รายคน และให้ Feedback (`manager/feedback.php`)
6. **รายงาน**: Admin summary + PDF · Employee performance + PDF/Excel

ไม่มีขั้นตอนอนุมัติ: สถานะ `Approved`/`Rejected` มีใน schema แต่ไม่มีโค้ดเขียนค่า (พบ `Approved` เฉพาะใน seed)

## 5. ไฟล์สำคัญ

| ไฟล์ | หน้าที่ |
|---|---|
| `includes/security.php` | session cookie, idle timeout 30 นาที, CSRF (`csrfField`/`csrfVerify`/`csrfRequirePost`), ตรวจสถานะบัญชีทุก request, redirect ตาม role |
| `config/database.php` | สร้าง PDO (ERRMODE_EXCEPTION, FETCH_ASSOC) |
| `login.php` | `password_verify`, rate limit จาก `login_logs`, `session_regenerate_id`, ตั้งค่า session |
| `includes/kpi-score-helper.php` | **แหล่งหลัก** ตัดเกรด + โหลด/บันทึกเกณฑ์ |
| `includes/monthly-period-helper.php` | หารอบตามเดือน, ตรวจการใช้งานรอบก่อนลบ |
| `employee/kpi-import.php` | **ช่องทางเดียวที่บันทึกผล KPI**: นำเข้าไฟล์ Excel ของบริษัท → Preview → Commit (`Draft`) |
| `includes/kpi-import-company-reader.php` | อ่าน/ตรวจแบบฟอร์ม KPI AM (allowlist ชีต, ตรวจสูตรทุกชั้น, กฎ KPI 14) |
| `includes/kpi-import-company-map.php` | Mapping หัวข้อแบบฟอร์ม → KPI ในระบบ (ยืนยันแล้วเฉพาะ KPI 14) + Checklist 12 ข้อ |
| `includes/kpi-import-helper.php` | Validate / Commit กลาง (Transaction + ล็อก, Draft เท่านั้น, ปิด origin Template) |
| `employee/kpi/kpi.php` | (ปิดแล้ว) ส่งต่อไปหน้านำเข้า |
| `employee/kpi-detail.php` | รายละเอียด + ประวัติ (ดูอย่างเดียว · ไม่มีฟอร์มบันทึกแล้ว) |
| `employee/performance.php` | ดูผลงานรายเดือน/ไตรมาส |
| `employee/performance-export-data.php` | ข้อมูล + สูตรคะแนนของ Export PDF/Excel |
| `admin/kpi-assignment/kpi-assignment.php` | สร้าง assignment (ตรวจน้ำหนักใน transaction) |
| `admin/kpi-assignment/kpi-assignment-edit.php` | แก้ target/weight/ช่วงวันที่ |
| `admin/kpi-management/kpi-edit.php` | แก้ KPI + เกณฑ์ (เขียนทั้ง `score_5..1` และ `kpi_score_criteria`) |
| `admin/kpi-summary/kpi-summary-data.php` | ข้อมูลรายงานสรุป Admin |
| `manager/includes/manager-data.php` | โหลดข้อมูลทั้งปีครั้งเดียวสำหรับทุกหน้า Manager |
| `database/schema.sql` | โครงสร้างฐานข้อมูล |

## 6. จุดคำนวณ KPI

**ตัดเกรดรายตัว** — `kpiPerformanceGrade()` ใน `includes/kpi-score-helper.php`
- ใช้เกณฑ์ที่ Admin กำหนดก่อน · ถ้าไม่มีเกณฑ์เชิงตัวเลข ใช้สำรอง `round(Actual / Target × 5)` บีบเป็น 1..5
- แปลงข้อความเกณฑ์ (เช่น `>100%`, `95% - 97%`) เป็นช่วงตัวเลข
- เดาว่าเกณฑ์เป็นค่าดิบหรือ % ของเป้า จากการเทียบ Target กับช่วงเกณฑ์ (tolerance 1.5 เท่า)

**ลำดับแหล่งเกณฑ์** (`loadKpiScoreCriteria()`): `kpi_score_criteria` → `kpi_score_levels` → `kpi_indicators.score_5..1`

**ฟังก์ชันกลาง**: `kpiCalculateScore($kpi, $levels, $actual, $target)` คืน grade / achievement / error · ทุกหน้าที่ตัดเกรดต้องเรียกตัวนี้

**กฎ KPI ID 9 "มูลค่าไม่ต่อสัญญา"** (ระบุด้วย `kpi_id = 9` + ชื่อตรงประกอบ · ตุลาคม 2026)
- Target = Budget (จำนวนเงิน) · Actual = Total R (ไม่รวม Total N)
- Achievement = Total R / Budget × 100 คำนวณด้วยจำนวนเต็มหน่วยสตางค์ ตัดลง 2 ตำแหน่ง
- ≥100% = 5 · ≥96% = 4 · ≥90% = 3 · ≥85% = 2 · <85% = 1
- ช่วงต่อเนื่องเป็นข้อกำหนดของระบบให้ครอบคลุมทศนิยม ไม่ใช่สำเนาสูตร Excel ทุกประการ
- Budget 0 / NULL → ไม่คำนวณ แจ้ง error ไม่บันทึก · Admin กรอก Budget > 0 ได้ไม่เกิน 99,999,999.99 (`decimal(10,2)`)
- ไม่มีการบังคับ Target = 100 แล้ว

**เส้นทางข้อมูล**: `kpi_assignments.target_value` → ตอนพนักงานบันทึก คัดลอก target + actual + เกรด ลง `kpi_performances` → รายงานทุกหน้าอ่าน `score` ที่บันทึกไว้ · หน้า KPI ของฉันและหน้ารายละเอียดคำนวณระดับตามเกณฑ์ปัจจุบันจาก Actual + Budget ปัจจุบัน และแสดงคะแนนที่บันทึกไว้ควบคู่เมื่อไม่ตรงกัน

**สูตรรวมคะแนนซ้ำหลายที่ (ผลไม่ตรงกัน)**

| ตำแหน่ง | สูตร |
|---|---|
| `admin/index.php` (SQL ~บรรทัด 305) | Σ(score / max_score × weight) / Σweight × 100 |
| `admin/kpi-summary/kpi-summary-data.php` (~278) | เหมือนข้างบน แต่เขียนใน PHP |
| `manager/includes/manager-data.php` (~292) | เหมือนข้างบน แต่เขียนใน PHP |
| `employee/performance-export-data.php` (~58, ~222) | floor(weight × เกรด) / (weight × 5) · ไม่ใช้ `max_score` |
| `employee/index.php` (~313) | ค่าเฉลี่ยไม่ถ่วงน้ำหนัก |

## 7. Known Issues

1. (ปิดแล้ว ตุลาคม 2026) เดิม `employee/kpi-detail.php` บันทึกผลได้โดยไม่ตรวจ `kpi_type` · ปัจจุบันหน้านี้ดูอย่างเดียว
2. (ปิดแล้ว ตุลาคม 2026) เดิมสองหน้ากรอกผลงานบันทึกสถานะต่างกัน · ปัจจุบันบันทึกผ่านการนำเข้าไฟล์บริษัทเท่านั้น (`Draft`)
3. รายงาน Admin/Manager ไม่กรองตามสถานะ — นับทุกแถวที่ `score IS NOT NULL` รวม `Draft`
4. `kpi-summary-data.php` ใช้ `MAX(kp.actual)`/`MAX(kp.status)` แต่ตั้งชื่อเป็น `last_*` (ไม่ใช่ค่าล่าสุด · MAX ของ status เทียบตามตัวอักษร)
5. `max_score` รับค่า 1..5 แต่เกรดสูงสุดคือ 5 เสมอ → score / max_score อาจเกิน 100% เมื่อ max_score < 5
6. แก้ Target หรือเกณฑ์แล้ว `kpi_performances.score` เดิมไม่ถูกคำนวณใหม่ (ต้องรัน migration recalculate เอง)
7. `kpi-assignment-edit.php` ตรวจน้ำหนักรวมแล้ว UPDATE โดยไม่มี transaction (หน้าสร้างใช้ transaction)
8. `manager_feedback` ไม่มี FK → ลบพนักงาน/รอบประเมินแล้วเหลือข้อมูลกำพร้า
9. หน้า Employee (`kpi-detail.php`, Export) ไม่ตรวจ `role_id` (`kpi.php` และหน้านำเข้าตรวจ `role_id = 3` แล้ว) — ผู้ใช้ role อื่นที่มี `employee_id` เข้าได้ (ยังไม่ทราบว่าตั้งใจหรือไม่)
10. Credential ฐานข้อมูล hard-code ใน `config/database.php` เป็นค่า default ของเครื่องพัฒนา — ต้องเปลี่ยนก่อนใช้งานจริง

ไม่พบ SQL Injection (ใช้ prepared statement ทั้งหมด) · ทุกฟอร์ม POST ตรวจ CSRF · `config/`, `includes/`, `database/`, `vendor/` มี `.htaccess` ป้องกัน

## 8. ข้อควรระวังก่อนแก้ไข

- **แก้สูตรเกรด**: แก้ที่ `kpi-score-helper.php` จุดเดียว แล้วทดสอบทั้งการนำเข้า (`kpi-import-helper.php`), หน้าแสดงผล และสคริปต์ `20260921_recalculate_kpi_scores.php`
- **แก้สูตรรวม/เปอร์เซ็นต์**: ต้องแก้ทั้ง 5 จุดในตารางหัวข้อ 6 พร้อมกัน
- **เปิดนำเข้า KPI ใหม่**: ห้ามคาดเดา Mapping · ต้องผ่าน Checklist 12 ข้อในหัว `includes/kpi-import-company-map.php` และได้รับการยืนยันจากเจ้าของระบบก่อนตั้ง `confirmed = true`
- **การทดสอบ**: ผลทดสอบการนำเข้าทั้งหมดมาจากฐานทดสอบแยก (MariaDB socket-only + ข้อมูลสังเคราะห์) · ไฟล์บริษัทจริงทดสอบเฉพาะการอ่าน/ตรวจโครงสร้าง · **ยังไม่ได้ทดสอบการบันทึกกับ Production**
- **ห้ามเปลี่ยนลำดับแหล่งเกณฑ์** โดยไม่ตรวจ KPI ที่ยังใช้เกณฑ์แบบเก่า — เกรดจะเปลี่ยน
- **KPI 9 ผูกกับ ID 9 + ชื่อ "มูลค่าไม่ต่อสัญญา"** — ถ้าเปลี่ยนชื่อหรือ ID กฎพิเศษจะไม่ทำงาน (มี error_log เตือนเมื่อ ID ตรงแต่ชื่อไม่ตรง)
- **ข้อมูลเดิมของ KPI 9** — Budget ของ assignment เดิมเป็น 5.00 และผลงานเดิมกรอกเป็น % · Admin ต้องกรอก Budget จริงใหม่ · ห้ามรัน recalculate `--apply` โดยไม่ได้รับอนุญาต
- **FK CASCADE**: ลบ assignment / รอบประเมิน / พนักงาน → ผลงานถูกลบตาม · หน้าลบรอบประเมินมีการตรวจการใช้งานก่อน
- **Migration ที่มี `--apply`** เขียนทับคะแนนจริง — รันแบบดูผลก่อน และใช้ `--backup=ไฟล์.csv` ทุกครั้ง · ทดสอบด้วย `--db=` กับฐานทดสอบได้
- Role ID ถูก hard-code (1/2/3) หลายไฟล์ — เปลี่ยนตาราง `roles` ต้องตรวจทุกจุด

## 9. สิ่งที่ยังไม่ทราบ

- เวอร์ชัน PHP / MariaDB ที่ใช้งานจริง
- จำนวน KPI ที่ยังใช้เกณฑ์แบบเก่า (`kpi_score_levels` หรือ `score_5..1`)
- ยอด Budget สูงสุดจริงของบริษัท (ถ้าเกิน 99,999,999.99 ต้องขยายคอลัมน์ `target_value`)
- กฎธุรกิจว่า `Draft` ควรนับในรายงานหรือไม่ และจะมีขั้นตอนอนุมัติหรือไม่ (ผลจากการนำเข้าเป็น `Draft` ทั้งหมด)
- Mapping ของ KPI อื่นในแบบฟอร์ม KPI AM (ชื่อหัวข้อบางข้อสะกดต่างจากชื่อในระบบ) และความสัมพันธ์ของ KPI 9 กับ KPI 14 (ใช้ Total R / Budget เหมือนกัน)
- Manager ควรเห็นเฉพาะแผนกตัวเองหรือไม่ (ปัจจุบันเห็นทุกแผนก)
