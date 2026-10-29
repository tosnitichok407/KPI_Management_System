<?php

/*
|--------------------------------------------------------------------------
| ขั้นตอนส่งผลและอนุมัติผล KPI รายเดือน
|--------------------------------------------------------------------------
|
| หน่วยของการส่ง / อนุมัติ = พนักงาน 1 คน × 1 เดือน (ทุกแถวผลงานของเดือนนั้นพร้อมกัน)
|
|   พนักงาน   Draft / Rejected  → Submitted   (ส่งให้ Manager ตรวจ)
|   Manager   Submitted         → Approved    (อนุมัติ · ล็อกถาวร)
|   Manager   Submitted         → Rejected    (ส่งกลับให้แก้ไข · ต้องระบุเหตุผล)
|
| Rejected แก้ไขได้เหมือน Draft (นำเข้าไฟล์ทับ / แนบหลักฐาน) แล้วส่งใหม่
| ทุกการดำเนินการบันทึกลง kpi_approval_logs (Migration 20261025_kpi_approval_workflow)
| ไม่มีตาราง → ปิดขั้นตอนนี้ หน้าเว็บทำงานแบบเดิม
|
| สิทธิ์: ส่ง = พนักงานเจ้าของผลงาน (role 3) · อนุมัติ / ส่งกลับ = Manager (role 2) เท่านั้น
| Manager ดูแลพนักงานทุกแผนกของสาขา จึงไม่จำกัดตามแผนก · ห้ามอนุมัติผลงานของตัวเอง
|
*/

require_once __DIR__ . "/kpi-score-helper.php";

const KPI_EDITABLE_STATUSES = ["Draft", "Rejected"];

/*
| KPI ที่เกณฑ์ในระบบยังรอเจ้าของระบบยืนยัน · คะแนนของ KPI เหล่านี้ "ยืนยันความถูกต้องไม่ได้" เสมอ
| เมื่อเจ้าของระบบยืนยันเกณฑ์แล้ว ให้ลบ KPI ออกจากรายการนี้ (ไม่ต้องแก้ส่วนอื่น)
| 26 ต.ค. 2026: เจ้าของระบบยืนยันเกณฑ์ KPI 10 และ KPI 14 แล้ว (กฎใน includes/kpi-score-helper.php) จึงลบออก
|   KPI 14 ที่ผลอยู่ในช่องว่างของสูตรบริษัท ยังแสดง "เกณฑ์รอยืนยัน" ผ่าน RENEWAL_KPI_GAP_MESSAGE
*/
const KPI_CRITERIA_PENDING_CONFIRMATION = [];

const KPI_SCORE_CHECK_LABELS = [
    "ok" => "ตรงเกณฑ์",
    "mismatch" => "ไม่ตรงเกณฑ์",
    "pending" => "เกณฑ์รอยืนยัน",
    "unverifiable" => "ตรวจไม่ได้",
];
const KPI_APPROVAL_COMMENT_MAX = 1000;
const KPI_APPROVAL_ROLE_MANAGER = 2;

const KPI_STATUS_LABELS = [
    "Draft" => "ยังไม่ส่ง (Draft)",
    "Submitted" => "รอ Manager อนุมัติ",
    "Approved" => "อนุมัติแล้ว",
    "Rejected" => "ส่งกลับให้แก้ไข",
    "Mixed" => "สถานะไม่ตรงกัน",
    "None" => "ยังไม่มีผลงาน",
];

const KPI_APPROVAL_ACTION_LABELS = [
    "submit" => "ส่งผลการประเมิน",
    "approve" => "อนุมัติ",
    "return" => "ส่งกลับให้แก้ไข",
];

function kpiApprovalEnabled(PDO $pdo): bool
{
    static $enabled = null;

    if ($enabled === null) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_approval_logs'");
            $enabled = (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $exception) {
            $enabled = false;
        }
    }

    return $enabled;
}

function kpiStatusIsEditable(?string $status): bool
{
    return in_array((string) $status, KPI_EDITABLE_STATUSES, true);
}

/* สถานะรวมของเดือนจากรายการสถานะของแต่ละแถว */
function kpiApprovalStateFromStatuses(array $statuses): string
{
    if (!$statuses) {
        return "None";
    }

    $unique = array_values(array_unique($statuses));

    if (count($unique) === 1) {
        return (string) $unique[0];
    }

    /* Draft ปน Rejected = ยังแก้ไขได้ทั้งเดือน (นับเป็น Rejected เพื่อให้เห็นว่าเคยถูกส่งกลับ) */
    if (!array_diff($unique, KPI_EDITABLE_STATUSES)) {
        return "Rejected";
    }

    return "Mixed";
}

