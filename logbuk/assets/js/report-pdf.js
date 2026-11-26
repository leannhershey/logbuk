/* Builds the monthly report PDF in the browser, no libraries needed. */
(function () {
  "use strict";

  if (window.LogbukReports) return;

  var API = (function () {
    var cfg = window.LOGBUK_CONFIG || {};
    if (cfg.API_BASE) return cfg.API_BASE + "/reports_api.php";

    var here = window.location.pathname;
    var base = here.replace(/\/pages\/[^\/]*$/, "/");
    if (base === here) base = here.replace(/[^\/]*$/, "");
    return base + "api/reports_api.php";
  })();

  var MONTH_NAMES = ["January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"];

  var HELV = [
    278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
    556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
    1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
    667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
    333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
    556,556,333,500,278,556,500,722,500,500,500,334,260,334,584
  ];
  var HELV_B = [
    278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
    556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
    975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
    667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
    333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
    611,611,389,556,333,611,556,778,556,556,500,389,280,389,584
  ];

  var WIN_ANSI = {
    "–": "\\226", "—": "\\227", "‘": "\\221", "’": "\\222", "“": "\\223",
    "”": "\\224", "•": "\\225", "…": "\\205", "₱": "\\240", "ñ": "\\361",
    "Ñ": "\\321", "é": "\\351", "è": "\\350", "á": "\\341", "à": "\\340",
    "í": "\\355", "ó": "\\363", "ú": "\\372", "ü": "\\374", "¿": "\\277"
  };

  function pdfString(str) {
    var out = "";
    for (var i = 0; i < str.length; i++) {
      var ch = str.charAt(i);
      if (ch === "\\") { out += "\\\\"; continue; }
      if (ch === "(") { out += "\\("; continue; }
      if (ch === ")") { out += "\\)"; continue; }
      if (WIN_ANSI[ch]) { out += WIN_ANSI[ch]; continue; }
      var code = str.charCodeAt(i);
      if (code >= 32 && code <= 126) { out += ch; continue; }
      out += "?";
    }
    return out;
  }

  function textWidth(str, bold, size) {
    var table = bold ? HELV_B : HELV;
    var w = 0;
    for (var i = 0; i < str.length; i++) {
      var code = str.charCodeAt(i);
      var idx = code - 32;
      w += (idx >= 0 && idx < table.length) ? table[idx] : 556;
    }
    return (w / 1000) * size;
  }

  function fit(str, bold, size, maxWidth) {
    if (textWidth(str, bold, size) <= maxWidth) return str;
    var ell = "...";
    var lo = 0, hi = str.length;
    while (lo < hi) {
      var mid = Math.ceil((lo + hi) / 2);
      if (textWidth(str.slice(0, mid) + ell, bold, size) <= maxWidth) lo = mid;
      else hi = mid - 1;
    }
    return str.slice(0, lo) + ell;
  }

  function hexColor(hex) {
    var h = String(hex).replace("#", "");
    return [
      parseInt(h.slice(0, 2), 16) / 255,
      parseInt(h.slice(2, 4), 16) / 255,
      parseInt(h.slice(4, 6), 16) / 255
    ].map(function (v) { return v.toFixed(3); }).join(" ");
  }

  function Page(w, h) {
    this.w = w; this.h = h; this.ops = [];
  }
  Page.prototype.rect = function (x, y, w, h, color, mode) {
    if (color) this.ops.push(hexColor(color) + " " + (mode || "f"));
    this.ops.push(x.toFixed(2) + " " + y.toFixed(2) + " " + w.toFixed(2) + " " + h.toFixed(2) + " re");
    if (color) this.ops.push(mode === "f" ? "f" : mode === "S" ? "S" : "B");
    else this.ops.push("S");
    return this;
  };
  Page.prototype.fill = function (x, y, w, h, color) {
    this.ops.push(hexColor(color) + " rg");
    this.ops.push(x.toFixed(2) + " " + y.toFixed(2) + " " + w.toFixed(2) + " " + h.toFixed(2) + " re f");
    return this;
  };
  Page.prototype.line = function (x1, y1, x2, y2, color, width) {
    this.ops.push(hexColor(color) + " RG");
    this.ops.push((width || 0.6).toFixed(2) + " w");
    this.ops.push(x1.toFixed(2) + " " + y1.toFixed(2) + " m " + x2.toFixed(2) + " " + y2.toFixed(2) + " l S");
    return this;
  };

  Page.prototype.text = function (x, y, str, opts) {
    opts = opts || {};
    var size = opts.size || 9;
    var bold = !!opts.bold;
    var s = String(str == null ? "" : str);
    if (opts.maxWidth) s = fit(s, bold, size, opts.maxWidth);

    var tx = x;
    if (opts.align === "right") tx = x - textWidth(s, bold, size);
    else if (opts.align === "center") tx = x - textWidth(s, bold, size) / 2;

    this.ops.push("BT");
    this.ops.push(hexColor(opts.color || "#111111") + " rg");
    this.ops.push("/" + (bold ? "F2" : "F1") + " " + size.toFixed(2) + " Tf");
    this.ops.push(tx.toFixed(2) + " " + y.toFixed(2) + " Td");
    this.ops.push("(" + pdfString(s) + ") Tj");
    this.ops.push("ET");
    return this;
  };
  Page.prototype.widthOf = function (str, bold, size) { return textWidth(str, bold, size); };

  function buildPdf(pages, info) {
    var objects = [];
    var pageCount = pages.length;
    var firstPageObj = 5;

    var kids = [];
    for (var i = 0; i < pageCount; i++) kids.push((firstPageObj + i * 2) + " 0 R");

    objects[0] = "<< /Type /Catalog /Pages 2 0 R >>";
    objects[1] = "<< /Type /Pages /Kids [" + kids.join(" ") + "] /Count " + pageCount + " >>";
    objects[2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    for (var p = 0; p < pageCount; p++) {
      var page = pages[p];
      var pageObjNo = firstPageObj + p * 2;
      var contentObjNo = pageObjNo + 1;
      objects[pageObjNo - 1] =
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " + page.w + " " + page.h + "] " +
        "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " + contentObjNo + " 0 R >>";

      var stream = page.ops.join("\n");
      objects[contentObjNo - 1] =
        "<< /Length " + stream.length + " >>\nstream\n" + stream + "\nendstream";
    }

    var infoNo = objects.length + 1;
    objects.push(
      "<< /Title (" + pdfString(info.title || "Log Report") + ") " +
      "/Author (LogBUk) /Creator (LogBUk Monthly Log Report) " +
      "/CreationDate (D:" + info.date + ") >>"
    );

    var out = "%PDF-1.4\n%\u00e2\u00e3\u00cf\u00d3\n";
    var offsets = [];
    for (var o = 0; o < objects.length; o++) {
      offsets.push(out.length);
      out += (o + 1) + " 0 obj\n" + objects[o] + "\nendobj\n";
    }
    var xrefPos = out.length;
    out += "xref\n0 " + (objects.length + 1) + "\n";
    out += "0000000000 65535 f \n";
    for (var k = 0; k < offsets.length; k++) {
      out += ("0000000000" + offsets[k]).slice(-10) + " 00000 n \n";
    }
    out += "trailer\n<< /Size " + (objects.length + 1) + " /Root 1 0 R /Info " + infoNo + " 0 R >>\n";
    out += "startxref\n" + xrefPos + "\n%%EOF\n";

    var bytes = new Uint8Array(out.length);
    for (var b = 0; b < out.length; b++) bytes[b] = out.charCodeAt(b) & 0xff;
    return new Blob([bytes], { type: "application/pdf" });
  }

  var PAGE_W = 842, PAGE_H = 595, M = 36;
  var CONTENT_W = PAGE_W - M * 2;
  var NAVY = "#0b3c9c", ORANGE = "#ff5a0f", HEAD_BG = "#ffe3a9",
      ROW_ALT = "#f7f9fd", BORDER = "#dbe4f3", MUTED = "#6b7280", INK = "#111111";

  function renderReport(data) {
    var pages = [];
    var page = new Page(PAGE_W, PAGE_H);
    pages.push(page);
    var y = PAGE_H - M;

    var period = data.period.label;
    var stamp = new Date();

    page.fill(0, PAGE_H - 74, PAGE_W, 74, NAVY);
    page.fill(0, PAGE_H - 78, PAGE_W, 4, ORANGE);
    page.text(M, PAGE_H - 34, "LOGBUK", { size: 20, bold: true, color: "#ffffff" });
    page.text(M + page.widthOf("LOGBUK", true, 20) + 8, PAGE_H - 34,
      "Bicol University Dormitory Digitalized Log Book System", { size: 9, color: "#c9d8f2" });
    page.text(PAGE_W - M, PAGE_H - 30, "MONTHLY LOG REPORT", { size: 13, bold: true, color: "#ffffff", align: "right" });
    page.text(PAGE_W - M, PAGE_H - 46, period, { size: 11, color: "#ffe3a9", align: "right" });

    y = PAGE_H - 104;

    var t = data.totals;
    var boxes = [
      ["Log entries", String(t.logs)],
      ["Occupants", String(t.occupants)],
      ["Late entries", String(t.late)],
      ["Still timed in", String(t.open)],
      ["Total hours", String(t.hours)],
      ["Busiest day", t.busiest_day ? fmtDay(t.busiest_day) + " (" + t.busiest_count + ")" : "—"]
    ];
    var gap = 10;
    var bw = (CONTENT_W - gap * (boxes.length - 1)) / boxes.length;
    boxes.forEach(function (box, i) {
      var bx = M + i * (bw + gap);
      page.fill(bx, y - 46, bw, 46, "#f4f7fd");
      page.fill(bx, y - 46, 3, 46, ORANGE);
      page.text(bx + 12, y - 18, box[1], { size: 15, bold: true, color: NAVY });
      page.text(bx + 12, y - 34, box[0].toUpperCase(), { size: 6.5, color: MUTED });
    });
    y -= 70;

    var leftW = CONTENT_W * 0.36;
    var rightX = M + leftW + 16;
    var rightW = CONTENT_W - leftW - 16;
    var sectionTop = y;

    page.text(M, y, "WHERE OCCUPANTS WENT", { size: 8, bold: true, color: NAVY });
    y -= 14;
    var cats = Object.keys(data.categories);
    if (!cats.length) {
      page.text(M, y, "No log entries in this period.", { size: 8, color: MUTED });
      y -= 14;
    } else {
      var maxCat = Math.max.apply(null, cats.map(function (c) { return data.categories[c]; }));

      var BAR_X = M + 74;
      var BAR_MAX = leftW - 74 - 26;
      cats.forEach(function (c) {
        var n = data.categories[c];
        page.text(M, y, c, { size: 8, color: INK });
        var barW = maxCat ? Math.max(2, (n / maxCat) * BAR_MAX) : 0;
        page.fill(BAR_X, y - 1, BAR_MAX, 6, "#eef2f9");
        page.fill(BAR_X, y - 1, barW, 6, ORANGE);
        page.text(M + leftW, y, String(n), { size: 8, bold: true, color: INK, align: "right" });
        y -= 13;
      });
    }

    var yRight = sectionTop;
    page.text(rightX, yRight, "PER OCCUPANT", { size: 8, bold: true, color: NAVY });
    yRight -= 14;
    var occRows = data.occupants.slice(0, 8);
    if (!occRows.length) {
      page.text(rightX, yRight, "No occupants logged in this period.", { size: 8, color: MUTED });
      yRight -= 14;
    } else {
      page.text(rightX, yRight, "NAME", { size: 6.5, color: MUTED });
      page.text(rightX + rightW - 74, yRight, "VISITS", { size: 6.5, color: MUTED, align: "right" });
      page.text(rightX + rightW, yRight, "HOURS*", { size: 6.5, color: MUTED, align: "right" });
      yRight -= 4;
      page.line(rightX, yRight, rightX + rightW, yRight, BORDER, 0.5);
      yRight -= 12;
      occRows.forEach(function (o) {
        page.text(rightX, yRight, fit(o.full_name, false, 8, rightW - 100), { size: 8, color: INK });
        page.text(rightX + rightW - 74, yRight, String(o.visits), { size: 8, color: INK, align: "right" });
        page.text(rightX + rightW, yRight, (o.minutes / 60).toFixed(1), { size: 8, color: INK, align: "right" });
        yRight -= 12;
      });
      if (data.occupants.length > occRows.length) {
        page.text(rightX, yRight, "+" + (data.occupants.length - occRows.length) +
          " more in the log table", { size: 7, color: MUTED });
        yRight -= 12;
      }
      page.text(rightX, yRight, "* includes visits that have not timed out yet",
        { size: 6, color: MUTED });
      yRight -= 10;
    }

    y = Math.min(y, yRight) - 16;

    page.text(M, y, "LOG ENTRIES", { size: 8, bold: true, color: NAVY });
    page.text(M + CONTENT_W, y, data.logs.length + " row(s)", { size: 7, color: MUTED, align: "right" });
    y -= 14;

    var cols = [
      { k: "date",       label: "DATE",         w: 62,  align: "left"  },
      { k: "occupant_id",label: "ID",           w: 54,  align: "left"  },
      { k: "full_name",  label: "OCCUPANT",     w: 156, align: "left"  },
      { k: "room",       label: "ROOM",         w: 40,  align: "left"  },
      { k: "time_in",    label: "TIME IN",      w: 56,  align: "left"  },
      { k: "time_out",   label: "TIME OUT",     w: 58,  align: "left"  },
      { k: "duration",   label: "DURATION",     w: 54,  align: "left"  },
      { k: "whereabouts",label: "WHEREABOUTS",  w: 150, align: "left"  },
      { k: "late",       label: "LATE",         w: 30,  align: "center" }
    ];
    var rowH = 15;

    function drawTableHeader(pg, top, withTitle) {
      var hy = top;
      if (withTitle) {
        pg.text(M, hy, "LOG ENTRIES (continued)", { size: 7.5, bold: true, color: NAVY });
        hy -= 12;
      }
      pg.fill(M, hy - 16, CONTENT_W, 16, HEAD_BG);
      var cx = M + 6;
      cols.forEach(function (c) {
        var lx = cx;
        if (c.align === "center") lx = cx + c.w / 2;
        else if (c.align === "right") lx = cx + c.w;
        pg.text(lx, hy - 11, c.label, { size: 6.5, bold: true, color: "#7a5a12", align: c.align === "center" ? "center" : "left" });
        cx += c.w;
      });
      return hy - 16;
    }

    var tableTop = y;
    y = drawTableHeader(page, tableTop, false);

    data.logs.forEach(function (row, i) {
      if (y - rowH < M + 34) {
        page = new Page(PAGE_W, PAGE_H);
        pages.push(page);
        y = PAGE_H - M;
        page.text(M, y, "LOGBUK", { size: 9, bold: true, color: NAVY });
        page.text(M + 44, y, "Monthly Log Report — " + period, { size: 9, color: MUTED });
        y -= 10;
        page.line(M, y, PAGE_W - M, y, BORDER, 0.5);
        y -= 12;
        y = drawTableHeader(page, y, true);
      }
      if (i % 2 === 1) page.fill(M, y - rowH + 3, CONTENT_W, rowH - 3, ROW_ALT);
      var x = M + 6;
      cols.forEach(function (c) {
        var val = c.k === "late" ? (row.late ? "YES" : "") : String(row[c.k] == null ? "" : row[c.k]);
        if (c.k === "duration" && row.open) val += " *";
        var color = INK;
        if (c.k === "late" && row.late) { color = "#e11d2e"; }
        if (c.k === "time_out" && row.open) { color = ORANGE; }
        var tx = x;
        if (c.align === "center") tx = x + c.w / 2;
        page.text(tx, y - rowH + 8, c.align === "center" ? val : fit(val, false, 7.5, c.w - 6), {
          size: 7.5, color: color, align: c.align === "center" ? "center" : "left"
        });
        x += c.w;
      });
      y -= rowH;
    });

    if (!data.logs.length) {
      page.text(M + 6, y - 14, "No log entries were recorded in " + period + ".", { size: 8, color: MUTED });
      y -= 24;
    } else if (data.totals.open > 0) {
      y -= 6;
      page.text(M + 6, y, "* still timed in — duration counted up to the moment this report was generated.",
        { size: 6.5, color: MUTED });
      y -= 10;
    }

    if (data.warnings && data.warnings.length) {

      if (y - 110 < M) {
        page = new Page(PAGE_W, PAGE_H);
        pages.push(page);
        y = PAGE_H - M - 20;
      }
      y -= 14;
      page.line(M, y, PAGE_W - M, y, BORDER, 0.5);
      y -= 16;
      page.text(M, y, "NOTES ON THIS REPORT", { size: 8, bold: true, color: NAVY });
      y -= 2;
      y -= 13;
      data.warnings.forEach(function (w) {
        if (y - 20 < M + 24) {
          page = new Page(PAGE_W, PAGE_H);
          pages.push(page);
          y = PAGE_H - M - 20;
        }
        page.text(M, y, "•", { size: 7.5, color: ORANGE });

        var words = String(w).split(" ");
        var line = "", lines = [];
        words.forEach(function (word) {
          var test = line ? line + " " + word : word;
          if (textWidth(test, false, 7.5) > CONTENT_W - 16) { lines.push(line); line = word; }
          else line = test;
        });
        if (line) lines.push(line);
        lines.forEach(function (l) {
          page.text(M + 10, y, l, { size: 7.5, color: "#7f1d1d" });
          y -= 11;
        });
        y -= 2;
      });
    }

    pages.forEach(function (pg, idx) {
      pg.line(M, M + 14, PAGE_W - M, M + 14, BORDER, 0.5);
      pg.text(M, M + 4, "LogBUk — Bicol University Dormitory Digitalized Log Book System", { size: 6.5, color: MUTED });
      pg.text(PAGE_W - M, M + 4,
        "Generated " + fmtStamp(stamp) + "   |   Page " + (idx + 1) + " of " + pages.length,
        { size: 6.5, color: MUTED, align: "right" });
    });

    return buildPdf(pages, {
      title: "LogBUk Monthly Log Report — " + period,
      date: pdfDate(stamp)
    });
  }

  function fmtDay(iso) {
    var p = String(iso).split("-");
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"][d.getMonth()]
      + " " + d.getDate();
  }
  function fmtStamp(d) {
    var h = d.getHours(), ap = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;
    var mm = d.getMinutes(); mm = mm < 10 ? "0" + mm : mm;
    return fmtDay(d.getFullYear() + "-" + (d.getMonth() + 1) + "-" + d.getDate()) + ", " + d.getFullYear() +
      " " + h + ":" + mm + " " + ap;
  }
  function pdfDate(d) {
    function p(n) { return (n < 10 ? "0" : "") + n; }
    return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
  }

  var modal = null, els = null, monthsCache = null;

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }

  function buildModal() {
    if (modal) return modal;

    modal = el("div", "report-modal");
    modal.id = "reportModal";
    modal.hidden = true;
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "reportModalTitle");

    var card = el("div", "report-modal__card");
    card.innerHTML =
      '<div class="report-modal__head">' +
        '<h2 id="reportModalTitle">Generate log report</h2>' +
        '<button type="button" class="report-modal__close" aria-label="Close">&times;</button>' +
      '</div>' +
      '<p class="report-modal__intro">Select the month and year, then generate a PDF of every ' +
        'log entry recorded in that month.</p>' +
      '<div class="report-modal__fields">' +
        '<label class="report-field"><span>Month</span>' +
          '<select id="reportMonth"></select></label>' +
        '<label class="report-field"><span>Year</span>' +
          '<select id="reportYear"></select></label>' +
      '</div>' +
      '<p class="report-modal__hint" id="reportHint">Loading the months that have data…</p>' +
      '<div class="report-modal__status" id="reportStatus" role="status" aria-live="polite"></div>' +
      '<div class="report-modal__actions">' +
        '<button type="button" class="btn-modal btn-modal--ghost" data-report-close>Cancel</button>' +
        '<button type="button" class="btn-modal btn-modal--primary" id="reportGenerate">' +
          'Generate log reports</button>' +
      '</div>';

    modal.appendChild(card);
    document.body.appendChild(modal);

    els = {
      month: modal.querySelector("#reportMonth"),
      year: modal.querySelector("#reportYear"),
      hint: modal.querySelector("#reportHint"),
      status: modal.querySelector("#reportStatus"),
      generate: modal.querySelector("#reportGenerate")
    };

    modal.addEventListener("click", function (e) {
      if (e.target === modal || e.target.closest("[data-report-close]") ||
          e.target.closest(".report-modal__close")) close();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && modal && !modal.hidden) close();
    });
    els.generate.addEventListener("click", generate);

    return modal;
  }

  function loadMonths() {
    els.hint.textContent = "Loading the months that have data…";
    els.generate.disabled = true;

    return fetch(API + "?action=months", { credentials: "same-origin" })
      .then(function (r) {
        if (r.status === 401) throw new Error("Please sign in as an administrator first.");
        if (!r.ok) throw new Error("The report service answered HTTP " + r.status + ".");
        return r.json();
      })
      .then(function (data) {
        monthsCache = data.months || [];
        var monthSel = els.month, yearSel = els.year;
        monthSel.innerHTML = "";
        yearSel.innerHTML = "";

        if (!monthsCache.length) {
          els.hint.textContent = "There are no log entries yet, so there is nothing to report.";
          els.generate.disabled = true;
          return;
        }

        var years = [];
        monthsCache.forEach(function (m) { if (years.indexOf(m.year) === -1) years.push(m.year); });

        years.forEach(function (y) {
          var o = el("option"); o.value = y; o.textContent = y; yearSel.appendChild(o);
        });

        fillMonths(monthsCache[0].year);

        els.hint.textContent = monthsCache.length + " month(s) with data. " +
          "Newest first.";
        els.generate.disabled = false;

        monthSel.addEventListener("change", paintHint);
        yearSel.addEventListener("change", function () {
          fillMonths(parseInt(yearSel.value, 10));
          paintHint();
        });
        paintHint();
      })
      .catch(function (err) {
        els.hint.textContent = err.message;
        els.generate.disabled = true;
      });
  }

  function fillMonths(year) {
    els.month.innerHTML = "";
    monthsCache.filter(function (m) { return m.year === year; }).forEach(function (m) {
      var o = el("option");
      o.value = m.month;
      o.textContent = MONTH_NAMES[m.month - 1] + " (" + m.logs + " log" + (m.logs === 1 ? "" : "s") + ")";
      els.month.appendChild(o);
    });
  }

  function selected() {
    var y = parseInt(els.year.value, 10);
    var m = parseInt(els.month.value, 10);
    return { year: y, month: m };
  }

  function paintHint() {
    var s = selected();
    var found = monthsCache && monthsCache.filter(function (x) {
      return x.year === s.year && x.month === s.month;
    })[0];
    if (!found) { els.hint.textContent = "No data for that month."; return; }
    var msg = found.logs + " log entr" + (found.logs === 1 ? "y" : "ies") +
      " in " + MONTH_NAMES[s.month - 1] + " " + s.year + ".";
    if (found.open_logs) msg += " " + found.open_logs + " still have no time-out.";
    els.hint.textContent = msg;
  }

  function setStatus(msg, kind) {
    els.status.textContent = msg || "";
    els.status.className = "report-modal__status" + (kind ? " is-" + kind : "");
  }

  function generate() {
    var s = selected();
    if (!s.year || !s.month) return;

    els.generate.disabled = true;
    els.generate.textContent = "Generating…";
    setStatus("Reading the log entries…");

    fetch(API + "?action=summary&year=" + s.year + "&month=" + s.month, { credentials: "same-origin" })
      .then(function (r) {
        if (r.status === 401) throw new Error("Please sign in as an administrator first.");
        if (!r.ok) throw new Error("The report service answered HTTP " + r.status + ".");
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || "The report could not be built.");
        setStatus("Building the PDF…");
        var blob = renderReport(data);
        var name = "LogBUk-log-report-" + s.year + "-" + (s.month < 10 ? "0" + s.month : s.month) + ".pdf";
        download(blob, name);
        setStatus("Saved " + name + " (" + Math.round(blob.size / 1024) + " KB).", "ok");
      })
      .catch(function (err) {
        setStatus(err.message, "bad");
      })
      .then(function () {
        els.generate.disabled = false;
        els.generate.textContent = "Generate log reports";
      });
  }

  function download(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(url);
      a.remove();
    }, 1500);
  }

  function open() {
    buildModal();
    modal.hidden = false;
    document.body.classList.add("report-modal-open");
    setStatus("");
    loadMonths();
    setTimeout(function () { if (els.year) els.year.focus(); }, 60);
  }

  function close() {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove("report-modal-open");
  }

  window.LogbukReports = {
    open: open,
    close: close,

    buildPdf: renderReport
  };

  function autowire() {
    document.addEventListener("click", function (e) {
      var trigger = e.target.closest("[data-report-modal]");
      if (trigger) { e.preventDefault(); open(); }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", autowire);
  } else {
    autowire();
  }
})();
