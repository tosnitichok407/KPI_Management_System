# PROJECT_CONTEXT — KPI Management System

บริบทสำหรับการพัฒนาต่อ · ศึกษาจากโค้ดใน branch `fix/review-findings-20260929` (ตุลาคม 2026)
แหล่งอ้างอิงเชิงลึก: `ARCHITECTURE.md`, `README.md`, `database/schema.sql`

---

## 1. ภาพรวมและเทคโนโลยี

- ระบบประเมินผล KPI รายเดือนของพนักงาน 3 บทบาท: Admin, Manager, Employee
- PHP แบบ procedural (ไม่มี framework) + MySQL/MariaDB ผ่าน PDO · รันบน XAMPP
- Composer: `dompdf/dompdf` (PDF), `phpoffice/phpspreadsheet` (Excel) · กราฟใช้ Chart.js
- ไม่มีชุดทดสอบอัตโนมัติใน Repo (ชุดทดสอบการนำเข้าที่ใช้ตรวจงานอยู่นอก Repo และรันกับฐานข้อมูลทดสอบแยกเท่านั้น)

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
| `kpi_import_history` | ประวัติการนำเข้าไฟล์บริษัท 1 แถวต่อการยืนยันที่สำเร็จ · `stored_name` = ไฟล์ใน `storage/imports` (NULL = ลบแล้ว · เก็บ 3 ไฟล์ล่าสุดต่อพนักงาน) | FK → `employees` CASCADE · Migration 20261019 (ยังไม่รันบน Production) |
| `kpi_performance_evidence` | ไฟล์หลักฐานต่อผลงาน (≤ 5 ไฟล์ · ≤ 10 MB · xlsx/xls/jpg/jpeg/png/pdf) · ไฟล์ใน `storage/evidence` | FK → `kpi_performances` RESTRICT · Migration 20261019 |

ตารางเก่า (ไม่มีโค้ดเขียนแล้ว): `employee_kpi`, `performance_summary` — อ้างถึงเฉพาะตอนตรวจก่อนลบ
`kpi_score_levels` — ยังถูกอ่านเป็นแหล่งเกณฑ์สำรอง

## 4. Workflow หลัก

1. **Admin** สร้างรอบประเมินรายเดือน (`admin/evaluation/`)
2. **Admin** สร้าง KPI + เกณฑ์ระดับ 5..1 (`admin/kpi-management/`)
3. **Admin** มอบหมาย KPI ให้พนักงานรายปี กำหนด Target + Weight · น้ำหนักรวมต่อคนต่อปี ≤ 100% (`admin/kpi-assignment/`)
4. **Employee** นำเข้าผลงานจาก **ไฟล์ Excel จริงของบริษัท** (`employee/kpi-import.php`) เดือนที่รอบยัง `Open` → Preview → ยืนยัน → บันทึกเป็น `Draft`
   - รับเฉพาะ KPI ที่ยืนยัน Mapping แล้ว: ปัจจุบันมี **KPI 14 "บรรลุเป้าหมายการต่อสัญญา"** (Actual = Total R ÷ Budget × 100 ตัด 2 ตำแหน่ง · Target 100 · คะแนนจาก `kpiCalculateScore()`) และ KPI 8
   - **KPI 10 / 11 / 12** (ยืนยัน 2026-10-19): Actual = ช่อง "ที่ทำได้" ในแบบฟอร์ม KPI AM (แถว 15-17) × 100 ไม่ปัดเศษ · **Competency 15-19**: คะแนน 1-5 จากช่อง "ที่ทำได้" · **KPI 9 "มูลค่าไม่ต่อสัญญา"** (เจ้าของระบบกำหนด 2026-10-19 · แบบฟอร์มฉบับปรับปรุง): **Actual = สรุปทั้งปี69!U23** (= ช่อง K14 ของแบบฟอร์ม KPI AM) · ตาราง "Portfolio(จำนวนราย)" แถวของแต่ละเดือน (มกราคม = U23 … ธันวาคม = U34) · U = H/C (จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget) × 100 ตัดทศนิยมลง 2 ตำแหน่ง · **Target = 100 (%)** · คะแนนตามกฎ KPI 9 ของระบบ (≥100 / 96 / 90 / 85%) ซึ่งตรงกับสูตรเกรด L14 · ตรวจสูตรต้นทางทุกชั้น (C / D / E / G ต้องอ้างชีตของเดือนนั้นแถวเดียวกัน · F = Renew 3Y ต้องว่าง)
   - **KPI 8 "จำนวนรายการปรับเพิ่มมูลค่าสัญญา"**: **เปิดใช้งานแล้ว** (เจ้าของระบบอนุมัติ 2026-10-18) · ข้อมูล KPI 8 ของเดือนใดไม่ผ่าน → ไฟล์เดือนนั้นนำเข้าไม่ได้ทั้งไฟล์ (รวม KPI 14) · รายละเอียดกฎในหัวข้อ 6
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
| `includes/kpi-import-company-reader.php` | อ่าน/ตรวจแบบฟอร์ม KPI AM (allowlist ชีต, ตรวจสูตรทุกชั้น, กฎ KPI 14, KPI 8, KPI 10-12 และ Competency) |
| `includes/kpi-import-company-map.php` | Mapping หัวข้อแบบฟอร์ม → KPI ในระบบ (ยืนยันแล้ว KPI 14, 8, 9, 10, 11, 12, 15-19) + Checklist 12 ข้อ |
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

