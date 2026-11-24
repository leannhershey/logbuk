(function () {
  const views = ["role", "occupant", "admin"];

  function show() {
    let name = location.hash.replace("#", "");
    if (!views.includes(name)) name = "role";
    views.forEach((v) => {
      document.getElementById(`view-${v}`).classList.toggle("is-active", v === name);
      const pill = document.getElementById(`pill-${v}`);
      if (pill) pill.classList.toggle("hidden", v !== name);
    });
    const focusEl = { occupant: "occupantId", admin: "passcode" }[name];
    if (focusEl) document.getElementById(focusEl).focus();
  }

  window.addEventListener("hashchange", show);
  document.addEventListener("DOMContentLoaded", show);
})();

/* Occupant ID entry, info and Time-In / Time-Out: see assets/js/occupant-logbook.js */

/* Admin verification */
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("verifyForm");
  const field = document.getElementById("passcodeField");
  const input = document.getElementById("passcode");
  const cfg = window.LOGBUK_CONFIG;

  input.addEventListener("input", () => LogbukUtils.setFieldError(field, ""));

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const passcode = input.value.trim();

    if (!passcode) {
      LogbukUtils.setFieldError(field, "Please enter the passcode.");
      return;
    }

    try {
      if (cfg.USE_MOCK) {
        if (passcode !== cfg.MOCK_ADMIN_PASSCODE) throw new Error("bad");
        sessionStorage.setItem("logbuk_admin", "1");
      } else {
        await LogbukUtils.api("verify_passcode.php", {
          method: "POST",
          body: JSON.stringify({ passcode })
        });
      }
      window.location.href = "pages/dashboard.html";
    } catch (err) {
      LogbukUtils.setFieldError(field, "Incorrect passcode. Try again.");
      input.select();
    }
  });
});
