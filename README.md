# KPI Management System

ระบบจัดการและประเมินผล KPI ของพนักงานรายเดือน พัฒนาด้วย PHP + MySQL/MariaDB (PDO) สำหรับใช้งานบน XAMPP
แยกการใช้งานเป็น 3 บทบาท คือ **Admin**, **Manager** และ **Employee** พร้อมส่งออกแบบฟอร์มประเมินตามรูปแบบของบริษัทเป็น **Excel** และ **PDF**

> 📐 **นักพัฒนาที่จะแก้โค้ด** อ่าน [ARCHITECTURE.md](ARCHITECTURE.md) — อธิบายว่าแต่ละส่วนเชื่อมกันอย่างไร
> ข้อตกลงร่วมที่ห้ามแก้ข้างเดียว และโค้ดสำคัญพร้อมคำอธิบาย
> README ฉบับนี้ตอบว่า "ระบบทำอะไรได้บ้าง และติดตั้งอย่างไร"

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
Employee: นำเข้าผลงานรายเดือนจากไฟล์ Excel ของบริษัท (เฉพาะ KPI ที่ยืนยัน Mapping แล้ว)
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
| **น้ำหนักรวมต่อคน = 100%** | Performance + Competency ของพนักงานแต่ละคนในปีเดียวกันรวมกันไม่เกิน 100% (ระบบตรวจยอดรวมทั้งสองประเภทตอนมอบหมายและตอนแก้ไข) |

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
| `departments` | `manager/departments/departments.php` | เปรียบเทียบผลรายแผนก (ประเมินแล้ว / คะแนนเฉลี่ย + ระดับ · Performance / Competency เป็นบรรทัดรอง) · เจาะดูรายเดือน (เฉพาะเดือนที่ถึงรอบแล้ว) และพนักงานในแผนก · ค้นหาและกรองตามระดับในตาราง · ไม่แสดงแผนกที่ไม่มี KPI มอบหมายในปีนั้น (เช่น ผู้ดูแล / ฝ่ายบริหาร) ทั้งในตารางและตัวเลือกแผนก |
| `employees` | `manager/employees/employees.php` | จัดอันดับรายพนักงาน (ค้นหาชื่อ / รหัส / แผนก / ตำแหน่ง · กรองตามระดับ และ Feedback ของเดือนที่เลือก) และเจาะดูรายคน (ผลรายเดือนเฉพาะเดือนที่ถึงรอบแล้ว + KPI ที่ได้รับมอบหมาย ค้นหา/กรองตามประเภท · KPI 9 แสดงเป็น %) |
| Feedback | `manager/feedback.php` | ปุ่ม Feedback เปิดฟอร์ม (Dialog) เลือกเดือน ให้คะแนน 0-100 และข้อความถึงพนักงาน — 1 รายการต่อ หัวหน้า + พนักงาน + เดือน (ส่งซ้ำ = แก้ไข) |

ข้อมูลของทุกหน้าโหลดครั้งเดียวต่อปีผ่าน `manager/includes/manager-data.php` แล้วสรุปรายแผนก / รายคน / รายเดือนใน PHP
แถบค้นหา / ตัวกรองของตาราง: `manager/includes/table-tools.php` + `assets/js/manager-table.js` (กรองบนหน้าเว็บทันที ไม่โหลดหน้าใหม่)

### Employee

