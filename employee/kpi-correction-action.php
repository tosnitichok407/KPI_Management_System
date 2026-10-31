<?php

/*
|--------------------------------------------------------------------------
| พนักงานส่งคำขอแก้ไขข้อมูล KPI (POST จาก employee/kpi-correction.php)
|--------------------------------------------------------------------------
|
| เฉพาะพนักงาน (role 3) · ตรวจ CSRF · ส่งได้เฉพาะผลงานของตัวเอง
| ตรวจค่า / เหตุผล / ชนิดและขนาดไฟล์ / คำขอซ้ำ ที่ includes/kpi-correction.php (ใน Transaction)
| ไม่เปลี่ยนค่าในผลงาน · ค่าจริงเปลี่ยนเมื่อ Manager อนุมัติเท่านั้น
|
*/

require_once __DIR__ . "/../includes/security.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/kpi-correction.php";

/** @var PDO $pdo */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION["employee_id"] ?? 0);

if ((int) ($_SESSION["role_id"] ?? 0) !== 3 || $employeeId <= 0) {
    redirectToRoleHome("../");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: kpi-correction.php");
    exit;
}

$performanceId = (int) ($_POST["performance_id"] ?? 0);
$previousId = (int) ($_POST["previous_request_id"] ?? 0);
$back = "kpi-correction.php" . ($previousId > 0 ? "?resubmit={$previousId}" : ($performanceId > 0 ? "?performance_id={$performanceId}" : ""));

$finish = function (string $type, string $message, string $location, array $old = []): void {
    $_SESSION["correction_flash"] = ["type" => $type, "message" => $message];
    if ($old) {
        $_SESSION["correction_old"] = $old;
    }
    header("Location: " . $location);
    exit;
};

/* ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งหมด (รวม CSRF) */
if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
    $finish("error", "ขนาดไฟล์รวมเกินกว่าที่ระบบรับได้ (ไฟล์ละไม่เกิน 10 MB)", "kpi-correction.php");
}

if (!csrfVerify()) {
    $finish("error", "Session หมดอายุ กรุณาลองใหม่อีกครั้ง", $back);
}

$old = [
    "performance_id" => $performanceId,
    "request_type" => (string) ($_POST["request_type"] ?? ""),
    "proposed_value" => mb_substr((string) ($_POST["proposed_value"] ?? ""), 0, 30),
    "reason" => mb_substr((string) ($_POST["reason"] ?? ""), 0, KPI_CORRECTION_REASON_MAX),
];

$result = kpiCorrectionSubmit(
    $pdo,
    $employeeId,
    (int) $_SESSION["user_id"],
    [
        "performance_id" => $performanceId,
        "request_type" => $old["request_type"],
        "proposed_value" => $old["request_type"] === "value_change" ? (string) ($_POST["proposed_value"] ?? "") : "",
        "reason" => (string) ($_POST["reason"] ?? ""),
        "previous_request_id" => $previousId,
        "reuse_previous_files" => ($_POST["reuse_previous_files"] ?? "") === "1",
    ],
    kpiCorrectionNormalizeFiles($_FILES["files"] ?? null)
);

if ($result["error"] !== null) {
    $finish("error", $result["error"], $back, $old);
}

$finish("success", "ส่งคำขอ #" . $result["request_id"] . " ให้ผู้จัดการสาขาพิจารณาแล้ว · ค่าเดิมยังไม่เปลี่ยนจนกว่าจะอนุมัติ", "kpi-correction.php#request-" . $result["request_id"]);
