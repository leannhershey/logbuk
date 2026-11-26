/* Sidebar collapse and mobile drawer, plus the header clock. */
(function () {
  "use strict";

  var KEY = "logbuk.sidebar";
  var RAIL_MAX = 1100;
  var PHONE_MAX = 700;

  var state = {
    mode: "expanded",
    mobileOpen: false
  };

  function load() {
    try {
      var v = window.localStorage.getItem(KEY);
      state.mode = (v === "rail" || v === "hidden") ? v : "expanded";
    } catch (e) {  }
  }

  function save() {
    try {
      window.localStorage.setItem(KEY, state.mode);
    } catch (e) {  }
  }

  function collapseTarget() {
    return window.innerWidth <= RAIL_MAX ? "hidden" : "rail";
  }

  function isPhone() {
    return window.innerWidth <= PHONE_MAX;
  }

  function apply() {
    var shell = document.querySelector(".admin-shell");
    var sidebar = document.getElementById("sidebar");
    if (!shell || !sidebar) return;

    var collapsed = state.mode !== "expanded";

    shell.classList.toggle("is-sidebar-collapsed", collapsed && !isPhone());
    shell.classList.toggle("is-sidebar-hidden", state.mode === "hidden");
    shell.classList.toggle("is-sidebar-open", isPhone() && state.mobileOpen);

    sidebar.setAttribute("data-collapsed", collapsed ? "true" : "false");

    var btn = document.getElementById("sidebarToggle");
    if (btn) {
      var label = isPhone()
        ? (state.mobileOpen ? "Hide menu" : "Show menu")
        : (collapsed ? "Expand sidebar" : "Collapse sidebar");

      btn.classList.toggle("is-collapsed", collapsed && !isPhone());
      btn.setAttribute("aria-label", label);
      btn.setAttribute("aria-expanded", String(isPhone() ? state.mobileOpen : !collapsed));
      btn.setAttribute("data-tooltip", label);
    }

    var scrim = document.querySelector(".sidebar-scrim");
    if (scrim) scrim.hidden = !(isPhone() && state.mobileOpen);

    document.body.classList.toggle("sidebar-is-rail", collapsed && !isPhone());
  }

  function toggle() {
    if (isPhone()) {
      state.mobileOpen = !state.mobileOpen;
      apply();
      return;
    }
    state.mode = state.mode === "expanded" ? collapseTarget() : "expanded";
    save();
    apply();
  }

  function closeMobile() {
    if (!state.mobileOpen) return;
    state.mobileOpen = false;
    apply();
  }

  var peekTimer = null;

  function peek(on) {

    return;

    var shell = document.querySelector(".admin-shell");
    if (!shell) return;
    clearTimeout(peekTimer);
    if (on) {
      peekTimer = setTimeout(function () {
        shell.classList.add("is-sidebar-peek");
      }, 60);
    } else {
      peekTimer = setTimeout(function () {
        shell.classList.remove("is-sidebar-peek");
      }, 220);
    }
  }

  function wirePeek() {
  }

  function buildToggle() {
    var header = document.querySelector(".page-header");
    if (!header || document.getElementById("sidebarToggle")) return;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.id = "sidebarToggle";
    btn.className = "sidebar-toggle";
    btn.setAttribute("aria-controls", "sidebar");
    btn.setAttribute("aria-label", "Collapse sidebar");
    btn.innerHTML =
      '<svg class="sidebar-toggle__chevron" viewBox="0 0 24 24" fill="none" ' +
      'stroke="currentColor" stroke-width="2.4" stroke-linecap="round" ' +
      'stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      '<polyline points="14 6 8 12 14 18"></polyline></svg>';

    header.insertBefore(btn, header.firstChild);
  }

  function buildScrim() {
    var shell = document.querySelector(".admin-shell");
    if (!shell || document.querySelector(".sidebar-scrim")) return;
    var scrim = document.createElement("div");
    scrim.className = "sidebar-scrim";
    scrim.hidden = true;
    scrim.addEventListener("click", closeMobile);
    shell.appendChild(scrim);
  }

  function init() {
    load();
    buildToggle();
    buildScrim();
    wirePeek();

    var btn = document.getElementById("sidebarToggle");
    if (btn) btn.addEventListener("click", toggle);

    document.addEventListener("keydown", function (e) {

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "b") {
        e.preventDefault();
        toggle();
        return;
      }
      if (e.key === "Escape") {
        if (state.mobileOpen) closeMobile();
        else if (state.mode !== "expanded") { state.mode = "expanded"; save(); apply(); }
      }
    });

    var sidebar = document.getElementById("sidebar");
    if (sidebar) {
      sidebar.addEventListener("click", function (e) {
        if (e.target.closest("a.nav-link")) closeMobile();
      });
    }

    var lastPhone = isPhone();
    window.addEventListener("resize", function () {
      var nowPhone = isPhone();
      if (nowPhone !== lastPhone) {
        lastPhone = nowPhone;
        state.mobileOpen = false;
      }
      apply();
    });

    var sidebar2 = document.getElementById("sidebar");
    if (sidebar2 && !sidebar2.children.length) {
      var tries = 0;
      var iv = setInterval(function () {
        if (sidebar2.children.length || ++tries > 40) { clearInterval(iv); apply(); }
      }, 25);
    }

    apply();
  }

  window.LogbukSidebar = { toggle: toggle, apply: apply, peek: peek };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

;

(function () {
  "use strict";

  if (window.LogbukClock) return;

  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  var DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function timeText(d) {
    var h = d.getHours();
    var suffix = h >= 12 ? "PM" : "AM";
    h = h % 12;
    if (h === 0) h = 12;
    return h + ":" + pad(d.getMinutes()) + " " + suffix;
  }

  function dateText(d) {
    return DAYS[d.getDay()] + ", " + MONTHS[d.getMonth()] + " " + d.getDate() + " " + d.getFullYear();
  }

  function build() {
    var header = document.querySelector(".page-header");
    if (!header || document.getElementById("liveClock")) return null;

    var wrap = document.createElement("div");
    wrap.id = "liveClock";
    wrap.className = "live-clock";
    wrap.setAttribute("role", "timer");
    wrap.setAttribute("aria-live", "off");
    wrap.innerHTML =
      '<span class="live-clock__time" data-clock="time">--:-- --</span>' +
      '<span class="live-clock__date" data-clock="date">—</span>';

    header.appendChild(wrap);
    return wrap;
  }

  var nodes = null;
  var timer = null;

  function paint() {
    if (!nodes) return;
    var now = new Date();
    var t = nodes.querySelector('[data-clock="time"]');
    var d = nodes.querySelector('[data-clock="date"]');
    if (t) {
      t.textContent = timeText(now);
      t.setAttribute("datetime", now.toISOString());
    }
    if (d) d.textContent = dateText(now);
  }

  function schedule() {
    window.clearTimeout(timer);
    var now = new Date();
    var msToNextMinute = (60 - now.getSeconds()) * 1000 - now.getMilliseconds();
    timer = window.setTimeout(function () {
      paint();
      schedule();
    }, msToNextMinute + 50);
  }

  function init() {
    nodes = build();
    if (!nodes) return;
    paint();
    schedule();

    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) { paint(); schedule(); }
    });
  }

  window.LogbukClock = {
    refresh: function () { paint(); schedule(); },

    timeText: timeText,
    dateText: dateText
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

;
