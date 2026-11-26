/* Builds the daily report PDF in the browser, no libraries needed. */
(function () {
  "use strict";

  var PAGE_W = 842, PAGE_H = 595, M = 36;
  var CONTENT_W = PAGE_W - M * 2;

  var C = {
    navy: "#0b3c9c", navyDark: "#0a2f7a", blue: "#3b82f6", orange: "#ff5a0f",
    orangeDark: "#c2410c", amber: "#ffe3a9", amberInk: "#7a5a12",
    page: "#f3f3f3", white: "#ffffff", ink: "#111111", muted: "#6b7280",
    line: "#eef0f4", zebra: "#f7f9fd",
    on: "#1d9bf0", onBg: "#dbeeff", late: "#e11d2e", lateBg: "#ffe4d2"
  };

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
    "”": "\\224", "•": "\\225", "…": "\\205", "ñ": "\\361", "Ñ": "\\321",
    "é": "\\351", "è": "\\350", "á": "\\341", "à": "\\340", "í": "\\355",
    "ó": "\\363", "ú": "\\372", "ü": "\\374", "¿": "\\277", "·": "\\267"
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
      out += (code >= 32 && code <= 126) ? ch : "?";
    }
    return out;
  }

  function textWidth(str, bold, size) {
    var table = bold ? HELV_B : HELV, w = 0;
    for (var i = 0; i < str.length; i++) {
      var idx = str.charCodeAt(i) - 32;
      w += (idx >= 0 && idx < table.length) ? table[idx] : 556;
    }
    return (w / 1000) * size;
  }

  function fit(str, bold, size, maxWidth) {
    if (textWidth(str, bold, size) <= maxWidth) return str;
    var ell = "...", lo = 0, hi = str.length;
    while (lo < hi) {
      var mid = Math.ceil((lo + hi) / 2);
      if (textWidth(str.slice(0, mid) + ell, bold, size) <= maxWidth) lo = mid;
      else hi = mid - 1;
    }
    return str.slice(0, lo) + ell;
  }

  function rgb(hex) {
    var h = String(hex).replace("#", "");
    return [0, 2, 4].map(function (i) {
      return (parseInt(h.slice(i, i + 2), 16) / 255).toFixed(3);
    }).join(" ");
  }
  function n(v) { return v.toFixed(2); }

  function Page() { this.ops = []; this.images = []; }
  Page.prototype.fill = function (x, y, w, h, color) {
    this.ops.push(rgb(color) + " rg " + n(x) + " " + n(y) + " " + n(w) + " " + n(h) + " re f");
    return this;
  };
  Page.prototype.roundRect = function (x, y, w, h, r, color) {
    var k = 0.5523 * r, o = this.ops;
    o.push(rgb(color) + " rg");
    o.push(n(x + r) + " " + n(y) + " m");
    o.push(n(x + w - r) + " " + n(y) + " l");
    o.push(n(x + w - r + k) + " " + n(y) + " " + n(x + w) + " " + n(y + r - k) + " " + n(x + w) + " " + n(y + r) + " c");
    o.push(n(x + w) + " " + n(y + h - r) + " l");
    o.push(n(x + w) + " " + n(y + h - r + k) + " " + n(x + w - r + k) + " " + n(y + h) + " " + n(x + w - r) + " " + n(y + h) + " c");
    o.push(n(x + r) + " " + n(y + h) + " l");
    o.push(n(x + r - k) + " " + n(y + h) + " " + n(x) + " " + n(y + h - r + k) + " " + n(x) + " " + n(y + h - r) + " c");
    o.push(n(x) + " " + n(y + r) + " l");
    o.push(n(x) + " " + n(y + r - k) + " " + n(x + r - k) + " " + n(y) + " " + n(x + r) + " " + n(y) + " c");
    o.push("h f");
    return this;
  };
  Page.prototype.line = function (x1, y1, x2, y2, color, width) {
    this.ops.push(rgb(color) + " RG " + n(width || 0.6) + " w " + n(x1) + " " + n(y1) + " m " + n(x2) + " " + n(y2) + " l S");
    return this;
  };
  Page.prototype.text = function (x, y, str, opts) {
    opts = opts || {};
    var size = opts.size || 9, bold = !!opts.bold;
    var s = String(str == null ? "" : str);
    if (opts.maxWidth) s = fit(s, bold, size, opts.maxWidth);
    var tx = x;
    if (opts.align === "right") tx = x - textWidth(s, bold, size);
    else if (opts.align === "center") tx = x - textWidth(s, bold, size) / 2;
    this.ops.push("BT " + rgb(opts.color || C.ink) + " rg /" + (bold ? "F2" : "F1") + " " +
      size.toFixed(2) + " Tf " + n(tx) + " " + n(y) + " Td (" + pdfString(s) + ") Tj ET");
    return this;
  };
  Page.prototype.widthOf = function (str, bold, size) { return textWidth(str, bold, size); };
  Page.prototype.image = function (x, y, w, h, name) {
    this.ops.push("q " + n(w) + " 0 0 " + n(h) + " " + n(x) + " " + n(y) + " cm /" + name + " Do Q");
    this.images.push(name);
    return this;
  };

  function buildPdf(pages, info, img) {
    var objs = [];
    var kids = [];
    var nPages = pages.length;
    var imgObj = img ? 0 : -1;

    var next = img ? 6 : 5;
    var pageNos = [];
    for (var p = 0; p < nPages; p++) { pageNos.push(next); kids.push(next + " 0 R"); next += 2; }
    if (img) imgObj = 5;

    objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    objs[2] = "<< /Type /Pages /Kids [" + kids.join(" ") + "] /Count " + nPages + " >>";
    objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    var binaries = {};
    if (img) {
      binaries[imgObj] = img.data;
      objs[imgObj] = "<< /Type /XObject /Subtype /Image /Width " + img.w + " /Height " + img.h +
        " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " + img.data.length + " >>";
    }

    for (var q = 0; q < nPages; q++) {
      var pg = pages[q], pNo = pageNos[q], cNo = pNo + 1;
      var xo = pg.images.length ? " /XObject << " + pg.images.map(function (nm) {
        return "/" + nm + " " + imgObj + " 0 R";
      }).join(" ") + " >>" : "";
      objs[pNo] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " + PAGE_W + " " + PAGE_H + "] " +
        "/Resources << /Font << /F1 3 0 R /F2 4 0 R >>" + xo + " >> /Contents " + cNo + " 0 R >>";
      var stream = pg.ops.join("\n");
      objs[cNo] = "<< /Length " + stream.length + " >>\nstream\n" + stream + "\nendstream";
    }

    var infoNo = next;
    objs[infoNo] = "<< /Title (" + pdfString(info.title) + ") /Author (LogBUk) /Creator (LogBUk Daily Report) /CreationDate (D:" + info.date + ") >>";

    var chunks = [], pos = 0;
    function push(str) {
      var b = new Uint8Array(str.length);
      for (var i = 0; i < str.length; i++) b[i] = str.charCodeAt(i) & 0xff;
      chunks.push(b); pos += b.length;
    }
    push("%PDF-1.4\n%\u00e2\u00e3\u00cf\u00d3\n");
    var total = infoNo, offsets = [];
    for (var o = 1; o <= total; o++) {
      if (!objs[o]) continue;
      offsets[o] = pos;
      push(o + " 0 obj\n");
      if (binaries[o]) {
        push(objs[o].split("/Length " + img.data.length)[0] + "/Length " + img.data.length + " >>\nstream\n");
        chunks.push(binaries[o]); pos += binaries[o].length;
        push("\nendstream\nendobj\n");
      } else {
        push(objs[o] + "\nendobj\n");
      }
    }
    var xref = pos, xl = "xref\n0 " + (total + 1) + "\n0000000000 65535 f \n";
    for (var k = 1; k <= total; k++) {
      xl += offsets[k] !== undefined ? ("0000000000" + offsets[k]).slice(-10) + " 00000 n \n" : "0000000000 65535 f \n";
    }
    push(xl + "trailer\n<< /Size " + (total + 1) + " /Root 1 0 R /Info " + infoNo + " 0 R >>\nstartxref\n" + xref + "\n%%EOF\n");

    return new Blob(chunks, { type: "application/pdf" });
  }

  function pad(v) { return (v < 10 ? "0" : "") + v; }
  function pdfDate(d) {
    return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) +
      pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
  }
  var MONTHS = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  var MON = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
  function fmtStamp(d) {
    var h = d.getHours(), ap = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;
    return MON[d.getMonth()] + " " + d.getDate() + ", " + d.getFullYear() + " " + h + ":" + pad(d.getMinutes()) + " " + ap;
  }
  function fmtLong(iso) {
    var p = String(iso).split("-");
    if (p.length !== 3) return String(iso);
    return MONTHS[+p[1] - 1] + " " + (+p[2]) + ", " + p[0];
  }

  var COLS = [
    { label: "Occupant",     key: "occupant",    w: 175, align: "left"   },
    { label: "Room",         key: "room",        w: 58,  align: "left"   },
    { label: "Date",         key: "date",        w: 72,  align: "left"   },
    { label: "Time In",      key: "time_in",     w: 68,  align: "left"   },
    { label: "Time Out",     key: "time_out",    w: 68,  align: "left"   },
    { label: "Whereabouts",  key: "whereabouts", w: 0,   align: "left"   },
    { label: "Entry Status", key: "status",      w: 88,  align: "center" }
  ];
  (function fitWidths() {
    var used = 0;
    COLS.forEach(function (c) { if (c.w) used += c.w; });
    COLS.forEach(function (c) { if (!c.w) c.w = CONTENT_W - used; });
  })();

  var HEAD_H = 24, ROW_H = 28, FOOT_Y = M + 14;
  var HEADER_H = 34;

  function drawHeader(pg, data) {
    var top = PAGE_H - M;
    pg.text(M, top - 16, data.title || "Report", { size: 15, bold: true, color: C.navy });
    pg.text(PAGE_W - M, top - 15, "Generated " + (data.generated || fmtStamp(new Date())),
      { size: 8.5, color: C.muted, align: "right" });
    pg.line(M, top - 26, PAGE_W - M, top - 26, C.line, 0.8);
    return top - HEADER_H;
  }

  function drawStatCards(pg, top, stats) {
    var items = [
      { label: "Time-Ins",     value: stats.time_ins  },
      { label: "Time-Outs",    value: stats.time_outs },
      { label: "Late Entries", value: stats.late      }
    ];
    var gap = 14, w = (CONTENT_W - gap * (items.length - 1)) / items.length, h = 56;
    items.forEach(function (it, i) {
      var x = M + i * (w + gap), y = top - h;
      pg.roundRect(x, y, w, h, 8, C.amber);
      pg.text(x + w / 2, y + 29, String(it.value), { size: 20, bold: true, color: C.navy, align: "center" });
      pg.text(x + w / 2, y + 11, it.label, { size: 7.5, bold: true, color: C.amberInk, align: "center" });
    });
    return top - h;
  }

  function drawTableHeader(pg, top) {
    pg.fill(M, top - HEAD_H, CONTENT_W, HEAD_H, C.amber);
    var cx = M;
    COLS.forEach(function (c) {
      var tx = cx + 10, al = "left";
      if (c.align === "center") { tx = cx + c.w / 2; al = "center"; }
      pg.text(tx, top - 15, c.label, { size: 7, bold: true, color: C.amberInk, align: al });
      cx += c.w;
    });
    return top - HEAD_H;
  }

  function drawBadge(pg, cx, cy, isLate) {
    var label = isLate ? "Late" : "On Time";
    var tw = pg.widthOf(label, true, 7);
    var w = tw + 18, h = 13;
    pg.roundRect(cx - w / 2, cy - h / 2, w, h, 6.5, isLate ? C.lateBg : C.onBg);
    pg.text(cx, cy - 2.6, label, { size: 7, bold: true, color: isLate ? C.orangeDark : C.on, align: "center" });
  }

  function drawFooter(pages, stamp) {
    pages.forEach(function (pg, idx) {
      pg.line(M, FOOT_Y, PAGE_W - M, FOOT_Y, C.line, 0.8);
      pg.text(M, FOOT_Y - 11, "LogBUk  |  Bicol University Dormitory Digitalized Log Book System",
        { size: 6.5, color: C.muted });
      pg.text(PAGE_W - M, FOOT_Y - 11,
        "Generated " + fmtStamp(stamp) + "     Page " + (idx + 1) + " of " + pages.length,
        { size: 6.5, color: C.muted, align: "right" });
    });
  }

  function continuationHeader(pg, data) {
    var top = PAGE_H - M;
    pg.text(M, top - 8, data.title || "Report", { size: 10, bold: true, color: C.navy });
    pg.text(PAGE_W - M, top - 8, "Generated " + (data.generated || fmtStamp(new Date())),
      { size: 8, color: C.muted, align: "right" });
    pg.line(M, top - 16, PAGE_W - M, top - 16, C.line, 0.8);
    return top - 24;
  }

  function render(data, img) {
    var stamp = new Date();
    var pages = [], page = new Page();
    pages.push(page);

    var y = drawHeader(page, data);
    y = drawStatCards(page, y, data.stats);
    y -= 18;

    y = drawTableHeader(page, y);

    if (!data.rows.length) {
      page.text(PAGE_W / 2, y - 30, data.emptyText || "No log records for this period",
        { size: 10, bold: true, color: C.navy, align: "center" });
      y -= 56;
    }

    data.rows.forEach(function (row, i) {
      if (y - ROW_H < M + 30) {
        page = new Page();
        pages.push(page);
        y = continuationHeader(page, data);
        y = drawTableHeader(page, y);
      }
      if (i % 2 === 1) page.fill(M, y - ROW_H, CONTENT_W, ROW_H, C.zebra);
      var cx = M, mid = y - ROW_H / 2;
      COLS.forEach(function (c) {
        if (c.key === "status") {
          drawBadge(page, cx + c.w / 2, mid, row.status === "Late");
        } else if (c.key === "occupant") {
          var name = row.full_name || row.occupant || "";
          var occId = row.occupant_id || "";
          page.text(cx + 10, y - 12, fit(name, true, 7.5, c.w - 16), {
            size: 7.5,
            bold: true,
            color: C.ink
          });
          if (occId) {
            page.text(cx + 10, y - 22, fit(occId, false, 6.5, c.w - 16), {
              size: 6.5,
              bold: false,
              color: C.muted
            });
          }
        } else {
          var val = row[c.key] == null ? "" : String(row[c.key]);
          var isPlaceholder = val === "-";
          page.text(cx + 10, mid - 2.6, fit(val, false, 7.5, c.w - 16), {
            size: 7.5,
            bold: false,
            color: isPlaceholder ? C.muted : C.ink
          });
        }
        cx += c.w;
      });
      page.line(M, y - ROW_H, PAGE_W - M, y - ROW_H, C.line, 0.6);
      y -= ROW_H;
    });

    drawFooter(pages, stamp);
    return buildPdf(pages, {
      title: data.title || "LogBUk Report",
      date: pdfDate(stamp)
    }, img);
  }

  function loadLogo(url) {
    return fetch(url, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error("logo " + r.status); return r.arrayBuffer(); })
      .then(function (buf) {
        return new Promise(function (resolve, reject) {
          var blob = new Blob([buf], { type: "image/jpeg" });
          var u = URL.createObjectURL(blob), im = new Image();
          im.onload = function () {
            URL.revokeObjectURL(u);
            resolve({ name: "Logo", data: new Uint8Array(buf), w: im.naturalWidth, h: im.naturalHeight });
          };
          im.onerror = function () { URL.revokeObjectURL(u); reject(new Error("logo decode")); };
          im.src = u;
        });
      })
      .catch(function () { return null; });
  }

  function logoUrl() {
    var here = window.location.pathname;
    var base = here.replace(/\/pages\/[^\/]*$/, "/");
    if (base === here) base = here.replace(/[^\/]*$/, "");
    return base + "assets/images/logo_report.png";
  }

  window.LogbukDailyReport = {

    build: function (data) {
      return loadLogo(logoUrl()).then(function (img) { return render(data, img); });
    },

    render: render,
    download: function (data, filename) {
      return this.build(data).then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1500);
        return blob;
      });
    }
  };
})();
