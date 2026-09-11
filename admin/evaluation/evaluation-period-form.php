<?php

/*
|--------------------------------------------------------------------------
| Evaluation Period Form (ใช้ร่วมกันหน้าเพิ่ม / แก้ไข)
|--------------------------------------------------------------------------
|
| ตัวแปรที่ต้องกำหนดก่อน include:
| $formAction, $formYear, $formMonth, $formStatus,
| $existingMonths (ปี => [เดือนที่มีรอบแล้ว]), $lockPeriod, $submitLabel
|
*/

$monthNames = monthlyPeriodMonths();

$currentYear = (int) date("Y");

if ($formYear < 2000 || $formYear > 2100) {
    $formYear = $currentYear;
}

$yearOptions = range($currentYear - 2, $currentYear + 3);

if (!in_array($formYear, $yearOptions, true)) {
    $yearOptions[] = $formYear;
    sort($yearOptions);
}

$takenMonths = $existingMonths[$formYear] ?? [];

$previewPeriod =
    ($formMonth >= 1 && $formMonth <= 12 && !in_array($formMonth, $takenMonths, true))
    ? monthlyPeriodDetails($formYear, $formMonth)
    : null;

?>

<form
    method="POST"
    action="<?= htmlspecialchars($formAction, ENT_QUOTES, "UTF-8") ?>"
    class="period-form"
    id="periodForm"
>

    <?php if ($lockPeriod): ?>

        <!-- ปี/เดือนถูกล็อก: ส่งค่าเดิมผ่าน hidden -->
        <input type="hidden" name="period_year" value="<?= $formYear ?>">
        <input type="hidden" name="period_month" value="<?= $formMonth ?>">

        <div class="period-lock-note">
            รอบนี้มีผลงาน KPI บันทึกแล้ว จึงเปลี่ยนปี/เดือนไม่ได้ (แก้ไขได้เฉพาะสถานะ)
        </div>

    <?php endif; ?>


    <div class="period-form-layout">

        <div>

            <!-- =====================================================
                 1. YEAR
            ====================================================== -->

            <div class="period-form-step">

                <label for="period_year" class="period-form-label">
                    1. เลือกปี
                </label>

                <select
                    id="period_year"
                    <?= $lockPeriod ? "disabled" : 'name="period_year"' ?>
                >
                    <?php foreach ($yearOptions as $year): ?>
                        <option value="<?= $year ?>" <?= $year === $formYear ? "selected" : "" ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </div>


            <!-- =====================================================
                 2. MONTH (จัดกลุ่มตาม Quarter)
            ====================================================== -->

            <div class="period-form-step">

                <span class="period-form-label">
                    2. เลือกเดือน
                </span>

                <div class="month-picker">

                    <?php foreach (["Q1", "Q2", "Q3", "Q4"] as $quarter): ?>

                        <?php [$firstMonth, $lastMonth] = getQuarterMonths($quarter); ?>

                        <div class="month-picker-quarter">

                            <div class="month-picker-quarter-title">
                                <?= $quarter ?>
                            </div>

                            <?php for ($month = $firstMonth; $month <= $lastMonth; $month++): ?>

                                <?php $taken = in_array($month, $takenMonths, true); ?>

                                <label class="month-tile <?= $taken ? "is-taken" : "" ?>">

                                    <input
                                        type="radio"
                                        <?= $lockPeriod ? "" : 'name="period_month"' ?>
                                        value="<?= $month ?>"
                                        <?= ($month === $formMonth && !$taken) ? "checked" : "" ?>
                                        <?= ($lockPeriod || $taken) ? "disabled" : "" ?>
                                        required
                                    >

                                    <span>
                                        <?= htmlspecialchars($monthNames[$month], ENT_QUOTES, "UTF-8") ?>
                                        <small class="month-tile-note"><?= $taken ? "สร้างแล้ว" : "" ?></small>
                                    </span>

                                </label>

                            <?php endfor; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

                <small class="period-form-hint">
                    1 ปี + 1 เดือน สร้างรอบประเมินได้ครั้งเดียว เดือนที่สร้างแล้วจะเลือกไม่ได้
                </small>

            </div>


            <!-- =====================================================
                 3. STATUS
            ====================================================== -->

            <div class="period-form-step">

                <label for="status" class="period-form-label">
                    3. สถานะ
                </label>

                <select id="status" name="status">
                    <option value="Open" <?= $formStatus === "Open" ? "selected" : "" ?>>Open</option>
                    <option value="Closed" <?= $formStatus === "Closed" ? "selected" : "" ?>>Closed</option>
                </select>

            </div>

        </div>


        <!-- =========================================================
             PREVIEW (คำนวณอัตโนมัติ)
        ========================================================== -->

        <aside class="period-preview">

            <h3>ข้อมูลรอบประเมิน</h3>

            <dl>

                <div>
                    <dt>ชื่อรอบ</dt>
                    <dd id="previewName"><?= $previewPeriod ? htmlspecialchars($previewPeriod["period_name"], ENT_QUOTES, "UTF-8") : "-" ?></dd>
                </div>

                <div>
                    <dt>Quarter</dt>
                    <dd id="previewQuarter"><?= $previewPeriod ? $previewPeriod["quarter"] : "-" ?></dd>
                </div>

                <div>
                    <dt>วันที่เริ่มต้น</dt>
                    <dd id="previewStart"><?= $previewPeriod ? date("d/m/Y", strtotime($previewPeriod["start_date"])) : "-" ?></dd>
                </div>

                <div>
                    <dt>วันที่สิ้นสุด</dt>
                    <dd id="previewEnd"><?= $previewPeriod ? date("d/m/Y", strtotime($previewPeriod["end_date"])) : "-" ?></dd>
                </div>

            </dl>

            <small>ระบบคำนวณจากปีและเดือนที่เลือกให้อัตโนมัติ</small>

        </aside>

    </div>


    <!-- =========================================================
         BUTTONS
    ========================================================== -->

    <div class="form-actions">

        <a
            href="../index.php?page=evaluation"
            class="btn btn-secondary"
        >
            ยกเลิก
        </a>

        <button
            type="submit"
            class="btn btn-primary"
            id="periodSubmit"
        >
            <?= htmlspecialchars($submitLabel, ENT_QUOTES, "UTF-8") ?>
        </button>

    </div>

