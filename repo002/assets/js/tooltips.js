/* =====================================================================
   Tooltips for icon-only buttons.
   
   Any element with data-tooltip="..." gets a styled tooltip on hover and on
   keyboard focus. A single fixed element is used (not a ::after bubble) so
   tooltips are never clipped by the table's scroll container or the card's
   overflow:hidden -- a real problem for the Actions column.
   
   Progressive enhancement: the markup keeps its native `title`, which is
   removed on first hover so the browser does not draw a second tooltip.
   Screen readers still get aria-label.
   ===================================================================== */
(function () {
  "use strict";

  const SHOW_DELAY = 120;   // ms -- stops tooltips flashing while the mouse travels
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
    // Prefer data-tooltip; fall back to the native title / aria-label.
    return el.getAttribute("data-tooltip") || el.getAttribute("title") || el.getAttribute("aria-label") || "";
  }

  function place(el) {
    const t = ensureTip();
    const rect = el.getBoundingClientRect();
    const gap = 8;

    // Default: above the target, centred. Flip below when there is no room.
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

  function show(el) {
    const text = textFor(el);
    if (!text) return;

    // The native tooltip would otherwise appear as well.
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
      // put the native title back so the semantics survive JS-free moments
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
      // ignore moves between the element and its own children
      if (el.contains(e.relatedTarget)) return;
      hide();
    });

    // Keyboard users get the same help.
    document.addEventListener("focusin", (e) => {
      const el = e.target.closest("[data-tooltip]");
      if (el) show(el);
    });
    document.addEventListener("focusout", (e) => {
      if (e.target.closest("[data-tooltip]")) hide();
    });

    // Escape dismisses; scrolling or resizing repositions.
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });
    window.addEventListener("scroll", () => { if (current) place(current); }, true);
    window.addEventListener("resize", hide);
  });
})();
