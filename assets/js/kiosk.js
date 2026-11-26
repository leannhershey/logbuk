/* Kiosk flows: role choice, occupant ID, time-in / time-out and the admin passcode. */
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

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("verifyForm");
  const field = document.getElementById("passcodeField");
  const input = document.getElementById("passcode");

  input.addEventListener("input", () => LogbukUtils.setFieldError(field, ""));

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const passcode = input.value.trim();

    if (!passcode) {
      LogbukUtils.setFieldError(field, "Please enter the passcode.");
      return;
    }

    try {
      await LogbukUtils.api("verify_passcode.php", { method: "POST", body: JSON.stringify({ passcode }) });
      window.location.href = "pages/dashboard.php";
    } catch (err) {
      const status = err && err.status;
      LogbukUtils.setFieldError(
        field,
        status === 401 ? "Incorrect passcode. Try again."
          : !status ? "Cannot reach the server. Open the site through Apache, e.g. http://localhost/logbuk/, not as a file."
          : (err && err.message) || "Sign-in failed. Try again."
      );
      if (status === 401) input.select();
    }
  });
});

;

document.addEventListener("DOMContentLoaded", () => {
  const $ = (id) => document.getElementById(id);
  const U = window.LogbukUtils;

  const page = document.querySelector(".public-page");
  const steps = { id: $("occ-step-id"), info: $("occ-step-info"), done: $("occ-step-done") };

  const idForm = $("idForm");
  const idField = $("idField");
  const idInput = $("occupantId");
  const btnVerify = $("btnVerify");

  const whereField = $("whereaboutsField");
  const whereInput = $("whereabouts");
  const actionField = $("actionField");
  const btnIn = $("btnTimeIn");
  const btnOut = $("btnTimeOut");

  let busy = false;

  function showStep(name) {
    Object.entries(steps).forEach(([key, el]) => (el.hidden = key !== name));
    page.classList.toggle("bg-dorm", name !== "id");
  }

  async function call(action, payload) {
    const data = await U.api("occupant_logbook.php", {
      method: "POST",
      body: JSON.stringify({ action, ...payload })
    });
    return data;
  }

  function messageOf(err) {
    return (err && err.data && err.data.message) || "Cannot reach the server. Check your connection and try again.";
  }

  async function guarded(btn, fn) {
    if (busy) return;
    busy = true;
    btn.disabled = true;
    try { await fn(); } finally { busy = false; btn.disabled = false; }
  }

  function resetToIdStep() {
    idInput.value = "";
    whereInput.value = "";
    U.setFieldError(idField, "");
    U.setFieldError(whereField, "");
    U.setFieldError(actionField, "");
    showStep("id");
    if (location.hash === "#occupant") idInput.focus();
  }

  async function backToLogbook(e) {
    if (e) e.preventDefault();
    try { await call("reset"); } catch (_) {  }
    resetToIdStep();
  }

  idInput.addEventListener("input", () => {
    const digits = idInput.value.replace(/\D/g, "").slice(0, 6);
    idInput.value = digits.length > 2 ? digits.slice(0, 2) + "-" + digits.slice(2) : digits;
    U.setFieldError(idField, "");
  });

  idForm.addEventListener("submit", (e) => {
    e.preventDefault();
    const id = idInput.value.trim();
    if (!id) return U.setFieldError(idField, "Please enter your occupant ID.");

    guarded(btnVerify, async () => {
      try {
        const res = await call("verify", { occupant_id: id });
        renderInfo(res.occupant);
        showStep("info");
      } catch (err) {
        U.setFieldError(idField, messageOf(err));
      }
    });
  });

  function renderInfo(o) {
    $("occName").textContent = o.name;
    $("occId").textContent = o.id;
    $("occProgram").textContent = o.program;
    $("occRoom").textContent = o.room;
    $("occContact").textContent = o.contact;

    const pill = $("occStatus");
    pill.textContent = o.label;
    pill.className = "occ-status " + (o.status === "On Premises" ? "occ-status--on" : "occ-status--off");

    const inside = o.status === "On Premises";
    btnIn.hidden = inside;
    btnOut.hidden = !inside;
    whereField.hidden = !inside;

    whereInput.value = "";
    U.setFieldError(whereField, "");
    U.setFieldError(actionField, "");
  }

  whereInput.addEventListener("input", () => {
    U.setFieldError(whereField, "");
    U.setFieldError(actionField, "");
  });

  btnIn.addEventListener("click", () =>
    guarded(btnIn, async () => {
      U.setFieldError(actionField, "");
      try {
        const res = await call("time_in");
        renderDone(res.result);
        showStep("done");
      } catch (err) {
        U.setFieldError(actionField, messageOf(err));
      }
    })
  );

  btnOut.addEventListener("click", () =>
    guarded(btnOut, async () => {
      U.setFieldError(actionField, "");
      const destination = whereInput.value.trim();
      if (!destination) {
        U.setFieldError(whereField, "Please enter your destination before time-out.");
        whereInput.focus();
        return;
      }
      try {
        const res = await call("time_out", { whereabouts: destination });
        renderDone(res.result);
        showStep("done");
      } catch (err) {
        U.setFieldError(actionField, messageOf(err));
      }
    })
  );

  function renderDone(r) {
    $("doneTitle").textContent = r.transaction + " Successful!";

    const rows = [
      ["Occupant", r.name],
      ["Transaction", r.transaction],
      ["Date", r.date],
      ["Time", r.time]
    ];
    if (r.whereabouts) rows.push(["Whereabouts", r.whereabouts]);

    const dl = $("doneReceipt");
    dl.replaceChildren();
    rows.forEach(([label, value]) => {
      const dt = document.createElement("dt");
      dt.textContent = label;
      const dd = document.createElement("dd");
      dd.textContent = value;
      dl.append(dt, dd);
    });

    const dt = document.createElement("dt");
    dt.textContent = "Updated Status";
    const dd = document.createElement("dd");
    const pill = document.createElement("span");
    pill.className = "occ-status " + (r.status === "On Premises" ? "occ-status--on" : "occ-status--off");
    pill.textContent = r.label;
    dd.append(pill);
    dl.append(dt, dd);

    $("doneLate").hidden = !r.late;
    if (r.late) {
      $("doneLateText").textContent =
        "Recorded after the " + (r.curfew || "10:00 PM") + " curfew. This entry was flagged as late.";
    }
  }

  document.querySelectorAll("[data-occ-back]").forEach((el) => el.addEventListener("click", backToLogbook));

  window.addEventListener("hashchange", () => {
    if (location.hash === "#occupant") backToLogbook();
    else page.classList.remove("bg-dorm");
  });
});

;
