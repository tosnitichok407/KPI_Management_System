/*
 * Manager: ค้นหา / กรองแถวในตารางบนหน้าเว็บ (ไม่โหลดหน้าใหม่)
 * <div data-table-filter="tableId"> มี input[type=search] และ select[data-filter=key]
 * แถว <tr data-search="..." data-key="..."> · แถวที่ไม่ตรงถูกซ่อน · แสดง "x จาก y รายการ"
 */
(function () {
    document.querySelectorAll("[data-table-filter]").forEach(function (tools) {
        var table = document.getElementById(tools.dataset.tableFilter);
        if (!table || !table.tBodies.length) {
            return;
        }

        var body = table.tBodies[0];
        var rows = Array.prototype.filter.call(body.rows, function (row) { return row.hasAttribute("data-search"); });
        var search = tools.querySelector("input[type=search]");
        var selects = tools.querySelectorAll("select[data-filter]");
        var count = tools.querySelector("[data-table-count]");
        var columns = table.tHead ? table.tHead.rows[0].cells.length : 1;

        var empty = document.createElement("tr");
        empty.className = "table-no-match";
        empty.hidden = true;
        empty.innerHTML = '<td colspan="' + columns + '" class="empty-state">ไม่พบรายการที่ตรงกับการค้นหา</td>';
        body.appendChild(empty);

        function apply() {
            var words = (search ? search.value : "").toLowerCase().trim().split(/\s+/).filter(Boolean);
            var shown = 0;

            rows.forEach(function (row) {
                var text = row.dataset.search || "";
                var match = words.every(function (word) { return text.indexOf(word) !== -1; });

                selects.forEach(function (select) {
                    if (match && select.value !== "" && row.dataset[select.dataset.filter] !== select.value) {
                        match = false;
                    }
                });

                row.hidden = !match;
                shown += match ? 1 : 0;
            });

            empty.hidden = shown > 0 || rows.length === 0;

            if (count) {
                count.textContent = shown === rows.length ? rows.length + " รายการ" : "แสดง " + shown + " จาก " + rows.length + " รายการ";
            }
        }

        if (search) {
            search.addEventListener("input", apply);
        }
        selects.forEach(function (select) { select.addEventListener("change", apply); });
        apply();
    });
})();
