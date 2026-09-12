<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/includes/layout.php";
require_once __DIR__ . "/includes/feedback.php";


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ($employeeId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Feedback ของปีที่เลือก (ค่าเริ่มต้น = ปีล่าสุดที่มี Feedback)
|--------------------------------------------------------------------------
*/

$currentYear = (int) date("Y");
$feedbackYears = employeeFeedbackYears($pdo, $employeeId);

$selectedYear = (int) ($_GET["year"] ?? ($feedbackYears[0] ?? $currentYear));

if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}

$yearOptions = array_unique(array_merge($feedbackYears, [$currentYear, $selectedYear]));
rsort($yearOptions);

$items = employeeFeedbackList($pdo, $employeeId, ["year" => $selectedYear]);

$average = $items
    ? array_sum(array_map(fn($item) => (float) $item["evaluation_score"], $items)) / count($items)
    : null;


/* ตัวเลือกปี (ส่งฟอร์มทันทีเมื่อเปลี่ยน) */

$yearFilter = '<form method="GET" class="feedback-year-filter">'
    . '<label for="feedbackYear">ปี</label>'
    . '<select id="feedbackYear" name="year" onchange="this.form.submit()">';

foreach ($yearOptions as $year) {
    $yearFilter .= '<option value="' . $year . '"' . ($year === $selectedYear ? " selected" : "") . ">" . $year . "</option>";
}

$yearFilter .= '</select><noscript><button type="submit">ดู</button></noscript></form>';

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback จากหัวหน้า | KPI Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/employee-kpi.css?v=layout-employee-2">
    <link rel="stylesheet" href="../assets/css/employee-feedback.css?v=1">
</head>
<body>

    <?php employeeLayoutStart("feedback", "../"); ?>

        <section class="page-header">
            <div class="page-title-block">
                <h1>Feedback จากหัวหน้า</h1>
                <p>ข้อความและคะแนนประเมินรายเดือนจากหัวหน้างานของคุณ</p>
            </div>
        </section>

        <?php
        renderFeedbackCard($items, [
            "root" => "../",
            "title" => "ปี " . $selectedYear,
            "subtitle" => count($items) . " รายการ"
                . ($average !== null ? " · คะแนนเฉลี่ยจากหัวหน้า " . number_format($average, 1) . " / 100" : ""),
            "header_extra" => $yearFilter,
            "empty" => "ยังไม่มี Feedback จากหัวหน้าในปี " . $selectedYear
        ]);
        ?>

    <?php employeeLayoutEnd("../"); ?>

</body>
</html>
