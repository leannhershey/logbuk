(function () {
  const links = [
    // hrefs are relative to /pages, where every screen lives
    { key: "dashboard", label: "Dashboard",       href: "dashboard.html",   icon: "home" },
    { key: "occupants", label: "Occupants",       href: "occupants.php",    icon: "users" },
    { key: "rooms",     label: "Rooms",           href: "404-page.html?from=rooms",   icon: "door" },
    { key: "logs",      label: "Dormitory Logs",  href: "404-page.html?from=logs",    icon: "file", badgeId: "navLogsBadge" },
    { key: "reports",   label: "Reports",         href: "404-page.html?from=reports", icon: "chart" }
  ];

  document.addEventListener("DOMContentLoaded", () => {
    const aside = document.getElementById("sidebar");
    if (!aside) return;
    const active = aside.dataset.active;

    aside.innerHTML = `
      <div class="sidebar__logo">
        <img src="../assets/images/logo_white.png" alt="LogBUk logo">
      </div>
      <nav class="sidebar__nav" aria-label="Main navigation">
        ${links.map((l) => `
          <a class="nav-link ${l.key === active ? "is-active" : ""}" href="${l.href}">
            ${LogbukIcons.get(l.icon)}
            <span>${l.label}</span>
            ${l.badgeId ? `<span class="nav-badge hidden" id="${l.badgeId}">0</span>` : ""}
          </a>`).join("")}
      </nav>
      <div class="sidebar__user">
        <div class="sidebar__avatar">${LogbukIcons.get("admin")}</div>
        <div class="sidebar__user-info"><strong>Dormitory</strong><small>Administrator</small></div>
        <button class="sidebar__logout" id="logoutBtn" data-tooltip="Log out" aria-label="Log out" type="button">
          ${LogbukIcons.get("logout")}
        </button>
      </div>`;

    document.getElementById("logoutBtn").addEventListener("click", async () => {
      const cfg = window.LOGBUK_CONFIG;
      if (!cfg.USE_MOCK) {
        try { await LogbukUtils.api("logout.php", { method: "POST" }); } catch (e) { /* ignore */ }
      }
      sessionStorage.removeItem("logbuk_admin");
      window.location.href = "../index.html";
    });
  });
})();

/* Dashboard */
document.addEventListener("DOMContentLoaded", async () => {
  const cfg = window.LOGBUK_CONFIG;
  const { esc, formatTime, formatDate } = LogbukUtils;

  // Guard (sample mode only – PHP session guards the real API)
  if (cfg.USE_MOCK && !sessionStorage.getItem("logbuk_admin")) {
    window.location.href = "../index.html#admin";
    return;
  }

  let data;
  try {
    data = cfg.USE_MOCK
      ? window.LOGBUK_MOCK.dashboard
      : await LogbukUtils.api("dashboard.php");
  } catch (err) {
    if (err.status === 401) { window.location.href = "../index.html#admin"; return; }
    document.getElementById("activityBody").innerHTML =
      `<tr><td colspan="5" class="table-empty">Unable to load dashboard data.</td></tr>`;
    return;
  }

  // Stat cards
  document.getElementById("statTotal").textContent = data.total_occupants;
  document.getElementById("statInside").textContent = data.inside;
  document.getElementById("statOutside").textContent = data.outside;
  document.getElementById("statLate").textContent = data.late_entries;

  // Sidebar badge on "Dormitory Logs"
  const badge = document.getElementById("navLogsBadge");
  if (badge && data.unread_logs > 0) {
    badge.textContent = data.unread_logs;
    badge.classList.remove("hidden");
  }

  // Pie chart
  const total = data.inside + data.outside;
  const onPct = total ? (data.inside / total) * 100 : 0;
  document.getElementById("pie").style.setProperty("--on-pct", `${onPct}%`);
  document.getElementById("legendOn").textContent = data.inside;
  document.getElementById("legendOff").textContent = data.outside;

  // Recent activity (newest first)
  const rows = [...data.recent_activities].sort(
    (a, b) => new Date(b.time_in.replace(" ", "T")) - new Date(a.time_in.replace(" ", "T"))
  );

  const body = document.getElementById("activityBody");
  if (!rows.length) {
    body.innerHTML = `<tr><td colspan="5" class="table-empty">No activity recorded yet.</td></tr>`;
    return;
  }

  body.innerHTML = rows.map((r) => {
    const isInside = !r.time_out;
    return `
      <tr>
        <td><strong>${esc(r.full_name)}</strong><small>${esc(r.occupant_id)}</small></td>
        <td>${esc(r.room_number)}</td>
        <td><strong>${formatTime(r.time_in)}</strong><small>${formatDate(r.time_in)}</small></td>
        <td>${r.time_out
          ? `<strong>${formatTime(r.time_out)}</strong><small>${formatDate(r.time_out)}</small>`
          : "-"}</td>
        <td><div class="status-cell">
          <span class="pill ${isInside ? "pill--on" : "pill--off"}">${isInside ? "On Premises" : "Off Premises"}</span>
          ${Number(r.is_late) ? `<span class="pill pill--late">Late</span>` : ""}
        </div></td>
      </tr>`;
  }).join("");
});