**กฎนำเข้า KPI 8 "จำนวนรายการปรับเพิ่มมูลค่าสัญญา"** (`value_rule` = `contract_uplift_s_over_o_floor_percent` · เปิดใช้งาน 2026-10-18)
- ความหมายที่เจ้าของระบบยืนยัน: ร้อยละที่มูลค่าสัญญารวมเพิ่มขึ้นจากมูลค่าเดิม · Target ใน Assignment ต้องเป็น 20
- แหล่งข้อมูล: ชีตของเดือนที่เลือก (ไม่ใช่ช่อง "ที่ทำได้" ใน KPI AM) · แถวรวม n หาจากสูตร `O<n> = SUM(O5:O<n-2>)`
- **S (ผลต่าง) เป็นแหล่งข้อมูลหลัก** — พิมพ์เองได้ ไม่ต้องเป็นสูตร `=R-O` · O (ราคาเดิม) ว่างได้ · R (ราคาสรุป) เป็นข้อมูลประกอบ
- ตรวจ `S<n> = SUM(S5:S<n-2>)` และค่าตรงกับผลรวมรายแถว · `S<n+1> = S<n>/$O$<n>` และค่าตรงกับ S<n>/O<n> · O<n> > 0
- **Actual = FLOOR(S<n+1> × 100)** เป็นจำนวนเต็ม ปัดลงครั้งเดียวด้วย BCMath (ไม่ใช้ `round()`) · ต้องตรงกับ FLOOR(S<n> × 100 ÷ O<n>)
- ปฏิเสธ: แถวที่มี O หรือ R แต่ S ว่าง (ไม่เติมค่าแทน) · S เป็นสูตร `=R-O` แต่ R ว่างขณะที่ O มีค่า · S เป็นสูตร `=R-O` แต่ค่าที่เก็บไว้ไม่ตรง R − O (ช่องว่างนับเป็น 0 · ถ้า O และ R ว่างทั้งคู่ S จากสูตรเป็น 0 และนับเป็นแถวว่าง ไม่ถูกปฏิเสธ) · ผลติดลบ · R ว่างทั้งเดือน · Error / External Link · อ้างชีตเดือนอื่น · เดือนยังไม่สิ้นสุดตามเวลา Asia/Bangkok
- เกณฑ์คะแนนอ้างอิงสูตร Excel ของบริษัท (`KPI AM!L13`) ที่ Admin บันทึกในระบบ: >20% = 5 · 15-20% = 4 · 10-14% = 3 · 5-9% = 2 · <5% = 1 · การนำเข้าตรวจว่าเกณฑ์ครบ 5 ระดับและครอบคลุม Actual 0–1000
- ข้อจำกัด: ค่า S ที่พิมพ์เองไม่มีต้นทางให้ตรวจ · แถวที่มีราคาแต่ S ว่างทำให้ทั้งเดือนนำเข้าไม่ได้ · คะแนนของแถว KPI 8 เดิมไม่ถูกคำนวณใหม่ตามเกณฑ์ใหม่

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
- **KPI 8 เปิดแล้ว (2026-10-18)**: ต้องมี BCMath บน PHP ของเว็บไซต์ (ตรวจแล้วบน Apache ของ XAMPP เครื่องนี้) · ข้อมูล KPI 8 ของเดือนใดไม่ผ่าน ไฟล์เดือนนั้นนำเข้าไม่ได้ทั้งไฟล์ (รวม KPI 14) · ปิด KPI 8 ได้โดยตั้ง `confirmed = false` ใน Mapping
- **Timezone**: ไม่มีการตั้ง timezone ในโค้ด (ใช้ `date.timezone` ของ php.ini · XAMPP = Europe/Berlin) · กฎเดือนสิ้นสุดของ KPI 8 ใช้ Asia/Bangkok แยกต่างหาก · กฎของ KPI 14 และ `kpiImportCommit` ยังใช้เวลาของ PHP · ช่วงต้นวันที่ 1 ตามเวลาไทยที่ระบบยังถือว่าเดือนก่อนยังไม่สิ้นสุดขึ้นกับความต่างระหว่างเวลาเบอร์ลินกับเวลาไทย: เวลามาตรฐานของเบอร์ลิน (UTC+1) ต่างกัน 6 ชั่วโมง → ช่วง 00:00–05:59 น. เวลาไทย · ช่วง Daylight Saving Time ของเบอร์ลิน (UTC+2 ประมาณปลายมีนาคมถึงปลายตุลาคม) ต่างกัน 5 ชั่วโมง → ช่วง 00:00–04:59 น. เวลาไทย
- **ห้ามเปลี่ยนลำดับแหล่งเกณฑ์** โดยไม่ตรวจ KPI ที่ยังใช้เกณฑ์แบบเก่า — เกรดจะเปลี่ยน
- **KPI 9 ผูกกับ ID 9 + ชื่อ "มูลค่าไม่ต่อสัญญา"** — ถ้าเปลี่ยนชื่อหรือ ID กฎพิเศษจะไม่ทำงาน (มี error_log เตือนเมื่อ ID ตรงแต่ชื่อไม่ตรง)
- **ข้อมูลเดิมของ KPI 9** — Budget ของ assignment เดิมเป็น 5.00 และผลงานเดิมกรอกเป็น % · Admin ต้องกรอก Budget จริงใหม่ · ห้ามรัน recalculate `--apply` โดยไม่ได้รับอนุญาต
- **FK CASCADE**: ลบ assignment / รอบประเมิน / พนักงาน → ผลงานถูกลบตาม · หน้าลบรอบประเมินมีการตรวจการใช้งานก่อน
- **ไฟล์อัปโหลด (`storage/`)**: ต้องให้ Apache (ผู้ใช้ `daemon`) อ่าน/เขียนโฟลเดอร์ได้ก่อนเปิดใช้ · ฟีเจอร์ประวัติ/หลักฐานเปิดเองเมื่อมีตารางของ Migration 20261019 (`kpiFileFeatureEnabled`) · ลบพนักงาน → ประวัติถูกลบตาม แต่ตัวไฟล์ใน `storage/imports` ไม่ถูกลบ (ระบบปฏิเสธการลบพนักงานที่มีผลงานอยู่แล้ว) · สำรอง `storage/` คู่กับฐานข้อมูล
- **Migration ที่มี `--apply`** เขียนทับคะแนนจริง — รันแบบดูผลก่อน และใช้ `--backup=ไฟล์.csv` ทุกครั้ง · ทดสอบด้วย `--db=` กับฐานทดสอบได้
- Role ID ถูก hard-code (1/2/3) หลายไฟล์ — เปลี่ยนตาราง `roles` ต้องตรวจทุกจุด

