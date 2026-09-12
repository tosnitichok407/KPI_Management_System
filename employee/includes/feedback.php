<?php

/*
|--------------------------------------------------------------------------
| Feedback จากหัวหน้า (ฝั่งพนักงาน)
|--------------------------------------------------------------------------
|
| หัวหน้าบันทึกผ่าน manager/feedback.php → ตาราง manager_feedback
| - manager_id = user_id ของหัวหน้า
| - 1 รายการ ต่อ หัวหน้า + พนักงาน + รอบประเมิน (เดือน)
|
| ใช้ในหน้า employee/feedback.php, หน้าแรก และหน้าผลการปฏิบัติงาน
|
*/

/* Feedback ของพนักงาน (ใหม่สุดก่อน) · filters: year, period_id, limit */
function employeeFeedbackList(PDO $pdo, int $employeeId, array $filters = []): array
{
    $where = ["f.employee_id = :employee_id"];
    $params = [":employee_id" => $employeeId];

    if (!empty($filters["year"])) {
        $where[] = "ep.period_year = :year";
        $params[":year"] = (int) $filters["year"];
    }

    if (!empty($filters["period_id"])) {
        $where[] = "f.period_id = :period_id";
        $params[":period_id"] = (int) $filters["period_id"];
    }

    $limit = !empty($filters["limit"]) ? "LIMIT " . (int) $filters["limit"] : "";

    $stmt = $pdo->prepare("
        SELECT
            f.feedback_id,
            f.evaluation_score,
            f.feedback,
            f.created_at,
            f.updated_at,
            ep.period_year,
            ep.period_month,
            ep.quarter,
            TRIM(CONCAT(COALESCE(me.first_name, ''), ' ', COALESCE(me.last_name, ''))) AS manager_name,
            mp.position_name AS manager_position
        FROM manager_feedback f
        INNER JOIN evaluation_periods ep ON ep.period_id = f.period_id
        LEFT JOIN users mu ON mu.user_id = f.manager_id
        LEFT JOIN employees me ON me.employee_id = mu.employee_id
        LEFT JOIN positions mp ON mp.position_id = me.position_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY ep.period_year DESC, ep.period_month DESC, f.updated_at DESC
        {$limit}
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/* ปีที่มี Feedback (สำหรับตัวเลือกปี) */
function employeeFeedbackYears(PDO $pdo, int $employeeId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT ep.period_year
        FROM manager_feedback f
        INNER JOIN evaluation_periods ep ON ep.period_id = f.period_id
        WHERE f.employee_id = :employee_id
        ORDER BY ep.period_year DESC
    ");
    $stmt->execute([":employee_id" => $employeeId]);

    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}


/*
| การ์ด Feedback
| options: root, title, subtitle, link ["href", "label"], header_extra (HTML), empty
*/
function renderFeedbackCard(array $items, array $options = []): void
{
    static $cssLoaded = false;

    $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");

    $root = $options["root"] ?? "../";
    $link = $options["link"] ?? null;

    $months = [
        1 => "มกราคม", 2 => "กุมภาพันธ์", 3 => "มีนาคม", 4 => "เมษายน",
        5 => "พฤษภาคม", 6 => "มิถุนายน", 7 => "กรกฎาคม", 8 => "สิงหาคม",
        9 => "กันยายน", 10 => "ตุลาคม", 11 => "พฤศจิกายน", 12 => "ธันวาคม"
    ];

    if (!$cssLoaded) {
        echo '<link rel="stylesheet" href="' . $e($root . "assets/css/employee-feedback.css?v=1") . '">';
        $cssLoaded = true;
    }

    ?>

    <section class="feedback-card">

        <div class="feedback-card__header">

            <div>
                <h2><?= $e($options["title"] ?? "💬 Feedback จากหัวหน้า") ?></h2>
                <?php if (!empty($options["subtitle"])): ?>
                    <p><?= $e($options["subtitle"]) ?></p>
                <?php endif; ?>
            </div>

            <?= $options["header_extra"] ?? "" ?>

            <?php if ($link): ?>
                <a href="<?= $e($link["href"]) ?>" class="feedback-card__link"><?= $e($link["label"]) ?></a>
            <?php endif; ?>

        </div>

        <div class="feedback-card__body">

            <?php if (empty($items)): ?>

                <div class="feedback-empty">
                    <?= $e($options["empty"] ?? "ยังไม่มี Feedback จากหัวหน้า") ?>
                </div>

            <?php else: ?>

                <div class="feedback-list">

                    <?php foreach ($items as $item): ?>

                        <?php
                        $score = (float) $item["evaluation_score"];
                        $tone = $score >= 80 ? "high" : ($score >= 60 ? "mid" : "low");
                        $manager = trim((string) ($item["manager_name"] ?? "")) ?: "หัวหน้างาน";
                        $position = trim((string) ($item["manager_position"] ?? ""));
                        ?>

                        <article class="feedback-item feedback-item--<?= $tone ?>">

                            <div class="feedback-item__head">

                                <div class="feedback-item__period">
                                    <strong>ประจำเดือน<?= $e($months[(int) $item["period_month"]] ?? "") ?> <?= (int) $item["period_year"] ?></strong>
                                    <span><?= $e($item["quarter"]) ?></span>
                                </div>

                                <span class="feedback-score feedback-score--<?= $tone ?>">
                                    <?= number_format($score, 1) ?> / 100
                                </span>

                            </div>

                            <p class="feedback-item__message"><?= nl2br($e($item["feedback"])) ?></p>

                            <div class="feedback-item__meta">
                                จาก <?= $e($manager) ?><?= $position !== "" ? " · " . $e($position) : "" ?>
                                · อัปเดต <?= $e(date("d/m/Y H:i", strtotime($item["updated_at"]))) ?>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

    <?php
}
