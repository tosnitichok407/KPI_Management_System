<?php

/*
|--------------------------------------------------------------------------
| Filter Bar (ใช้ร่วมกันทุกหน้า manager)
|--------------------------------------------------------------------------
|
| ตัวแปรที่ต้องกำหนดก่อน include:
| $page, $filterYear, $filterMonth, $availableYears, $monthNames, $yearData
| $showMonth (true/false), $showDepartment (true/false), $filterDepartment
| $extraHidden (array name => value) เช่น employee_id
|
*/

$showMonth = $showMonth ?? true;
$showDepartment = $showDepartment ?? true;
$extraHidden = $extraHidden ?? [];

$hasFilter =
    $filterYear !== (int) date("Y") ||
    ($showMonth && $filterMonth > 0) ||
    ($showDepartment && $filterDepartment > 0);

?>

<form method="GET" action="index.php" class="manager-filter">

    <!-- ต้องส่ง page กลับไปด้วย ไม่งั้นจะเด้งไปหน้าแรก -->
    <input type="hidden" name="page" value="<?= htmlspecialchars($page, ENT_QUOTES, "UTF-8") ?>">

    <?php foreach ($extraHidden as $name => $value): ?>
        <input type="hidden" name="<?= htmlspecialchars($name, ENT_QUOTES, "UTF-8") ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8") ?>">
    <?php endforeach; ?>


    <div class="form-group">

        <label for="filter_year">ปี</label>

        <select name="year" id="filter_year">
            <?php foreach ($availableYears as $year): ?>
                <option value="<?= $year ?>" <?= $year === $filterYear ? "selected" : "" ?>><?= $year ?></option>
            <?php endforeach; ?>
        </select>

    </div>


    <?php if ($showMonth): ?>

        <div class="form-group">

            <label for="filter_month">เดือน</label>

            <select name="month" id="filter_month">

                <option value="0">ทั้งปี</option>

                <?php foreach ($monthNames as $number => $name): ?>
                    <option value="<?= $number ?>" <?= $number === $filterMonth ? "selected" : "" ?>>
                        <?= $name ?> (<?= getQuarterByMonth($number) ?>)<?= isset($yearData["periods"][$number]) ? "" : " · ยังไม่มีรอบ" ?>
                    </option>
                <?php endforeach; ?>

            </select>

        </div>

    <?php endif; ?>


    <?php if ($showDepartment): ?>

        <div class="form-group">

            <label for="filter_department">แผนก</label>

            <select name="department_id" id="filter_department">

                <option value="0">ทุกแผนก</option>

                <?php foreach ($yearData["departments"] as $departmentId => $departmentName): ?>
                    <option value="<?= $departmentId ?>" <?= $departmentId === $filterDepartment ? "selected" : "" ?>>
                        <?= htmlspecialchars($departmentName, ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endforeach; ?>

            </select>

        </div>

    <?php endif; ?>


    <div class="filter-actions">

        <button type="submit" class="btn btn-primary">
            แสดงผล
        </button>

        <?php if ($hasFilter): ?>

            <a href="index.php?page=<?= htmlspecialchars($page, ENT_QUOTES, "UTF-8") ?>" class="btn btn-secondary">
                ล้างตัวกรอง
            </a>

        <?php endif; ?>

    </div>

</form>
