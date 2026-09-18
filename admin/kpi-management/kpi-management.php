<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */

/*
|--------------------------------------------------------------------------
| Admin Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
}


/*
|--------------------------------------------------------------------------
| Get KPI
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        k.kpi_id,
        k.kpi_name,
        k.description,
        k.weight,
        k.unit,
        k.max_score,

        c.category_name

    FROM kpi_indicators k

    LEFT JOIN kpi_categories c
        ON k.category_id = c.category_id

    ORDER BY
        c.category_id ASC,
        k.kpi_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$kpis = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ข้อความหลังลบ KPI (ตั้งไว้ใน session โดย kpi-delete.php) */

$kpiSuccess = $_SESSION["kpi_success"] ?? "";
$kpiError = $_SESSION["kpi_error"] ?? "";

unset($_SESSION["kpi_success"], $_SESSION["kpi_error"]);

?>



    <div class="page-container">
        <div class="page-header">
            <div class="page-title">
                <h1>
                    KPI Management
                </h1>

                <p>
                    จัดการตัวชี้วัดผลการปฏิบัติงาน
                </p>
            </div>

            <div class="header-actions">

                <a
                    href="kpi-management/kpi-add.php"
                    class="btn btn-primary">
                    + เพิ่ม KPI
                </a>
            </div>
        </div>

                <?php if ($kpiSuccess !== ""): ?>
            <div class="alert alert-success"><?= htmlspecialchars($kpiSuccess, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <?php if ($kpiError !== ""): ?>
            <div class="alert alert-error"><?= htmlspecialchars($kpiError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>
                                ID
                            </th>

                            <th>
                                หมวดหมู่
                            </th>

                            <th>
                                KPI
                            </th>

                            <th>
                                รายละเอียด
                            </th>

                            <th>
                                น้ำหนัก
                            </th>

                            <th>
                                หน่วย
                            </th>

                            <th>
                                คะแนนสูงสุด
                            </th>

                            <th>
                                จัดการ
                            </th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if (count($kpis) > 0): ?>

                            <?php foreach ($kpis as $kpi): ?>

                                <tr>

                                    <td>
                                        <?= (int) $kpi["kpi_id"] ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $kpi["category_name"] ?? "-"
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                $kpi["kpi_name"]
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $kpi["description"] ?? "-"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $kpi["weight"]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $kpi["unit"] ?? "-"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $kpi["max_score"] ?? "-"
                                        ) ?>
                                    </td>

                                    <td>

                                        <div class="action-buttons">
                                            <a
                                                href="kpi-management/kpi-edit.php?id=<?= (int) $kpi["kpi_id"] ?>"
                                                class="btn-small edit">
                                                แก้ไข
                                            </a>

                                            <form
                                                method="POST"
                                                action="kpi-management/kpi-delete.php"
                                                class="inline-form"
                                                onsubmit="return confirm('ต้องการลบ KPI นี้ใช่หรือไม่?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= (int) $kpi["kpi_id"] ?>">
                                                <button type="submit" class="btn-small danger">
                                                    ลบ
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td
                                    colspan="8"
                                    class="empty">
                                    ยังไม่มีข้อมูล KPI
                                </td>

                            </tr>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