| หน้า | ไฟล์ | ความสามารถ |
| --- | --- | --- |
| หน้าแรก | `employee/index.php` | ข้อมูลส่วนตัว สถิติ กราฟ และ Feedback ล่าสุดจากหัวหน้า |
| บันทึกผล KPI (Excel) | `employee/kpi-import.php` | นำเข้าจากไฟล์ Excel จริงของบริษัท (เลือกปี/เดือน → Preview → ยืนยัน) · แสดงสถานะความพร้อมของ KPI แต่ละตัว · **ประวัติการนำเข้า** (วันเวลา เดือนข้อมูล ไฟล์ที่แนบ ผล) แสดง 3 รายการล่าสุด ปุ่ม "ดูทั้งหมด" แสดงทุกรายการ · เก็บตัวไฟล์ไว้ดาวน์โหลดเฉพาะ 3 ไฟล์ล่าสุด · ดูหัวข้อ "การบันทึกผล KPI" ด้านล่าง |
| KPI ของฉัน (ปิดแล้ว) | `employee/kpi/kpi.php` | ไม่กรอกผลด้วยตนเองแล้ว · URL เดิมส่งต่อไปหน้าบันทึกผล KPI (Excel) |
| ผลการปฏิบัติงาน | `employee/performance.php` | เลือกปี/เดือน ดูผลรายไตรมาส/รายเดือน (แยก Performance / Competency · ตัวเลขน้ำหนัก / Target / Actual / ความสำเร็จแสดงเป็นจำนวนเต็ม ปัดแบบปกติ (.5 ขึ้นไปปัดขึ้น) เฉพาะการแสดงผล คะแนนคิดจากค่าจริง) Feedback ของเดือนนั้น และส่งออก **PDF / Excel** · **แนบหลักฐาน** ต่อ KPI ต่อเดือน (Excel / รูปภาพ JPG PNG / PDF · ไฟล์ละ ≤ 10 MB · สูงสุด 5 ไฟล์ · แนบ/ลบได้เฉพาะผลงาน Draft) · หัวหน้าและ Admin ดู/ดาวน์โหลดได้ |
| รายละเอียด KPI | `employee/kpi-detail.php` | เปิดจากหน้าผลการปฏิบัติงาน: รายละเอียด KPI และประวัติผลงานทุกเดือน (ดูอย่างเดียว ไม่มีฟอร์มบันทึก) |
| Feedback จากหัวหน้า | `employee/feedback.php` | รายการ Feedback ทั้งปี (เดือน คะแนน ข้อความ ชื่อหัวหน้า) เลือกปีได้ |
| ข้อมูลส่วนตัว | `employee/profile.php` | ข้อมูลพนักงาน |

ทุกหน้าของพนักงานใช้โครงสร้างเดียวกับ Admin ผ่าน `employee/includes/layout.php`

### การบันทึกผล KPI (สถานะปัจจุบัน · ตุลาคม 2026)

- **ช่องทางเดียว:** `employee/kpi-import.php` นำเข้าจาก **ไฟล์ Excel จริงของบริษัท** (แบบฟอร์ม KPI AM) เลือกปีและเดือน → ระบบแสดง Preview → พนักงานยืนยัน → บันทึก
- **ปิดแล้ว:**
  - หน้ากรอกผลด้วยตนเอง: `employee/kpi/kpi.php` ส่งต่อ (303) ไปหน้านำเข้า, `employee/kpi-detail.php` เหลือเฉพาะดูรายละเอียดและประวัติ
  - **System-generated Template**: `employee/kpi-import-template.php` ส่งกลับหน้านำเข้า (ไม่ส่งไฟล์) · การอัปโหลด / Preview / Commit แบบ Template ถูกปฏิเสธฝั่ง Server ทุก KPI (`kpiImportValidate` / `kpiImportCommit` รับเฉพาะ origin = ไฟล์บริษัท ซึ่งกำหนดจาก Session)
- **KPI ที่ยืนยัน Mapping แล้ว: KPI 14, KPI 8, KPI 9, KPI 10 / 11 / 12 และ Competency 15-19** (ครบทุกหัวข้อในแบบฟอร์ม) (`includes/kpi-import-company-map.php`)
  - ชีต `สรุปทั้งปี..` ตาราง `Portfolio(มูลค่า)` แถวของเดือนหาจากชื่อเดือนในคอลัมน์ B · C = Budget (`'เดือน'!O41` = `SUM(O5:O39)` จาก Port26) · H = Total R = `SUM(D:G)` (Renew 1Y / 2Y / 3Y / Bait 3Y ไม่รวม New) · **R = `H/C`** (มกราคม = R5 = K12 ในแบบฟอร์ม · แบบฟอร์มฉบับปรับปรุง 2026-10-19)
  - Actual = Total R ÷ Budget × 100 ตัดทศนิยมลงเหลือ 2 ตำแหน่ง · Target ใน Assignment ต้องเป็น 100 (%) · คะแนนจาก `kpiCalculateScore()` ตามเกณฑ์ของ KPI ในระบบ (ไม่ใช้เกรดจาก Excel)
  - ตรวจหัวตาราง สูตร (`R = H/C`, `H = SUM(D:G)`) ค่า R = H/C และสูตรต้นทางทุกชั้นก่อนนำเข้า · F (Renew 3Y คอลัมน์ใหม่) ต้องว่าง — มีข้อมูลเมื่อไรจะไม่นำเข้าจนกว่าจะยืนยันแหล่งข้อมูล
