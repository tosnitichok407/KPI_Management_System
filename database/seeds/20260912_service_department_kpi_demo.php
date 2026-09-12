<?php

/*
|--------------------------------------------------------------------------
| Demo Seed: KPI ฝ่ายบริการ (บริษัทกำจัดแมลง) ปี 2026
|--------------------------------------------------------------------------
|
| สร้างหัวข้อ KPI ของฝ่ายบริการ + มอบหมายให้พนักงานฝ่ายบริการ + ผลงานตัวอย่างรายเดือน
| เพื่อให้ Manager ดูภาพรวม (Dashboard / รายแผนก / รายคน) ระหว่างทดสอบระบบ
|
|   php database/seeds/20260912_service_department_kpi_demo.php              ใส่ข้อมูล demo
|   php database/seeds/20260912_service_department_kpi_demo.php --rollback   ลบข้อมูล demo ของสคริปต์นี้
|   เพิ่ม --db=ชื่อฐานข้อมูล เพื่อรันกับฐานอื่น (เช่นฐานทดสอบ)
|
| - Performance 6 หัวข้อ รวม 80% + Competency 5 หัวข้อ รวม 20% = 100% ต่อคน (เหมือนฝ่ายสำนักงาน)
| - มอบหมายทั้งปี 2026 ให้พนักงานฝ่ายบริการ (ยกเว้น Administration Service ซึ่งเป็นงานธุรการ)
| - ผลงาน ม.ค.-ก.ค. Approved, ส.ค. Submitted, ก.ย. บางคน Draft (เดือนที่กำลังประเมิน)
| - เกรดตัดตามเกณฑ์ของแต่ละ KPI · สุ่มแบบกำหนด seed จึงรันซ้ำได้ผลเดิม
| - --rollback ลบ KPI ของสคริปต์นี้ → การมอบหมาย / ผลงาน / เกณฑ์ ถูกลบตาม (ON DELETE CASCADE)
|
*/

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../../config/database.php";

$options = getopt("", ["db:", "rollback"]);

if (!empty($options["db"])) {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$options["db"]};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";

const DEMO_YEAR = 2026;
const SERVICE_DEPARTMENT = "ฝ่ายบริการ";
const SERVICE_CATEGORY = "KPI ฝ่ายบริการ";
const SERVICE_CATEGORY_DESCRIPTION = "หมวดตัวชี้วัดผลการปฏิบัติงานของฝ่ายบริการ (งานบริการกำจัดแมลง)";
const COMPETENCY_CATEGORY = "KPI ด้านพฤติกรรมและสมรรถนะ";
const EXCLUDED_POSITIONS = ["Administration Service"];
const SEED = 20260912;


/*
|--------------------------------------------------------------------------
| หัวข้อ KPI
|--------------------------------------------------------------------------
|
| Performance: หน่วย % · bands = ค่าต่ำสุดของเกรด 5..2 · floor = ค่าต่ำสุดที่ใช้สุ่มเกรด 1
| difficulty = ปรับความยากของหัวข้อ (ใช้สุ่มผลงาน demo เท่านั้น)
|
*/