## 9. สิ่งที่ยังไม่ทราบ

- เวอร์ชัน PHP / MariaDB ที่ใช้งานจริง
- จำนวน KPI ที่ยังใช้เกณฑ์แบบเก่า (`kpi_score_levels` หรือ `score_5..1`)
- ยอด Budget สูงสุดจริงของบริษัท (ถ้าเกิน 99,999,999.99 ต้องขยายคอลัมน์ `target_value`)
- กฎธุรกิจว่า `Draft` ควรนับในรายงานหรือไม่ และจะมีขั้นตอนอนุมัติหรือไม่ (ผลจากการนำเข้าเป็น `Draft` ทั้งหมด)
- KPI 9: Actual จาก สรุปทั้งปี69!U ของเดือน (มกราคม U23) · KPI 14 จาก R ของเดือน (มกราคม R5) — แบบฟอร์มฉบับปรับปรุงเท่านั้น (ไฟล์ฉบับเดิมที่ใช้ Q / T จะไม่ผ่านการตรวจหัวตาราง) · Target ใน Assignment ของ KPI 9 (5.00) ไม่ถูกใช้ในการนำเข้า แต่ยังแสดงในหน้าระดับ Assignment (Admin / Manager) · T34 ในไฟล์ปี 69 สูตรผิด (=C34/G34) · ผลงาน KPI 9 เดือนมกราคมเดิมในระบบจริง (Draft) ต้องนำเข้าใหม่และติ๊กยืนยันจึงจะอัปเดต
- KPI 8: กติกาสำหรับแถวที่มีราคา (O/R) แต่ไม่มีผลต่าง (S) · ความถูกต้องของค่า S ที่พิมพ์เอง · จะเลือกแก้ timezone ของ KPI 14 แบบใด
- Manager ควรเห็นเฉพาะแผนกตัวเองหรือไม่ (ปัจจุบันเห็นทุกแผนก)