- **KPI 8 "จำนวนรายการปรับเพิ่มมูลค่าสัญญา": เปิดใช้งานแล้ว** (เจ้าของระบบอนุมัติ Mapping 2026-10-18)
  - ⚠️ กฎไม่บันทึกบางส่วน: ถ้าข้อมูล KPI 8 ของเดือนที่เลือกไม่ผ่าน ไฟล์ของเดือนนั้นนำเข้าไม่ได้ทั้งไฟล์ (รวม KPI 14) · ไฟล์ปี 69 จึงนำเข้า มี.ค. มิ.ย. ก.ค. ก.ย. ไม่ได้จนกว่าจะแก้ข้อมูลในไฟล์
  - ความหมายที่เจ้าของระบบยืนยัน: ร้อยละที่มูลค่าสัญญารวมเพิ่มขึ้นจากมูลค่าเดิม · Target ใน Assignment ต้องเป็น 20 (%)
  - แหล่งข้อมูล = ชีตของเดือนที่เลือก (เช่น `ม.ค.69`) ไม่ใช้ช่อง "ที่ทำได้" ใน KPI AM · แถวรวมหาจากคอลัมน์ O (`O<n> = SUM(O5:O<n-2>)` · มิ.ย. ในไฟล์ปี 69 คือแถว 40)
  - **S (ผลต่าง) เป็นแหล่งข้อมูลหลัก:** S พิมพ์เองได้ ไม่ต้องเป็นสูตร `=R-O` · O (ราคาเดิม) ว่างได้ · R (ราคาสรุป) เป็นข้อมูลประกอบ ไม่ใช้แทน S
  - ตรวจ `S41 = SUM(S5:S39)` และค่าใน S41 ต้องเท่ากับผลรวมของ S รายแถว · `S42 = S41/$O$41` และค่าใน S42 ต้องเท่ากับ S41/O41 · O41 ต้องมากกว่า 0
  - **Actual = FLOOR(S42 × 100)** เป็นจำนวนเต็ม ปัดลงครั้งเดียวด้วย BCMath (ไม่ใช้ `round()`) และต้องได้ค่าเดียวกับ S41 × 100 ÷ O41 · ต้องมีส่วนขยาย BCMath บน PHP ของเว็บไซต์
  - ปฏิเสธ: แถวที่มี O หรือ R แต่ S ว่าง (ระบบไม่เติมค่าแทน) · S เป็นสูตร `=R-O` แต่ R ว่างขณะที่ O มีค่า · S เป็นสูตร `=R-O` แต่ค่าที่ Excel เก็บไว้ไม่เท่ากับ R − O (ช่องว่างนับเป็น 0 · ถ้า O และ R ว่างทั้งคู่ S จากสูตรเป็น 0 และนับเป็นแถวว่าง ไม่ถูกปฏิเสธ) · ผลติดลบ (ไม่ปรับเป็น 0) · R ว่างทั้งเดือน · ค่าที่อ่านไม่ได้ / Error · External Link · การอ้างชีตของเดือนอื่น · เดือนที่ยังไม่สิ้นสุดตามเวลา **Asia/Bangkok**
  - คะแนนจาก `kpiCalculateScore()` ตามเกณฑ์ของ KPI 8 ในระบบ ซึ่ง Admin กำหนดตามสูตร Excel ของบริษัท (`KPI AM!L13`): >20% = 5 · 15-20% = 4 · 10-14% = 3 · 5-9% = 2 · <5% = 1 · การนำเข้าตรวจว่าเกณฑ์ครบ 5 ระดับและครอบคลุม Actual 0–1000
  - ข้อจำกัด: ค่า S ที่พิมพ์เองไม่มีต้นทางให้ตรวจ (ตรวจได้แค่ว่าเป็นตัวเลขและรวมตรงกับ S41) · ไฟล์ปี 69 ผ่านการตรวจระดับ Reader เฉพาะ ม.ค. ก.พ. เม.ษ. พ.ค. ส.ค. (เดือนอื่นถูกปฏิเสธตามกฎ)
