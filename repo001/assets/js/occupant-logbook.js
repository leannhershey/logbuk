/* Occupant E-Logbook (module: Occupant Time-In & Time-Out)
   Screens (all inside #view-occupant in index.html):
     1. #occ-step-id    occupant ID entry
     2. #occ-step-info  occupant information + TIME-IN / TIME-OUT (+ whereabouts)
     3. #occ-step-done  confirmation
   Every rule is enforced by api/occupant_logbook.php; this file only shows the result. */
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

  /* ---------- helpers ---------- */
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

  /* run fn with the button locked, so a double click can never send two requests */
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

  /* Back to E-logbook: also forget the verified occupant on the server */
  async function backToLogbook(e) {
    if (e) e.preventDefault();
    try { await call("reset"); } catch (_) { /* nothing to undo if the server is unreachable */ }
    resetToIdStep();
  }

  /* ---------- step 1: verify ID ---------- */
  // 010001 -> 01-0001 while typing
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

  /* ---------- step 2: occupant info ---------- */
  function renderInfo(o) {
    $("occName").textContent = o.name;
    $("occId").textContent = o.id;
    $("occProgram").textContent = o.program;
    $("occRoom").textContent = o.room;
    $("occContact").textContent = o.contact;

    const pill = $("occStatus");
    pill.textContent = o.label;
    pill.className = "occ-status " + (o.status === "Inside" ? "occ-status--on" : "occ-status--off");

    const inside = o.status === "Inside";
    btnIn.hidden = inside;           // off premises -> TIME-IN
    btnOut.hidden = !inside;         // on premises  -> whereabouts + TIME-OUT
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

  /* ---------- step 3: confirmation ---------- */
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
      dd.textContent = value;               // textContent: occupant-typed text is never parsed as HTML
      dl.append(dt, dd);
    });

    const dt = document.createElement("dt");
    dt.textContent = "Updated Status";
    const dd = document.createElement("dd");
    const pill = document.createElement("span");
    pill.className = "occ-status " + (r.status === "Inside" ? "occ-status--on" : "occ-status--off");
    pill.textContent = r.label;
    dd.append(pill);
    dl.append(dt, dd);

    $("doneLate").hidden = !r.late;
    if (r.late) {
      $("doneLateText").textContent =
        "Recorded after the " + (r.curfew || "10:00 PM") + " curfew. This entry was flagged as late.";
    }
  }

  /* ---------- navigation ---------- */
  document.querySelectorAll("[data-occ-back]").forEach((el) => el.addEventListener("click", backToLogbook));

  // coming back to #occupant from Home / the role screen always starts clean
  window.addEventListener("hashchange", () => {
    if (location.hash === "#occupant") backToLogbook();
    else page.classList.remove("bg-dorm");
  });
});
