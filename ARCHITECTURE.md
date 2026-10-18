# สถาปัตยกรรมและจุดเชื่อมของระบบ

เอกสารนี้อธิบายว่า KPI Management System **ทำงานอย่างไร** และ **แต่ละส่วนเชื่อมกันตรงไหน**
สำหรับคนที่ต้องเข้าไปแก้โค้ด

| เอกสาร | ตอบคำถาม |
| --- | --- |
| [README.md](README.md) | ระบบทำอะไรได้บ้าง · ติดตั้งอย่างไร · ตารางในฐานข้อมูลมีอะไร |
| **ARCHITECTURE.md** (ฉบับนี้) | คำขอหนึ่งครั้งเดินทางผ่านอะไร · ข้อตกลงร่วมที่ห้ามแก้ข้างเดียว · โค้ดสำคัญอยู่ตรงไหน |

> เลขบรรทัดอ้างอิงสถานะ ณ commit ล่าสุด ใช้ชื่อฟังก์ชัน/ตัวแปรค้นหาจะแม่นกว่าถ้าโค้ดขยับ

---

## สารบัญ

- [1. แผนที่หนึ่งหน้า](#1-แผนที่หนึ่งหน้า)
- [2. ข้อตกลงร่วม — สิ่งที่เชื่อมทุกส่วนเข้าด้วยกัน](#2-ข้อตกลงร่วม--สิ่งที่เชื่อมทุกส่วนเข้าด้วยกัน)
- [3. โมเดลข้อมูล](#3-โมเดลข้อมูล)
- [4. การตัดเกรด KPI](#4-การตัดเกรด-kpi)
- [5. เดินตามโค้ด 4 เส้นทาง](#5-เดินตามโค้ด-4-เส้นทาง)
- [6. แผนที่การพึ่งพา](#6-แผนที่การพึ่งพา)
- [7. กับดักที่ต้องรู้ก่อนแก้โค้ด](#7-กับดักที่ต้องรู้ก่อนแก้โค้ด)

---

## 1. แผนที่หนึ่งหน้า

ระบบนี้ **ไม่มี framework ไม่มี router กลาง ไม่มี autoloader ของตัวเอง**
ทุกหน้าคือไฟล์ PHP ที่รับคำขอเองตั้งแต่ต้นจนจบ สิ่งที่ทำให้ทั้งระบบสอดคล้องกันคือ
**ทุกไฟล์เริ่มต้นเหมือนกัน** และ **ใช้ helper ชุดเดียวกัน**

```text
                    ┌──────────────────────────────────┐
  HTTP request ───► │ includes/security.php            │  ประตูเดียวของทุกหน้า
                    │  · session (HttpOnly/SameSite)   │
                    │  · security headers              │
                    │  · csrfToken/Field/Verify        │
                    │  · redirectToRoleHome()          │
                    └───────────────┬──────────────────┘
                                    │
                    ┌───────────────▼──────────────────┐
                    │ config/database.php  →  $pdo     │  ตัวแปร global ตัวเดียว
                    └───────────────┬──────────────────┘
                                    │
          ┌─────────────────────────┼─────────────────────────┐
          ▼                         ▼                         ▼
   admin/index.php          manager/index.php          employee/*.php
   role_id === 1            role_id ∈ {1,2}            มี employee_id
   router ?page=            router ?page=              หน้าแยก + layout()
          │                         │                         │
          │                         │                         │
     include partial          managerLoadYearData()      employeeLayoutStart()
     จาก $pages[]             โหลดทั้งปีครั้งเดียว        + เนื้อหา + End()
          │                         │                         │
          └─────────────────────────┴─────────────────────────┘
                                    │
                    ┌───────────────▼──────────────────┐
                    │ includes/ — helper ที่ใช้ร่วมกัน  │
                    │  kpi-score-helper   ตัดเกรด      │
                    │  monthly-period-helper  รอบเดือน │
                    │  quarter-helper     ไตรมาส       │
                    │  period-picker      ตัวเลือกเดือน│
                    │  pdf-helper         PDF ไทย      │
                    └──────────────────────────────────┘
```

**สามบทบาทใช้รูปแบบต่างกัน** — เรื่องนี้ทำให้สับสนบ่อยที่สุด

| บทบาท | รูปแบบ | รายละเอียด |
| --- | --- | --- |
| Admin | router + inline dashboard | `admin/index.php?page=x` · `$pages` map 8 รายการ · หน้า `home` เป็น HTML ฝังอยู่ใน `index.php` เอง (`"home" => null`) |
| Manager | router ล้วน | `manager/index.php?page=x` · `$pages` map 3 รายการ · `home` ชี้ไปไฟล์จริง `dashboard/dashboard.php` |
| Employee | หน้าแยกอิสระ | ไม่มี router · แต่ละไฟล์เรียก `employeeLayoutStart()` / `employeeLayoutEnd()` ครอบเนื้อหา |

---

## 2. ข้อตกลงร่วม — สิ่งที่เชื่อมทุกส่วนเข้าด้วยกัน

นี่คือหัวใจของเอกสารนี้ ข้อตกลง 9 ข้อต่อไปนี้คือ "กาว" ที่ยึดระบบไว้
**แก้ข้างเดียวเมื่อไหร่ ระบบจะเพี้ยนแบบเงียบ ๆ ทันที**

### 2.1 `includes/security.php` คือประตูเดียว ห้ามเรียก `session_start()` เอง

ทุกไฟล์ที่รับคำขอต้องเริ่มด้วยบรรทัดนี้ **เป็นบรรทัดแรกเสมอ** (ก่อน `config/database.php`)

```php
require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
```

ไฟล์นี้ทำ 3 อย่างที่หน้าอื่นพึ่งพา — [`includes/security.php:18-43`](includes/security.php#L18-L43)

```php
if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.use_strict_mode", "1");      // กัน session fixation
    ini_set("session.use_only_cookies", "1");
    session_set_cookie_params([
        "secure" => $isHttps,  "httponly" => true,  "samesite" => "Lax"
    ]);
    session_start();
}
```

เงื่อนไข `session_status() === PHP_SESSION_NONE` คือเหตุผลที่ `require_once` ซ้ำหลายชั้นไม่พัง
และเป็นสาเหตุที่ **ลำดับ require สำคัญ** — ถ้ามีไฟล์ไหนเรียก `session_start()` เองก่อน
cookie flags ทั้งหมดจะหายไปโดยไม่มี error

### 2.2 Session คือสัญญาระหว่าง `login.php` กับทุกหน้า

`login.php` เขียนคีย์ชุดนี้ลง session หลังตรวจรหัสผ่านผ่าน แล้ว **ไม่มีที่ไหนเขียนซ้ำอีกเลย**
ทุกหน้าอ่านอย่างเดียว — [`login.php:315-352`](login.php#L315-L352)

| คีย์ | ใครใช้ |
| --- | --- |
| `user_id` | ทุกหน้า (เช็ค login) · `manager_feedback.manager_id` |
| `role_id` | ตัวกำหนดสิทธิ์ทั้งระบบ · `roleHomeUrl()` |
| `employee_id` | **ขอบเขตข้อมูลของพนักงาน** — ทุก query ฝั่ง employee ผูกกับคีย์นี้ |
| `first_name` / `last_name` / `employee_code` | `employeeLayoutStart()` และ topbar |
| `department_id` | `manager/index.php:48` อ่านแต่ **ยังไม่ได้ใช้กรอง** (ดู [§7](#7-กับดักที่ต้องรู้ก่อนแก้โค้ด)) |
| `csrf_token` | สร้าง lazy ตอนเรียก `csrfToken()` ครั้งแรก |

`session_regenerate_id(true)` ถูกเรียกก่อนเขียนคีย์เหล่านี้ ([`login.php:315`](login.php#L315))

### 2.3 CSRF — คู่ `csrfField()` / `csrfVerify()` ต้องมาเป็นคู่เสมอ

ทุกฟอร์ม POST ในระบบทำแบบเดียวกัน ไม่มีข้อยกเว้น

```php
<form method="POST">
    <?= csrfField() ?>          <!-- ฝั่งแสดงผล -->
```
```php
if (!csrfVerify()) {             // ฝั่งรับค่า — ต้องเป็นเงื่อนไขแรกสุด
    $error = "Session หมดอายุ กรุณาลองใหม่อีกครั้ง";
} elseif (...)
```

ไฟล์ action ที่ไม่มีหน้าจอ (ลบ / เปิด-ปิดสถานะ) ใช้ `csrfRequirePost()` ซึ่งบังคับทั้ง method และ token
— [`includes/security.php:81-87`](includes/security.php#L81-L87)

```php
function csrfRequirePost(string $redirectTo): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST" || !csrfVerify()) {
        header("Location: " . $redirectTo);
        exit;
    }
}
```

### 2.4 กุญแจของผลงานคือ `(assignment_id, period_id)`

ประโยคเดียวที่อธิบายโมเดลทั้งระบบ:

```text
มอบหมาย KPI = รายปี        kpi_assignments   1 แถว ต่อ (KPI, พนักงาน, ปี)
รอบประเมิน  = รายเดือน      evaluation_periods 1 แถว ต่อ (ปี, เดือน)
ผลงาน      = มอบหมาย × เดือน  kpi_performances  1 แถว ต่อ (assignment, period)
```

เวลาจะเขียนหรืออ่านผลงาน **ต้องมีครบทั้งสองคีย์** ทุกหน้าที่บันทึกผลงานจึงมีลำดับเดียวกัน:
หา `period_id` จากปี+เดือนที่เลือก → หา assignment ที่เป็นของพนักงานคนนี้ → ค่อยเขียน

```php
$selectedPeriod   = findEvaluationPeriodByMonth($pdo, $selectedYear, $selectedMonth);
$selectedPeriodId = $selectedPeriod ? (int) $selectedPeriod["period_id"] : 0;
```

ถ้าเดือนนั้นยังไม่มีรอบประเมิน ระบบ **ปฏิเสธการบันทึก** ไม่ใช่สร้างรอบให้อัตโนมัติ
— ปัจจุบันอยู่ใน `kpiImportValidate()` ([`includes/kpi-import-helper.php`](includes/kpi-import-helper.php)) · เดิมอยู่ใน `employee/kpi/kpi.php` ซึ่งปิดแล้ว

### 2.5 "KPI มีผลในเดือนไหน" — เงื่อนไข SQL ชุดเดียวทั้งระบบ

Assignment มอบหมายรายปี แต่จำกัดช่วงด้วย `start_date`/`end_date` ได้
เงื่อนไขนี้ถูกคัดลอกไปใช้ **เหมือนกันทุกที่** ถ้าจะแก้ ต้องแก้ทุกจุดพร้อมกัน

```sql
COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= :month_end
AND COALESCE(a.end_date,   CONCAT(a.assignment_year, '-12-31')) >= :month_start
AND a.status = 'Active'
```

ใช้อยู่ที่: `includes/kpi-import-helper.php` (`kpiImportValidate` ตอนนำเข้า) · `employee/performance-export-data.php`
· `employee/performance.php` · `admin/kpi-summary/kpi-summary-data.php`

ฝั่ง Manager ทำเรื่องเดียวกันแต่เป็น PHP เพราะโหลดข้อมูลทั้งปีมาไว้ในหน่วยความจำแล้ว
— `managerAssignmentInMonth()` ใน [`manager/includes/manager-data.php:204`](manager/includes/manager-data.php#L204)

### 2.6 "ผลงานล่าสุดของเดือนนี้" — subquery pattern

หนึ่ง assignment + หนึ่ง period ควรมีผลงานแถวเดียว แต่โค้ดไม่ไว้ใจฐานข้อมูล
จึงใช้ subquery หยิบมาแถวเดียวเสมอ เพื่อกัน KPI ซ้ำในตาราง

```sql
LEFT JOIN kpi_performances kp
    ON kp.performance_id = (
        SELECT kp2.performance_id
        FROM kpi_performances kp2
        WHERE kp2.assignment_id = a.assignment_id
          AND kp2.employee_id   = a.employee_id
          AND kp2.period_id     = :period_id
        ORDER BY kp2.performance_id DESC
        LIMIT 1
    )
```

> ระวัง (ข้อมูลเดิม): หน้ากรอกเดิม `employee/kpi/kpi.php` (ปิดแล้ว) เรียงด้วย `performance_id DESC` อย่างเดียว
> แต่ `employee/performance-export-data.php:326` เรียงด้วย `performance_date DESC, performance_id DESC`
> ปกติให้ผลเท่ากัน แต่จะต่างกันถ้ามีข้อมูลซ้ำที่วันที่ไม่เรียงตาม id

### 2.7 เกณฑ์ระดับผลงาน — `loadKpiScoreCriteria()` เป็นแหล่งความจริงเดียว

เกณฑ์ 5..1 ของ KPI กระจายอยู่ **3 ตาราง** ด้วยเหตุผลทางประวัติศาสตร์
ฟังก์ชันเดียวนี้ทำหน้าที่รวบให้เป็นรูปแบบเดียว `[kpi_id => [5 => "...", 4 => "...", ...]]`

```text
1. kpi_score_criteria          ← หน้า "เพิ่ม KPI" ของ Admin บันทึกที่นี่
2. kpi_score_levels            ← รูปแบบเก่า
3. kpi_indicators.score_5..1   ← หน้า "แก้ไข KPI" ของ Admin บันทึกที่นี่
```

**เลือกแหล่งเดียวต่อ KPI ไม่ผสมข้ามแหล่ง** — ถ้า KPI มีข้อมูลใน `kpi_score_criteria` แล้ว
คอลัมน์ `score_5..score_1` จะถูกมองข้ามทั้งชุด นี่คือสาเหตุที่หน้าแก้ไข KPI
แก้เกณฑ์ของ KPI บางตัวแล้วไม่มีผล (ดู [§7](#7-กับดักที่ต้องรู้ก่อนแก้โค้ด))

ฟังก์ชันนี้ยังกันความผิดพลาดของผู้เรียกไว้ด้วย: ถ้าไม่ได้ `SELECT` คอลัมน์ `score_5..score_1`
มาใน array ที่ส่งเข้ามา ฟังก์ชันจะ query เพิ่มเอง แทนที่จะคืนเกณฑ์ว่างเงียบ ๆ
— [`includes/kpi-score-helper.php`](includes/kpi-score-helper.php) ท้ายไฟล์

```php
$legacyIds = array_values(array_filter(
    $ids,
    fn($kpiId) => empty($fromCriteria[$kpiId]) && empty($fromLevels[$kpiId])
));
// ... KPI ที่ตกมาถึงข้อ 3 แต่ผู้เรียกไม่ได้ส่งคอลัมน์มา → query เพิ่ม
```

ผู้ใช้: `includes/kpi-import-helper.php` (ตัดเกรดตอนนำเข้า) · `employee/kpi-detail.php` (แสดง + ตัดเกรดในประวัติ)
· `employee/performance-export-data.php` (แบบฟอร์ม Excel/PDF) · สคริปต์คำนวณย้อนหลัง

### 2.8 สูตรคะแนน — สองสำนวน ผลลัพธ์เดียวกัน

ระบบเขียนสูตรถ่วงน้ำหนักไว้ 2 แบบคนละที่ **ต้องให้ผลตรงกันเสมอ**

**แบบฟอร์มประเมิน** (Excel/PDF) — [`employee/performance-export-data.php:43-46`](employee/performance-export-data.php#L43-L46)

```php
$weight = (float) $kpi["weight_value"];
$grade  = $kpi["score_value"] !== null ? (float) $kpi["score_value"] : null;
$full   = $weight * $fullGrade;              // คะแนนเต็ม = น้ำหนัก × 5
$real   = $grade === null ? null : $weight * $grade;   // คะแนนจริง = น้ำหนัก × เกรด
```

**หน้าสรุปของ Admin และ Dashboard ของ Manager** — เขียนเป็นสัดส่วนแทน

```php
$weightedSum    += ($score / $maxScore) * $weight;    // manager-data.php:290
$weightedWeight += $weight;
// ...
$stats["weighted_percent"] = $weightedSum / $weightedWeight * 100;
```

สองสำนวนนี้เท่ากันทางคณิตศาสตร์ เพราะ `Σ(weight×grade) / Σ(weight×5) = Σ(grade/5 × weight) / Σweight`
ข้อดีของสำนวนหลังคือ **ไม่ต้องสมมติว่าน้ำหนักรวมเป็น 100** — ใช้ได้แม้พนักงานได้รับมอบหมายไม่ครบ

จาก % ไปเป็นเกรดตัวอักษร ใช้ `evaluationGrade()` ซึ่งเทียบเป็นฐาน 500 ก่อนเสมอ
— [`employee/performance-export-data.php:116-132`](employee/performance-export-data.php#L116-L132)

```php
$score = round($actual / $full * 500, 6);     // เทียบเป็นฐาน 500 ก่อนตัดเกรด
foreach (evaluationGradeScale() as $row) {    // 451 A+ · 400 A · 300 B · 200 C · 100 D
    if ($score >= $row["min"]) { return $row; }
}
```

### 2.9 Layout — โครง HTML เดียวกันทั้ง 3 บทบาท

ทุกบทบาทใช้ CSS ชุดเดียวกันจาก `assets/css/admin.css` และโครงเดียวกัน

```html
<aside class="sidebar"> ... </aside>
<main class="main-content">
    <header class="topbar"> ... </header>
    <section class="page-container">
        <div class="page-container">   <!-- เนื้อหาหน้าอยู่ตรงนี้ -->
```

- **Admin / Manager** — `index.php` วาดโครงเอง แล้ว `include` ไฟล์เนื้อหาเข้ามาตรงกลาง
- **Employee** — [`employee/includes/layout.php`](employee/includes/layout.php) ห่อให้เป็นฟังก์ชัน

```php
employeeLayoutStart("kpi", "../../");   // $activeNav, $rootPath
// ... เนื้อหาหน้า ...
employeeLayoutEnd("../../");
```

`$rootPath` คือ path กลับไป root ของโปรเจกต์ — `"../"` สำหรับ `employee/*.php`
และ `"../../"` สำหรับหน้าใน `employee/kpi/` **ส่งผิดแล้ว logo กับ logout จะพัง** (ปัจจุบัน `employee/kpi/kpi.php` ส่งต่อไปหน้านำเข้าโดยไม่ render หน้า)

Sidebar ของ Admin/Manager เป็น partial ที่ป้องกันการเข้าตรงด้วยการเช็คว่า `$pages` มีอยู่ไหม
— [`manager/includes/sidebar.php:3-7`](manager/includes/sidebar.php#L3-L7)

```php
if (!isset($pages)) {
    // ไฟล์นี้เป็น partial ต้อง include จาก manager/index.php เท่านั้น
    http_response_code(404);
    exit;
}
```

---

## 3. โมเดลข้อมูล

```text
roles ──< users >── employees ──< kpi_assignments >── kpi_indicators ──< kpi_score_criteria
            │           │              │                    │          └< kpi_score_levels
            │           │              │                    └── kpi_categories
         login_logs     │              │
                        │              └──< kpi_performances >── evaluation_periods
                        │                                              │
                        └──────── manager_feedback ────────────────────┘
                             (manager_id = users.user_id)
```

**ตารางที่มีข้อมูลจริง** (จากฐานข้อมูลพัฒนา)

| ตาราง | บทบาท |
| --- | --- |
| `kpi_performances` | ตารางที่ใหญ่ที่สุดและสำคัญที่สุด — ผลงานรายเดือน |
| `kpi_assignments` | การมอบหมายรายปี เป็นตัวเชื่อม พนักงาน ↔ KPI ↔ น้ำหนัก ↔ เป้าหมาย |
| `kpi_indicators` | หัวข้อ KPI + เกณฑ์แบบเก่าใน `score_5..score_1` |
| `kpi_score_criteria` | เกณฑ์แบบปัจจุบัน 1 แถวต่อ (KPI, ระดับ) |
| `evaluation_periods` | รอบประเมิน 1 แถวต่อ (ปี, เดือน) |
| `manager_feedback` | Feedback รายเดือนจากหัวหน้า |

**ตารางที่ยังว่างและไม่มีโค้ดเขียนลงไป** — `employee_kpi` และ `performance_summary`
เป็นซากจากโครงสร้างเดิม `evaluationPeriodUsage()` ยังนับอยู่ตอนตรวจว่าลบรอบประเมินได้ไหม
([`includes/monthly-period-helper.php:63-72`](includes/monthly-period-helper.php#L63-L72)) แต่ไม่มีหน้าไหนใช้

**การ CASCADE ที่ต้องระวัง** — ลบ KPI หนึ่งหัวข้อจะลากผลงานของพนักงานหายตามไปด้วย

```text
kpi_indicators ─(CASCADE)→ kpi_assignments ─(CASCADE)→ kpi_performances
```

หน้าลบ KPI จึงปฏิเสธถ้า KPI ถูกมอบหมายไปแล้ว

---

## 4. การตัดเกรด KPI

ส่วนที่เป็นกฎธุรกิจหลักของระบบ อยู่ใน [`includes/kpi-score-helper.php`](includes/kpi-score-helper.php) ไฟล์เดียว

### 4.1 ปัญหาที่ไฟล์นี้แก้

เดิมหน้ากรอกผลงานคิดคะแนนเป็นสัดส่วนล้วน โดยไม่อ่านเกณฑ์ที่แสดงอยู่บนหน้าจอ

```php
$scoreValue = ($actualValue / $targetValue) * 5;     // โค้ดเดิม — ถูกลบแล้ว
```

ทำให้ KPI ที่ตั้งเกณฑ์ว่า `5 : >100%` และ `4 : 100%` แต่ทำได้เท่าเป้าพอดี กลับได้ 5 เต็ม
และใช้กับ KPI แบบ "ยิ่งน้อยยิ่งดี" ไม่ได้เลย (ทำได้ 10% ทั้งที่เป้าคือไม่เกิน 5% → ได้ 5 เต็มเพราะตัดเพดาน)

### 4.2 ขั้นตอนการตัดเกรด

```text
ข้อความเกณฑ์ 5..1          ค่าที่ทำได้ (Actual) + เป้าหมาย (Target)
      │                              │
      ▼                              │
parseKpiCriteria()                   │   แปลงข้อความเป็นกฎเชิงตัวเลข
  ">100%"      → gt 100              │   ข้ามระดับที่เป็น "-" หรือข้อความล้วน
  "100%"       → exact 100           │
  "99-90%"     → range 90..99        │
  "95% - 97%"  → range 95..97        │
  "<5%"        → lt 5                │
  "98% ขึ้นไป"  → gte 98              │
  "ต่ำกว่า 85%" → lt 85               │
      │                              │
      ▼                              ▼
      └──────► kpiCriteriaCompareValue()   เลือกฐานเทียบ: ค่าดิบ หรือ % ของเป้า
                        │
                        ▼
               ไล่ระดับ 5 → 1  ระดับแรกที่เข้าเกณฑ์ = เกรดที่ได้
                        │
                        ▼ (ไม่เข้าช่วงใดเลย)
               kpiCriteriaHigherIsBetter()  ดูทิศทางของเกณฑ์
               วางตำแหน่งตามขอบ · แย่กว่าเกณฑ์ต่ำสุด = 1
```

### 4.3 สามการตัดสินใจที่ไม่ชัดในตัวเอง

**ก. ทิศทางของเกณฑ์** — ระบบไม่มีคอลัมน์บอกว่า KPI ไหน "ยิ่งมากยิ่งดี"
จึงเดาจากตัวเลขในเกณฑ์เอง: ถ้าไล่ระดับ 5→1 แล้วตัวเลขลดลง แปลว่ายิ่งมากยิ่งดี

```php
function kpiCriteriaHigherIsBetter(array $rules): bool
{
    $points = array_values(array_map("kpiCriterionMidpoint", $rules));
    // นับคู่ที่เรียงลง vs เรียงขึ้น
    return $descending >= $ascending;
}
```

ตัวอย่างเกณฑ์ `5:<5%` และ `4:5%` → ค่าตัวแทน `4.9999` กับ `5`
เรียงขึ้น → ตัดสินว่า **ยิ่งน้อยยิ่งดี** ✓

> หมายเหตุ: KPI ID 9 "มูลค่าไม่ต่อสัญญา" ไม่ผ่านตัวอ่านเกณฑ์ทั่วไปนี้แล้ว (แม้ `kpi_score_levels` จะยังมีข้อความ `5:<5%` ค้างอยู่)
> ใช้กฎเฉพาะ Achievement = Total R / Budget × 100 ผ่าน `kpiCalculateScore()` · รายละเอียดใน README หัวข้อเกณฑ์คะแนน

**ข. ฐานเทียบ: ค่าดิบ หรือ % ของเป้า** — เกณฑ์ในระบบมี 2 แบบปนกัน

| KPI | เป้าหมาย | เกณฑ์ | ความหมาย |
| --- | --- | --- | --- |
| การเข้าให้บริการตรงตามนัดหมาย | 98 | `ตรงเวลา 95% - 97%` | เกณฑ์เป็นหน่วยเดียวกับค่าที่วัด → **เทียบค่าดิบ** |
| เข้าเยี่ยมลูกค้าตาม KPI | 20 ครั้ง | `>100%`, `99-96%` | เกณฑ์เป็น % ความสำเร็จ → **เทียบสัดส่วน** |

แยกด้วยตำแหน่งของ Target เทียบกับช่วงตัวเลขของเกณฑ์

```php
$sameUnit = $target >= min($bounds) - KPI_SCORE_EPSILON
    && $target <= max($bounds) * KPI_CRITERIA_TARGET_TOLERANCE + KPI_SCORE_EPSILON;

return $sameUnit ? $actual : $actual / $target * 100;
```

`KPI_CRITERIA_TARGET_TOLERANCE = 1.5` เผื่อกรณีเกณฑ์สูงสุดเขียน `98% ขึ้นไป` แต่ตั้งเป้าไว้ 100%
ซึ่งยังเป็นหน่วยเดียวกัน

**ค. KPI ที่ไม่มีเกณฑ์เชิงตัวเลข** — เช่นเกณฑ์เขียนว่า `ไม่ค้าง`
คงสูตรสัดส่วนเดิมไว้เป็นตัวสำรอง ต่างแค่ปัดเป็นระดับเต็ม เพื่อให้ไฮไลต์ตรงกับคะแนนเสมอ

```php
function kpiGradeFromTargetRatio(float $actual, ?float $target): ?int
{
    if ($target === null || $target <= 0) { return null; }
    $grade = (int) round($actual / $target * KPI_MAX_LEVEL);
    return max(KPI_MIN_LEVEL, min(KPI_MAX_LEVEL, $grade));
}
```

จุดเข้าใช้งานจากภายนอกมีตัวเดียว:

```php
function kpiPerformanceGrade(array $levels, float $actual, ?float $target): ?int
{
    return kpiGradeFromCriteria($levels, $actual, $target)
        ?? kpiGradeFromTargetRatio($actual, $target);
}
```

### 4.4 Competency ไม่ผ่านเส้นทางนี้

Competency KPI ไม่มี Actual ไม่มีการคำนวณ — คะแนน 1-5 มาจากไฟล์บริษัทเมื่อ Mapping ของหัวข้อนั้นยืนยันแล้ว
(`kpiImportValidate()` ตรวจแค่ว่าเป็นจำนวนเต็ม 1-5) · ปัจจุบันยังไม่มี Competency ที่ยืนยัน Mapping จึงยังนำเข้าไม่ได้
(เดิมพนักงานเลือกระดับเองในหน้า `employee/kpi/kpi.php` ซึ่งปิดแล้ว)

---

## 5. เดินตามโค้ด 4 เส้นทาง

### เส้นทาง A — เข้าสู่ระบบ

```text
login.php
  ├─ throttle: นับ login_logs ที่ Failed ใน 15 นาที เกิน 10 ครั้ง → ปฏิเสธ (ต่อ username หรือ IP)
  ├─ csrfVerify()
  ├─ SELECT user + employee + role   (JOIN users → employees → roles)
  ├─ password_verify()
  ├─ ตรวจ users.status และ employees.status
  ├─ session_regenerate_id(true)
  ├─ เขียนคีย์ session ทั้งชุด (§2.2)
  ├─ INSERT login_logs (Success)
  └─ redirect ตาม role_id → admin/ | manager/ | employee/
```

ทุกสาขาที่ล้มเหลวเขียน `login_logs` พร้อม `failure_reason` · ตรวจรหัสผ่าน **ก่อน** สถานะบัญชี
ผู้ที่ไม่รู้รหัสผ่านจึงได้ข้อความเดียวกันเสมอ (`Invalid Employee ID or Password.`)
ข้อความ "บัญชีถูกปิด" แสดงเฉพาะเมื่อรหัสผ่านถูกต้อง

### เส้นทาง B — พนักงานบันทึกผลงาน (ปัจจุบัน: นำเข้าจากไฟล์ Excel ของบริษัทเท่านั้น)

ตั้งแต่ตุลาคม 2026 ช่องทางเดียวที่เขียน `kpi_performances` คือ `kpiImportCommit()` ผ่านหน้า `employee/kpi-import.php`
หน้ากรอกเอง (`employee/kpi/kpi.php`, ฟอร์มใน `employee/kpi-detail.php`) และ System-generated Template ถูกปิดแล้ว

```text
POST /employee/kpi-import.php  action=upload  format=company  company_year  company_month  import_file
  │
  ├─ 1. csrfVerify() · role_id = 3 · employee_id จาก session
  ├─ 2. ตรวจไฟล์: .xlsx ≤ 2 MB · ZIP ≤ 200 entries · ไม่มี Macro · ส่วนที่อ่านจริง ≤ 20 MB
  ├─ 3. includes/kpi-import-company-reader.php (อ่านอย่างเดียว)
  │      - รหัสพนักงานในชีต KPI AM ต้องตรงกับผู้ใช้ทุกตัวอักษร · ปีในชื่อชีตต้องตรงกับปีที่เลือก
  │      - อ่านเฉพาะเดือนที่เลือก · โหลดเฉพาะเซลล์ที่สูตรอ้างถึง (allowlist ชีต) · ไล่ตรวจสูตรทุกชั้น
  │      - เฉพาะหัวข้อที่ Mapping ยืนยันแล้ว (includes/kpi-import-company-map.php) → READY
  │        ปัจจุบัน: KPI 14 "บรรลุเป้าหมายการต่อสัญญา" เท่านั้น
  │          ชีต สรุปทั้งปี.. ตาราง Portfolio(มูลค่า) แถวเดือนจากคอลัมน์ B
  │          C = Budget ('เดือน'!O41 = SUM(O5:O39) ← Port26) · G = Total R = SUM(D:F) (ไม่รวม New) · Q = G/C
  │          Actual = Total R ÷ Budget × 100 ตัดลง 2 ตำแหน่ง · Target ต้องเป็น 100
  │          Budget > 0 แต่ Total R = 0: เดือนยังไม่สิ้นสุด → REJECTED · สิ้นสุดแล้ว → READY + ต้องยืนยันรายแถว
  │      - หัวข้ออื่น → NEEDS_CONFIRMATION (ไม่บันทึก) · ข้อมูล/สูตรผิด → REJECTED (ไม่บันทึกทั้งไฟล์)
  ├─ 4. kpiImportValidate(..., origin = company) — Assignment ของผู้ใช้, รอบ Open, Draft เท่านั้น, ซ้ำในไฟล์
  │      คะแนนจาก kpiCalculateScore() (เกณฑ์ของ KPI ในระบบ) → เก็บ Preview ใน Session (token ใช้ครั้งเดียว)
  │
POST action=confirm  import_token  confirm_update[]  confirm_flag[]
  │
  ├─ origin มาจาก Session: ไม่ใช่ company → ปฏิเสธ (ปิด Template)
  ├─ kpiImportCommit(): Transaction + ล็อก → ตรวจซ้ำ ต่างจาก Preview แม้แถวเดียว → Rollback ทั้งชุด
  │      INSERT เป็น Draft · UPDATE เฉพาะ Draft ที่ติ๊ก confirm_update · แถวที่ต้องยืนยัน (0%) ไม่ติ๊ก
  │      หรือเดือนยังไม่สิ้นสุด → ไม่บันทึก (declined)
  └─ ไม่เขียนทับ Submitted / Approved / Rejected · ไม่เปลี่ยน Draft เป็นสถานะอื่น
```

ผลทดสอบของเส้นทางนี้มาจาก **ฐานข้อมูลทดสอบแยก** (MariaDB socket-only + ข้อมูลสังเคราะห์) · ไฟล์บริษัทจริงทดสอบเฉพาะการอ่าน/ตรวจโครงสร้าง
**ยังไม่ได้ทดสอบการบันทึกกับ Production** · KPI อื่นยังไม่มี Mapping ที่ยืนยัน จึงนำเข้าไม่ได้

### เส้นทาง B (เดิม — ก่อนตุลาคม 2026 · ปิดแล้ว ไว้อ้างอิง)

> ส่วนนี้อธิบายหน้ากรอกผลงานเดิม ซึ่งปิดแล้ว: `employee/kpi/kpi.php` ส่งต่อไปหน้านำเข้า และ `employee/kpi-detail.php` ไม่มีฟอร์มบันทึกแล้ว
> หลักการเดียวกันยังใช้ในการนำเข้า (employee_id จาก session, Assignment ของผู้ใช้, รอบ Open, ตัดเกรดที่ฟังก์ชันกลาง)

`employee/kpi/kpi.php` (เดิม) รับทั้ง GET และ POST ในไฟล์เดียว

```text
POST /employee/kpi/kpi.php?tab=performance&year=2026&month=9
  │
  ├─ 1. csrfVerify()                                 ← ไม่ผ่าน = จบทันที
  ├─ 2. ตรวจ $selectedPeriod ว่ามีรอบประเมินของเดือนนี้
  │
  ├─ 3. ดึง assignment พร้อม "เจ้าของ" ในคำสั่งเดียว  ← กันการแก้ข้อมูลคนอื่น
  │      WHERE a.assignment_id = :assignment_id
  │        AND a.employee_id   = :employee_id        ← จาก session เท่านั้น
  │        AND a.status = 'Active'
  │        AND <เงื่อนไขช่วงเดือน §2.5>
  │      ถ้าไม่เจอ → "ไม่สามารถบันทึก KPI นี้ในเดือนที่เลือกได้"
  │
  ├─ 4. แยกตาม kpi_type
  │      Performance → ตรวจรูปแบบ Actual → โหลดเกณฑ์ → kpiPerformanceGrade()
  │      Competency  → ตรวจว่า score อยู่ใน 1-5
  │
  └─ 5. มีแถวเดิมไหม → UPDATE : INSERT   (ผูกกับ period_id)
```

หัวใจอยู่ที่ขั้นตอนที่ 3: **`employee_id` มาจาก session เสมอ ไม่เคยมาจาก request**
นี่คือสิ่งที่กัน IDOR ทั้งฝั่งพนักงาน

ขั้นตอนที่ 4 ฝั่ง Performance:

```php
$assignmentLevels =
    loadKpiScoreCriteria($pdo, [$assignment])[(int) $assignment["kpi_id"]] ?? [];

$grade = kpiPerformanceGrade(
    $assignmentLevels,
    $actualValue,
    $targetValue > 0 ? $targetValue : null
);
```

**ฝั่งแสดงผลคำนวณเกรดใหม่จาก Actual ทุกครั้ง** ไม่ได้อ่านจาก `score` ที่บันทึกไว้
เพื่อให้ไฮไลต์ "ระดับที่ได้" ตรงกับเกณฑ์เสมอ แม้ข้อมูลเก่าจะยังไม่ได้คำนวณใหม่
และถ้าทั้งสองค่าไม่ตรงกัน หน้าจอจะเตือนว่าคะแนนที่บันทึกไว้มาจากสูตรเก่า

> `employee/kpi-detail.php` เป็นเส้นทางบันทึกเส้นที่สอง ทำเรื่องเดียวกันคนละหน้า
> ต่างกันที่มันบันทึกเป็น `status = 'Submitted'` ส่วน `kpi/kpi.php` บันทึกเป็น `'Draft'`
> **แก้กฎการคำนวณต้องดูทั้งสองไฟล์** (ตอนนี้ทั้งคู่เรียก `kpiPerformanceGrade()` แล้ว)

### เส้นทาง C — ส่งออกแบบฟอร์มประเมิน

Excel กับ PDF ใช้ข้อมูลชุดเดียวกันจาก `employee/performance-export-data.php`

```text
performance-export-excel.php          performance-export-pdf.php
        │                                        │
        ├──── requireEmployeeExportAccess() ─────┤   401/403 ถ้าไม่มีสิทธิ์
        │       employee_id จาก session เท่านั้น    │
        │                                        │
        ├──── loadEmployeePerformanceReport() ───┤   KPI + ผลงาน + เกณฑ์ของเดือนนั้น
        │            └─ loadKpiScoreCriteria()   │
        │                                        │
        ├──── loadEmployeeYearScores() ──────────┤   คะแนนรายเดือน ม.ค.-ธ.ค.
        │            └─ evaluationGrade()        │
        │                                        │
   buildEvaluationForm()                   buildEvaluationForm()
   แต่เขียนเป็น "สูตร Excel"                  แต่ render เป็น HTML → Dompdf
   =C10*5, =IF(K10="","",C10*K10)          createThaiPdf() + thaiPdfWrap()
```

ความต่างที่สำคัญ: **Excel ใส่สูตรจริงลงเซลล์** ไม่ใช่ค่าที่คำนวณแล้ว
เพื่อให้ผู้ใช้แก้เกรดในไฟล์แล้วคะแนนอัปเดตเอง — [`performance-export-excel.php:229,249`](employee/performance-export-excel.php#L229)

```php
$sheet->setCellValue("I{$row}", "=C{$row}*" . FULL_GRADE);              // คะแนนเต็ม
$sheet->setCellValue("L{$row}", "=IF(K{$row}=\"\",\"\",C{$row}*K{$row})"); // คะแนนจริง
```

ดังนั้นเวลาแก้สูตรคะแนน ต้องแก้ **3 ที่**: PHP (`buildEvaluationForm`), สูตรใน Excel,
และ `excelGradeFormula()` ที่แปลง `evaluationGradeScale()` เป็น `IF` ซ้อนกัน

PDF ต้องการการจัดการพิเศษเพราะ Dompdf ตัดบรรทัดภาษาไทยไม่เป็น
`thaiPdfWrap()` จึงตัดคำเองโดยรักษาสระ/วรรณยุกต์ให้ติดกับพยัญชนะ
— [`includes/pdf-helper.php:61`](includes/pdf-helper.php#L61)

### เส้นทาง D — Manager Dashboard

ต่างจากฝั่งอื่นตรงที่ **โหลดข้อมูลทั้งปีครั้งเดียว แล้วสรุปใน PHP ทั้งหมด**

```php
$yearData = managerLoadYearData($pdo, $filterYear);   // manager/index.php:126
```

คืน array ก้อนเดียวที่ทุกหน้าใช้ร่วมกัน:

```php
[
  "year"                      => int,
  "employees"                 => [employee_id => row],
  "departments"               => [department_id => name],
  "assignments"               => [assignment_id => row],
  "assignments_by_employee"   => [employee_id => [assignment_id, ...]],
  "performances"              => [row, ...],
  "performances_by_assignment"=> [assignment_id => [period_month => row]],
  "periods"                   => [period_month => row],
  "feedback"                  => [employee_id => [period_month => row]]
]
```

หลังจากนั้นทุกตัวเลขบนหน้าจอมาจากฟังก์ชันสรุปที่ทำงานกับ array นี้ ไม่แตะฐานข้อมูลอีก

| ฟังก์ชัน | ใช้ทำอะไร |
| --- | --- |
| `managerEmployeeIds($data, $departmentId)` | เลือกกลุ่มพนักงาน (0 = ทุกแผนก) |
| `managerStats($data, $ids, $month, $type)` | **ตัวสรุปหลัก** ใช้ได้ทั้งรายคนและรายแผนก แค่เปลี่ยนชุด id |
| `managerMonthlyScores()` / `managerQuarterScores()` | ข้อมูลกราฟเส้น |
| `managerScoreLevel()` | แปลงคะแนนเฉลี่ยเป็นป้าย (โดดเด่น / อยู่ในเกณฑ์ / ควรติดตาม) |
| `managerUrl($page, $params)` | สร้างลิงก์ โดยตัด `null`, `""` และ `0` ออก |

ตัวกรองปี/เดือน/แผนกเป็น **ตัวแปร global ที่ `index.php` ตั้งไว้** แล้วไฟล์เนื้อหาอ่านตรง ๆ
`manager/includes/filter-bar.php` ก็อ่านจากตัวแปรเหล่านี้เหมือนกัน และไม่ได้ถูก include โดย `index.php`
แต่ละหน้าเนื้อหา include เองหลังตั้งค่า `$showMonth` / `$showDepartment`

> `manager/dashboard/dashboard.php:141` เรียก `managerLoadYearData()` **อีกรอบ** สำหรับปีก่อนหน้า
> เพื่อทำกราฟเทียบปี — เป็นจุดที่ query หนักที่สุดในระบบ

---

## 6. แผนที่การพึ่งพา

ใครเรียกอะไร (ไม่รวม `vendor/`)

```text
includes/security.php ────────── ทุกไฟล์ที่รับ HTTP request (ไม่มีข้อยกเว้น)
config/database.php ──────────── ทุกไฟล์ที่แตะฐานข้อมูล → ให้ $pdo

includes/kpi-score-helper.php
    ├── includes/kpi-import-helper.php (Validate/Commit การนำเข้า)
    ├── employee/kpi-detail.php (แสดงผล)
    ├── employee/performance-export-data.php
    └── database/migrations/20260921_recalculate_kpi_scores.php

includes/monthly-period-helper.php
    ├── employee/kpi-detail.php · performance.php · includes/kpi-import-company-reader.php
    ├── admin/evaluation/* · admin/kpi-assignment/ · admin/kpi-summary/kpi-summary-data.php
    ├── manager/includes/manager-data.php
    └── includes/period-picker.php

includes/quarter-helper.php ──── admin/index.php · employee/index.php
                                 manager/includes/manager-data.php · includes/period-picker.php

includes/period-picker.php ───── employee/performance.php · admin/kpi-summary/kpi-summary.php
includes/pdf-helper.php ──────── employee/performance-export-pdf.php

employee/includes/layout.php ─── ทุกหน้าของพนักงาน
employee/includes/feedback.php ─ employee/index.php · feedback.php · performance.php

employee/performance-export-data.php
    ├── employee/performance-export-excel.php
    └── employee/performance-export-pdf.php

manager/includes/manager-data.php ─ manager/index.php (แล้วส่งต่อให้ทุกหน้าเนื้อหา)
admin/kpi-summary/kpi-summary-data.php ─ kpi-summary.php · kpi-summary-export-pdf.php
```

**สังเกต** — `performance-export-data.php` เคยเป็นบ้านของ `loadKpiScoreCriteria()`
จึงถูก require จากหน้าที่ไม่ได้ export อะไรเลย ตอนนี้ฟังก์ชันย้ายไป `includes/kpi-score-helper.php`
แล้ว และ require ที่ไม่จำเป็นถูกตัดออก — ไฟล์นี้เหลือหน้าที่เดียวคือป้อนข้อมูลให้แบบฟอร์ม Excel/PDF

---

## 7. กับดักที่ต้องรู้ก่อนแก้โค้ด

> **อัปเดต 29 ก.ย. 2026** — หัวข้อย่อยต่อไปนี้ **แก้ไขแล้ว** เนื้อหาด้านล่างคงไว้เป็นบันทึกที่มาของปัญหา
>
> | หัวข้อ | สิ่งที่แก้ |
> | --- | --- |
> | 7.3 Session ไม่เคยตรวจสิทธิ์ซ้ำ | `includes/security.php` มี idle timeout 30 นาที และ `securityRecheckAccount()` ตรวจสถานะบัญชีทุก request (เรียกจาก `config/database.php`) |
> | 7.4 เกณฑ์ KPI บันทึกคนละที่ | หน้าเพิ่มและหน้าแก้ไข KPI บันทึกผ่าน `saveKpiScoreCriteria()` ลง `kpi_score_criteria` และเลือก `kpi_type` ได้ · แถวเกณฑ์ว่างไม่ถูกนับเป็นเกณฑ์ |
> | 7.5 น้ำหนัก 100% ตรวจแยกประเภท | ตรวจยอดรวม Performance + Competency ทั้งตอนมอบหมายและตอนแก้ไข |
> | 7.8 ไม่มี schema / `employee_kpi` | เพิ่ม `database/schema.sql` · Dashboard ของ Admin และหน้าแรกพนักงานอ่านจาก `kpi_performances` แล้ว ไม่ใช้ `employee_kpi` / `performance_summary` |
>
> เพิ่มเติม: พนักงานบันทึกผลงานได้เฉพาะรอบที่ `Open` · กฎ KPI "มูลค่าไม่ต่อสัญญา" ย้ายไปอยู่ใน `includes/kpi-score-helper.php`
> · ลิงก์ logout ต้องมี CSRF token (`logoutUrl()`) · ฟอนต์ `assets/fonts/` ถูกนำกลับมา
>
> **ยังไม่แก้** (ต้องตัดสินใจเชิงออกแบบ): 7.1 ขั้นอนุมัติคะแนน · 7.2 ขอบเขตแผนกของ Manager · 7.6 · 7.7

เรียงตามความรุนแรง

### 7.1 คะแนนเป็นการรายงานตัวเอง ไม่มีขั้นอนุมัติ

`kpi_performances.status` มีค่า `Draft` / `Submitted` / `Approved` / `Rejected`
แต่ **ไม่มี query ไหนในระบบกรองด้วยคอลัมน์นี้เลย** และคำว่า `'Approved'` ไม่ปรากฏในโค้ดทั้งโปรเจกต์

```sql
-- admin/kpi-summary/kpi-summary-data.php · manager/includes/manager-data.php
-- employee/performance-export-data.php  ทั้งสามใช้เงื่อนไขเดียวกัน:
WHERE kp.score IS NOT NULL      -- ไม่มี AND kp.status = 'Approved'
```

ผลคือพนักงานบันทึกปุ๊บ คะแนนขึ้นรายงานผู้บริหารและไฟล์ PDF/Excel ทันที
และระบบยังไม่มีหน้าให้หัวหน้าให้คะแนนหรืออนุมัติ (`manager/feedback.php` เขียนลง
`manager_feedback` ซึ่งเป็นคนละเรื่องกับคะแนน KPI)

### 7.2 Manager ไม่จำกัดขอบเขตแผนก

`manager/index.php:48` อ่าน `$managerDepartmentId` จาก session แล้ว **ไม่ได้ใช้ที่ไหนเลย**
`managerLoadYearData()` โหลดพนักงาน Active ทั้งบริษัทโดยไม่มีเงื่อนไขแผนก

อันตรายกว่าคือฝั่งเขียน — `manager/feedback.php` ตรวจแค่ว่าพนักงานมีตัวตนและ Active
ไม่ได้ตรวจว่าอยู่ในความดูแลของหัวหน้าคนนั้น และใช้ `ON DUPLICATE KEY UPDATE`
ซึ่งทำให้เขียนทับ Feedback ของหัวหน้าคนอื่นได้

### 7.3 Session ไม่เคยตรวจสิทธิ์ซ้ำ

`role_id` และสถานะบัญชีถูกคัดลอกลง session ตอน login ครั้งเดียว
ปิดบัญชีพนักงานแล้ว session ที่เปิดค้างอยู่ยังใช้งานได้ต่อ เพราะ cookie เป็น `lifetime => 0`
(อยู่จนปิดเบราว์เซอร์) และไม่มี idle timeout ฝั่งเซิร์ฟเวอร์

`employee/index.php` ถึงกับ `SELECT e.status` มาแล้วแต่ไม่ได้เช็ค

### 7.4 เกณฑ์ KPI บันทึกคนละที่กับที่อ่าน

- หน้า **เพิ่ม KPI** บันทึกลง `kpi_score_criteria`
- หน้า **แก้ไข KPI** อ่านและบันทึก `kpi_indicators.score_5..score_1`
- `loadKpiScoreCriteria()` เลือก **แหล่งเดียวต่อ KPI** ตามลำดับ criteria → levels → columns

ผลคือ KPI ที่สร้างผ่านหน้าเพิ่ม (มีข้อมูลใน `kpi_score_criteria`) จะ **แก้เกณฑ์ผ่านหน้าแก้ไขไม่ได้**
— บันทึกสำเร็จ ไม่มี error แต่ระบบยังอ่านของเดิม

### 7.5 น้ำหนัก 100% ตรวจแยกตามประเภท ไม่ใช่รวม

UI บอกว่า "Performance + Competency = 100%" แต่โค้ดตรวจทีละ `kpi_type`
— [`admin/kpi-assignment/kpi-assignment.php:288-310, 441`](admin/kpi-assignment/kpi-assignment.php#L288-L310)

```sql
WHERE a.employee_id = ? AND a.assignment_year = ? AND k.kpi_type = ?   -- แยกประเภท
```

ทางทฤษฎีจึงมอบหมาย Performance 100% + Competency 100% = 200% ได้
(ข้อมูลปัจจุบันยังไม่มีใครเกิน แต่ไม่มีอะไรกัน)

### 7.6 Partial ของ Admin เปิดตรงผ่าน URL ได้

Sidebar มี guard `if (!isset($pages))` แต่ **ไฟล์เนื้อหาใน `$pages` ไม่มี**
เปิด `admin/kpi-management/kpi-management.php` ตรง ๆ จะได้ HTML fragment เปล่า ๆ
ไม่มี `<html>` ไม่มี sidebar และ path ของ asset พังหมด

ไม่ใช่ช่องโหว่ข้ามสิทธิ์ (แต่ละไฟล์ยังตรวจ `role_id === 1` เอง) แต่เป็นหน้าเสียที่ไม่ควรเข้าถึงได้

### 7.7 Sidebar ใช้ `$_GET["page"]` ดิบ ไม่ใช่ `$page` ที่ตรวจแล้ว

```php
$currentPage = $_GET["page"] ?? "home";    // ไม่ผ่าน whitelist
```

เข้า `?page=xyz` → router ตกกลับไปหน้า home แต่ sidebar ไม่ไฮไลต์เมนูไหนเลย

### 7.8 อื่น ๆ

| เรื่อง | รายละเอียด |
| --- | --- |
| รหัสฐานข้อมูลอยู่ใน git | `config/database.php` ใช้ `root` รหัสว่าง และถูก track ใน git (`.htaccess` กันเฉพาะทาง HTTP) |
| `login-test.php` | หน้า debug ต้องลบก่อนขึ้นระบบจริง |
| `assets/js/dashboard.js` | ไม่มีหน้าไหนเรียกใช้ |
| `employee_kpi` / `performance_summary` | ตารางว่าง ไม่มีโค้ดเขียน แต่ `evaluationPeriodUsage()` ยังนับอยู่ |
| ไม่มี schema ตั้งต้นใน repo | ติดตั้งเครื่องใหม่ต้องใช้ไฟล์ dump |

---

## ภาคผนวก — เช็กลิสต์เวลาเพิ่มหน้าใหม่

**หน้าของพนักงาน**

```php
<?php
require_once __DIR__ . "/../includes/security.php";      // 1. ต้องมาก่อนเสมอ
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/includes/layout.php";

if (!isset($_SESSION["user_id"])) {                      // 2. ตรวจ login
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);     // 3. ขอบเขตข้อมูลจาก session
if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}
// ... query ที่ WHERE employee_id = :employee_id เสมอ ...
?>
<body>
    <?php employeeLayoutStart("ชื่อเมนู", "../"); ?>
        <!-- เนื้อหา · escape ด้วย htmlspecialchars(..., ENT_QUOTES, "UTF-8") -->
    <?php employeeLayoutEnd("../"); ?>
</body>
```

ถ้าเป็นเมนูใหม่ ต้องเพิ่มใน `$navItems` ของ [`employee/includes/layout.php:31-37`](employee/includes/layout.php#L31-L37) ด้วย

**หน้าของ Admin (แบบ partial ผ่าน router)**

1. เพิ่มคู่ `"key" => "subdir/file.php"` ใน `$pages` ของ `admin/index.php`
2. เพิ่มลิงก์ใน `admin/includes/sidebar.php` พร้อมเงื่อนไข active
3. ถ้ามี CSS เฉพาะหน้า เพิ่มใน `$pageStylesheets`
4. ไฟล์เนื้อหา **ห้ามมี `<!DOCTYPE>`** — ปล่อยเฉพาะ fragment
5. ใส่ guard `role_id === 1` ในไฟล์เนื้อหาเองด้วย (เผื่อถูกเปิดตรง)

**ฟอร์ม POST ทุกที่**

```php
<form method="POST"><?= csrfField() ?>   <!-- ฝั่งแสดง -->
```
```php
if (!csrfVerify()) { $error = "..."; }   // ฝั่งรับ — เงื่อนไขแรกสุด
```
