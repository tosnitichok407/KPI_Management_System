<?php

session_start();
require_once "../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$roleId = (int) ($_SESSION["role_id"] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    header("Location: ../employee/index.php");
    exit;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$periods = $pdo->query("SELECT period_id, period_name, start_date, end_date, status FROM evaluation_periods ORDER BY start_date DESC, period_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

$periodId = (int) ($_GET["period_id"] ?? ($periods[0]["period_id"] ?? 0));
$departmentId = (int) ($_GET["department_id"] ?? ($_SESSION["department_id"] ?? 0));
if ($roleId === 2 && $departmentId <= 0) {
    $departmentId = (int) ($_SESSION["department_id"] ?? 0);
}

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period["period_id"] === $periodId) {
        $selectedPeriod = $period;
        break;
    }
}

$where = "e.status = 'Active'";
$params = [];
if ($departmentId > 0) {
    $where .= " AND e.department_id = :department_id";
    $params[":department_id"] = $departmentId;
}

$summary = ["employees" => 0, "assigned" => 0, "evaluated" => 0, "average_score" => 0];
if ($periodId > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.employee_id) AS employees, COUNT(DISTINCT ka.assignment_id) AS assigned, COUNT(DISTINCT CASE WHEN ek.score IS NOT NULL THEN ek.employee_kpi_id END) AS evaluated, COALESCE(AVG(ek.score), 0) AS average_score FROM employees e LEFT JOIN kpi_assignments ka ON ka.employee_id = e.employee_id AND ka.period_id = :period_id AND ka.status = 'Active' LEFT JOIN employee_kpi ek ON ek.employee_id = e.employee_id AND ek.kpi_id = ka.kpi_id AND ek.period_id = ka.period_id WHERE {$where}");
    $stmt->execute(array_merge([":period_id" => $periodId], $params));
    $summary = array_merge($summary, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

$employeeStmt = $pdo->prepare("SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, p.position_name, COALESCE(AVG(ek.score), 0) AS average_score, COUNT(DISTINCT ka.assignment_id) AS total_kpi, COUNT(DISTINCT CASE WHEN ek.score IS NOT NULL THEN ek.employee_kpi_id END) AS evaluated_kpi FROM employees e LEFT JOIN positions p ON p.position_id = e.position_id LEFT JOIN kpi_assignments ka ON ka.employee_id = e.employee_id AND ka.period_id = :period_id AND ka.status = 'Active' LEFT JOIN employee_kpi ek ON ek.employee_id = e.employee_id AND ek.kpi_id = ka.kpi_id AND ek.period_id = ka.period_id WHERE {$where} GROUP BY e.employee_id, e.employee_code, e.first_name, e.last_name, p.position_name ORDER BY average_score DESC, e.first_name ASC");
$employeeStmt->execute(array_merge([":period_id" => $periodId], $params));
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$departmentRows = [];
if ($periodId > 0) {
    $departmentStmt = $pdo->prepare("SELECT d.department_id, d.department_name, COUNT(DISTINCT e.employee_id) AS employee_count, COALESCE(AVG(ek.score), 0) AS average_score, COUNT(DISTINCT CASE WHEN ek.score IS NOT NULL THEN ek.employee_kpi_id END) AS evaluated_count FROM departments d LEFT JOIN employees e ON e.department_id = d.department_id AND e.status = 'Active' LEFT JOIN kpi_assignments ka ON ka.employee_id = e.employee_id AND ka.period_id = :period_id AND ka.status = 'Active' LEFT JOIN employee_kpi ek ON ek.employee_id = e.employee_id AND ek.kpi_id = ka.kpi_id AND ek.period_id = ka.period_id GROUP BY d.department_id, d.department_name ORDER BY average_score DESC, d.department_name");
    $departmentStmt->execute([":period_id" => $periodId]);
    $departmentRows = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
}

$completion = (int) $summary["assigned"] > 0 ? round(((int) $summary["evaluated"] / (int) $summary["assigned"]) * 100) : 0;
$managerName = trim(($_SESSION["first_name"] ?? "") . " " . ($_SESSION["last_name"] ?? ""));
$flash = $_GET["saved"] ?? "";

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Overview | KPI System</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/manager.css">
</head>

<body>
    <aside class="manager-sidebar">
        <div class="brand"><img src="../assets/images/Advance-Logo.png" alt="Advance Asia Group">
            <div><strong>KPI System</strong><small>Manager workspace</small></div>
        </div>
        <nav>
            <a class="active" href="index.php">◉ <span>ภาพรวมผลงาน</span></a>
            <a href="#departments">▦ <span>ผลรายแผนก</span></a>
            <a href="#employees">♙ <span>ผลงานพนักงาน</span></a>
        </nav>
        <a class="logout" href="../logout.php">ออกจากระบบ</a>
    </aside>

    <main class="manager-main">
        <header class="page-heading">
            <div>
                <p class="eyebrow">MANAGER CONTROL ROOM</p>
                <h1>ภาพรวมผลการปฏิบัติงาน</h1>
                <p class="muted">สวัสดีคุณ <?= e($managerName ?: "ผู้จัดการ") ?> ติดตามความคืบหน้าของทีมได้จากหน้านี้</p>
            </div>
            <div class="avatar"><?= e(mb_strtoupper(mb_substr($managerName ?: "M", 0, 1))) ?></div>
        </header>

        <?php if ($flash === "1"): ?><div class="alert success">บันทึก feedback และผลการประเมินเรียบร้อยแล้ว</div><?php endif; ?>

        <form class="filters" method="get">
            <label>รอบการประเมิน<select name="period_id" onchange="this.form.submit()"><?php foreach ($periods as $period): ?><option value="<?= (int) $period["period_id"] ?>" <?= (int) $period["period_id"] === $periodId ? "selected" : "" ?>><?= e($period["period_name"]) ?></option><?php endforeach; ?></select></label>
            <label>แผนก<select name="department_id" onchange="this.form.submit()">
                    <option value="0">ทุกแผนก</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department["department_id"] ?>" <?= (int) $department["department_id"] === $departmentId ? "selected" : "" ?>><?= e($department["department_name"]) ?></option><?php endforeach; ?>
                </select></label>
            <span class="period-note"><?= $selectedPeriod ? e($selectedPeriod["start_date"]) . " - " . e($selectedPeriod["end_date"]) : "ยังไม่มีรอบประเมิน" ?></span>
        </form>

        <section class="metric-grid">
            <article class="metric"><span class="metric-icon orange">↗</span>
                <div>
                    <p>คะแนนเฉลี่ยทีม</p><strong><?= number_format((float) $summary["average_score"], 1) ?></strong><small>จากคะแนนเต็ม 100</small>
                </div>
            </article>
            <article class="metric"><span class="metric-icon navy">♙</span>
                <div>
                    <p>พนักงานในมุมมอง</p><strong><?= number_format((int) $summary["employees"]) ?></strong><small>คนที่ยังปฏิบัติงาน</small>
                </div>
            </article>
            <article class="metric"><span class="metric-icon green">✓</span>
                <div>
                    <p>ประเมินแล้ว</p><strong><?= $completion ?>%</strong><small><?= (int) $summary["evaluated"] ?> จาก <?= (int) $summary["assigned"] ?> KPI</small>
                </div>
            </article>
            <article class="metric highlight">
                <p>ต้องติดตาม</p><strong><?= max(0, (int) $summary["employees"] - count(array_filter($employees, fn($employee) => (int) $employee["evaluated_kpi"] > 0))) ?></strong><small>พนักงานที่ยังไม่มีผลประเมิน</small>
            </article>
        </section>

        <section class="content-grid" id="departments">
            <article class="panel department-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">COMPANY PULSE</p>
                        <h2>ภาพรวมแต่ละแผนก</h2>
                    </div><span class="legend"><i></i> คะแนนเฉลี่ย</span>
                </div>
                <div class="department-list"><?php foreach ($departmentRows as $department): $score = min(100, (float) $department["average_score"]); ?><div class="department-row">
                            <div class="department-name"><strong><?= e($department["department_name"]) ?></strong><small><?= (int) $department["employee_count"] ?> คน · ประเมินแล้ว <?= (int) $department["evaluated_count"] ?> รายการ</small></div>
                            <div class="bar"><span style="width: <?= $score ?>%"></span></div><strong class="score"><?= number_format($score, 1) ?></strong>
                        </div><?php endforeach; ?><?php if (!$departmentRows): ?><p class="empty">ยังไม่มีข้อมูลผลการประเมินในรอบนี้</p><?php endif; ?></div>
            </article>
            <article class="panel insight-panel">
                <p class="eyebrow">TEAM SIGNAL</p>
                <h2>จุดที่ควรใส่ใจ</h2>
                <div class="signal"><span class="signal-dot amber"></span>
                    <div><strong><?= max(0, (int) $summary["assigned"] - (int) $summary["evaluated"]) ?> KPI</strong>
                        <p>รอการประเมินจากทีม</p>
                    </div>
                </div>
                <div class="signal"><span class="signal-dot coral"></span>
                    <div><strong><?= count(array_filter($employees, fn($employee) => (float) $employee["average_score"] > 0 && (float) $employee["average_score"] < 60)) ?> คน</strong>
                        <p>ควรนัดพูดคุยเพิ่มเติม</p>
                    </div>
                </div>
                <div class="signal"><span class="signal-dot teal"></span>
                    <div><strong><?= count(array_filter($employees, fn($employee) => (float) $employee["average_score"] >= 80)) ?> คน</strong>
                        <p>ผลงานโดดเด่น</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="panel employee-panel" id="employees">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">PEOPLE PERFORMANCE</p>
                    <h2>ผลงานของพนักงาน</h2>
                </div><span class="count-label"><?= count($employees) ?> คน</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>พนักงาน</th>
                            <th>ตำแหน่ง</th>
                            <th>ความคืบหน้า</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($employees as $employee): $score = (float) $employee["average_score"];
                                $progress = (int) $employee["total_kpi"] > 0 ? round(((int) $employee["evaluated_kpi"] / (int) $employee["total_kpi"]) * 100) : 0;
                                $status = $score >= 80 ? ["โดดเด่น", "good"] : ($score > 0 && $score < 60 ? ["ควรติดตาม", "needs"] : ($progress < 100 ? ["รอประเมิน", "pending"] : ["อยู่ในเกณฑ์", "steady"])); ?><tr>
                                <td>
                                    <div class="person"><span class="person-avatar"><?= e(mb_strtoupper(mb_substr($employee["first_name"], 0, 1))) ?></span>
                                        <div><strong><?= e($employee["first_name"] . " " . $employee["last_name"]) ?></strong><small><?= e($employee["employee_code"]) ?></small></div>
                                    </div>
                                </td>
                                <td><?= e($employee["position_name"] ?: "ไม่ระบุตำแหน่ง") ?></td>
                                <td>
                                    <div class="progress-label"><span><?= $progress ?>%</span><small><?= (int) $employee["evaluated_kpi"] ?>/<?= (int) $employee["total_kpi"] ?> KPI</small></div>
                                    <div class="mini-bar"><span style="width: <?= $progress ?>%"></span></div>
                                </td>
                                <td><strong class="table-score <?= $status[1] ?>"><?= number_format($score, 1) ?></strong></td>
                                <td><span class="status <?= $status[1] ?>"><?= $status[0] ?></span></td>
                                <td><button type="button" class="feedback-btn" data-id="<?= (int) $employee["employee_id"] ?>" data-name="<?= e($employee["first_name"] . " " . $employee["last_name"]) ?>">Feedback</button></td>
                            </tr><?php endforeach; ?></tbody>
                </table><?php if (!$employees): ?><p class="empty">ไม่พบพนักงานในตัวกรองนี้</p><?php endif; ?>
            </div>
        </section>
    </main>

    <dialog id="feedbackDialog">
        <form method="post" action="feedback.php"><button type="button" class="dialog-close" onclick="feedbackDialog.close()">×</button>
            <p class="eyebrow">ONE-ON-ONE REVIEW</p>
            <h2>Feedback & การประเมิน</h2>
            <p class="dialog-person" id="dialogPerson"></p><input type="hidden" name="employee_id" id="employeeId"><input type="hidden" name="period_id" value="<?= $periodId ?>"><label>คะแนนประเมิน (0-100)<input type="number" name="evaluation_score" min="0" max="100" step="0.1" required></label><label>Feedback ถึงพนักงาน<textarea name="feedback" rows="5" placeholder="เขียนข้อเสนอแนะ จุดแข็ง และสิ่งที่ควรพัฒนา" required></textarea></label><button class="primary-btn" type="submit">บันทึกผลการประเมิน</button>
        </form>
    </dialog>
    <script>
        const feedbackDialog = document.getElementById('feedbackDialog');
        document.querySelectorAll('.feedback-btn').forEach((button) => button.addEventListener('click', () => {
            document.getElementById('employeeId').value = button.dataset.id;
            document.getElementById('dialogPerson').textContent = button.dataset.name;
            feedbackDialog.showModal();
        }));
    </script>
</body>

</html>