$performanceKpis = [
    [
        "name" => "การเข้าให้บริการตรงตามนัดหมาย",
        "description" => "สัดส่วนงานบริการที่เข้าหน้างานตรงเวลานัดหมายกับลูกค้า (คลาดเคลื่อนไม่เกิน 30 นาที)",
        "weight" => 20,
        "target" => 98,
        "bands" => [5 => 98, 4 => 95, 3 => 90, 2 => 85],
        "floor" => 78,
        "difficulty" => -0.1,
        "criteria" => [5 => "ตรงเวลา 98% ขึ้นไป", 4 => "ตรงเวลา 95% - 97%", 3 => "ตรงเวลา 90% - 94%", 2 => "ตรงเวลา 85% - 89%", 1 => "ตรงเวลาต่ำกว่า 85%"]
    ],
    [
        "name" => "การให้บริการครบตามแผนงานประจำเดือน",
        "description" => "จำนวนจุดบริการ/สัญญาที่ให้บริการครบตามรอบ เทียบกับแผนงานประจำเดือน",
        "weight" => 15,
        "target" => 100,
        "bands" => [5 => 100, 4 => 97, 3 => 94, 2 => 90],
        "floor" => 82,
        "difficulty" => 0.1,
        "criteria" => [5 => "ครบตามแผน 100%", 4 => "ครบตามแผน 97% - 99%", 3 => "ครบตามแผน 94% - 96%", 2 => "ครบตามแผน 90% - 93%", 1 => "ครบตามแผนต่ำกว่า 90%"]
    ],
    [
        "name" => "ความพึงพอใจของลูกค้าหลังให้บริการ",
        "description" => "ผลแบบประเมินความพึงพอใจของลูกค้าหลังให้บริการ คิดเป็นร้อยละของคะแนนเต็ม",
        "weight" => 15,
        "target" => 90,
        "bands" => [5 => 90, 4 => 85, 3 => 80, 2 => 75],
        "floor" => 65,
        "difficulty" => 0.0,
        "criteria" => [5 => "ความพึงพอใจ 90% ขึ้นไป", 4 => "ความพึงพอใจ 85% - 89%", 3 => "ความพึงพอใจ 80% - 84%", 2 => "ความพึงพอใจ 75% - 79%", 1 => "ความพึงพอใจต่ำกว่า 75%"]
    ],
    [
        "name" => "งานบริการที่ไม่มีการเรียกกลับแก้ไข",
        "description" => "สัดส่วนงานที่ลูกค้าไม่ต้องแจ้งให้กลับไปแก้ไขหรือให้บริการซ้ำภายใน 30 วัน (No Callback)",
        "weight" => 15,
        "target" => 98,
        "bands" => [5 => 98, 4 => 96, 3 => 94, 2 => 92],
        "floor" => 85,
        "difficulty" => -0.2,
        "criteria" => [5 => "ไม่มี Callback 98% ขึ้นไป", 4 => "ไม่มี Callback 96% - 97%", 3 => "ไม่มี Callback 94% - 95%", 2 => "ไม่มี Callback 92% - 93%", 1 => "ไม่มี Callback ต่ำกว่า 92%"]
    ],
    [
        "name" => "การปฏิบัติตามมาตรฐานความปลอดภัยและการใช้สารเคมี",
        "description" => "คะแนนตรวจประเมินหน้างาน: ใช้สารเคมีถูกชนิดและอัตราผสม สวม PPE ครบ ขนย้ายและจัดเก็บสารเคมีถูกต้อง",
        "weight" => 10,
        "target" => 100,
        "bands" => [5 => 100, 4 => 95, 3 => 90, 2 => 85],
        "floor" => 75,
        "difficulty" => 0.2,
        "criteria" => [5 => "ผ่านการตรวจ 100%", 4 => "ผ่านการตรวจ 95% - 99%", 3 => "ผ่านการตรวจ 90% - 94%", 2 => "ผ่านการตรวจ 85% - 89%", 1 => "ผ่านการตรวจต่ำกว่า 85%"]
    ],
    [
        "name" => "ความถูกต้องครบถ้วนของรายงานบริการ",
        "description" => "ใบงาน/รายงานบริการส่งครบ ถูกต้อง มีลายเซ็นลูกค้าและรูปถ่ายหน้างาน ภายในวันที่ให้บริการ",
        "weight" => 5,
        "target" => 100,
        "bands" => [5 => 98, 4 => 95, 3 => 90, 2 => 85],
        "floor" => 75,
        "difficulty" => 0.1,
        "criteria" => [5 => "ถูกต้องครบถ้วน 98% ขึ้นไป", 4 => "ถูกต้องครบถ้วน 95% - 97%", 3 => "ถูกต้องครบถ้วน 90% - 94%", 2 => "ถูกต้องครบถ้วน 85% - 89%", 1 => "ถูกต้องครบถ้วนต่ำกว่า 85%"]
    ]
];

