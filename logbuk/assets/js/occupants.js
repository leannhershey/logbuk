/* Occupants screen: filters, deactivate/reactivate modals, room panel on the form. */
(function () {
  "use strict";

  const API = "../api/occupants_api.php";

  document.addEventListener("DOMContentLoaded", () => {
    window.LogbukIcons.render();

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

    document.addEventListener("click", (e) => {
      const link = e.target.closest(".pager__btn");
      if (!link || link.classList.contains("is-active")) return;
      e.preventDefault();
      const target = new URL(link.href, location.href);
      refresh(Number(target.searchParams.get("page") || 1));
    });

  }

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

    const here = new URLSearchParams(location.search);
    if (here.get("sort")) params.set("sort", here.get("sort"));
    if (here.get("dir")) params.set("dir", here.get("dir"));

    return params;
  }

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
      const freshError = doc.querySelector(".db-error");

      if (freshCard && card) {
        card.replaceWith(freshCard);
        freshCard.classList.remove("is-loading");
      }

      if (freshError) {
        document.querySelector(".page-body").prepend(freshError);
      }

      window.LogbukIcons.render();

    } catch (err) {
      if (card) card.classList.remove("is-loading");
      console.warn("Refresh failed:", err);
    }
  }

  function initModals() {
    const deactivateModal = document.getElementById("deactivateModal");
    const reactivateModal = document.getElementById("reactivateModal");

    let pending = { id: null, name: null, room: null };

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

  function refreshKeepPage() {
    const page = Number(new URLSearchParams(location.search).get("page") || 1);
    refresh(page);
  }

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

  window.LogbukOccupants = { toast, refresh };
})();

;

(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("occupantForm");
    if (!form) return;

    const mode = form.dataset.mode || "create";
    const editingId = form.dataset.occupantId || "";
    const rooms = JSON.parse(form.dataset.rooms || "[]");
    const nextIds = JSON.parse(form.dataset.nextIds || "{}");

    const roomSelect = document.getElementById("roomId");
    const idInput = document.getElementById("occupantId");
    const panelRoom = document.getElementById("roomPanelRoom");
    const panelCount = document.getElementById("roomPanelCount");
    const panelPeople = document.getElementById("roomPanelPeople");
    const panelHint = document.getElementById("roomPanelHint");
    const saveBtn = document.getElementById("saveBtn");
    const formAlert = document.getElementById("formAlert");

    function findRoom(id) {
      return rooms.find((r) => r.room_id === id) || null;
    }

    function paintRoom() {
      const room = findRoom(roomSelect.value);

      if (!room) {
        panelRoom.textContent = "No room selected";
        panelCount.textContent = "—";
        panelPeople.innerHTML = "";
        panelHint.textContent = "Choose a room to see how many slots are left.";
        if (idInput && mode === "create") idInput.value = "";
        return;
      }

      const occupied = room.used;
      const capacity = room.capacity;

      panelRoom.textContent = "Room " + room.room_id;
      panelCount.textContent = occupied + "/" + capacity;
      panelPeople.innerHTML = Array.from({ length: capacity }, (_, i) =>
        `<span class="person ${i < occupied ? "person--filled" : ""}">
           <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
             <circle cx="12" cy="7.5" r="4"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/>
           </svg>
         </span>`).join("");

      if (room.is_full) {
        panelHint.textContent = "This room is full — pick another room.";
      } else {
        panelHint.textContent = room.available + " slot(s) available.";
      }

      if (idInput && mode === "create") {
        idInput.value = previewId(room.room_id);
      }
    }

    function previewId(roomId) {
      const floor = roomId.slice(0, 2);
      return nextIds[floor] || "";
    }

    roomSelect.addEventListener("change", () => {
      clearError("room_id");
      paintRoom();
    });
    paintRoom();

    const rules = {
      first_name: (v) => (!v.trim() ? "First name is required." : ""),
      last_name: (v) => (!v.trim() ? "Last name is required." : ""),
      contact_number: (v) => {
        const digits = v.replace(/\D+/g, "");
        if (!digits) return "Contact number is required.";
        if (!/^09\d{9}$/.test(digits)) return "Use 11 digits starting with 09 (e.g. 09171234567).";
        return "";
      },
      room_id: (v) => (!v ? "Please choose a room." : ""),
    };

    function fieldWrap(name) {
      return document.getElementById("field-" + name);
    }

    function showError(name, message) {
      const wrap = fieldWrap(name);
      if (!wrap) return;
      const span = wrap.querySelector(".field__error span:last-child");
      wrap.classList.toggle("has-error", !!message);
      if (span) span.textContent = message || "";
    }

    function clearError(name) { showError(name, ""); }

    Object.keys(rules).forEach((name) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (!input) return;
      input.addEventListener("input", () => {
        if (fieldWrap(name)?.classList.contains("has-error")) {
          showError(name, rules[name](input.value));
        }
      });
      input.addEventListener("blur", () => showError(name, rules[name](input.value)));
    });

    const contact = document.getElementById("contactNumber");
    if (contact) {
      contact.addEventListener("input", () => {
        const clean = contact.value.replace(/\D+/g, "").slice(0, 11);
        if (clean !== contact.value) contact.value = clean;
      });
    }

    form.addEventListener("submit", (e) => {
      let firstError = null;
      Object.keys(rules).forEach((name) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;
        const message = rules[name](input.value);
        showError(name, message);
        if (message && !firstError) firstError = input;
      });

      if (firstError) {
        e.preventDefault();
        formAlert.textContent = "Please fix the highlighted fields.";
        formAlert.classList.remove("hidden");
        firstError.focus();
        return;
      }

      formAlert.classList.add("hidden");

      if (!e.defaultPrevented && window.fetch) {
        const payload = {
          action: "validate",
          occupant_id: editingId,
          first_name: form.first_name.value,
          middle_name: form.middle_name.value,
          last_name: form.last_name.value,
          suffix: form.suffix.value,
          program: form.program.value,
          contact_number: form.contact_number.value,
          room_id: form.room_id.value,
        };

        e.preventDefault();
        saveBtn.disabled = true;
        saveBtn.dataset.label = saveBtn.textContent;
        saveBtn.textContent = "Checking…";

        fetch("../api/occupants_api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        })
          .then((r) => r.json().catch(() => ({ ok: false })))
          .then((data) => {
            if (data.ok) {
              form.submit();
              return;
            }
            Object.entries(data.errors || {}).forEach(([field, message]) => showError(field, message));
            formAlert.textContent = data.message || "Please fix the highlighted fields.";
            formAlert.classList.remove("hidden");
          })
          .catch(() => {
            form.submit();
          })
          .finally(() => {
            saveBtn.disabled = false;
            saveBtn.textContent = saveBtn.dataset.label || saveBtn.textContent;
          });
      }
    });
  });
})();

;
