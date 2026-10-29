<?php

/*
|--------------------------------------------------------------------------
| Manager: แถบค้นหา / ตัวกรองของตาราง (กรองบนหน้าเว็บทันที · assets/js/manager-table.js)
|--------------------------------------------------------------------------
|
| managerTableTools("tableId", "ค้นหา...", ["level" => [...ตัวเลือก]])
|   แถวในตารางต้องมี data-search="ข้อความที่ค้นหาได้" และ data-<key>="ค่า" ตามตัวกรองที่ส่งมา
| managerLevelOptions(): ตัวเลือกระดับตาม managerScoreLevel()
| managerSearchText(...): รวมข้อความสำหรับ data-search (ตัวพิมพ์เล็ก)
|
*/

function managerLevelOptions(): array
{
    return [
        "" => "ทุกระดับ",
        "good" => "โดดเด่น",
        "steady" => "อยู่ในเกณฑ์",
        "needs" => "ควรติดตาม",
        "pending" => "รอประเมิน"
    ];
}

function managerSearchText(?string ...$parts): string
{
    return htmlspecialchars(mb_strtolower(trim(implode(" ", array_filter($parts, fn($part) => $part !== null && $part !== ""))), "UTF-8"), ENT_QUOTES, "UTF-8");
}

function managerTableTools(string $tableId, string $placeholder, array $filters = []): void
{
    $id = htmlspecialchars($tableId, ENT_QUOTES, "UTF-8");
    ?>
    <div class="table-tools" data-table-filter="<?= $id ?>">
        <label class="table-search">
            <?= icon("search") ?>
            <span class="visually-hidden"><?= htmlspecialchars($placeholder, ENT_QUOTES, "UTF-8") ?></span>
            <input type="search" placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, "UTF-8") ?>" autocomplete="off">
        </label>
        <?php foreach ($filters as $key => [$label, $options]): ?>
            <label class="table-select">
                <span class="visually-hidden"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></span>
                <select data-filter="<?= htmlspecialchars($key, ENT_QUOTES, "UTF-8") ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?>">
                    <?php foreach ($options as $value => $text): ?>
                        <option value="<?= htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($text, ENT_QUOTES, "UTF-8") ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endforeach; ?>
        <span class="table-count" data-table-count aria-live="polite"></span>
    </div>
    <?php
}

/* คะแนนเฉลี่ย + ระดับในเซลล์เดียว · Performance / Competency เป็นบรรทัดรอง */
function managerScoreCell(array $row): void
{
    [$levelText, $levelClass] = managerScoreLevel($row["avg_score"], (int) $row["evaluated"], (int) $row["total_kpi"]);

    if ($row["avg_score"] === null) {
        echo '<span class="level level-' . $levelClass . '">' . $levelText . '</span>';
        return;
    }

    echo '<div class="score-line"><strong>' . number_format((float) $row["avg_score"], 2) . '</strong><small>/ 5</small>'
        . '<span class="level level-' . $levelClass . '">' . $levelText . '</span></div>'
        . '<div class="cell-sub">Performance ' . managerFormat($row["avg_performance"]) . ' · Competency ' . managerFormat($row["avg_competency"]) . '</div>'
        /* ร้อยละถ่วงน้ำหนัก Σ(คะแนน / คะแนนเต็ม × น้ำหนัก) / Σน้ำหนัก × 100 · สูตรเดียวกับรายงาน Admin และแบบฟอร์ม KPI AM (M30 / J30) */
        . (isset($row["weighted_percent"]) && $row["weighted_percent"] !== null
            ? '<div class="cell-sub">ถ่วงน้ำหนัก ' . managerFormat((float) $row["weighted_percent"], 1, "%") . '</div>'
            : '');
}

/* จำนวน KPI ที่ประเมินแล้ว + แถบ */
function managerProgressCell(array $row): void
{
    $ratio = $row["total_kpi"] > 0 ? $row["evaluated"] / $row["total_kpi"] : 0;

    echo '<div class="stat-main">' . (int) $row["evaluated"] . ' / ' . (int) $row["total_kpi"] . ' <small>KPI</small></div>'
        . '<div class="progress ' . ($ratio >= 1 && $row["total_kpi"] > 0 ? "done" : "") . '"><span style="width: ' . round($ratio * 100) . '%"></span></div>';
}

/* แสดงเฉพาะเดือนที่มีรอบประเมิน และ (ถึงเดือนนั้นแล้ว หรือมีผลงาน / Feedback) · เดือนในอนาคตที่ยังว่างไม่แสดง */
function managerMonthVisible(array $row, int $year, int $month): bool
{
    if (empty($row["period"])) {
        return false;
    }

    $started = sprintf("%04d-%02d-01", $year, $month) <= date("Y-m-d");

    return $started || (int) $row["evaluated"] > 0 || (int) ($row["feedback_count"] ?? 0) > 0 || !empty($row["feedback"]);
}