$competencyKpis = [
    [
        "name" => "ความรู้ด้านแมลงและเทคนิคการกำจัดแบบผสมผสาน (IPM)",
        "description" => "รู้วงจรชีวิตและพฤติกรรมของแมลงหลัก (ปลวก หนู แมลงสาบ ยุง มด) และเลือกวิธีกำจัดที่เหมาะสมกับหน้างาน",
        "weight" => 4,
        "difficulty" => -0.1,
        "criteria" => [
            5 => "วิเคราะห์ต้นเหตุการระบาดและวางแผนป้องกันระยะยาวให้ลูกค้าได้ พร้อมสอนงานผู้อื่น",
            4 => "เลือกวิธีและสารเคมีได้เหมาะสมกับชนิดแมลงและหน้างานโดยไม่ต้องมีผู้แนะนำ",
            3 => "รู้ชนิดแมลงหลักและวิธีกำจัดมาตรฐานตามคู่มือบริษัท",
            2 => "รู้ชนิดแมลงหลักบางส่วน ต้องมีผู้แนะนำในการเลือกวิธีกำจัด",
            1 => "ยังขาดความรู้พื้นฐานเรื่องแมลงและวิธีกำจัด"
        ]
    ],
    [
        "name" => "ความปลอดภัยและการใช้อุปกรณ์ป้องกัน (PPE)",
        "description" => "สวมอุปกรณ์ป้องกันครบ ใช้ ขนย้าย และจัดเก็บสารเคมีตามมาตรฐาน และป้องกันผลกระทบต่อลูกค้าและสิ่งแวดล้อม",
        "weight" => 4,
        "difficulty" => 0.2,
        "criteria" => [
            5 => "ปฏิบัติตามมาตรฐานความปลอดภัยครบทุกครั้ง และช่วยตรวจเตือนเพื่อนร่วมทีม",
            4 => "ปฏิบัติตามมาตรฐานความปลอดภัยครบทุกครั้งโดยไม่ต้องมีผู้เตือน",
            3 => "ปฏิบัติตามมาตรฐานเป็นส่วนใหญ่ มีข้อบกพร่องเล็กน้อยที่แก้ไขได้ทันที",
            2 => "ละเลยการสวม PPE หรือขั้นตอนความปลอดภัยบางครั้ง ต้องได้รับการตักเตือน",
            1 => "ละเลยมาตรฐานความปลอดภัยบ่อยครั้ง หรือเกิดเหตุที่กระทบลูกค้าหรือตนเอง"
        ]
    ],
    [
        "name" => "การบริการลูกค้าและการสื่อสาร",
        "description" => "อธิบายงาน ให้คำแนะนำการป้องกันแมลง และตอบข้อซักถามของลูกค้าอย่างสุภาพ ชัดเจน",
        "weight" => 4,
        "difficulty" => 0.0,
        "criteria" => [
            5 => "ลูกค้าชื่นชม แนะนำวิธีป้องกันได้ตรงจุด และจัดการข้อร้องเรียนได้ด้วยตนเอง",
            4 => "สื่อสารสุภาพชัดเจน อธิบายงานและผลการให้บริการให้ลูกค้าเข้าใจทุกครั้ง",
            3 => "สื่อสารกับลูกค้าได้ตามมาตรฐาน ตอบคำถามพื้นฐานได้",
            2 => "สื่อสารไม่ชัดเจนในบางครั้ง ลูกค้าต้องสอบถามซ้ำ",
            1 => "มีข้อร้องเรียนเรื่องมารยาทหรือการสื่อสารกับลูกค้า"
        ]
    ],
    [
        "name" => "ความรับผิดชอบและการตรงต่อเวลา",
        "description" => "มาปฏิบัติงานตรงเวลา ทำงานตามแผนที่ได้รับมอบหมายจนเสร็จ และแจ้งปัญหาล่วงหน้า",
        "weight" => 4,
        "difficulty" => 0.1,
        "criteria" => [
            5 => "ตรงต่อเวลาเสมอ รับผิดชอบงานเกินที่ได้รับมอบหมายและช่วยทีมเมื่อมีงานเร่งด่วน",
            4 => "ตรงต่อเวลาและปิดงานตามแผนได้ครบโดยไม่ต้องติดตาม",
            3 => "ปฏิบัติงานตามแผนได้ มีล่าช้าบ้างแต่แจ้งล่วงหน้า",
            2 => "ล่าช้าหรือต้องติดตามงานบ่อยครั้ง",
            1 => "ขาดงาน มาสาย หรือทิ้งงานโดยไม่แจ้ง"
        ]
    ],
    [
        "name" => "การทำงานเป็นทีมและการดูแลอุปกรณ์",
        "description" => "ประสานงานกับทีมและฝ่ายสำนักงาน ดูแลเครื่องมือ เครื่องพ่น และรถบริการให้พร้อมใช้งาน",
        "weight" => 4,
        "difficulty" => 0.0,
        "criteria" => [
            5 => "เป็นแบบอย่างด้านการทำงานเป็นทีม ดูแลอุปกรณ์ให้พร้อมใช้และเสนอแนวทางปรับปรุง",
            4 => "ประสานงานกับทีมได้ดี ตรวจเช็กและทำความสะอาดอุปกรณ์หลังใช้งานทุกครั้ง",
            3 => "ทำงานร่วมกับทีมได้ ดูแลอุปกรณ์ตามรอบที่กำหนด",
            2 => "ประสานงานกับทีมได้บ้าง ละเลยการดูแลอุปกรณ์เป็นบางครั้ง",
            1 => "ไม่ให้ความร่วมมือกับทีม หรืออุปกรณ์เสียหายจากการใช้งานไม่ถูกต้อง"
        ]
    ]
];

