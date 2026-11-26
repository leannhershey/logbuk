/* Config, icons, API helper, form errors and logout. Loaded on every page. */
window.LOGBUK_CONFIG = {

  API_BASE: (function () {
    var here = window.location.pathname;
    var base = here.replace(/\/pages\/[^\/]*$/, "/");
    if (base === here) base = here.replace(/[^\/]*$/, "");
    return base + "api";
  })()
};

(function () {
  const wrap = (body, fill) =>
    `<svg viewBox="0 0 24 24" fill="${fill || "none"}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body}</svg>`;

  const icons = {
    home: wrap('<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>'),
    users: wrap('<circle cx="12" cy="8" r="3.2"/><path d="M5.5 20c0-3.6 3-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="5" cy="10" r="2.2"/><circle cx="19" cy="10" r="2.2"/><path d="M1.5 18c.2-2.2 1.6-3.6 3.5-4M22.5 18c-.2-2.2-1.6-3.6-3.5-4"/>'),
    exit: wrap('<path d="M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5"/><path d="M3 12h12"/><path d="M11 8l4 4-4 4"/>'),
    clock: wrap('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'),
    door: wrap('<rect x="6" y="3" width="12" height="18" rx="1"/><circle cx="15" cy="12" r=".8" fill="currentColor"/>'),
    file: wrap('<path d="M7 3h8l4 4v14H7z"/><path d="M15 3v4h4M10 12h6M10 16h6"/>'),
    chart: wrap('<path d="M4 20V4"/><path d="M4 20h16"/><rect x="8" y="12" width="3" height="6"/><rect x="13" y="8" width="3" height="10"/><rect x="18" y="14" width="2" height="4"/>'),
    logout: wrap('<path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5"/><path d="M9 12h11"/><path d="M16 8l4 4-4 4"/>'),
    user: wrap('<circle cx="12" cy="8" r="4" fill="currentColor" stroke="none"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7z" fill="currentColor" stroke="none"/>'),
    admin: wrap('<circle cx="10" cy="8" r="4" fill="currentColor" stroke="none"/><path d="M2 21c0-4 3.6-7 8-7 1.2 0 2.3.2 3.3.6" fill="currentColor" stroke="none"/><circle cx="18" cy="17.5" r="2.4"/><path d="M18 13.5v1M18 20.5v1M14.5 17.5h1M20.5 17.5h1M15.6 15.1l.7.7M19.7 19.2l.7.7M15.6 19.9l.7-.7M19.7 15.8l.7-.7"/>'),
    key: wrap('<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3M15 12v2"/><circle cx="8" cy="12" r="1" fill="currentColor"/>'),
    clipboard: wrap('<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 15l5.5-5.5 1.5 1.5L10.5 16.5 8.5 17z"/>'),
    warning: wrap('<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.1"/>'),
    homeSolid: wrap('<path d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z" fill="currentColor" stroke="none"/>'),
    search: wrap('<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>'),
    plus: wrap('<path d="M12 5v14M5 12h14"/>'),
    edit: wrap('<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="M14.5 5.5l4 4"/>'),
    ban: wrap('<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'),
    refresh: wrap('<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v5h-5"/>'),
    settings: wrap('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'),
    check: wrap('<path d="M5 12l5 5 9-10"/>')
  };

  window.LogbukIcons = {
    get: (name) => icons[name] || "",

    render(root) {
      (root || document).querySelectorAll("[data-icon]").forEach((el) => {
        el.innerHTML = icons[el.dataset.icon] || "";
        el.style.display = "contents";
      });
    }
  };

  document.addEventListener("DOMContentLoaded", () => window.LogbukIcons.render());
})();

