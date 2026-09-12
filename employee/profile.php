<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/includes/layout.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$roleId = (int) ($_SESSION["role_id"] ?? 0);

if ($roleId !== 3) {
    if ($roleId === 1) {
        header("Location: ../admin/index.php");
    } else {
        header("Location: ../login.php");
    }

    exit;
}

$stmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.email,
        e.phone,
        e.hire_date,
        e.status,
        d.department_name,
        p.position_name
    FROM users u
    INNER JOIN employees e
        ON u.employee_id = e.employee_id
    LEFT JOIN departments d
        ON e.department_id = d.department_id
    LEFT JOIN positions p
        ON e.position_id = p.position_id
    WHERE u.user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    ":user_id" => (int) $_SESSION["user_id"]
]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$fullName = trim(
    ($employee["first_name"] ?? "") . " " . ($employee["last_name"] ?? "")
);

$initials = strtoupper(
    mb_substr($employee["first_name"] ?? "?", 0, 1)
);

$hireDate = !empty($employee["hire_date"])
    ? date("d/m/Y", strtotime($employee["hire_date"]))
    : "-";

$statusLabel = ($employee["status"] ?? "") === "Active"
    ? "กำลังปฏิบัติงาน"
    : "ไม่ใช้งาน";

function profileValue(?string $value): string
{
    return trim((string) $value) !== ""
        ? htmlspecialchars($value, ENT_QUOTES, "UTF-8")
        : "-";
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลส่วนตัว | KPI Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/employee-kpi.css?v=layout-employee-2">
</head>
<body>

    <?php employeeLayoutStart("profile", "../"); ?>

        <section class="page-header">
            <div class="page-title-block">
                <h1>ข้อมูลส่วนตัว</h1>
                <p>ข้อมูลพนักงานของคุณ</p>
            </div>
        </section>

        <section class="employee-profile">
            <div class="employee-profile__header">
                <div class="employee-profile__avatar"><?= profileValue($initials) ?></div>
                <div>
                    <h2><?= profileValue($fullName) ?></h2>
                    <p><?= profileValue($employee["position_name"] ?? null) ?></p>
                </div>
                <span class="employee-profile__status <?= ($employee["status"] ?? "") === "Active" ? "is-active" : "is-inactive" ?>">
                    <?= profileValue($statusLabel) ?>
                </span>
            </div>

            <div class="employee-profile__details">
                <div class="employee-detail">
                    <span>รหัสพนักงาน</span>
                    <strong><?= profileValue($employee["employee_code"] ?? null) ?></strong>
                </div>
                <div class="employee-detail">
                    <span>แผนก</span>
                    <strong><?= profileValue($employee["department_name"] ?? null) ?></strong>
                </div>
                <div class="employee-detail">
                    <span>ตำแหน่ง</span>
                    <strong><?= profileValue($employee["position_name"] ?? null) ?></strong>
                </div>
                <div class="employee-detail">
                    <span>วันที่เริ่มงาน</span>
                    <strong><?= profileValue($hireDate) ?></strong>
                </div>
                <div class="employee-detail">
                    <span>อีเมล</span>
                    <strong><?= profileValue($employee["email"] ?? null) ?></strong>
                </div>
                <div class="employee-detail">
                    <span>เบอร์โทรศัพท์</span>
                    <strong><?= profileValue($employee["phone"] ?? null) ?></strong>
                </div>
            </div>
        </section>

    <?php employeeLayoutEnd("../"); ?>

</body>
</html>
