/* Fills the dashboard cards, pie and activity table from api/dashboard.php. */
(function () {
  "use strict";

  const U = window.LogbukUtils;

  document.addEventListener("DOMContentLoaded", async () => {
    let data;
    try {
      data = await U.api("dashboard.php");
    } catch (err) {
      if (err && err.status === 401) {
        window.location.href = "../index.html#admin";
        return;
      }

      const why = err && err.timeout
        ? "The server did not answer in time."
        : err && err.status >= 500
          ? ((err.data && err.data.message) || "The server could not provide the counts.")
          : "Could not reach the server. Open the site through http://localhost/, not as a file.";

      ["statTotal", "statInside", "statOutside", "statLate"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.textContent = "0";
      });
      document.getElementById("activityBody").innerHTML =
        '<tr><td colspan="5" class="table-empty">' + U.esc(why) + "</td></tr>";
      return;
    }

    document.getElementById("statTotal").textContent = data.total_occupants;
    document.getElementById("statInside").textContent = data.inside;
    document.getElementById("statOutside").textContent = data.outside;
    document.getElementById("statLate").textContent = data.late_entries;

    const total = data.inside + data.outside;
    const onPct = total ? (data.inside / total) * 100 : 0;
    document.getElementById("pie").style.setProperty("--on-pct", onPct + "%");
    document.getElementById("legendOn").textContent = data.inside;
    document.getElementById("legendOff").textContent = data.outside;

    const rows = [...data.recent_activities].sort(
      (a, b) => new Date(b.time_in.replace(" ", "T")) - new Date(a.time_in.replace(" ", "T"))
    );

    const body = document.getElementById("activityBody");
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="table-empty">No activity recorded yet.</td></tr>';
      return;
    }

    body.innerHTML = rows.map((r) => {
      const isInside = !r.time_out;
      return `
      <tr>
        <td><strong>${U.esc(r.full_name)}</strong><small>${U.esc(r.occupant_id)}</small></td>
        <td>${U.esc(r.room_number)}</td>
        <td><strong>${U.formatTime(r.time_in)}</strong><small>${U.formatDate(r.time_in)}</small></td>
        <td>${r.time_out ? "<strong>" + U.formatTime(r.time_out) + "</strong><small>" + U.formatDate(r.time_out) + "</small>" : "-"}</td>
        <td><div class="status-cell">
          <span class="pill ${isInside ? "pill--on" : "pill--off"}">${isInside ? "On Premises" : "Off Premises"}</span>
          ${Number(r.is_late) ? '<span class="pill pill--late">Late</span>' : ""}
        </div></td>
      </tr>`;
    }).join("");
  });
})();