window.LogbukUtils = {

  formatTime(dateStr) {
    if (!dateStr) return "-";
    const d = new Date(dateStr.replace(" ", "T"));
    return d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true });
  },

  formatDate(dateStr) {
    if (!dateStr) return "";
    const d = new Date(dateStr.replace(" ", "T"));
    const mon = d.toLocaleDateString("en-US", { month: "short" });
    return `${mon}. ${d.getDate()}, ${d.getFullYear()}`;
  },

  esc(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  },

  async api(path, options) {
    const res = await fetch(`${window.LOGBUK_CONFIG.API_BASE}/${path}`, {
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      ...options
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Request failed"), { status: res.status, data });
    return data;
  },

  setFieldError(fieldEl, message) {
    const err = fieldEl.querySelector(".field__error > span:last-child");
    fieldEl.classList.toggle("has-error", !!message);
    if (err) err.textContent = message || "";
  }
};

;

(function () {
  "use strict";

  const SHOW_DELAY = 120;
  let tip = null;
  let showTimer = null;
  let current = null;

  function ensureTip() {
    if (tip) return tip;
    tip = document.createElement("div");
    tip.className = "tooltip";
    tip.setAttribute("role", "tooltip");
    tip.hidden = true;
    document.body.appendChild(tip);
    return tip;
  }

  function textFor(el) {

    return el.getAttribute("data-tooltip") || el.getAttribute("title") || el.getAttribute("aria-label") || "";
  }

  function place(el) {
    const t = ensureTip();
    const rect = el.getBoundingClientRect();
    const gap = 8;

    t.style.visibility = "hidden";
    t.hidden = false;

    const tw = t.offsetWidth;
    const th = t.offsetHeight;
    const margin = 8;

    let top = rect.top - th - gap;
    let placement = "top";
    if (top < margin) {
      top = rect.bottom + gap;
      placement = "bottom";
    }

    let left = rect.left + rect.width / 2 - tw / 2;
    left = Math.max(margin, Math.min(left, window.innerWidth - tw - margin));

    t.style.top = Math.round(top) + "px";
    t.style.left = Math.round(left) + "px";
    t.dataset.placement = placement;
    t.style.visibility = "";
  }

  function labelIsVisible(el) {
    if (!el.hasAttribute("data-tooltip-self")) return false;
    const label = el.querySelector("span:not([data-icon])");
    if (!label) return false;
    const box = label.getBoundingClientRect();
    return box.width > 20 && getComputedStyle(label).display !== "none";
  }

  function show(el) {
    if (labelIsVisible(el)) return;
    const text = textFor(el);
    if (!text) return;

    if (el.hasAttribute("title")) {
      el.dataset.tooltipFallback = el.getAttribute("title");
      el.removeAttribute("title");
    }

    current = el;
    el.setAttribute("aria-describedby", "logbuk-tooltip");
    const t = ensureTip();
    t.id = "logbuk-tooltip";
    t.textContent = text;
    place(el);
    t.classList.add("is-visible");
  }

  function hide() {
    clearTimeout(showTimer);
    if (!tip) return;
    tip.classList.remove("is-visible");
    tip.hidden = true;
    if (current) {
      current.removeAttribute("aria-describedby");

      if (current.dataset.tooltipFallback) {
        current.setAttribute("title", current.dataset.tooltipFallback);
        delete current.dataset.tooltipFallback;
      }
      current = null;
    }
  }

  function scheduleShow(el) {
    clearTimeout(showTimer);
    showTimer = setTimeout(() => show(el), SHOW_DELAY);
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("mouseover", (e) => {
      const el = e.target.closest("[data-tooltip]");
      if (el) scheduleShow(el);
    });

    document.addEventListener("mouseout", (e) => {
      const el = e.target.closest("[data-tooltip]");
      if (!el) return;

      if (el.contains(e.relatedTarget)) return;
      hide();
    });

    document.addEventListener("focusin", (e) => {
      const el = e.target.closest("[data-tooltip]");
      if (el) show(el);
    });
    document.addEventListener("focusout", (e) => {
      if (e.target.closest("[data-tooltip]")) hide();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });
    window.addEventListener("scroll", () => { if (current) place(current); }, true);
    window.addEventListener("resize", hide);
  });
})();

;

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("logoutBtn");
  if (!btn) return;
  btn.addEventListener("click", async () => {
    try {
      await fetch(window.LOGBUK_CONFIG.API_BASE + "/logout.php", { method: "POST", credentials: "same-origin" });
    } catch (e) {  }
    window.location.href = "../index.html";
  });
});
