<?php

session_start();

require_once "../../config/database.php";

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
    header("Location: ../../dashboard.php");
    exit;
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

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>KPI Management</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="../../assets/css/kpi.css">



</head>

<body>

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
                    href="../index.php"
                    class="btn btn-secondary">
                    Dashboard
                </a>

                <a
                    href="kpi-add.php"
                    class="btn btn-primary">
                    + เพิ่ม KPI
                </a>
            </div>
        </div>

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
                                        <?= htmlspecialchars($kpi["kpi_id"]) ?>
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
                                                href="kpi-edit.php?id=<?= $kpi["kpi_id"] ?>"
                                                class="btn-small edit">
                                                แก้ไข
                                            </a>

                                            <a
                                                href="kpi-delete.php?id=<?= $kpi["kpi_id"] ?>"
                                                class="btn-small danger"
                                                onclick="return confirm('ต้องการลบ KPI นี้ใช่หรือไม่?');">
                                                ลบ
                                            </a>
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
</body>

</html>