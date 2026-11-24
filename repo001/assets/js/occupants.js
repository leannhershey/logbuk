/* =====================================================================
   Occupants screen (occupants.php)
   - icon rendering + logout
   - live search / filter / sort / pagination (progressive enhancement:
     the page works fully without this file, the form just submits)
   - deactivate / reactivate modals -> api/occupants_api.php
   ===================================================================== */
(function () {
  "use strict";

  const API = "api/occupants_api.php";

  document.addEventListener("DOMContentLoaded", () => {
    window.LogbukIcons.render();

    const logout = document.getElementById("logoutBtn");
    if (logout) {
      logout.addEventListener("click", async () => {
        // The session lives on the server, so it has to be destroyed there.
        // Bouncing to ../index.html alone would leave the admin logged in.
        try {
          await fetch("../api/logout.php", { method: "POST", credentials: "same-origin" });
        } catch (e) { /* offline: still send the user home */ }
        sessionStorage.removeItem("logbuk_admin");
        window.location.href = "../index.html";
      });
    }

    initFilters();
    initModals();

    const flash = document.getElementById("flashMessage");
    if (flash) {
      setTimeout(() => {
        flash.style.transition = "opacity .4s";
        flash.style.opacity = "0";
        setTimeout(() => flash.remove(), 450);
      }, 4500);
    }
  });

  /* ------------------------------------------------------------------
     Live filtering / sorting / paging
     ------------------------------------------------------------------ */

  function initFilters() {
    const form = document.getElementById("filterForm");
    if (!form) return;

    const search = document.getElementById("searchInput");
    const selects = ["roomFilter", "residencyFilter", "presenceFilter"]
      .map((id) => document.getElementById(id))
      .filter(Boolean);

    let timer = null;

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      refresh(1);
    });

    if (search) {
      search.addEventListener("input", () => {
        clearTimeout(timer);
        timer = setTimeout(() => refresh(1), 250);
      });
    }
    selects.forEach((sel) => sel.addEventListener("change", () => refresh(1)));

    /* Sortable headers: swap the arrow in place, keep other filters. */
    document.addEventListener("click", (e) => {
      const link = e.target.closest(".th-sort");
      if (!link) return;
      e.preventDefault();
      const params = currentParams();
      params.set("sort", link.dataset.sort);
      params.set("dir", link.dataset.dir);
      params.delete("page");
      history.replaceState(null, "", "occupants.php?" + params.toString());
      refresh(1);
    });

    /* Pager */
    document.addEventListener("click", (e) => {
      const link = e.target.closest(".pager__btn");
      if (!link || link.classList.contains("is-active")) return;
      e.preventDefault();
      const target = new URL(link.href, location.href);
      refresh(Number(target.searchParams.get("page") || 1));
    });

    /* Summary chips */
    document.querySelectorAll(".chip").forEach((chip) => {
      const href = chip.getAttribute("href");
      if (!href) return;
      chip.addEventListener("click", (e) => {
        e.preventDefault();
        const target = new URL(href, location.href);
        history.replaceState(null, "", target.pathname + target.search);
        refresh(1);
      });
    });
  }

  /** Collect the toolbar state into URLSearchParams. */
  function currentParams() {
    const params = new URLSearchParams();
    const val = (id) => {
      const el = document.getElementById(id);
      return el ? el.value.trim() : "";
    };

    const q = val("searchInput");
    if (q) params.set("q", q);

    [["room", "roomFilter"], ["residency", "residencyFilter"], ["presence", "presenceFilter"]]
      .forEach(([key, id]) => {
        const v = val(id);
        if (v && v !== "all") params.set(key, v);
      });

    // keep the active sort from the URL (set by the sort links)
    const here = new URLSearchParams(location.search);
    if (here.get("sort")) params.set("sort", here.get("sort"));
    if (here.get("dir")) params.set("dir", here.get("dir"));

    return params;
  }

  /** Fetch the same page with new params and swap only the table card. */
  async function refresh(page) {
    const params = currentParams();
    if (page > 1) params.set("page", String(page));

    const url = "occupants.php" + (params.toString() ? "?" + params.toString() : "");
    history.replaceState(null, "", url);

    const card = document.getElementById("listCard");
    if (card) card.classList.add("is-loading");

    try {
      const res = await fetch(url, { headers: { "X-Requested-With": "fetch" } });
      if (!res.ok) throw new Error("HTTP " + res.status);

      const doc = new DOMParser().parseFromString(await res.text(), "text/html");
      const freshCard = doc.getElementById("listCard");
      const freshChips = doc.querySelector(".stat-chips");
      const freshError = doc.querySelector(".db-error");

      if (freshCard && card) {
        card.replaceWith(freshCard);
        freshCard.classList.remove("is-loading");
      }

      const chipsHere = document.querySelector(".stat-chips");
      if (freshChips && chipsHere) {
        chipsHere.replaceWith(freshChips);
        bindChips(freshChips);
      }

      if (freshError) {
        document.querySelector(".page-body").prepend(freshError);
      }

      window.LogbukIcons.render();
      // the toolbar keeps focus; nothing else to rebind (delegated handlers)
    } catch (err) {
      if (card) card.classList.remove("is-loading");
      console.warn("Refresh failed:", err);
    }
  }

  function bindChips(scope) {
    scope.querySelectorAll(".chip").forEach((chip) => {
      chip.addEventListener("click", (e) => {
        e.preventDefault();
        const target = new URL(chip.getAttribute("href"), location.href);
        history.replaceState(null, "", target.pathname + target.search);
        refresh(1);
      });
    });
  }

  /* ------------------------------------------------------------------
     Deactivate / reactivate modals
     ------------------------------------------------------------------ */

  function initModals() {
    const deactivateModal = document.getElementById("deactivateModal");
    const reactivateModal = document.getElementById("reactivateModal");

    let pending = { id: null, name: null, room: null };

    /* ---- open ---- */
    document.addEventListener("click", (e) => {
      const trigger = e.target.closest("[data-action]");
      if (!trigger) return;

      const action = trigger.dataset.action;
      pending = {
        id: trigger.dataset.id,
        name: trigger.dataset.name,
        room: trigger.dataset.room || null,
      };

      if (action === "open-deactivate") {
        document.getElementById("deactivateText").innerHTML =
          `<strong>${escapeHtml(pending.name)}</strong> will become <strong>Inactive</strong> and leave Room ` +
          `<strong>${escapeHtml(pending.room || "—")}</strong>. Their log history is kept. ` +
          `They can be reactivated later with a new room.`;
        document.getElementById("deactivateError").classList.add("hidden");
        document.getElementById("deactivateReason").value = "";
        openModal(deactivateModal, document.getElementById("confirmDeactivate"));
      }

      if (action === "open-reactivate") {
        document.getElementById("reactivateText").innerHTML =
          `<strong>${escapeHtml(pending.name)}</strong> will become <strong>Active</strong> again. ` +
          `Choose the room they will be assigned to. Their occupant ID and log history stay the same.`;
        document.getElementById("reactivateError").classList.add("hidden");
        openModal(reactivateModal, document.getElementById("confirmReactivate"));
      }
    });

    /* ---- close ---- */
    document.addEventListener("click", (e) => {
      if (e.target.closest("[data-close-modal]")) {
        closeModal(deactivateModal);
        closeModal(reactivateModal);
      }
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        closeModal(deactivateModal);
        closeModal(reactivateModal);
      }
    });

    /* ---- confirm deactivate ---- */
    const confirmDeactivate = document.getElementById("confirmDeactivate");
    if (confirmDeactivate) {
      confirmDeactivate.addEventListener("click", async () => {
        const reason = document.getElementById("deactivateReason").value;
        await callApi(
          { action: "deactivate", occupant_id: pending.id, reason },
          confirmDeactivate,
          document.getElementById("deactivateError"),
          (data) => {
            closeModal(deactivateModal);
            const used = data.room ? data.room.used : null;
            const cap = data.room ? data.room.capacity : null;
            toast(used !== null
              ? `${pending.id} deactivated. Room ${pending.room} now has ${used}/${cap} slots used.`
              : `${pending.id} deactivated.`);
            refreshKeepPage();
          }
        );
      });
    }

    /* ---- confirm reactivate ---- */
    const confirmReactivate = document.getElementById("confirmReactivate");
    if (confirmReactivate) {
      confirmReactivate.addEventListener("click", async () => {
        const room = document.getElementById("reactivateRoom").value;
        const ok = await callApi(
          { action: "reactivate", occupant_id: pending.id, room_id: room },
          confirmReactivate,
          document.getElementById("reactivateError"),
          () => {
            closeModal(reactivateModal);
            toast(`${pending.id} reactivated in Room ${room}.`);
            refreshKeepPage();
          }
        );
        return ok;
      });
    }
  }

  /** POST to the API, show errors inline, run onSuccess. */
  async function callApi(payload, button, errorBox, onSuccess) {
    const original = button.textContent;
    button.disabled = true;
    button.textContent = "Saving…";
    errorBox.classList.add("hidden");

    try {
      const res = await fetch(API, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.ok) {
        errorBox.textContent = data.message || "Something went wrong. Try again.";
        errorBox.classList.remove("hidden");
        return false;
      }

      onSuccess(data);
      return true;
    } catch (err) {
      errorBox.textContent = "Network error. Check that PHP is running.";
      errorBox.classList.remove("hidden");
      return false;
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  }

  /** Re-render the current page (keeps page/sort/filters). */
  function refreshKeepPage() {
    const page = Number(new URLSearchParams(location.search).get("page") || 1);
    refresh(page);
  }

  /* ---- modal helpers ---- */
  function openModal(modal, focusEl) {
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add("modal-open");
    const focusTarget = focusEl || modal.querySelector(".btn-modal--primary");
    if (focusTarget) setTimeout(() => focusTarget.focus(), 30);
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    if (!document.querySelector(".modal:not([hidden])")) {
      document.body.classList.remove("modal-open");
    }
  }

  /* ---- toast ---- */
  let toastTimer = null;
  function toast(message) {
    const el = document.getElementById("toast");
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
    el.classList.add("is-visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      el.classList.remove("is-visible");
      setTimeout(() => { el.hidden = true; }, 250);
    }, 3800);
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  }

  /* expose for the forms if needed */
  window.LogbukOccupants = { toast, refresh };
})();
