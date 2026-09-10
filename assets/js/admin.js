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
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    window.scrollTo(0, Number(scrollY) || 0);
                });
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
        if (!isSameOrigin(url) || url.pathname.indexOf("/admin/") === -1) {
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
        if (!sessionStorage.getItem(pendingKey)) {
            savePendingReturn();
        }
    });

    window.addEventListener("pageshow", restoreScroll);
})();