$performanceWeight = array_sum(array_column($performanceKpis, "weight"));
$competencyWeight = array_sum(array_column($competencyKpis, "weight"));

if ($performanceWeight + $competencyWeight != 100) {
    exit("น้ำหนักรวมต้องเท่ากับ 100% (ตอนนี้ {$performanceWeight} + {$competencyWeight})\n");
}

$allNames = array_merge(array_column($performanceKpis, "name"), array_column($competencyKpis, "name"));
$namePlaceholders = implode(",", array_fill(0, count($allNames), "?"));


/*
|--------------------------------------------------------------------------
| --rollback: ลบข้อมูล demo ของสคริปต์นี้
|--------------------------------------------------------------------------
*/

if (isset($options["rollback"])) {

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        DELETE k FROM kpi_indicators k
        INNER JOIN kpi_categories c ON c.category_id = k.category_id
        WHERE k.kpi_name IN ({$namePlaceholders})
          AND c.category_name IN (?, ?)
    ");
    $stmt->execute([...$allNames, SERVICE_CATEGORY, COMPETENCY_CATEGORY]);
    $deletedKpis = $stmt->rowCount();

    $stmt = $pdo->prepare("
        DELETE FROM kpi_categories
        WHERE category_name = ?
          AND category_id NOT IN (SELECT category_id FROM kpi_indicators WHERE category_id IS NOT NULL)
    ");
    $stmt->execute([SERVICE_CATEGORY]);

    $pdo->commit();

    echo "Rollback: ลบ KPI {$deletedKpis} หัวข้อ (การมอบหมาย/ผลงาน/เกณฑ์ถูกลบตาม) และหมวด " . SERVICE_CATEGORY . "\n";
    exit;
}


/*
|--------------------------------------------------------------------------
| Pre-checks
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM kpi_indicators k
    INNER JOIN kpi_categories c ON c.category_id = k.category_id
    WHERE k.kpi_name IN ({$namePlaceholders}) AND c.category_name IN (?, ?)
");
$stmt->execute([...$allNames, SERVICE_CATEGORY, COMPETENCY_CATEGORY]);

if ((int) $stmt->fetchColumn() > 0) {
    exit("มีข้อมูล demo ของสคริปต์นี้อยู่แล้ว (รัน --rollback ก่อนหากต้องการสร้างใหม่)\n");
}

$employeeStmt = $pdo->prepare("
    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, p.position_name,
           (SELECT COALESCE(SUM(a.weight), 0) FROM kpi_assignments a
            WHERE a.employee_id = e.employee_id AND a.assignment_year = ? AND a.status = 'Active') AS current_weight
    FROM employees e
    INNER JOIN departments d ON d.department_id = e.department_id
    LEFT JOIN positions p ON p.position_id = e.position_id
    WHERE d.department_name = ?
      AND e.status = 'Active'
    ORDER BY e.employee_code
");
$employeeStmt->execute([DEMO_YEAR, SERVICE_DEPARTMENT]);

$employees = [];

foreach ($employeeStmt->fetchAll(PDO::FETCH_ASSOC) as $employee) {

    if (in_array($employee["position_name"], EXCLUDED_POSITIONS, true)) {
        echo "ข้าม {$employee["employee_code"]} ({$employee["position_name"]}): งานธุรการ ไม่ใช่งานบริการหน้างาน\n";
        continue;
    }

    if ((float) $employee["current_weight"] > 0) {
        echo "ข้าม {$employee["employee_code"]}: มี KPI ปี " . DEMO_YEAR . " อยู่แล้ว ({$employee["current_weight"]}%)\n";
        continue;
    }

    $employees[] = $employee;
}

if (empty($employees)) {
    exit("ไม่พบพนักงาน" . SERVICE_DEPARTMENT . "ที่มอบหมาย KPI ได้\n");
}

$periodStmt = $pdo->prepare("
    SELECT period_month, period_id, start_date
    FROM evaluation_periods
    WHERE period_year = ? AND period_month BETWEEN 1 AND 9
    ORDER BY period_month
");
$periodStmt->execute([DEMO_YEAR]);
$periods = [];

foreach ($periodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $periods[(int) $row["period_month"]] = $row;
}


/*
|--------------------------------------------------------------------------
| Demo score model (deterministic)
|--------------------------------------------------------------------------
*/

mt_srand(SEED);

$random = static fn(): float => mt_rand() / mt_getrandmax();

function demoGrade(float $raw): int
{
    return max(1, min(5, (int) round($raw)));
}

/* ผลที่ทำได้ (%) ให้อยู่ในช่วงเกณฑ์ของเกรดนั้น */
function demoActual(array $kpi, int $grade): int
{
    $bands = $kpi["bands"];

    if ($grade === 5) {
        return mt_rand($bands[5], 100);
    }

    if ($grade === 1) {
        return mt_rand($kpi["floor"], $bands[2] - 1);
    }

    return mt_rand($bands[$grade], $bands[$grade + 1] - 1);
}


/*
|--------------------------------------------------------------------------
| Insert
|--------------------------------------------------------------------------
*/

$pdo->beginTransaction();

try {

    /* หมวด */

    $categoryId = function (string $name, ?string $description) use ($pdo): int {
        $stmt = $pdo->prepare("SELECT category_id FROM kpi_categories WHERE category_name = ? LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if ($id) {
            return (int) $id;
        }

        $pdo->prepare("INSERT INTO kpi_categories (category_name, description) VALUES (?, ?)")->execute([$name, $description]);

        return (int) $pdo->lastInsertId();
    };

    $serviceCategoryId = $categoryId(SERVICE_CATEGORY, SERVICE_CATEGORY_DESCRIPTION);
    $competencyCategoryId = $categoryId(COMPETENCY_CATEGORY, null);


    /* หัวข้อ KPI + เกณฑ์ 5..1 (เก็บใน kpi_score_criteria เหมือนหน้าเพิ่ม KPI ของ Admin) */

    $kpiStmt = $pdo->prepare("
        INSERT INTO kpi_indicators (category_id, kpi_name, kpi_type, description, weight, unit, max_score)
        VALUES (?, ?, ?, ?, ?, ?, 5)
    ");
    $criteriaStmt = $pdo->prepare("INSERT INTO kpi_score_criteria (kpi_id, score_level, criteria) VALUES (?, ?, ?)");

    $definitions = [];

    foreach ([["Performance", $performanceKpis, $serviceCategoryId, "%"], ["Competency", $competencyKpis, $competencyCategoryId, "คะแนน"]] as [$type, $kpis, $category, $unit]) {
        foreach ($kpis as $kpi) {

            $kpiStmt->execute([$category, $kpi["name"], $type, $kpi["description"], $kpi["weight"], $unit]);
            $kpi["kpi_id"] = (int) $pdo->lastInsertId();
            $kpi["type"] = $type;

            foreach ($kpi["criteria"] as $level => $text) {
                $criteriaStmt->execute([$kpi["kpi_id"], $level, $text]);
            }

            $definitions[] = $kpi;
        }
    }


    /* มอบหมาย + ผลงานรายเดือน */

    $assignmentStmt = $pdo->prepare("
        INSERT INTO kpi_assignments (kpi_id, employee_id, assignment_year, period_id, target_value, weight, start_date, end_date, status)
        VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 'Active')
    ");
    $performanceStmt = $pdo->prepare("
        INSERT INTO kpi_performances (assignment_id, employee_id, period_id, performance_date, target, actual, score, comment, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)
    ");

    $assignmentCount = 0;
    $performanceCount = 0;
    $monthGrades = [];

    foreach ($employees as $index => $employee) {

        // ความสามารถพื้นฐาน + แนวโน้มพัฒนาการรายเดือน (หัวหน้าทีม/อาวุโสสูงกว่าเล็กน้อย)
        $isLead = preg_match('/^(Supervisor|Senior|Leader)/', (string) $employee["position_name"]) === 1;
        $base = min(4.8, 2.9 + $random() * 1.6 + ($isLead ? 0.3 : 0));
        $trend = -0.02 + $random() * 0.1;
        $hasSeptember = $index % 3 !== 0;

        foreach ($definitions as $kpi) {

            $target = $kpi["type"] === "Performance" ? $kpi["target"] : 5;

            $assignmentStmt->execute([
                $kpi["kpi_id"], $employee["employee_id"], DEMO_YEAR,
                $target, $kpi["weight"], DEMO_YEAR . "-01-01", DEMO_YEAR . "-12-31"
            ]);
            $assignmentId = (int) $pdo->lastInsertId();
            $assignmentCount++;

            foreach ($periods as $month => $period) {

                if ($month === 9 && !$hasSeptember) {
                    continue;
                }

                $raw = $base + $trend * ($month - 1) + $kpi["difficulty"] + ($random() - 0.5) * 1.2;
                $grade = demoGrade($raw);
                $actual = $kpi["type"] === "Performance" ? demoActual($kpi, $grade) : null;
                $status = $month <= 7 ? "Approved" : ($month === 8 ? "Submitted" : "Draft");

                $performanceStmt->execute([
                    $assignmentId, $employee["employee_id"], $period["period_id"], $period["start_date"],
                    $target, $actual, $grade, $status
                ]);
                $performanceCount++;
                $monthGrades[$month][] = $grade;
            }
        }
    }

    $pdo->commit();

} catch (Throwable $exception) {

    $pdo->rollBack();
    exit("ไม่สำเร็จ (ยกเลิกทั้งหมด): " . $exception->getMessage() . "\n");
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

echo "หมวด: " . SERVICE_CATEGORY . " (#{$serviceCategoryId}) · " . COMPETENCY_CATEGORY . " (#{$competencyCategoryId})\n";
echo "KPI: " . count($performanceKpis) . " Performance ({$performanceWeight}%) + " . count($competencyKpis) . " Competency ({$competencyWeight}%)\n";
echo "มอบหมาย: " . count($employees) . " คน · {$assignmentCount} รายการ · ผลงาน {$performanceCount} รายการ\n";

foreach ($monthGrades as $month => $grades) {
    printf("  เดือน %2d: %3d รายการ · เกรดเฉลี่ย %.2f\n", $month, count($grades), array_sum($grades) / count($grades));
}