- **KPI 10 / 11 / 12** (ยืนยัน 2026-10-19): Actual = ค่าในช่อง "ที่ทำได้" ของแบบฟอร์ม KPI AM (แถว 15-17) × 100 — ค่าใน Excel เป็นสัดส่วน (1 = 100%) · คำนวณแบบแม่นยำ ไม่ปัดเศษ (ทศนิยมเกิน 2 ตำแหน่ง / ติดลบ / ไม่ใช่ตัวเลข → ปฏิเสธ) · คะแนนจากเกณฑ์และ Target ของ KPI ในระบบ
  (อัปเดต KPI 10 เมื่อ 2026-10-21: Target = 10% · เกณฑ์ 5: `>10%`, 4: `5-9%`, 3: `1-4%`, 2: `0%`, 1: `ไม่มี` · KPI 12 ยังคงใช้สูตร L17 `>90% / 90% / 85-89% / 80-84% / <80%`)
- **Competency 15-19** (ยืนยัน 2026-10-19): คะแนนจำนวนเต็ม 1-5 จากช่อง "ที่ทำได้" (แถว 24-28)
- **KPI 9 "มูลค่าไม่ต่อสัญญา"** (เจ้าของระบบกำหนด 2026-10-19 · แบบฟอร์มฉบับปรับปรุง): **Actual = สรุปทั้งปี69!U23** (= ช่อง K14 ของแบบฟอร์ม KPI AM) · ตาราง "Portfolio(จำนวนราย)" แถวของแต่ละเดือน (มกราคม = U23 … ธันวาคม = U34) · U = H/C (จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget) × 100 ตัดทศนิยมลง 2 ตำแหน่ง · **Target = 100 (%)** · คะแนนตามกฎ KPI 9 ของระบบ (≥100 / 96 / 90 / 85%) ซึ่งตรงกับสูตรเกรด L14 · ตรวจสูตรต้นทางทุกชั้น (C / D / E / G ต้องอ้างชีตของเดือนนั้นแถวเดียวกัน · F = Renew 3Y ต้องว่าง)
  · ตัวตรวจกลางรับ Target รายแถวเฉพาะ KPI 9 ที่มาจากไฟล์บริษัท (= 100 · KPI อื่นมี Target รายแถว → ปฏิเสธ) · หน้าผลงาน / Dashboard / รายละเอียด KPI / Export แสดง KPI 9 แบบเดียวกับ KPI อื่น (Target 100% · Actual เป็น %) ไม่แสดง Budget / Total R
- KPI ใหม่ที่จะเปิดนำเข้าต้องผ่าน Checklist 12 ข้อในหัวไฟล์ Mapping · Assignment ปี 2026 ทุกรายการครอบคลุม ม.ค.–ธ.ค. (ปรับตามที่เจ้าของระบบแจ้ง 2026-10-19)
- **สถานะ:** ผลที่นำเข้าบันทึกเป็น `Draft` เสมอ (ไม่เปลี่ยนเป็น Submitted/Approved อัตโนมัติ) · ไม่เขียนทับ Submitted / Approved / Rejected · แก้ Draft เดิมต้องติ๊กยืนยันรายแถว · นำเข้าซ้ำ = "ไม่เปลี่ยนแปลง" · ข้อมูลผิดแม้หนึ่งรายการ → ไม่บันทึกทั้งไฟล์
- **Budget > 0 แต่ Total R = 0:** เดือนที่ยังไม่สิ้นสุด → ไม่บันทึก (ข้อมูลอาจยังไม่ครบ) · เดือนที่สิ้นสุดแล้ว → แสดงคำเตือน (Budget, Total R, ผล 0%) และต้องติ๊กยืนยันรายแถว ไม่ติ๊ก = ไม่บันทึกรายการนั้น · สูตรคำนวณไม่ได้ / ข้อมูลผิด → ปฏิเสธ ไม่ถือว่าเป็น 0
- **การทดสอบ:** ทดสอบบน **ฐานข้อมูลทดสอบแยก** (MariaDB แบบ socket-only + ข้อมูลสังเคราะห์) · ไฟล์บริษัทจริงทดสอบเฉพาะการอ่านและตรวจโครงสร้าง (ไม่บันทึก) · **ยังไม่ได้ทดสอบการบันทึกกับฐานข้อมูล Production**
  - KPI 8: Preview / ยืนยันอัปเดต Draft รายแถว / ไม่ยืนยัน / Rollback เมื่อข้อมูลเปลี่ยนระหว่าง Preview กับยืนยัน ทดสอบบนฐานข้อมูลทดสอบแยก (ก่อนเปิด Mapping จริง)

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
| Performance | พนักงานกรอก Actual ระบบเทียบกับ **เกณฑ์ระดับผลงาน (Criteria)** ของ KPI นั้นเพื่อหาเกรด 1-5 |
| Competency | พนักงาน/ผู้ประเมินเลือกระดับ 1-5 ตามเกณฑ์ของ KPI |

