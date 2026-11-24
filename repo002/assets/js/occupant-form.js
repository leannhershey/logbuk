/* =====================================================================
   Add / Edit occupant form
   - live Room Occupancy panel that follows the Assigned Room select
   - generated Occupant ID preview (create) 
   - client-side validation that mirrors includes/occupants.php
   The server re-validates everything, so this is convenience only.
   ===================================================================== */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("occupantForm");
    if (!form) return;

    const mode = form.dataset.mode || "create";
    const editingId = form.dataset.occupantId || "";
    const rooms = JSON.parse(form.dataset.rooms || "[]");
    const nextIds = JSON.parse(form.dataset.nextIds || "{}");   // floor -> next occupant ID

    const roomSelect = document.getElementById("roomId");
    const idInput = document.getElementById("occupantId");
    const panelRoom = document.getElementById("roomPanelRoom");
    const panelCount = document.getElementById("roomPanelCount");
    const panelPeople = document.getElementById("roomPanelPeople");
    const panelHint = document.getElementById("roomPanelHint");
    const saveBtn = document.getElementById("saveBtn");
    const formAlert = document.getElementById("formAlert");

    /* ---------- live room panel ---------- */
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

      // When editing, the occupant already occupies a slot in their own room,
      // so show the room as it will look after saving.
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

      // ID preview: <floor>-<next sequence>
      if (idInput && mode === "create") {
        idInput.value = previewId(room.room_id);
      }
    }

    /* The server sends the next ID per floor, so the preview and the saved
       record always agree. (Guessing from the visible table would break as
       soon as the list is paginated or filtered.) */
    function previewId(roomId) {
      const floor = roomId.slice(0, 2);
      return nextIds[floor] || "";
    }

    roomSelect.addEventListener("change", () => {
      clearError("room_id");
      paintRoom();
    });
    paintRoom();

    /* ---------- validation ---------- */
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

    /* digits only in the contact field */
    const contact = document.getElementById("contactNumber");
    if (contact) {
      contact.addEventListener("input", () => {
        const clean = contact.value.replace(/\D+/g, "").slice(0, 11);
        if (clean !== contact.value) contact.value = clean;
      });
    }

    /* ---------- submit ---------- */
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

      /* The room can fill up between page load and submit, so ask the server
         before sending the real POST. Server re-checks on save as well. */
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
              form.submit();          // re-submit without this handler
              return;
            }
            Object.entries(data.errors || {}).forEach(([field, message]) => showError(field, message));
            formAlert.textContent = data.message || "Please fix the highlighted fields.";
            formAlert.classList.remove("hidden");
          })
          .catch(() => {
            form.submit();            // network hiccup: let the server decide
          })
          .finally(() => {
            saveBtn.disabled = false;
            saveBtn.textContent = saveBtn.dataset.label || saveBtn.textContent;
          });
      }
    });
  });
})();
