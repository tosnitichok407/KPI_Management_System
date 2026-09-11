<?php

/*
|--------------------------------------------------------------------------
| KPI Category Form (ใช้ร่วมกันหน้าเพิ่ม / แก้ไข)
|--------------------------------------------------------------------------
|
| ตัวแปรที่ต้องกำหนดก่อน include:
| $formAction, $category_name, $description, $submitLabel
| $usedKpis (เฉพาะหน้าแก้ไข: รายการ KPI ที่ใช้หมวดหมู่นี้)
|
*/

$usedKpis = $usedKpis ?? null;

?>

<?php if ($usedKpis !== null): ?>

    <!-- =====================================================
         KPI ที่ใช้หมวดหมู่นี้ (แสดงผลกระทบก่อนแก้ไข)
    ====================================================== -->

    <div class="category-usage">

        <p class="category-usage-title">

            หมวดหมู่นี้ถูกใช้โดย
            <strong><?= count($usedKpis) ?> KPI</strong>

            <?php if (!empty($usedKpis)): ?>
                · การแก้ชื่อจะมีผลกับ KPI เหล่านี้ทั้งหมด
            <?php endif; ?>

        </p>

        <?php if (empty($usedKpis)): ?>

            <p class="category-usage-empty">
                ยังไม่มี KPI ใช้หมวดหมู่นี้
            </p>

        <?php else: ?>

            <ul class="category-usage-list">

                <?php foreach ($usedKpis as $kpi): ?>

                    <li class="<?= strtolower($kpi["kpi_type"]) === "competency" ? "competency" : "" ?>">
                        <?= htmlspecialchars($kpi["kpi_name"], ENT_QUOTES, "UTF-8") ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>

    </div>

<?php endif; ?>


<form
    method="POST"
    action="<?= htmlspecialchars($formAction, ENT_QUOTES, "UTF-8") ?>"
    class="category-form"
>


    <!-- =================================================
         Category Name
    ================================================== -->

    <div class="form-group">

        <label for="category_name">

            <span>
                ชื่อหมวดหมู่ KPI
                <span class="required">*</span>
            </span>

            <span class="char-count" data-count-for="category_name">
                <?= mb_strlen($category_name) ?> / 100
            </span>

        </label>

        <input
            type="text"
            id="category_name"
            name="category_name"
            class="form-control"
            value="<?= htmlspecialchars($category_name, ENT_QUOTES, "UTF-8") ?>"
            maxlength="100"
            placeholder="เช่น KPI ฝ่ายขาย"
            required
            autofocus
        >

        <div class="form-help">
            ชื่อต้องไม่ซ้ำกับหมวดหมู่ที่มีอยู่แล้ว
        </div>

    </div>


    <!-- =================================================
         Description
    ================================================== -->

    <div class="form-group">

        <label for="description">

            <span>รายละเอียด</span>

            <span class="char-count" data-count-for="description">
                <?= mb_strlen($description) ?> / 255
            </span>

        </label>

        <textarea
            id="description"
            name="description"
            class="form-control"
            maxlength="255"
            placeholder="อธิบายว่าหมวดหมู่นี้ใช้กับ KPI แบบไหน (ไม่บังคับ)"
        ><?= htmlspecialchars($description, ENT_QUOTES, "UTF-8") ?></textarea>

    </div>


    <!-- =================================================
         Buttons
    ================================================== -->

    <div class="form-actions">

        <a
            href="../index.php?page=kpi-categories"
            class="btn btn-secondary"
        >
            ยกเลิก
        </a>

        <button
            type="submit"
            class="btn btn-primary"
        >
            <?= htmlspecialchars($submitLabel, ENT_QUOTES, "UTF-8") ?>
        </button>

    </div>

</form>


<script>
    // นับตัวอักษรแบบสด (เตือนสีส้มเมื่อใกล้เต็ม)
    document.querySelectorAll(".char-count[data-count-for]").forEach(function (counter) {
        const field = document.getElementById(counter.dataset.countFor);
        if (!field) {
            return;
        }
        const max = parseInt(field.getAttribute("maxlength"), 10);
        const update = function () {
            const length = Array.from(field.value).length;
            counter.textContent = length + " / " + max;
            counter.classList.toggle("is-near", length >= max * 0.9);
        };
        field.addEventListener("input", update);
        update();
    });
</script>
