(function () {
    "use strict";

    const scrollPrefix = "adminScrollPosition_";
    const pendingKey = "adminPendingScroll";

    function pageIdentity(url) {
        const parsedUrl = new URL(url, window.location.origin);
        const page = parsedUrl.searchParams.get("page") || "home";
        return parsedUrl.pathname + "|page=" + page;
    }

    function pageKey(url) {
        const parsedUrl = new URL(url, window.location.origin);
        return scrollPrefix + parsedUrl.pathname + parsedUrl.search;
    }

    function saveScroll(url) {
        sessionStorage.setItem(pageKey(url), String(window.scrollY));
    }

    function savePendingReturn() {
        sessionStorage.setItem(pendingKey, JSON.stringify({
            identity: pageIdentity(window.location.href),
            scrollY: window.scrollY
        }));
    }

    function restoreScroll() {
        const currentIdentity = pageIdentity(window.location.href);
        const pendingValue = sessionStorage.getItem(pendingKey);
        let scrollY = sessionStorage.getItem(pageKey(window.location.href));

        if (pendingValue) {
            try {
                const pending = JSON.parse(pendingValue);
                if (pending.identity === currentIdentity) {
                    scrollY = pending.scrollY;
                    sessionStorage.removeItem(pendingKey);
                }
            } catch (error) {
                sessionStorage.removeItem(pendingKey);
            }
        }

        if (scrollY !== null) {
            const applyScroll = function () {
                window.scrollTo(0, Number(scrollY) || 0);
            };

            // เลื่อนทันที แล้วเลื่อนซ้ำหลังวาดเฟรมถัดไป เผื่อ layout ยังขยับอยู่
            applyScroll();
            requestAnimationFrame(function () {
                requestAnimationFrame(applyScroll);
            });
        }
    }

    function isSameOrigin(url) {
        return url.origin === window.location.origin;
    }

    document.addEventListener("click", function (event) {
        const link = event.target.closest("a[href]");
        if (!link || link.hasAttribute("data-no-scroll-save")) {
            return;
        }

        const url = new URL(link.href, window.location.origin);
        if (!isSameOrigin(url) || !/\/(admin|employee|manager)\//.test(url.pathname)) {
            return;
        }

        const pendingValue = sessionStorage.getItem(pendingKey);
        if (pendingValue) {
            try {
                const pending = JSON.parse(pendingValue);
                if (pending.identity === pageIdentity(url.href)) {
                    return;
                }
            } catch (error) {
                sessionStorage.removeItem(pendingKey);
            }
        }

        saveScroll(window.location.href);
        savePendingReturn();
    });

    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute("data-no-scroll-save")) {
            return;
        }

        saveScroll(window.location.href);

        // ฟอร์มที่ส่งกลับมาหน้าเดิม (เช่น ฟอร์มค้นหา) ให้จำตำแหน่งปัจจุบันเสมอ
        // ส่วนฟอร์มที่ไปหน้าอื่น (เช่น หน้าแก้ไข) คงตำแหน่งที่จำไว้ตอนคลิกเข้ามา
        // เฉพาะ GET form: ค่าในฟอร์ม (รวม hidden page=...) กลายเป็น query string ของหน้าปลายทาง
        // ส่วน POST form (หน้าแก้ไข) มักถูก redirect ไปหน้าอื่น จึงใช้กฎเดิม
        let returnsToSamePage = false;
        if ((form.method || "get").toLowerCase() === "get") {
            const targetUrl = new URL(form.getAttribute("action") || window.location.href, window.location.href);
            targetUrl.search = new URLSearchParams(new FormData(form)).toString();
            returnsToSamePage = pageIdentity(targetUrl.href) === pageIdentity(window.location.href);
        }

        if (returnsToSamePage || !sessionStorage.getItem(pendingKey)) {
            savePendingReturn();
        }
    });

    window.addEventListener("pageshow", restoreScroll);
})();
