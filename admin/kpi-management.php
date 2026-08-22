<?php

session_start();

require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| Admin Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    header("Location: ../dashboard.php");
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
        href="../assets/css/kpi.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Kanit", sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        .page-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px;
        }

        /* ========= HEADER ========= */

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 600;
        }

        .page-header p {
            margin: 5px 0 0;
            color: #6b7280;
        }

        .page-header .header-actions {
            display: flex;
            gap: 10px;
        }


        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 10px 18px;

            border-radius: 8px;

            text-decoration: none;

            border: none;

            font-family: inherit;
            font-size: 15px;

            cursor: pointer;
        }

        .btn-primary {
            background: #1f6b9c;
            color: white;
        }

        .btn-primary:hover {
            background: #18577f;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-edit {
            background: #f59e0b;
            color: white;
        }

        .btn-delete {
            background: #dc2626;
            color: white;
        }

        .btn-criteria {
            background: #059669;
            color: white;
        }

        .table-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            background: #24449b;
            color: white;
            padding: 13px;
            text-align: left;
        }

        td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        tr:hover {
            background: #f9fafb;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .actions .btn {
            padding: 7px 11px;
            font-size: 13px;
        }

        .empty {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
    </style>

</head>

<body>

    <div class="page-container">

        <div class="page-header">

            <div>

                <h1>
                    KPI Management
                </h1>

                <p>
                    จัดการตัวชี้วัดผลการปฏิบัติงาน
                </p>

            </div>

            <div class="header-actions">

                <a
                    href="index.php"
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

    </div>

    <div class="table-card">

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
                        Weight
                    </th>

                    <th>
                        Unit
                    </th>

                    <th>
                        Max Score
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

                                <div class="actions">

                                    <a
                                        href="kpi-criteria.php?kpi_id=<?= $kpi["kpi_id"] ?>"
                                        class="btn btn-criteria">
                                        เกณฑ์ 5–1
                                    </a>

                                    <a
                                        href="kpi-edit.php?kpi_id=<?= $kpi["kpi_id"] ?>"
                                        class="btn btn-edit">
                                        แก้ไข
                                    </a>

                                    <a
                                        href="kpi-delete.php?kpi_id=<?= $kpi["kpi_id"] ?>"
                                        class="btn btn-delete"
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

</body>

</html>