</form>


<script>
    (function () {

        const monthNames = <?= json_encode($monthNames, JSON_UNESCAPED_UNICODE) ?>;
        const takenByYear = <?= json_encode((object) $existingMonths) ?>;
        const locked = <?= $lockPeriod ? "true" : "false" ?>;

        const yearSelect = document.getElementById("period_year");
        const monthInputs = document.querySelectorAll(".month-tile input");
        const submitButton = document.getElementById("periodSubmit");

        const pad = (value) => String(value).padStart(2, "0");

        function setPreview(name, quarter, start, end) {
            document.getElementById("previewName").textContent = name;
            document.getElementById("previewQuarter").textContent = quarter;
            document.getElementById("previewStart").textContent = start;
            document.getElementById("previewEnd").textContent = end;
        }

        function render() {
            const year = parseInt(yearSelect.value, 10);
            const taken = (takenByYear[year] || []).map(Number);
            let selectedMonth = null;

            monthInputs.forEach(function (input) {
                const month = parseInt(input.value, 10);
                const isTaken = taken.includes(month);
                const tile = input.closest(".month-tile");

                if (!locked) {
                    input.disabled = isTaken;

                    if (isTaken && input.checked) {
                        input.checked = false;
                    }
                }

                tile.classList.toggle("is-taken", isTaken);
                tile.querySelector(".month-tile-note").textContent = isTaken ? "สร้างแล้ว" : "";

                if (input.checked) {
                    selectedMonth = month;
                }
            });

            if (selectedMonth === null) {
                setPreview("-", "-", "-", "-");
                submitButton.disabled = !locked;
                return;
            }

            const lastDay = new Date(year, selectedMonth, 0).getDate();

            setPreview(
                "ประจำเดือน" + monthNames[selectedMonth],
                "Q" + Math.ceil(selectedMonth / 3),
                "01/" + pad(selectedMonth) + "/" + year,
                pad(lastDay) + "/" + pad(selectedMonth) + "/" + year
            );

            submitButton.disabled = false;
        }

        yearSelect.addEventListener("change", render);

        monthInputs.forEach(function (input) {
            input.addEventListener("change", render);
        });

        render();

    })();
</script>