การตัดเกรดอยู่ใน [`includes/kpi-score-helper.php`](includes/kpi-score-helper.php) ใช้ร่วมกันทั้งการนำเข้า (`includes/kpi-import-helper.php`)
และหน้าแสดงผล — แปลงข้อความเกณฑ์เป็นกฎเชิงตัวเลข รองรับ `>100%`, `100%`, `99-90%`, `<5%`,
`ตรงเวลา 95% - 97%`, `ความพึงพอใจ 90% ขึ้นไป`, `ต่ำกว่า 85%`, `ไม่เกิน 3 ครั้ง`
รายละเอียดการทำงานดูใน [ARCHITECTURE.md](ARCHITECTURE.md#การตัดเกรด-kpi)

> KPI ที่ยังไม่มีเกณฑ์เชิงตัวเลข (เกณฑ์เป็นข้อความล้วน) จะใช้สูตรสัดส่วน `Actual ÷ Target × 5`
> ปัดเป็นระดับเต็ม 1-5 เป็นตัวสำรอง — ทางที่ถูกต้องคือให้ Admin กรอกเกณฑ์ 5..1 ให้ครบ

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

`loadKpiScoreCriteria()` ใน `includes/kpi-score-helper.php` อ่านตามลำดับความสำคัญ **และเลือกแหล่งเดียวต่อ KPI**
(ไม่ผสมข้ามแหล่ง) ใช้ร่วมกันทั้งการนำเข้า การตัดเกรดตอนบันทึก และแบบฟอร์ม Excel/PDF

1. `kpi_score_criteria` (หน้าเพิ่ม KPI และหน้าแก้ไข KPI ของ Admin บันทึกที่นี่ ผ่าน `saveKpiScoreCriteria()`)
2. `kpi_score_levels`
3. `kpi_indicators.score_5` … `score_1` (ข้อมูลเก่า · หน้าเพิ่ม/แก้ไขเขียนสำเนาไว้ด้วย)

KPI ID 9 "มูลค่าไม่ต่อสัญญา" ใช้กฎเฉพาะใน `includes/kpi-score-helper.php` แทนเกณฑ์ในฐานข้อมูลเสมอ (ระบุด้วย `kpi_id = 9` และต้องมีชื่อ KPI ตรงประกอบ)

- Target = **Budget** (จำนวนเงิน กำหนดที่หน้ามอบหมาย KPI · มากกว่า 0 และไม่เกิน 99,999,999.99 ตามคอลัมน์ `decimal(10,2)`)
- Actual = **Total R** (ห้ามรวม Total N และห้ามใช้ Total R+N)
- Achievement = Total R / Budget × 100 (ตัดทศนิยมลง 2 ตำแหน่ง)

| คะแนน | Achievement |
|---|---|
| 5 | ≥ 100% |
| 4 | ≥ 96% และ < 100% |
| 3 | ≥ 90% และ < 96% |
| 2 | ≥ 85% และ < 90% |
| 1 | < 85% |

> ช่วงคะแนนต่อเนื่องนี้เป็นข้อกำหนดของระบบ เพื่อให้เปอร์เซ็นต์ทศนิยมทุกค่ามีคะแนน (เช่น 99.5% → 4, 95.5% → 3)
> ไม่ใช่การคัดลอกสูตร Excel เดิมทุกประการ (Excel เขียนช่วงเป็นจำนวนเต็ม เช่น 96–99%, 90–95%)

Budget เป็น 0 หรือ NULL → ระบบไม่คำนวณคะแนน แจ้ง error และไม่บันทึกผลงาน
ทุกหน้า (นำเข้าผลงาน, รายละเอียด, ผลงาน, Dashboard, Export PDF/Excel, สคริปต์คำนวณย้อนหลัง) เรียก `kpiCalculateScore()` / `nonRenewalAchievement()` ตัวเดียวกัน
คะแนนเดิมในฐานข้อมูลไม่ถูกคำนวณใหม่อัตโนมัติ · หน้ารายละเอียดแสดงทั้ง "คะแนนที่บันทึกไว้" และ "คะแนนตามเกณฑ์ปัจจุบัน" เมื่อไม่ตรงกัน

ถ้าผู้เรียกไม่ได้ `SELECT` คอลัมน์ `score_5..score_1` มาด้วย ฟังก์ชันจะ query เพิ่มเองสำหรับ KPI ที่ตกมาถึงข้อ 3

---

## เทคโนโลยีที่ใช้

| ส่วน | เทคโนโลยี |
| --- | --- |
| Backend | PHP 8.2 (พัฒนาและทดสอบบน XAMPP PHP 8.2.4), PDO |
| Database | MySQL / MariaDB (utf8mb4) |
| Excel | [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) ^5.9 |
| PDF | [dompdf/dompdf](https://github.com/dompdf/dompdf) ^3.1 + ฟอนต์ TH Sarabun New (`assets/fonts/`) |
| Frontend | HTML / CSS / JavaScript, ฟอนต์ Kanit (Google Fonts), Chart.js (CDN) สำหรับกราฟของ Manager |
| Icon | [Lucide](https://lucide.dev) (ISC License) แบบ inline SVG — ไฟล์อยู่ใน `assets/icons/` แสดงผลด้วย `icon()` ใน `includes/icons.php` สีและขนาดกำหนดใน `assets/css/icons.css` |

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

4. นำเข้าโครงสร้างตารางจาก [`database/schema.sql`](database/schema.sql) แล้วเพิ่มข้อมูลตั้งต้น (roles 1 admin / 2 manager / 3 employee และบัญชี Admin) หรือใช้ไฟล์ dump จากผู้พัฒนา
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
| 3 | `20260921_recalculate_kpi_scores.php` | คำนวณคะแนน Performance ที่บันทึกด้วยสูตรสัดส่วนเดิมใหม่ให้ตรงเกณฑ์ |
| 4 | `20261019_import_history_and_evidence.sql` | ตาราง `kpi_import_history` (ประวัติการนำเข้า) และ `kpi_performance_evidence` (หลักฐานผลงาน) · รันซ้ำได้ · Rollback: `..._rollback.sql` (ปฏิเสธถ้ายังมีข้อมูล) · **ยังไม่ได้รันบนฐานข้อมูลจริง** — ก่อนรันฟีเจอร์ทั้งสองถูกปิด หน้าเว็บทำงานแบบเดิม |

ในไฟล์ SQL มี pre-check ที่ต้องผ่านก่อน และไม่มีการลบตาราง/คอลัมน์/ข้อมูลเดิม

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root kpi_management_system < database/migrations/20260911_monthly_evaluation_periods.sql
```

รายการที่ 3 เป็นสคริปต์ PHP (CLI เท่านั้น) ค่าเริ่มต้นเป็น **dry run** ไม่แตะข้อมูล ต้องใส่ `--apply` จึงจะเขียนจริง

```bash
php database/migrations/20260921_recalculate_kpi_scores.php                                   # ดูผลก่อน
php database/migrations/20260921_recalculate_kpi_scores.php --apply --backup=backup.csv       # เขียนจริง + สำรอง
```

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
│   ├── kpi/kpi.php               # (ปิดแล้ว) ส่งต่อไปหน้าบันทึกผล KPI (Excel)
│   ├── kpi-import.php            # บันทึกผล KPI จากไฟล์ Excel ของบริษัท
│   ├── kpi-import-template.php   # (ปิดแล้ว) เดิมดาวน์โหลด Template ของระบบ
│   ├── performance.php           # ผลการปฏิบัติงาน
│   ├── kpi-detail.php            # รายละเอียด KPI + ประวัติ (ดูอย่างเดียว)
│   ├── feedback.php              # Feedback จากหัวหน้า
│   ├── profile.php               # ข้อมูลส่วนตัว
│   ├── performance-export-data.php   # ข้อมูลแบบฟอร์ม + เกณฑ์เกรด (ใช้ร่วม Excel/PDF)
│   ├── performance-export-excel.php  # Export Excel
│   └── performance-export-pdf.php    # Export PDF
├── includes/
│   ├── security.php              # เริ่ม session (HttpOnly/SameSite/strict mode), CSRF token, redirect ตามบทบาท
│   ├── kpi-score-helper.php      # ตัดเกรด 1-5 จากเกณฑ์ระดับผลงาน + โหลดเกณฑ์จากฐานข้อมูล
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
│   ├── schema.sql                # โครงสร้างตารางทั้งหมด (ไม่มีข้อมูล)
│   ├── migrations/               # SQL migrations
│   └── seeds/                    # Demo seed (CLI เท่านั้น)
├── vendor/                       # Dompdf, PhpSpreadsheet (Composer)
├── login.php                     # เข้าสู่ระบบ + บันทึก login_logs + redirect ตามบทบาท
├── logout.php                    # ออกจากระบบ
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
- `session_regenerate_id()` หลังเข้าสู่ระบบสำเร็จ และ `logout.php` ล้าง session + cookie (ลิงก์ออกจากระบบต้องมี CSRF token)
- Session หมดอายุเมื่อไม่ใช้งาน 30 นาที และตรวจสถานะบัญชี/พนักงานซ้ำทุก request (ปิดบัญชีแล้ว session ที่ค้างอยู่ถูกตัดใน request ถัดไป)
- Login ตรวจรหัสผ่านก่อนสถานะบัญชี ผู้ที่ไม่รู้รหัสผ่านได้ข้อความเดียวกันเสมอ
- พนักงานนำเข้าผลงานได้เฉพาะรอบประเมินที่สถานะ `Open` และเฉพาะข้อมูลของตนเอง (รหัสพนักงานในไฟล์ต้องตรงกับผู้ใช้ · `employee_id` มาจาก session)
- ตรวจสถานะบัญชีและสถานะพนักงานก่อนเข้าสู่ระบบ บันทึกทุกความพยายามลง `login_logs`
- หน้า Admin และ Manager ตรวจ `role_id` ก่อนแสดงผล ผู้ใช้บทบาทอื่นถูกส่งกลับหน้าแรกของบทบาทตัวเอง
- หน้าพนักงานและไฟล์ Export ใช้ `employee_id` จาก session จึงแสดงเฉพาะข้อมูลของผู้ที่ login อยู่ (หน้าแรกและข้อมูลส่วนตัวตรวจ `role_id = 3` เพิ่ม ส่วนหน้าอื่นตรวจเพียงว่า login และมี `employee_id`)
- Output ถูก escape ด้วย `htmlspecialchars()` และข้อมูลที่ฝังใน `<script>` ใช้ `json_encode` พร้อม `JSON_HEX_TAG`
- CSRF token ในทุกฟอร์ม POST (`includes/security.php`) และการลบ / เปิด-ปิดสถานะทำผ่าน POST เท่านั้น
- Session cookie เป็น `HttpOnly` + `SameSite=Lax` (+ `Secure` อัตโนมัติเมื่อใช้ HTTPS) และเปิด `session.use_strict_mode`
- Login ถูกจำกัดชั่วคราวเมื่อล้มเหลวเกิน 10 ครั้งใน 15 นาที (นับจาก `login_logs` ต่อ username / IP)
- ข้อความ error ของฐานข้อมูลไม่แสดงให้ผู้ใช้ (บันทึกลง error log ของ PHP แทน)
- `config/`, `includes/`, `database/`, `vendor/` มี `.htaccess` ปิดการเข้าถึงผ่าน URL โดยตรง
- ไฟล์ที่ผู้ใช้อัปโหลด (ไฟล์นำเข้า / หลักฐาน) เก็บใน `storage/` (สร้าง `.htaccess` Require all denied อัตโนมัติ · ไม่ขึ้น Git) ชื่อบนดิสก์เป็นค่าสุ่ม `.dat` · ดาวน์โหลดผ่าน `file-download.php` ที่ตรวจสิทธิ์ (เจ้าของ / Manager / Admin · ไม่มีสิทธิ์ = 404) ส่งพร้อม `nosniff` และ CSP sandbox
- หลักฐานตรวจชนิดจากเนื้อไฟล์ (xlsx = ZIP ที่มี workbook · xls = OLE2 · รูปภาพ = getimagesize · pdf = %PDF-) ไม่เชื่อนามสกุลหรือ MIME จากเบราว์เซอร์ · แนบ/ลบใน Transaction ที่ล็อกแถวผลงาน (เจ้าของ + Draft เท่านั้น)

ก่อนใช้งานจริง

- เปลี่ยนบัญชีฐานข้อมูลใน `config/database.php` (ไม่ใช้ `root` ที่ไม่มีรหัสผ่าน)
- ปิด `display_errors` และบันทึก error ลง log, ใช้ HTTPS (session cookie จะเป็น `Secure` ให้อัตโนมัติ)
- สำรองฐานข้อมูลเป็นประจำ

---

## ข้อจำกัดและปัญหาที่ทราบ

| เรื่อง | รายละเอียด |
| --- | --- |
| ลบ KPI | ปุ่มลบในหน้าจัดการ KPI จะปฏิเสธเมื่อ KPI ถูกมอบหมายแล้ว (`kpi_assignments` / `employee_kpi`) เพราะการลบจะ CASCADE ไปถึงผลงานของพนักงาน |
| คะแนนเป็นการรายงานตัวเอง ไม่มีขั้นอนุมัติ | คอลัมน์ `kpi_performances.status` (Draft/Submitted/Approved) ถูกเขียนแต่ไม่มี query ใดกรอง — พนักงานบันทึกแล้วคะแนนขึ้นรายงานผู้บริหารและไฟล์ Export ทันที และระบบยังไม่มีหน้าให้หัวหน้าให้คะแนนหรืออนุมัติ |
| Manager ไม่จำกัดแผนก | `manager/index.php` อ่าน `$_SESSION["department_id"]` แต่ไม่ได้ใช้กรอง · `manager/feedback.php` ไม่ตรวจว่าพนักงานอยู่ในขอบเขตของหัวหน้าคนนั้น (Feedback แยกแถวตามหัวหน้า จึงไม่เขียนทับของหัวหน้าคนอื่น แต่หน้าจอ Manager แสดงรายการล่าสุดของเดือนนั้น) |
| KPI 9 เดือนที่ไม่มีการต่อสัญญา | U = 0 แต่มี Budget (จำนวนราย) → ต้องติ๊กยืนยันรายแถวก่อนบันทึก · เดือนที่ยังไม่สิ้นสุดไม่บันทึก · U34 (ธันวาคม) ในไฟล์ปี 69 เป็นสูตร =C34/H34 จึงถูกปฏิเสธ · ชื่อหัวข้อในแบบฟอร์มบางข้อสะกดต่างจากชื่อในระบบ ต้องให้เจ้าของระบบยืนยันทีละตัว |
| การทดสอบไฟล์จริง | ไฟล์บริษัทจริงทดสอบเฉพาะการอ่าน/ตรวจโครงสร้าง · ยังไม่ทดสอบการบันทึกกับฐานข้อมูล Production · "เดือนยังไม่สิ้นสุด" ของ KPI 14 ใช้วันที่ของ Server (`date.timezone` ใน php.ini ของ XAMPP คือ Europe/Berlin) ส่วน KPI 8 ใช้เวลา Asia/Bangkok |
| แสดง Actual เป็นจำนวนเต็ม | หน้าผลการปฏิบัติงานแสดง Actual แบบจำนวนเต็ม (เช่น 99.99 แสดงเป็น 99) ค่าที่บันทึกและคะแนนถูกต้อง |
| Feedback ยังไม่มีสถานะอ่านแล้ว | พนักงานไม่มีการแจ้งเตือน Feedback ใหม่ |
