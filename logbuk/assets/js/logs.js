/* Live filtering and pagination for the Dormitory Logs table. */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    window.LogbukIcons.render();

    const form = document.getElementById("logFilterForm");
    if (!form) return;

    const search = document.getElementById("searchInput");
    const room = document.getElementById("roomFilter");
    const date = document.getElementById("dateFilter");
    const viewInput = document.getElementById("viewInput");
    let timer = null;
    let busy = false;

    function params(page) {
      const p = new URLSearchParams();
      const q = search.value.trim();
      if (q) p.set("q", q);
      if (room.value && room.value !== "all") p.set("room", room.value);
      if (date.value) p.set("date", date.value);
      if (viewInput.value && viewInput.value !== "all") p.set("view", viewInput.value);
      if (page > 1) p.set("page", String(page));
      return p;
    }

    async function refresh(page, quiet) {
      if (busy) return;
      busy = true;
      const qs = params(page || 1).toString();
      const url = "logs.php" + (qs ? "?" + qs : "");
      history.replaceState(null, "", url);

      const card = document.getElementById("logsCard");
      if (card && !quiet) card.classList.add("is-loading");

      try {
        const res = await fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "fetch" } });
        if (res.redirected && /index\.html/.test(res.url)) { window.location.href = res.url; return; }
        if (!res.ok) throw new Error("HTTP " + res.status);
        const doc = new DOMParser().parseFromString(await res.text(), "text/html");

        const freshCard = doc.getElementById("logsCard");
        const freshTabs = doc.getElementById("logTabs");
        const freshClear = doc.getElementById("clearFilters");
        if (freshCard && card) card.replaceWith(freshCard);
        if (freshTabs) document.getElementById("logTabs").replaceWith(freshTabs);
        if (freshClear) {
          const clear = document.getElementById("clearFilters");
          clear.classList.toggle("is-idle", freshClear.classList.contains("is-idle"));
        }
        const badge = doc.getElementById("navLogsBadge"), here = document.getElementById("navLogsBadge");
        if (badge && here) { here.textContent = badge.textContent; here.className = badge.className; }
        window.LogbukIcons.render();
      } catch (err) {
        if (card) card.classList.remove("is-loading");
        console.warn("Refresh failed:", err);
      } finally {
        busy = false;
      }
    }

    form.addEventListener("submit", (e) => { e.preventDefault(); refresh(1); });
    search.addEventListener("input", () => { clearTimeout(timer); timer = setTimeout(() => refresh(1), 250); });
    room.addEventListener("change", () => refresh(1));
    date.addEventListener("change", () => refresh(1));

    document.getElementById("clearFilters").addEventListener("click", (e) => {
      e.preventDefault();
      search.value = ""; room.value = "all"; date.value = "";
      refresh(1);
    });

    document.addEventListener("click", (e) => {
      const tab = e.target.closest(".log-tab");
      if (tab) {
        e.preventDefault();
        viewInput.value = tab.dataset.view;
        refresh(1);
        return;
      }
      const pg = e.target.closest(".pager__btn");
      if (pg && !pg.classList.contains("is-active")) {
        e.preventDefault();
        refresh(Number(new URL(pg.href, location.href).searchParams.get("page") || 1));
      }
    });

    setInterval(() => {
      if (document.hidden || document.activeElement === search) return;
      const page = Number(new URLSearchParams(location.search).get("page") || 1);
      refresh(page, true);
    }, 30000);
  });
})();