/*
| สถานะของพนักงาน 1 คน × 1 เดือน
| คืน ["state", "counts" => [status => n], "rows" => n, "assigned" => n, "unscored" => n]
*/
function kpiApprovalMonthState(PDO $pdo, int $employeeId, int $periodId): array
{
    $stmt = $pdo->prepare("SELECT status, score FROM kpi_performances WHERE employee_id = :employee_id AND period_id = :period_id");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = [];
    $unscored = 0;
    foreach ($rows as $row) {
        $counts[$row["status"]] = ($counts[$row["status"]] ?? 0) + 1;
        if ($row["score"] === null) {
            $unscored++;
        }
    }

    $assigned = $pdo->prepare("
        SELECT COUNT(*)
        FROM kpi_assignments a
        INNER JOIN evaluation_periods ep ON ep.period_id = :period_id
        WHERE a.employee_id = :employee_id
          AND a.status = 'Active'
          AND COALESCE(a.start_date, CONCAT(a.assignment_year, '-01-01')) <= ep.end_date
          AND COALESCE(a.end_date, CONCAT(a.assignment_year, '-12-31')) >= ep.start_date
    ");
    $assigned->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    $editable = 0;
    foreach (KPI_EDITABLE_STATUSES as $editableStatus) {
        $editable += $counts[$editableStatus] ?? 0;
    }

    return [
        "state" => kpiApprovalStateFromStatuses(array_column($rows, "status")),
        /* ส่งได้เมื่อมีแถวที่ยังแก้ไขได้ และยังไม่มีแถวที่อนุมัติแล้ว (รวมเดือนเก่าที่ Draft ปน Submitted) */
        "can_submit" => $editable > 0 && empty($counts["Approved"]),
        "editable" => $editable,
        "counts" => $counts,
        "rows" => count($rows),
        "assigned" => (int) $assigned->fetchColumn(),
        "unscored" => $unscored,
    ];
}

/* ล็อกแถวผลงานของเดือน (เรียกภายใน Transaction) */
function kpiApprovalLockRows(PDO $pdo, int $employeeId, int $periodId): array
{
    $stmt = $pdo->prepare("
        SELECT performance_id, status, score
        FROM kpi_performances
        WHERE employee_id = :employee_id AND period_id = :period_id
        ORDER BY performance_id
        FOR UPDATE
    ");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kpiApprovalLog(PDO $pdo, int $employeeId, int $periodId, string $action, string $fromStatus, string $toStatus, array $performanceIds, ?string $comment, int $actorUserId, int $actorRoleId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO kpi_approval_logs
            (employee_id, period_id, action, from_status, to_status, performance_count, performance_ids, comment, actor_user_id, actor_role_id)
        VALUES
            (:employee_id, :period_id, :action, :from_status, :to_status, :performance_count, :performance_ids, :comment, :actor_user_id, :actor_role_id)
    ");
    $stmt->execute([
        ":employee_id" => $employeeId,
        ":period_id" => $periodId,
        ":action" => $action,
        ":from_status" => $fromStatus,
        ":to_status" => $toStatus,
        ":performance_count" => count($performanceIds),
        ":performance_ids" => implode(",", array_map("intval", $performanceIds)),
        ":comment" => $comment !== null && trim($comment) !== "" ? trim($comment) : null,
        ":actor_user_id" => $actorUserId,
        ":actor_role_id" => $actorRoleId,
    ]);
}

/* ตรวจรอบประเมิน · คืน [period หรือ null, error หรือ null] */
function kpiApprovalPeriod(PDO $pdo, int $periodId): array
{
    $stmt = $pdo->prepare("SELECT period_id, period_year, period_month, status FROM evaluation_periods WHERE period_id = :id");
    $stmt->execute([":id" => $periodId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [$period, $period ? null : "ไม่พบรอบประเมินของเดือนนี้"];
}

/*
| พนักงานส่งผลการประเมินของเดือน · คืน null = สำเร็จ หรือข้อความ error
| เงื่อนไข: มีแถว Draft / Rejected อย่างน้อย 1 รายการ · ยังไม่มีแถว Approved · แถวที่ส่งมีคะแนนครบ · รอบประเมินยัง Open
| แถวที่เป็น Submitted อยู่แล้ว (ข้อมูลเดิมที่ Draft ปน Submitted) คงไว้ → หลังส่งทั้งเดือนเป็น Submitted
*/
function kpiApprovalSubmit(PDO $pdo, int $employeeId, int $userId, int $roleId, int $periodId): ?string
{
    if (!kpiApprovalEnabled($pdo)) {
        return "ขั้นตอนส่งผลและอนุมัติยังไม่เปิดใช้งาน";
    }

    [$period, $error] = kpiApprovalPeriod($pdo, $periodId);
    if ($error !== null) {
        return $error;
    }
    if ((string) $period["status"] !== "Open") {
        return "รอบประเมินของเดือนนี้ปิดแล้ว ส่งผลการประเมินไม่ได้";
    }

    try {
        $pdo->beginTransaction();
        $rows = kpiApprovalLockRows($pdo, $employeeId, $periodId);

        if (!$rows) {
            $pdo->rollBack();
            return "ยังไม่มีผลงาน KPI ของเดือนนี้ กรุณานำเข้าไฟล์ Excel ก่อนส่ง";
        }

        $statuses = array_column($rows, "status");
        if (in_array("Approved", $statuses, true)) {
            $pdo->rollBack();
            return "ผลการประเมินของเดือนนี้ได้รับการอนุมัติแล้ว";
        }

        $editableRows = array_values(array_filter($rows, fn($row) => kpiStatusIsEditable((string) $row["status"])));
        if (!$editableRows) {
            $pdo->rollBack();
            return "ผลการประเมินของเดือนนี้ส่งแล้ว รอ Manager ตรวจสอบ";
        }

        if (array_filter($editableRows, fn($row) => $row["score"] === null)) {
            $pdo->rollBack();
            return "มี KPI ที่ยังไม่มีคะแนน กรุณาตรวจสอบผลงานก่อนส่ง";
        }

        $ids = array_map(fn($row) => (int) $row["performance_id"], $editableRows);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $update = $pdo->prepare("UPDATE kpi_performances SET status = 'Submitted' WHERE performance_id IN ({$in}) AND status IN ('Draft', 'Rejected')");
        $update->execute($ids);

        if ($update->rowCount() !== count($ids)) {
            $pdo->rollBack();
            return "ข้อมูลผลงานเปลี่ยนไประหว่างส่ง กรุณาลองใหม่อีกครั้ง";
        }

        kpiApprovalLog($pdo, $employeeId, $periodId, "submit", kpiApprovalStateFromStatuses($statuses), "Submitted", $ids, null, $userId, $roleId);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiApprovalSubmit failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "ส่งผลการประเมินไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/*
| Manager อนุมัติ / ส่งกลับ · $decision = "approve" | "return" · คืน null = สำเร็จ หรือข้อความ error
| เงื่อนไข: role Manager · ไม่ใช่ผลงานของตัวเอง · ทุกแถวของเดือนเป็น Submitted · ส่งกลับต้องมีเหตุผล
*/
function kpiApprovalReview(PDO $pdo, int $actorUserId, int $actorRoleId, int $actorEmployeeId, int $employeeId, int $periodId, string $decision, string $comment, bool $acknowledgedWarnings = false): ?string
{
    if (!kpiApprovalEnabled($pdo)) {
        return "ขั้นตอนส่งผลและอนุมัติยังไม่เปิดใช้งาน";
    }

    if ($actorRoleId !== KPI_APPROVAL_ROLE_MANAGER) {
        return "เฉพาะ Manager เท่านั้นที่อนุมัติหรือส่งกลับผลการประเมินได้";
    }

    if (!in_array($decision, ["approve", "return"], true)) {
        return "คำสั่งไม่ถูกต้อง";
    }

    if ($actorEmployeeId > 0 && $actorEmployeeId === $employeeId) {
        return "ไม่สามารถอนุมัติผลการประเมินของตัวเองได้";
    }

    $comment = trim($comment);
    if ($decision === "return" && $comment === "") {
        return "กรุณาระบุเหตุผลที่ส่งกลับให้แก้ไข";
    }
    if (mb_strlen($comment) > KPI_APPROVAL_COMMENT_MAX) {
        return "หมายเหตุต้องไม่เกิน " . KPI_APPROVAL_COMMENT_MAX . " ตัวอักษร";
    }

    [, $error] = kpiApprovalPeriod($pdo, $periodId);
    if ($error !== null) {
        return $error;
    }

    $toStatus = $decision === "approve" ? "Approved" : "Rejected";

    try {
        $pdo->beginTransaction();
        $rows = kpiApprovalLockRows($pdo, $employeeId, $periodId);

        if (!$rows) {
            $pdo->rollBack();
            return "ไม่พบผลงานของพนักงานในเดือนนี้";
        }

        $statuses = array_column($rows, "status");
        if (array_diff($statuses, ["Submitted"])) {
            $pdo->rollBack();
            $state = kpiApprovalStateFromStatuses($statuses);
            return "ผลการประเมินของเดือนนี้ไม่ได้อยู่ในสถานะรออนุมัติ (สถานะ " . (KPI_STATUS_LABELS[$state] ?? $state) . ")";
        }

        /*
        | อนุมัติเมื่อมีคำเตือนคะแนน (ไม่ตรงเกณฑ์ / เกณฑ์รอยืนยัน / ตรวจไม่ได้) ต้องติ๊กรับทราบ
        | และบันทึกจำนวนคำเตือนไว้ในประวัติ · ระบบไม่แก้คะแนนให้
        */
        if ($decision === "approve") {
            $check = kpiApprovalScoreCheck($pdo, $employeeId, $periodId);
            if (!$check["verified"]) {
                if (!$acknowledgedWarnings) {
                    $pdo->rollBack();
                    return "มีคะแนนที่ยังยืนยันความถูกต้องไม่ได้ (" . trim(kpiApprovalCheckSummary($check), "[]") . ") กรุณาตรวจรายการคำเตือนและติ๊กรับทราบก่อนอนุมัติ หรือส่งกลับให้แก้ไข";
                }
                $summary = kpiApprovalCheckSummary($check);
                $comment = trim($summary . ($comment !== "" ? " " . $comment : ""));
                if (mb_strlen($comment) > KPI_APPROVAL_COMMENT_MAX) {
                    $comment = mb_substr($comment, 0, KPI_APPROVAL_COMMENT_MAX);
                }
            }
        }

        $ids = array_map(fn($row) => (int) $row["performance_id"], $rows);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $update = $pdo->prepare("UPDATE kpi_performances SET status = ? WHERE performance_id IN ({$in}) AND status = 'Submitted'");
        $update->execute(array_merge([$toStatus], $ids));

        if ($update->rowCount() !== count($ids)) {
            $pdo->rollBack();
            return "ข้อมูลผลงานเปลี่ยนไประหว่างดำเนินการ กรุณาลองใหม่อีกครั้ง";
        }

        kpiApprovalLog($pdo, $employeeId, $periodId, $decision, "Submitted", $toStatus, $ids, $comment, $actorUserId, $actorRoleId);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("kpiApprovalReview failed (" . get_class($exception) . "): " . $exception->getMessage());
        return "บันทึกผลการอนุมัติไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/*
| ตรวจคะแนนที่บันทึกเทียบกับเกณฑ์ที่ใช้ประเมิน (อ่านอย่างเดียว · ไม่แก้คะแนน ไม่คำนวณย้อนหลัง)
|
| Performance: ตัดเกรดใหม่ด้วย kpiCalculateScore() จาก Actual + Target ที่บันทึกกับผลงานแถวนั้น
|              (ไม่มี → Target ของ Assignment) แล้วเทียบกับคะแนนที่บันทึก
| Competency : ตรวจว่าเป็นจำนวนเต็ม 1–5 (คะแนนมาจากแบบฟอร์มโดยตรง ไม่มีสูตรให้ตรวจ)
| KPI ใน KPI_CRITERIA_PENDING_CONFIRMATION → "pending" เสมอ แม้คะแนนตรงกับเกณฑ์ปัจจุบัน
|
| คืน ["rows" => [...], "counts" => [ok, mismatch, pending, unverifiable], "verified" => bool]
*/
function kpiApprovalScoreCheck(PDO $pdo, int $employeeId, int $periodId): array
{
    $stmt = $pdo->prepare("
        SELECT p.performance_id, p.target, p.actual, p.score, p.status,
               a.target_value AS assignment_target,
               k.kpi_id, k.kpi_name, k.kpi_type
        FROM kpi_performances p
        INNER JOIN kpi_assignments a ON a.assignment_id = p.assignment_id
        INNER JOIN kpi_indicators k ON k.kpi_id = a.kpi_id
        WHERE p.employee_id = :employee_id AND p.period_id = :period_id
        ORDER BY FIELD(k.kpi_type, 'Performance', 'Competency'), k.kpi_id
    ");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $criteria = $rows ? loadKpiScoreCriteria($pdo, $rows) : [];
    $counts = ["ok" => 0, "mismatch" => 0, "pending" => 0, "unverifiable" => 0];
    $result = [];

    foreach ($rows as $row) {
        $kpiId = (int) $row["kpi_id"];
        $stored = $row["score"] === null ? null : (float) $row["score"];
        $expected = null;
        $result_ = "ok";
        $reasons = [];

        if (strcasecmp((string) $row["kpi_type"], "Competency") === 0) {
            if ($stored === null) {
                $result_ = "unverifiable";
                $reasons[] = "ไม่มีคะแนนที่บันทึก";
            } elseif ($stored < KPI_MIN_LEVEL || $stored > KPI_MAX_LEVEL || abs($stored - round($stored)) > KPI_SCORE_EPSILON) {
                $result_ = "mismatch";
                $expected = null;
                $reasons[] = "คะแนน Competency ต้องเป็นจำนวนเต็ม 1–5 แต่บันทึกเป็น " . rtrim(rtrim(number_format($stored, 2, ".", ""), "0"), ".")
                    . " (มาจากหน้าบันทึกผลแบบเดิม · หน้าจอแสดงเป็นระดับ " . kpiScoreLevel($stored) . " แต่คะแนนรวมใช้ค่าทศนิยม)";
            }
        } else {
            $target = $row["target"] !== null ? $row["target"] : $row["assignment_target"];

            if ($row["actual"] === null) {
                $result_ = "unverifiable";
                $reasons[] = "ไม่มี Actual ให้ตัดเกรด";
            } else {
                $calc = kpiCalculateScore($row, $criteria[$kpiId] ?? [], $row["actual"], $target);
                $expected = $calc["grade"];

                if ($calc["error"] === null && $expected === null && !empty($calc["pending"])) {
                    /* KPI 14: ผลอยู่ในช่องว่างของสูตรเกณฑ์บริษัท → ไม่ให้คะแนนเอง และไม่นับว่าผ่าน */
                    $result_ = "pending";
                    $reasons[] = (string) $calc["pending"];
                    if ($stored !== null) {
                        $reasons[] = "มีคะแนนที่บันทึกไว้ " . rtrim(rtrim(number_format($stored, 2, ".", ""), "0"), ".") . " ซึ่งเกณฑ์ปัจจุบันไม่รองรับ";
                    }
                } elseif ($calc["error"] !== null || $expected === null) {
                    $result_ = "unverifiable";
                    $reasons[] = $calc["error"] ?? "ตัดเกรดตามเกณฑ์ปัจจุบันไม่ได้";
                } elseif ($stored === null) {
                    $result_ = "mismatch";
                    $reasons[] = "ไม่มีคะแนนที่บันทึก แต่ตามเกณฑ์ปัจจุบันได้ระดับ {$expected}";
                } elseif (abs($stored - $expected) > KPI_SCORE_EPSILON) {
                    $result_ = "mismatch";
                    $reasons[] = "คะแนนที่บันทึก " . rtrim(rtrim(number_format($stored, 2, ".", ""), "0"), ".") . " แต่ตามเกณฑ์ปัจจุบันได้ระดับ {$expected}";
                    if (!isNonRenewalKpi($row) && $row["target"] !== null && $row["assignment_target"] !== null
                        && abs((float) $row["target"] - (float) $row["assignment_target"]) > KPI_SCORE_EPSILON) {
                        $reasons[] = "Target ที่บันทึกกับผลงาน (" . (float) $row["target"] . ") ต่างจาก Target ปัจจุบันของ Assignment (" . (float) $row["assignment_target"] . ") จึงน่าจะคำนวณไว้ก่อนแก้เป้าหมาย / เกณฑ์";
                    } else {
                        $reasons[] = "คะแนนนี้น่าจะคำนวณด้วยเกณฑ์เดิมก่อนแก้ไข หรือบันทึกจากหน้าบันทึกผลแบบเดิม";
                    }
                }
            }
        }

        $pendingReason = KPI_CRITERIA_PENDING_CONFIRMATION[$kpiId] ?? null;
        if ($pendingReason !== null) {
            $reasons[] = "เกณฑ์ของ KPI นี้รอเจ้าของระบบยืนยัน: {$pendingReason} · ยืนยันความถูกต้องของคะแนนไม่ได้";
            if ($result_ === "ok") {
                $result_ = "pending";
            }
        }

        $counts[$result_]++;
        $result[] = [
            "performance_id" => (int) $row["performance_id"],
            "kpi_id" => $kpiId,
            "kpi_name" => (string) $row["kpi_name"],
            "kpi_type" => (string) $row["kpi_type"],
            "target" => $row["target"],
            "actual" => $row["actual"],
            "stored" => $stored,
            "expected" => $expected,
            "result" => $result_,
            "criteria_pending" => $pendingReason !== null,
            "reasons" => $reasons,
        ];
    }

    /* คำขอแก้ไขข้อมูลที่ยังรอพิจารณาในเดือนนี้ (Migration 20261026) → ยังไม่ถือว่าตรวจครบ */
    $pendingCorrections = 0;
    try {
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_correction_requests'")->fetchColumn() === 1;
        if ($exists) {
            $pc = $pdo->prepare("SELECT COUNT(*) FROM kpi_correction_requests WHERE employee_id = :employee_id AND period_id = :period_id AND status = 'Pending'");
            $pc->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);
            $pendingCorrections = (int) $pc->fetchColumn();
        }
    } catch (Throwable $exception) {
        $pendingCorrections = 0;
    }

    return [
        "rows" => $result,
        "counts" => $counts,
        "pending_corrections" => $pendingCorrections,
        "verified" => $rows !== [] && $counts["ok"] === count($rows) && $pendingCorrections === 0,
    ];
}

/* ข้อความสรุปคำเตือนสำหรับบันทึกในประวัติการอนุมัติ */
function kpiApprovalCheckSummary(array $check): string
{
    $parts = [];
    foreach (["mismatch", "pending", "unverifiable"] as $key) {
        if ($check["counts"][$key] > 0) {
            $parts[] = KPI_SCORE_CHECK_LABELS[$key] . " " . $check["counts"][$key];
        }
    }
    if (($check["pending_corrections"] ?? 0) > 0) {
        $parts[] = "คำขอแก้ไขรอพิจารณา " . $check["pending_corrections"];
    }

    return $parts ? "[อนุมัติโดยรับทราบคำเตือน: " . implode(" · ", $parts) . " รายการ]" : "";
}

/* ประวัติของพนักงาน 1 คน × 1 เดือน (ล่าสุดก่อน) */
function kpiApprovalHistory(PDO $pdo, int $employeeId, int $periodId): array
{
    if (!kpiApprovalEnabled($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT l.log_id, l.action, l.from_status, l.to_status, l.performance_count, l.comment, l.created_at,
               l.actor_role_id, u.username, e.first_name, e.last_name
        FROM kpi_approval_logs l
        INNER JOIN users u ON u.user_id = l.actor_user_id
        LEFT JOIN employees e ON e.employee_id = u.employee_id
        WHERE l.employee_id = :employee_id AND l.period_id = :period_id
        ORDER BY l.created_at DESC, l.log_id DESC
    ");
    $stmt->execute([":employee_id" => $employeeId, ":period_id" => $periodId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* รายการรอ Manager อนุมัติ (พนักงาน × เดือน ที่มีแถว Submitted) · ปีที่เลือก */
function kpiApprovalPending(PDO $pdo, int $year): array
{
    if (!kpiApprovalEnabled($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT p.employee_id, p.period_id, ep.period_year, ep.period_month,
               e.employee_code, e.first_name, e.last_name, d.department_name,
               COUNT(*) AS performance_count,
               (SELECT MAX(l.created_at) FROM kpi_approval_logs l
                 WHERE l.employee_id = p.employee_id AND l.period_id = p.period_id AND l.action = 'submit') AS submitted_at
        FROM kpi_performances p
        INNER JOIN evaluation_periods ep ON ep.period_id = p.period_id
        INNER JOIN employees e ON e.employee_id = p.employee_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        WHERE p.status = 'Submitted' AND ep.period_year = :year
        GROUP BY p.employee_id, p.period_id
        ORDER BY submitted_at IS NULL, submitted_at, ep.period_month, e.employee_code
    ");
    $stmt->execute([":year" => $year]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ชื่อผู้ดำเนินการสำหรับแสดงผล */
function kpiApprovalActorName(array $log): string
{
    $name = trim((string) ($log["first_name"] ?? "") . " " . (string) ($log["last_name"] ?? ""));
    $role = ["1" => "Admin", "2" => "Manager", "3" => "พนักงาน"][(string) $log["actor_role_id"]] ?? "";

    return ($name !== "" ? $name : (string) $log["username"]) . ($role !== "" ? " ({$role})" : "");
}
