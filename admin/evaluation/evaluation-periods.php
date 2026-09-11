<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/quarter-helper.php";
require_once __DIR__ . "/../../includes/monthly-period-helper.php";


/*
|--------------------------------------------------------------------------
| Admin Access Only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../../dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Evaluation Periods
|--------------------------------------------------------------------------
*/

// จำนวนข้อมูลที่อ้างอิงรอบประเมิน (> 0 = ลบไม่ได้)
$usageSql = implode(" + ", array_map(
    fn($table) => "(SELECT COUNT(*) FROM {$table} ref WHERE ref.period_id = p.period_id)",
    array_keys(evaluationPeriodReferenceTables())
));

$sql = "
    SELECT
        p.period_id,
        p.period_name,
        p.period_year,
        p.period_month,
        p.quarter,
        p.start_date,
        p.end_date,
        p.status,
        ({$usageSql}) AS usage_count

    FROM evaluation_periods p

    ORDER BY p.period_year DESC, p.period_month DESC, p.period_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthNames = monthlyPeriodMonths();

/*
| Quarter cards: แต่ละ Quarter แสดงเดือนที่สร้างรอบประเมินแล้ว
*/

$quarterPeriods = [];
foreach ($periods as $period) {
    $year = (int) $period["period_year"];

    foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter) {
        $key = $year . "-" . $quarter;

        if (!isset($quarterPeriods[$key])) {
            [$quarterStart, $quarterEnd] = getQuarterMonths($quarter);
            $quarterPeriods[$key] = [
                "year" => $year,
                "quarter" => $quarter,
                "start_date" => sprintf("%04d-%02d-01", $year, $quarterStart),
                "end_date" => date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $year, $quarterEnd))),
                "months" => []
            ];
        }
    }

    $month = (int) $period["period_month"];
    $quarterPeriods[$year . "-" . $period["quarter"]]["months"][$month] = $monthNames[$month];
}

foreach ($quarterPeriods as &$quarterPeriod) {
    ksort($quarterPeriod["months"]);
}
unset($quarterPeriod);


/*
|--------------------------------------------------------------------------
| Messages (หลังลบรอบประเมิน)
|--------------------------------------------------------------------------
*/

$successMessage = [
    "deleted" => "ลบรอบประเมินเรียบร้อยแล้ว"
][$_GET["success"] ?? ""] ?? "";

$errorMessage = [
    "not_found" => "ไม่พบรอบประเมินที่ต้องการลบ",
    "in_use" => "ไม่สามารถลบรอบประเมินได้ เนื่องจากมีข้อมูลที่ใช้รอบนี้อยู่",
    "delete_failed" => "ไม่สามารถลบรอบประเมินได้"
][$_GET["error"] ?? ""] ?? "";

?>



<div class="page-container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="page-header">

        <div>

            <h1>
                Evaluation Period Management
            </h1>

            <p>
                จัดการรอบการประเมินผลการปฏิบัติงาน
            </p>

        </div>


        <div class="header-actions">

            <a
                href="evaluation/evaluation-periods-add.php"
                class="btn btn-primary"
            >
                + เพิ่มรอบการประเมิน
            </a>

        </div>

    </header>

    <?php if ($successMessage !== ""): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ""): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <section class="quarter-period-grid">
        <?php foreach ($quarterPeriods as $quarterPeriod): ?>
            <article class="quarter-period-card">
                <h2><?= $quarterPeriod["quarter"] ?>/<?= $quarterPeriod["year"] ?></h2>
                <p><?= $quarterPeriod["start_date"] ?> - <?= $quarterPeriod["end_date"] ?></p>
                <strong>
                    <?= empty($quarterPeriod["months"])
                        ? "ยังไม่มีรอบประเมิน"
                        : htmlspecialchars(implode(", ", $quarterPeriod["months"]), ENT_QUOTES, "UTF-8") ?>
                </strong>
            </article>
        <?php endforeach; ?>
    </section>


    <!-- =========================================================
         TABLE
    ========================================================== -->

    <section class="table-card">


        <div class="table-header">

            <h2>
                Evaluation Periods
            </h2>

            <span>
                <?= count($periods) ?> periods
            </span>

        </div>


        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>
                            ID
                        </th>

                        <th>
                            รอบการประเมิน
                        </th>

                        <th>ปี</th>

                        <th>เดือน</th>

                        <th>Quarter</th>

                        <th>
                            วันที่เริ่มต้น
                        </th>

                        <th>
                            วันที่สิ้นสุด
                        </th>

                        <th>
                            สถานะ
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (empty($periods)): ?>

                    <tr>

                        <td
                            colspan="9"
                            class="empty-state"
                        >
                            ยังไม่มีรอบการประเมิน
                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($periods as $period): ?>

                        <tr>


                            <!-- ID -->

                            <td>

                                <?= (int) $period["period_id"] ?>

                            </td>


                            <!-- Period Name -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $period["period_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </strong>

                            </td>


                            <!-- Year / Month / Quarter -->

                            <td><?= (int) $period["period_year"] ?></td>

                            <td><?= htmlspecialchars($monthNames[(int) $period["period_month"]] ?? "-", ENT_QUOTES, "UTF-8") ?></td>

                            <td><?= htmlspecialchars($period["quarter"], ENT_QUOTES, "UTF-8") ?></td>


                            <!-- Start Date -->

                            <td>

                                <?= date("d/m/Y", strtotime($period["start_date"])) ?>

                            </td>


                            <!-- End Date -->

                            <td>

                                <?= date("d/m/Y", strtotime($period["end_date"])) ?>

                            </td>


                            <!-- Status -->

                            <td>

                                <?php if (
                                    $period["status"] === "Open"
                                ): ?>

                                    <span class="status-open">
                                        Open
                                    </span>

                                <?php else: ?>

                                    <span class="status-closed">
                                        Closed
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- Action -->

                            <td>

                                <div class="action-buttons">

                                    <a
                                        href="evaluation/evaluation-periods-edit.php?id=<?= (int) $period["period_id"] ?>"
                                        class="btn-small edit"
                                    >
                                        Edit
                                    </a>

                                    <?php $inUse = (int) $period["usage_count"] > 0; ?>

                                    <form
                                        method="POST"
                                        action="evaluation/evaluation-periods-delete.php"
                                        class="inline-form"
                                        onsubmit="return confirm(<?= htmlspecialchars(json_encode(
                                            "ต้องการลบรอบประเมิน " . $period["period_name"] . " " . $period["period_year"] . " หรือไม่?",
                                            JSON_UNESCAPED_UNICODE
                                        ), ENT_QUOTES, "UTF-8") ?>)"
                                    >
                                        <input type="hidden" name="id" value="<?= (int) $period["period_id"] ?>">

                                        <button
                                            type="submit"
                                            class="btn-small danger"
                                            <?= $inUse ? "disabled" : "" ?>
                                            title="<?= $inUse
                                                ? "มีข้อมูลที่ใช้รอบนี้อยู่ " . (int) $period["usage_count"] . " รายการ จึงลบไม่ได้"
                                                : "ลบรอบประเมิน" ?>"
                                        >
                                            Delete
                                        </button>
                                    </form>

                                </div>

                            </td>


                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>


