/* global LSHSAID, Chart */
(() => {
/* ==========================================================================
   Data payload — replaced by build.py
   ========================================================================== */
let DATA = null;

/* ==========================================================================
   State
   ========================================================================== */
const SITE_COLORS = {
  "CG Cookie":       "#f59a2f",   // primary amber
  "CG Cookie Docs":  "#f5c26b",   // butter amber
  "Superhive":       "#c13f5b",   // warm plum
  "Superhive Docs":  "#c1734a",   // toasted brown
};

const state = {
  rows: [],             // all rows across reports (+ session uploads)
  reports: [],
  sites: [],            // canonical site list from payload
  beaconMap: {},
  site: "ALL",          // current site selection
  filters: {
    from: null, to: null, type: "", res: "", q: "",
    chips: new Set(),   // quick-filter chips
  },
  page: 0, pageSize: 50,
  charts: {},
  expanded: new Set(),
  view: "overview",     // "overview" | "weekly" | "trends"
  trends: {
    range: "all",       // "8" | "12" | "26" | "all"
  },
  weekly: {
    primary: null,      // week-ending date string (e.g. "2026-04-23")
    compareTo: "__prev",// "__prev" | specific week-ending date
    extras: new Set(),  // additional weeks shown in the comparison table
  },
};

/* ==========================================================================
   Utilities
   ========================================================================== */
const TZ = "America/Chicago";

// Intl formatters, created once.
const _tzParts = new Intl.DateTimeFormat("en-US", {
  timeZone: TZ, hour12: false,
  year: "numeric", month: "2-digit", day: "2-digit",
  hour: "2-digit", minute: "2-digit", weekday: "short",
});
const _dowIndex = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

function toCentral(input) {
  if (!input) return null;
  const d = input instanceof Date ? input : new Date(input);
  if (isNaN(d)) return null;
  const parts = {};
  for (const p of _tzParts.formatToParts(d)) parts[p.type] = p.value;
  // Intl sometimes emits "24" for midnight; normalize.
  let hour = parseInt(parts.hour, 10);
  if (hour === 24) hour = 0;
  return {
    day: `${parts.year}-${parts.month}-${parts.day}`,
    hour,
    minute: parts.minute,
    dow: _dowIndex[parts.weekday],
    display: `${parts.year}-${parts.month}-${parts.day} ${String(hour).padStart(2, "0")}:${parts.minute}`,
  };
}

const fmt = {
  int: (n) => n == null ? "—" : n.toLocaleString("en-US"),
  pct: (n) => n == null || isNaN(n) ? "—" : (n * 100).toFixed(1) + "%",
  date: (s) => toCentral(s)?.day || "",
  datetime: (s) => toCentral(s)?.display || "",
  short: (s, n) => !s ? "" : (s.length > n ? s.slice(0, n) + "…" : s),
};

const TYPE_PILL = {
  ANSWER: "answer", CLARIFICATION: "clar", NO_ANSWER: "no_answer",
  HELP_OPTIONS: "help", HUMAN_REQUEST: "human",
  CLOSING: "other", GREETING: "other", MODERATION_FAILED: "other", ERROR: "no_answer",
};
const RES_PILL = {
  "Customer helped": "helped", "Customer not helped": "nothelped", "Escalation": "esc",
};
const RATING_CLASS = {
  "Great": "rating-great", "Okay": "rating-okay", "Not Good": "rating-bad",
};

const PALETTE = {
  resolution: {
    "Customer helped": "#9ec14a",         // olive green
    "Customer not helped": "#e05d41",     // warm terracotta
    "Escalation": "#f0b140",              // butter amber
  },
  rating: {
    "Great": "#9ec14a", "Okay": "#f0b140", "Not Good": "#e05d41", "": "#6c614e",
  },
  type: {
    ANSWER: "#f59a2f",           // primary amber
    CLARIFICATION: "#f5c26b",    // butter amber
    NO_ANSWER: "#e05d41",        // terracotta
    HELP_OPTIONS: "#e59640",     // orange
    HUMAN_REQUEST: "#c1734a",    // toasted brown
    CLOSING: "#9a8c74",
    GREETING: "#8a7c65",
    MODERATION_FAILED: "#7a6d58",
    ERROR: "#c13f2a",
  },
};

function colorFor(cat, key) {
  if (PALETTE[cat] && PALETTE[cat][key]) return PALETTE[cat][key];
  // deterministic fallback
  let h = 0; for (const c of key || "") h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return `hsl(${h % 360}, 55%, 62%)`;
}

function esc(s) {
  return String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function daysBetween(a, b) {
  const d = (new Date(b) - new Date(a)) / 86400000;
  return Math.max(1, Math.round(d) + 1);
}

function groupBy(arr, keyFn) {
  const m = new Map();
  for (const x of arr) {
    const k = keyFn(x);
    m.set(k, (m.get(k) || 0) + 1);
  }
  return m;
}

function weekKey(iso) {
  const c = toCentral(iso);
  if (!c) return null;
  const [y, m, d] = c.day.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d, 12));  // noon-UTC anchor avoids DST edges
  const dow = dt.getUTCDay() || 7;                 // Sun=0 → 7 so Mon=1 becomes the week start
  dt.setUTCDate(dt.getUTCDate() - dow + 1);
  return dt.toISOString().slice(0, 10);
}

// Week-ending Thursday for a row (smallest Thursday >= Central-time date).
// Mirrors build.py so uploaded CSVs bucket the same way as baked-in data.
function weekEndingThursday(iso) {
  const c = toCentral(iso);
  if (!c) return null;
  const [y, m, d] = c.day.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d, 12));  // noon-UTC avoids DST edges
  // JS getUTCDay: Sun=0 ... Thu=4 ... Sat=6
  const offset = (4 - dt.getUTCDay() + 7) % 7;
  dt.setUTCDate(dt.getUTCDate() + offset);
  return dt.toISOString().slice(0, 10);
}

// Given a week-ending Thursday, return the Friday 7 days earlier (inclusive
// window start). The window is [start, end] both inclusive.
function weekWindow(endingThu) {
  if (!endingThu) return [null, null];
  const [y, m, d] = endingThu.split("-").map(Number);
  const end = new Date(Date.UTC(y, m - 1, d, 12));
  const start = new Date(end.getTime());
  start.setUTCDate(start.getUTCDate() - 6);
  return [start.toISOString().slice(0, 10), end.toISOString().slice(0, 10)];
}

// Pretty label: "Apr 17 – Apr 23, 2026"
function formatWeekLabel(endingThu) {
  if (!endingThu) return "";
  const [s, e] = weekWindow(endingThu);
  const opts = { month: "short", day: "numeric" };
  const sDate = new Date(s + "T12:00:00Z");
  const eDate = new Date(e + "T12:00:00Z");
  return `${sDate.toLocaleDateString("en-US", { ...opts, timeZone: "UTC" })} – ${eDate.toLocaleDateString("en-US", { ...opts, year: "numeric", timeZone: "UTC" })}`;
}

function priorWeek(endingThu) {
  if (!endingThu) return null;
  const [y, m, d] = endingThu.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d, 12));
  dt.setUTCDate(dt.getUTCDate() - 7);
  return dt.toISOString().slice(0, 10);
}

/* ==========================================================================
   Filtering
   ========================================================================== */
function siteRows() {
  // rows that belong to the currently selected site (but ignoring all other filters)
  if (state.site === "ALL") return state.rows;
  return state.rows.filter((r) => r.site === state.site);
}

function applyFilters() {
  const { from, to, type, res, q, chips } = state.filters;
  const qlc = q.toLowerCase().trim();
  return state.rows.filter((r) => {
    if (state.site !== "ALL" && r.site !== state.site) return false;
    if (from && r._day && r._day < from) return false;
    if (to && r._day && r._day > to) return false;
    if (type && r.type !== type) return false;
    if (res && r.res !== res) return false;
    if (chips.size) {
      for (const c of chips) {
        const [cat, val] = c.split(":");
        if (cat === "type" && r.type !== val) return false;
        if (cat === "res" && r.res !== val) return false;
        if (cat === "rating" && r.rating !== val) return false;
        if (cat === "end" && r.end !== val) return false;
      }
    }
    if (qlc) {
      const hay = (r.q + " " + r.a + " " + r.comment + " " + r.cust).toLowerCase();
      if (!hay.includes(qlc)) return false;
    }
    return true;
  });
}

/* ==========================================================================
   KPI calculation
   ========================================================================== */
function computeKpis(rows) {
  const total = rows.length;
  const sessions = new Set(rows.map((r) => r.sid).filter(Boolean)).size;
  // "Identified customers" = rows whose Help Scout Customer ID column is populated.
  // Help Scout only emits a Customer ID when the Beacon has identified the user
  // (e.g. Beacon('identify', ...) on the host site). Some Beacons rarely set it,
  // so identifiedRows can be a tiny fraction of total — keep the rate honest by
  // dividing identifiedRows (not total) by the identified-customer count.
  const identifiedRows = rows.filter((r) => r.cust).length;
  const customers = new Set(rows.map((r) => r.cust).filter(Boolean)).size;
  const res = groupBy(rows, (r) => r.res || "");
  const helped = res.get("Customer helped") || 0;
  const notHelped = res.get("Customer not helped") || 0;
  const esc = res.get("Escalation") || 0;
  const resBase = helped + notHelped + esc;
  const resRate = resBase ? helped / resBase : null;
  const escRate = resBase ? esc / resBase : null;
  const rated = rows.filter((r) => r.rating);
  const ratingScore = rated.length
    ? rated.reduce((s, r) => s + ({ "Great": 1, "Okay": 0.5, "Not Good": 0 })[r.rating] * 1, 0) / rated.length
    : null;
  return { total, sessions, identifiedRows, customers, resRate, escRate, ratingScore, rated: rated.length };
}

function renderKpis(k) {
  const el = document.getElementById("kpis");
  const cards = [
    { lbl: "Interactions", val: fmt.int(k.total), delta: `${fmt.int(k.sessions)} sessions` },
    { lbl: "Identified customers", val: fmt.int(k.customers), delta: k.customers ? `${(k.identifiedRows / k.customers).toFixed(1)} turns / cust` : "no Customer ID on imported rows" },
    { lbl: "Resolution rate", val: fmt.pct(k.resRate), delta: "customers helped" },
    { lbl: "Escalation rate", val: fmt.pct(k.escRate), delta: "routed to humans" },
    { lbl: "Satisfaction score", val: k.ratingScore != null ? (k.ratingScore * 100).toFixed(0) + "%" : "—", delta: `${fmt.int(k.rated)} ratings · Great=100, Okay=50, Not Good=0` },
    { lbl: "Avg turns / session", val: k.sessions ? (k.total / k.sessions).toFixed(1) : "—", delta: "incl. clarifications" },
  ];
  el.innerHTML = cards.map((c) =>
    `<div class="kpi"><div class="lbl">${esc(c.lbl)}</div><div class="val">${esc(c.val)}</div><div class="delta">${esc(c.delta)}</div></div>`
  ).join("");
}

/* ==========================================================================
   Charts
   ========================================================================== */
Chart.defaults.color = "#a89782";
Chart.defaults.font.family = "Inter, -apple-system, sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.borderColor = "rgba(245,158,78,.06)";
Chart.defaults.plugins.legend.position = "bottom";
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.boxHeight = 10;
Chart.defaults.plugins.legend.labels.padding = 14;
Chart.defaults.plugins.tooltip.backgroundColor = "#221c14";
Chart.defaults.plugins.tooltip.titleColor = "#f6eedc";
Chart.defaults.plugins.tooltip.bodyColor = "#f5c26b";
Chart.defaults.plugins.tooltip.borderColor = "rgba(245,154,47,.2)";
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.displayColors = true;

function destroy(name) {
  if (state.charts[name]) { state.charts[name].destroy(); delete state.charts[name]; }
}

function mkCanvas(id) {
  const el = document.getElementById(id);
  return el;
}

/* --- time series: interactions per day stacked by resolution --- */
function renderTimeSeries(rows) {
  destroy("ts");
  const byDay = new Map();
  const resKeys = ["Customer helped", "Customer not helped", "Escalation", ""];
  for (const r of rows) {
    const d = r._day; if (!d) continue;
    if (!byDay.has(d)) byDay.set(d, { "Customer helped": 0, "Customer not helped": 0, "Escalation": 0, "": 0 });
    byDay.get(d)[r.res || ""] = (byDay.get(d)[r.res || ""] || 0) + 1;
  }
  const days = [...byDay.keys()].sort();
  document.getElementById("ts-meta").textContent = days.length ? `${days[0]} → ${days[days.length-1]}` : "";
  const datasets = resKeys.map((k) => ({
    label: k || "Unknown",
    data: days.map((d) => byDay.get(d)[k] || 0),
    backgroundColor: k ? colorFor("resolution", k) + "d0" : "#6c614e80",
    borderColor: k ? colorFor("resolution", k) : "#6c614e",
    borderWidth: 0, borderRadius: 4,
    stack: "s1",
  }));
  state.charts.ts = new Chart(mkCanvas("c-timeseries"), {
    type: "bar",
    data: { labels: days, datasets },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { mode: "index", intersect: false },
      scales: {
        x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 16 } },
        y: { stacked: true, grid: { color: "rgba(245,158,78,.06)" }, beginAtZero: true },
      },
      plugins: { legend: { position: "bottom" } },
    },
  });
}

/* --- donut helper --- */
function donut(id, countsMap, palette) {
  destroy(id);
  const entries = [...countsMap.entries()].filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
  const labels = entries.map(([k]) => k || "Unknown");
  const data = entries.map(([, v]) => v);
  const colors = entries.map(([k]) => colorFor(palette, k));
  const total = data.reduce((a, b) => a + b, 0);
  state.charts[id] = new Chart(mkCanvas(id), {
    type: "doughnut",
    data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: "#1e1a12", borderWidth: 2 }] },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: "68%",
      plugins: {
        legend: { position: "bottom" },
        tooltip: { callbacks: {
          label: (ctx) => `${ctx.label}: ${fmt.int(ctx.parsed)} (${((ctx.parsed/total)*100).toFixed(1)}%)`
        } },
      },
      onClick: (_e, els) => {
        if (!els.length) return;
        const label = labels[els[0].index];
        toggleChip(`${palette}:${label === "Unknown" ? "" : label}`);
      },
    },
  });
}

/* --- horizontal bar --- */
function hBar(id, entries, color) {
  destroy(id);
  const labels = entries.map((e) => e[0] || "—");
  const data = entries.map((e) => e[1]);
  state.charts[id] = new Chart(mkCanvas(id), {
    type: "bar",
    data: { labels, datasets: [{ data, backgroundColor: color, borderRadius: 4, borderSkipped: false }] },
    options: {
      indexAxis: "y", responsive: true, maintainAspectRatio: false,
      layout: { padding: { left: 4, right: 12 } },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { title: (items) => items[0].label } },
      },
      scales: {
        x: { grid: { color: "rgba(245,158,78,.06)" }, beginAtZero: true },
        y: {
          grid: { display: false },
          ticks: {
            autoSkip: false,
            font: { size: 11 },
            // Wrap long labels onto two lines so Chart.js reserves enough room.
            callback: function (value) {
              const lbl = this.getLabelForValue(value);
              if (lbl.length <= 32) return lbl;
              // break roughly in the middle at a space
              const mid = Math.floor(lbl.length / 2);
              const left = lbl.lastIndexOf(" ", mid);
              const right = lbl.indexOf(" ", mid);
              const breakAt = (left !== -1 && (mid - left) <= (right - mid || 99)) ? left : (right !== -1 ? right : mid);
              return [lbl.slice(0, breakAt).trim(), lbl.slice(breakAt).trim()];
            },
          },
        },
      },
    },
  });
}

/* --- by hour of day / by day of week --- */
function fmtHour12(h) {
  if (h === 0) return "12 AM";
  if (h === 12) return "12 PM";
  return h < 12 ? `${h} AM` : `${h - 12} PM`;
}

function renderHourChart(rows) {
  destroy("hour");
  const counts = Array(24).fill(0);
  let total = 0;
  for (const r of rows) {
    const c = toCentral(r.date);
    if (!c) continue;
    counts[c.hour]++; total++;
  }
  const max = Math.max(...counts);
  const peakHour = counts.indexOf(max);
  const meta = document.getElementById("c-hour-meta");
  if (meta) meta.textContent = total
    ? `peak ${fmtHour12(peakHour)} · ${max} interactions`
    : "";

  const labels = [];
  for (let h = 0; h < 24; h++) labels.push(fmtHour12(h).replace(" ", ""));

  const colors = counts.map((v) => {
    const a = max ? 0.35 + 0.65 * (v / max) : 0.35;
    return `rgba(245,154,47,${a})`;
  });

  state.charts.hour = new Chart(mkCanvas("c-hour"), {
    type: "bar",
    data: { labels, datasets: [{ data: counts, backgroundColor: colors, borderRadius: 4, borderSkipped: false }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => `${fmtHour12(items[0].dataIndex)} (Central time)`,
            label: (ctx) => {
              const pct = total ? ` · ${((ctx.parsed.y / total) * 100).toFixed(1)}%` : "";
              return `${ctx.parsed.y} interaction${ctx.parsed.y === 1 ? "" : "s"}${pct}`;
            },
          },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 12, font: { size: 10 } } },
        y: { grid: { color: "rgba(245,158,78,.06)" }, beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });
}

function renderDowChart(rows) {
  destroy("dow");
  const dowLabels = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  const dowFull   = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const counts = Array(7).fill(0);
  let total = 0;
  for (const r of rows) {
    const c = toCentral(r.date);
    if (!c) continue;
    counts[c.dow]++; total++;
  }
  const max = Math.max(...counts);
  const peak = counts.indexOf(max);
  const meta = document.getElementById("c-dow-meta");
  if (meta) meta.textContent = total
    ? `peak ${dowFull[peak]} · ${max} interactions`
    : "";

  const colors = counts.map((v) => {
    const a = max ? 0.35 + 0.65 * (v / max) : 0.35;
    return `rgba(245,154,47,${a})`;
  });

  state.charts.dow = new Chart(mkCanvas("c-dow"), {
    type: "bar",
    data: { labels: dowLabels, datasets: [{ data: counts, backgroundColor: colors, borderRadius: 4, borderSkipped: false }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => dowFull[items[0].dataIndex],
            label: (ctx) => {
              const pct = total ? ` · ${((ctx.parsed.y / total) * 100).toFixed(1)}%` : "";
              return `${ctx.parsed.y} interaction${ctx.parsed.y === 1 ? "" : "s"}${pct}`;
            },
          },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { grid: { color: "rgba(245,158,78,.06)" }, beginAtZero: true, ticks: { precision: 0 } },
      },
    },
  });
}

/* --- top referenced articles (hand-built list) --- */
function renderArticles(rows) {
  const map = new Map(); // title -> {count, url}
  for (const r of rows) for (const a of (r.arts || [])) {
    if (!a.t) continue;
    const cur = map.get(a.t) || { count: 0, url: a.u || "" };
    cur.count++;
    if (!cur.url && a.u) cur.url = a.u;
    map.set(a.t, cur);
  }
  const top = [...map.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, 12);
  document.getElementById("articles-meta").textContent = `${map.size} unique`;
  const el = document.getElementById("articles-list");
  if (!top.length) { el.innerHTML = '<div class="empty">No article references in the current filter.</div>'; return; }
  const max = top[0][1].count;
  el.innerHTML = top.map(([title, meta], i) => {
    const pct = Math.max(3, (meta.count / max) * 100);
    const rank = String(i + 1).padStart(2, "0");
    const titleHtml = meta.url
      ? `<a class="article-title" href="${esc(meta.url)}" target="_blank" title="${esc(title)}" style="color:inherit;text-decoration:none;">${esc(title)}</a>`
      : `<span class="article-title" title="${esc(title)}">${esc(title)}</span>`;
    return `<div class="article-item">
      <div class="article-rank">${rank}</div>
      <div class="article-body">
        ${titleHtml}
        <div class="article-bar-track"><div class="article-bar" style="width:${pct.toFixed(1)}%;"></div></div>
      </div>
      <div class="article-count">${fmt.int(meta.count)}</div>
    </div>`;
  }).join("");
}

/* --- comments list --- */
function renderComments(rows) {
  const withC = rows.filter((r) => r.comment).sort((a, b) => a.date < b.date ? 1 : -1);
  document.getElementById("comments-meta").textContent = `${withC.length} total`;
  const el = document.getElementById("comments-list");
  if (!withC.length) { el.innerHTML = '<div class="empty">No comments in current filter.</div>'; return; }
  el.innerHTML = withC.slice(0, 50).map((r) => {
    const cls = r.rating === "Great" ? "great" : r.rating === "Okay" ? "okay" : "";
    return `<div class="comment ${cls}">
      <div class="head"><span>${esc(fmt.datetime(r.date))}</span> · <span>${esc(r.rating || "—")}</span> · <span>${esc(r.src)}</span></div>
      <div class="body">${esc(r.comment)}</div>
      <div class="q">Q: ${esc(fmt.short(r.q, 140))}</div>
    </div>`;
  }).join("");
}

/* --- table --- */
function renderTable(rows) {
  const sorted = rows.slice().sort((a, b) => a.date < b.date ? 1 : -1);
  const total = sorted.length;
  const pages = Math.max(1, Math.ceil(total / state.pageSize));
  if (state.page >= pages) state.page = pages - 1;
  if (state.page < 0) state.page = 0;
  const slice = sorted.slice(state.page * state.pageSize, (state.page + 1) * state.pageSize);
  const tbody = document.getElementById("tbody");
  if (!slice.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="empty">No interactions match the current filter.</td></tr>`;
  } else {
    tbody.innerHTML = slice.map((r) => {
      const expanded = state.expanded.has(r.sid + r.date);
      const typePill = TYPE_PILL[r.type] || "other";
      const resPill = RES_PILL[r.res] || "other";
      const rCls = RATING_CLASS[r.rating] || "";
      const siteCol = SITE_COLORS[r.site] || "#8b93c4";
      const row = `<tr class="clickable" data-key="${esc(r.sid + r.date)}">
        <td style="font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--muted);">${esc(fmt.datetime(r.date))}</td>
        <td><span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:${siteCol};"><span style="width:7px;height:7px;border-radius:50%;background:${siteCol};display:inline-block;"></span>${esc(r.site || "—")}</span></td>
        <td class="q-cell"><div class="q">${esc(fmt.short(r.q, 180))}</div>
          <div class="meta">${esc(r.sid.slice(0, 8))} · ${esc(r.cust || "anon")}</div></td>
        <td><span class="pill ${typePill}">${esc(r.type || "—")}</span></td>
        <td><span class="pill ${resPill}">${esc(r.res || "—")}</span></td>
        <td><span class="${rCls}">${esc(r.rating || "—")}</span></td>
      </tr>`;
      const detail = expanded
        ? `<tr class="expanded-row"><td colspan="6">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
              <div><div style="color:var(--muted);font-size:11px;text-transform:uppercase;margin-bottom:6px;">Question</div>
                <div class="answer-preview">${esc(r.q)}</div></div>
              <div><div style="color:var(--muted);font-size:11px;text-transform:uppercase;margin-bottom:6px;">Answer</div>
                <div class="answer-preview">${esc(r.a)}</div></div>
            </div>
            ${r.comment ? `<div style="margin-top:14px;padding:10px 12px;background:rgba(224,93,65,.09);border-left:3px solid var(--bad);border-radius:8px;">
              <div style="color:var(--muted);font-size:11px;margin-bottom:4px;">Comment (${esc(r.rating)})</div>
              <div>${esc(r.comment)}</div></div>` : ""}
            ${r.arts && r.arts.length ? `<div style="margin-top:14px;">
              <div style="color:var(--muted);font-size:11px;text-transform:uppercase;margin-bottom:6px;">Articles referenced</div>
              ${r.arts.map((a) => `<div style="font-size:12px;">• ${esc(a.t)} ${a.u ? `<a href="${esc(a.u)}" target="_blank" style="color:var(--accent);">↗</a>` : ""}</div>`).join("")}
            </div>` : ""}
          </td></tr>`
        : "";
      return row + detail;
    }).join("");
  }
  document.getElementById("pag-info").textContent =
    `${fmt.int(total)} rows · page ${state.page + 1}/${pages}`;
  document.getElementById("pag-prev").disabled = state.page <= 0;
  document.getElementById("pag-next").disabled = state.page >= pages - 1;
  document.getElementById("table-meta").textContent = `(${fmt.int(total)})`;
}

/* --- quick chips --- */
function toggleChip(c) {
  if (state.filters.chips.has(c)) state.filters.chips.delete(c);
  else state.filters.chips.add(c);
  renderChipset();
  renderSiteSwitch();
  render();
}

function renderChipset() {
  const el = document.getElementById("quick-chips");
  const chips = [...state.filters.chips];
  if (!chips.length) { el.innerHTML = ""; return; }
  el.innerHTML = '<span style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;padding:5px 0;">Active filters:</span>'
    + chips.map((c) => {
      const [cat, val] = c.split(":");
      return `<span class="chip active" data-c="${esc(c)}">${esc(cat)}: ${esc(val || "—")} ✕</span>`;
    }).join("")
    + `<span class="chip" data-clear="1">Clear all</span>`;
  el.querySelectorAll("[data-c]").forEach((n) => n.onclick = () => toggleChip(n.dataset.c));
  el.querySelector("[data-clear]").onclick = () => { state.filters.chips.clear(); renderChipset(); renderSiteSwitch(); render(); };
}

/* ==========================================================================
   Weekly view
   ========================================================================== */
function weeklyScopeRows() {
  // Like siteRows(), but also ignores Overview-tab filters — the Weekly tab
  // has its own controls and shouldn't inherit date/type/res/search state.
  if (state.site === "ALL") return state.rows;
  return state.rows.filter((r) => r.site === state.site);
}

function availableWeeks() {
  // Weeks are determined by the dataset as a whole, not by the currently
  // selected site. A site may have 0 rows in a given week — that's fine; the
  // KPIs will read as zero/empty and the picker stays stable across sites.
  const weeks = new Set();
  for (const r of state.rows) if (r.week) weeks.add(r.week);
  return [...weeks].sort();          // ascending by date
}

function rowsForWeek(week) {
  if (!week) return [];
  return weeklyScopeRows().filter((r) => r.week === week);
}

// `dir` is +1 when higher-is-better (helped rate, interactions), -1 when
// lower-is-better (escalation rate, avg turns per session).
function deltaPill(curr, prev, isPct, dir = 1) {
  if (prev == null || curr == null) {
    return `<span class="delta-pill na">—</span>`;
  }
  const diff = curr - prev;
  if (Math.abs(diff) < 1e-9) {
    return `<span class="delta-pill flat">flat</span>`;
  }
  const favorable = (diff * dir) > 0;
  const magnitude = isPct
    ? (Math.abs(diff) * 100).toFixed(1) + "pp"
    : (prev === 0 ? "∞" : ((Math.abs(diff) / Math.abs(prev)) * 100).toFixed(0) + "%");
  const arrow = diff > 0 ? "▲" : "▼";
  return `<span class="delta-pill ${favorable ? "up" : "down"}">${arrow} ${magnitude}</span>`;
}

function renderWeekPicker() {
  const weeks = availableWeeks();
  const primarySel = document.getElementById("wk-primary");
  const compareSel = document.getElementById("wk-compare");
  const picker = document.getElementById("wk-picker");

  if (!weeks.length) {
    primarySel.innerHTML = '<option value="">No weekly data</option>';
    compareSel.innerHTML = '<option value="">—</option>';
    picker.innerHTML = '<div class="empty" style="padding:8px;">No weekly data for this site.</div>';
    return;
  }

  // Default primary = latest week. If current selection is no longer valid, reset.
  if (!state.weekly.primary || !weeks.includes(state.weekly.primary)) {
    state.weekly.primary = weeks[weeks.length - 1];
  }

  // Most recent first in the dropdown.
  const weeksDesc = weeks.slice().reverse();
  primarySel.innerHTML = weeksDesc.map((w) =>
    `<option value="${esc(w)}" ${w === state.weekly.primary ? "selected" : ""}>Week ending ${esc(w)} — ${esc(formatWeekLabel(w))}</option>`
  ).join("");

  const compareOpts = [`<option value="__prev">Prior week (auto)</option>`]
    .concat(weeksDesc
      .filter((w) => w !== state.weekly.primary)
      .map((w) => `<option value="${esc(w)}" ${w === state.weekly.compareTo ? "selected" : ""}>Week ending ${esc(w)}</option>`));
  compareSel.innerHTML = compareOpts.join("");

  // Multi-pick chips. Primary is always present; chips toggle "extras".
  picker.innerHTML = weeksDesc.map((w) => {
    let cls = "chip";
    if (w === state.weekly.primary) cls += " primary";
    else if (state.weekly.extras.has(w)) cls += " compare";
    return `<span class="${cls}" data-wk="${esc(w)}" title="${esc(formatWeekLabel(w))}">${esc(w)}</span>`;
  }).join("");

  picker.querySelectorAll("[data-wk]").forEach((el) => {
    el.onclick = () => {
      const w = el.dataset.wk;
      if (w === state.weekly.primary) return; // can't toggle the primary week off
      if (state.weekly.extras.has(w)) state.weekly.extras.delete(w);
      else state.weekly.extras.add(w);
      renderWeekly();
    };
  });
}

function renderWeeklyKpis() {
  const el = document.getElementById("wk-kpis");
  const primary = state.weekly.primary;
  if (!primary) { el.innerHTML = ""; return; }

  const primRows = rowsForWeek(primary);
  const cmpKey = state.weekly.compareTo === "__prev" ? priorWeek(primary) : state.weekly.compareTo;
  const cmpRows = cmpKey ? rowsForWeek(cmpKey) : [];
  const hasCmp = cmpRows.length > 0;

  const a = computeKpis(primRows);
  const b = computeKpis(cmpRows);
  const cmpLbl = hasCmp
    ? `vs. week ending ${cmpKey} (${formatWeekLabel(cmpKey)})`
    : (cmpKey ? `no data for week ${cmpKey}` : "no comparison");

  const cards = [
    { lbl: "Interactions", val: fmt.int(a.total), curr: a.total, prev: hasCmp ? b.total : null, pct: false, dir: 1,  meta: `${fmt.int(a.sessions)} sessions` },
    { lbl: "Identified customers", val: fmt.int(a.customers), curr: a.customers, prev: hasCmp ? b.customers : null, pct: false, dir: 1, meta: a.customers ? `${(a.identifiedRows / a.customers).toFixed(1)} turns / cust` : "no Customer ID on imported rows" },
    { lbl: "Resolution rate",  val: fmt.pct(a.resRate), curr: a.resRate, prev: hasCmp ? b.resRate : null, pct: true, dir: 1, meta: "customers helped" },
    { lbl: "Escalation rate",  val: fmt.pct(a.escRate), curr: a.escRate, prev: hasCmp ? b.escRate : null, pct: true, dir: -1, meta: "routed to humans" },
    { lbl: "Satisfaction score", val: a.ratingScore != null ? (a.ratingScore * 100).toFixed(0) + "%" : "—",
      curr: a.ratingScore, prev: hasCmp ? b.ratingScore : null, pct: true, dir: 1, meta: `${fmt.int(a.rated)} ratings` },
    { lbl: "Avg turns / session", val: a.sessions ? (a.total / a.sessions).toFixed(1) : "—",
      curr: a.sessions ? a.total / a.sessions : null,
      prev: hasCmp && b.sessions ? b.total / b.sessions : null, pct: false, dir: -1, meta: "incl. clarifications" },
  ];

  el.innerHTML = cards.map((c) =>
    `<div class="kpi wk-kpi">
       <div class="lbl">${esc(c.lbl)}</div>
       <div class="val">${esc(c.val)}</div>
       ${deltaPill(c.curr, c.prev, c.pct, c.dir)}
       <div class="delta">${esc(c.meta)}</div>
       <div class="cmp-lbl">${esc(cmpLbl)}</div>
     </div>`
  ).join("");
}

function renderWeeklyDailyChart() {
  destroy("wk-ts");
  const primary = state.weekly.primary;
  if (!primary) return;
  const [start, end] = weekWindow(primary);
  // Build 7 ordered day buckets.
  const days = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(start + "T12:00:00Z");
    d.setUTCDate(d.getUTCDate() + i);
    days.push(d.toISOString().slice(0, 10));
  }
  const resKeys = ["Customer helped", "Customer not helped", "Escalation", ""];
  const buckets = new Map(days.map((d) => [d, { "Customer helped": 0, "Customer not helped": 0, "Escalation": 0, "": 0 }]));
  for (const r of rowsForWeek(primary)) {
    const d = r._day; if (!buckets.has(d)) continue;
    buckets.get(d)[r.res || ""]++;
  }
  const datasets = resKeys.map((k) => ({
    label: k || "Unknown",
    data: days.map((d) => buckets.get(d)[k] || 0),
    backgroundColor: k ? colorFor("resolution", k) + "d0" : "#6c614e80",
    borderColor: k ? colorFor("resolution", k) : "#6c614e",
    borderWidth: 0, borderRadius: 4, stack: "s1",
  }));
  document.getElementById("wk-ts-meta").textContent = `${start} → ${end} (Fri → Thu)`;
  state.charts["wk-ts"] = new Chart(mkCanvas("wk-c-timeseries"), {
    type: "bar",
    data: { labels: days, datasets },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { mode: "index", intersect: false },
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, grid: { color: "rgba(245,158,78,.06)" }, beginAtZero: true },
      },
      plugins: { legend: { position: "bottom" } },
    },
  });
}

function renderWeeklyDonuts() {
  const rows = rowsForWeek(state.weekly.primary);
  donut("wk-c-resolution", groupBy(rows, (r) => r.res || ""), "resolution");
  donut("wk-c-answertype", groupBy(rows, (r) => r.type || ""), "type");
  donut("wk-c-rating", groupBy(rows.filter((r) => r.rating), (r) => r.rating), "rating");
}

function renderWeeklyCompareTable() {
  const primary = state.weekly.primary;
  const extras = [...state.weekly.extras].sort().reverse();
  const cmpKey = state.weekly.compareTo === "__prev" ? priorWeek(primary) : state.weekly.compareTo;
  // Columns: primary first, then compareTo (if distinct and not in extras), then extras.
  const cols = [primary];
  if (cmpKey && cmpKey !== primary && !extras.includes(cmpKey)) cols.push(cmpKey);
  for (const w of extras) if (!cols.includes(w)) cols.push(w);

  const table = document.getElementById("wk-compare-table");
  const meta = document.getElementById("wk-compare-meta");
  meta.textContent = cols.length > 1 ? `(${cols.length} weeks)` : "(select more weeks on the left to compare)";

  if (!primary) { table.innerHTML = ""; return; }

  const kpiByCol = Object.fromEntries(cols.map((w) => [w, computeKpis(rowsForWeek(w))]));

  const metrics = [
    { key: "total", label: "Interactions", pct: false, dir: 1 },
    { key: "sessions", label: "Sessions", pct: false, dir: 1 },
    { key: "customers", label: "Identified customers", pct: false, dir: 1 },
    { key: "resRate", label: "Resolution rate", pct: true, dir: 1 },
    { key: "escRate", label: "Escalation rate", pct: true, dir: -1 },
    { key: "ratingScore", label: "Satisfaction score", pct: true, dir: 1 },
    { key: "rated", label: "# rated", pct: false, dir: 1 },
    { key: "avgTurns", label: "Avg turns / session", pct: false, dir: -1, compute: (k) => k.sessions ? k.total / k.sessions : null },
  ];

  const format = (m, v) => {
    if (v == null) return "—";
    if (m.key === "avgTurns") return v.toFixed(2);
    if (m.pct) return (v * 100).toFixed(1) + "%";
    return fmt.int(v);
  };

  const header = `<thead><tr>
      <th style="min-width:180px;">Metric</th>
      ${cols.map((w, i) => `<th>${i === 0 ? "<span style='color:var(--accent);'>★</span> " : ""}${esc(w)}<div style="font-weight:400;color:var(--dim);font-size:10.5px;">${esc(formatWeekLabel(w))}</div></th>`).join("")}
      ${cols.length > 1 ? `<th style="min-width:120px;">Δ vs. primary</th>` : ""}
    </tr></thead>`;

  const body = metrics.map((m) => {
    const primVal = m.compute ? m.compute(kpiByCol[primary]) : kpiByCol[primary][m.key];
    const cells = cols.map((w, i) => {
      const k = kpiByCol[w];
      const v = m.compute ? m.compute(k) : k[m.key];
      const cls = i === 0 ? "num primary" : "num";
      return `<td class="${cls}">${format(m, v)}</td>`;
    }).join("");

    let deltaCell = "";
    if (cols.length > 1) {
      // Compare column 2 (index 1) to primary; when more, show a summary "avg of others vs. primary".
      const others = cols.slice(1).map((w) => (m.compute ? m.compute(kpiByCol[w]) : kpiByCol[w][m.key])).filter((v) => v != null);
      if (others.length && primVal != null) {
        const othersAvg = others.reduce((s, v) => s + v, 0) / others.length;
        const diff = primVal - othersAvg;
        const signed = m.dir * diff;
        const cls = Math.abs(diff) < 1e-9 ? "delta-flat" : (signed > 0 ? "delta-up" : "delta-down");
        const magnitude = m.pct
          ? (diff * 100).toFixed(1) + "pp"
          : (othersAvg === 0 ? "∞" : ((diff / othersAvg) * 100).toFixed(0) + "%");
        const prefix = diff > 0 ? "+" : diff < 0 ? "" : "";
        deltaCell = `<td class="num ${cls}">${prefix}${magnitude}</td>`;
      } else {
        deltaCell = `<td class="num delta-flat">—</td>`;
      }
    }

    return `<tr><td class="metric">${esc(m.label)}</td>${cells}${deltaCell}</tr>`;
  }).join("");

  table.innerHTML = header + `<tbody>${body}</tbody>`;
}

function renderWeeklyComments() {
  const rows = rowsForWeek(state.weekly.primary).filter((r) => r.comment).sort((a, b) => a.date < b.date ? 1 : -1);
  document.getElementById("wk-comments-meta").textContent = `${rows.length} total`;
  const el = document.getElementById("wk-comments-list");
  if (!rows.length) { el.innerHTML = '<div class="empty">No comments in the selected week.</div>'; return; }
  el.innerHTML = rows.slice(0, 50).map((r) => {
    const cls = r.rating === "Great" ? "great" : r.rating === "Okay" ? "okay" : "";
    return `<div class="comment ${cls}">
      <div class="head"><span>${esc(fmt.datetime(r.date))}</span> · <span>${esc(r.rating || "—")}</span> · <span>${esc(r.src)}</span></div>
      <div class="body">${esc(r.comment)}</div>
      <div class="q">Q: ${esc(fmt.short(r.q, 140))}</div>
    </div>`;
  }).join("");
}

function renderWeekly() {
  renderWeekPicker();
  renderWeeklyKpis();
  renderWeeklyDailyChart();
  renderWeeklyDonuts();
  renderWeeklyCompareTable();
  renderWeeklyComments();
}

/* ==========================================================================
   Trends tab
   ========================================================================== */
// One definition per KPI. pick() pulls the per-week value from a computeKpis
// result; headline() formats the whole-range aggregate (so the big number on a
// Trends card equals the Overview KPI card for the same site + range).
const TREND_METRICS = [
  {
    key: "total", label: "Interactions", color: "#f5c26b",
    pick: (k) => k.total, headline: (k) => fmt.int(k.total),
    descriptor: (k) => `${fmt.int(k.sessions)} sessions`,
    yPct: false, integer: true, lowerIsBetter: false, neutral: false,
  },
  {
    key: "customers", label: "Identified customers", color: "#f5c26b",
    pick: (k) => k.customers, headline: (k) => fmt.int(k.customers),
    descriptor: (k) => k.customers ? `${(k.identifiedRows / k.customers).toFixed(1)} turns / cust` : "no Customer ID on imported rows",
    yPct: false, integer: true, lowerIsBetter: false, neutral: false,
  },
  {
    key: "resRate", label: "Resolution rate", color: "#9ec14a",
    pick: (k) => k.resRate == null ? null : k.resRate * 100,
    headline: (k) => fmt.pct(k.resRate),
    descriptor: () => "customers helped",
    yPct: true, integer: false, lowerIsBetter: false, neutral: false,
  },
  {
    key: "escRate", label: "Escalation rate", color: "#f0b140",
    pick: (k) => k.escRate == null ? null : k.escRate * 100,
    headline: (k) => fmt.pct(k.escRate),
    descriptor: () => "routed to humans · lower is better",
    yPct: true, integer: false, lowerIsBetter: true, neutral: false,
  },
  {
    key: "ratingScore", label: "Satisfaction score", color: "#f59a2f",
    pick: (k) => k.ratingScore == null ? null : k.ratingScore * 100,
    headline: (k) => k.ratingScore != null ? (k.ratingScore * 100).toFixed(0) + "%" : "—",
    descriptor: (k) => `${fmt.int(k.rated)} ratings`,
    yPct: true, integer: false, lowerIsBetter: false, neutral: false,
  },
  {
    key: "avgTurns", label: "Avg turns / session", color: "#e57134",
    pick: (k) => k.sessions ? k.total / k.sessions : null,
    headline: (k) => k.sessions ? (k.total / k.sessions).toFixed(1) : "—",
    descriptor: () => "incl. clarifications",
    yPct: false, integer: false, lowerIsBetter: false, neutral: true,
  },
];

function visibleTrendWeeks() {
  const all = availableWeeks();              // ascending by date
  const r = state.trends.range;
  if (r === "all") return all;
  const n = parseInt(r, 10);
  return Number.isFinite(n) ? all.slice(-n) : all;
}

function trendDelta(points, m) {
  const real = points.filter((v) => v != null);
  if (real.length < 2) return `<span class="tr-d flat">—</span>`;
  const first = real[0];
  const last = real[real.length - 1];
  const diff = last - first;
  if (Math.abs(diff) < 1e-9) return `<span class="tr-d flat">flat</span>`;
  const arrow = diff > 0 ? "▲" : "▼";
  let mag;
  if (m.yPct) mag = Math.abs(diff).toFixed(1) + "pt";
  else if (m.neutral) mag = Math.abs(diff).toFixed(1);
  else mag = first === 0 ? "∞" : Math.round((Math.abs(diff) / Math.abs(first)) * 100) + "%";
  let cls;
  if (m.neutral) cls = "flat";
  else cls = ((m.lowerIsBetter ? -diff : diff) > 0) ? "up" : "down";
  return `<span class="tr-d ${cls}">${arrow} ${mag}</span>`;
}

function trendChart(m, weeks, points) {
  const chartKey = `trend-${m.key}`;
  destroy(chartKey);
  const yScale = m.yPct
    ? { min: 0, max: 100, ticks: { callback: (v) => v + "%" }, grid: { color: "rgba(245,158,78,.06)" } }
    : { beginAtZero: true, ticks: m.integer ? { precision: 0 } : {}, grid: { color: "rgba(245,158,78,.06)" } };
  state.charts[chartKey] = new Chart(mkCanvas(`tr-c-${m.key}`), {
    type: "line",
    data: {
      labels: weeks,
      datasets: [{
        label: m.label, data: points,
        borderColor: m.color, backgroundColor: m.color + "22",
        borderWidth: 2, tension: 0.3, fill: true, spanGaps: false,
        pointRadius: weeks.length > 26 ? 0 : 2, pointHoverRadius: 4,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (c) => {
          if (c.parsed.y == null) return "—";
          if (m.yPct) return c.parsed.y.toFixed(1) + "%";
          if (m.integer) return fmt.int(c.parsed.y);
          return c.parsed.y.toFixed(1);
        } } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 14 } },
        y: yScale,
      },
    },
  });
}

function renderTrends() {
  const weeks = visibleTrendWeeks();

  // Reflect the active range on the segmented control.
  document.querySelectorAll("#tr-range [data-range]").forEach((b) => {
    b.classList.toggle("on", b.dataset.range === state.trends.range);
  });

  const ctx = document.getElementById("tr-context");
  const grid = document.getElementById("tr-grid");

  if (!weeks.length) {
    ctx.textContent = "No weekly data";
    grid.innerHTML = `<div class="empty">No weekly data for this site.</div>`;
    TREND_METRICS.forEach((m) => destroy(`trend-${m.key}`));
    return;
  }

  const siteLbl = state.site === "ALL" ? "All sites" : state.site;
  ctx.textContent = `${weeks.length} week${weeks.length === 1 ? "" : "s"} · `
    + `${formatWeekLabel(weeks[0])} – ${formatWeekLabel(weeks[weeks.length - 1])} · ${siteLbl}`;

  // Per-week KPI snapshots (drives the lines + deltas) and the whole-range
  // aggregate (drives the headline values — same maths as the Overview cards).
  const weekKpis = weeks.map((w) => computeKpis(rowsForWeek(w)));
  const weeksSet = new Set(weeks);
  // Intentionally drops rows with an empty `week` (unparseable date) — they
  // can't sit on any weekly point, so folding them into the headline would make
  // it exceed the sum of the visible line. With real (importer-bucketed) data
  // every row has a week, so this equals the Overview KPI for the same scope.
  const rangeRows = weeklyScopeRows().filter((r) => r.week && weeksSet.has(r.week));
  const agg = computeKpis(rangeRows);

  grid.innerHTML = TREND_METRICS.map((m) => {
    const points = weekKpis.map(m.pick);
    return `
      <div class="tr-card">
        <div class="l">${esc(m.label)}</div>
        <div class="tr-row"><span class="v">${esc(m.headline(agg))}</span>${trendDelta(points, m)}</div>
        <div class="tr-sub">${esc(m.descriptor(agg))}</div>
        <div class="chart-wrap"><canvas id="tr-c-${m.key}"></canvas></div>
      </div>`;
  }).join("");

  TREND_METRICS.forEach((m) => trendChart(m, weeks, weekKpis.map(m.pick)));
}

function setView(view) {
  state.view = view;
  document.getElementById("view-overview").style.display = (view === "overview") ? "" : "none";
  document.getElementById("view-weekly").style.display   = (view === "weekly") ? "" : "none";
  document.getElementById("view-trends").style.display   = (view === "trends") ? "" : "none";
  document.querySelectorAll("#view-tabs button").forEach((b) => {
    b.classList.toggle("nav-tab-active", b.dataset.view === view);
  });
  // Charts that were hidden don't auto-size; rerun the relevant view. Weekly
  // view also lazily initializes `state.weekly.primary` inside its picker —
  // run it first so the site-switcher counts (which depend on that selection)
  // are computed against the right scope.
  if (view === "weekly") renderWeekly();
  else if (view === "trends") renderTrends();
  else render();
  renderSiteSwitch();
}

/* ==========================================================================
   Top-level render
   ========================================================================== */
function render() {
  const rows = applyFilters();

  renderKpis(computeKpis(rows));
  renderTimeSeries(rows);

  donut("c-resolution", groupBy(rows, (r) => r.res || ""), "resolution");
  donut("c-answertype", groupBy(rows, (r) => r.type || ""), "type");
  donut("c-rating", groupBy(rows.filter((r) => r.rating), (r) => r.rating), "rating");

  const endMap = groupBy(rows, (r) => r.end || "");
  hBar("c-endreasons", [...endMap.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8), "#f59a2fcc");

  renderHourChart(rows);
  renderDowChart(rows);

  renderArticles(rows);
  renderComments(rows);
  renderTable(rows);
}

/* ==========================================================================
   Init / wiring
   ========================================================================== */
function siteForBeacon(bid) {
  if (!bid) return "Unknown";
  return state.beaconMap[bid] || `Unknown (${bid.slice(0, 8)}…)`;
}

function normalizeRow(r, src) {
  // handle rows from build-time (compact) OR from live CSV upload (raw)
  if ("sid" in r) {
    return {
      ...r,
      _day: toCentral(r.date)?.day || "",
      week: r.week || weekEndingThursday(r.date) || "",
      src: r.src || src || "session",
      site: r.site || siteForBeacon(r.beacon || ""),
    };
  }
  const arts = [];
  for (let i = 1; i <= 3; i++) {
    const t = (r["Article " + i] || "").trim();
    if (t) arts.push({ t, u: (r["Article " + i + " URL"] || "").trim() });
  }
  const beacon = (r["Beacon ID"] || "").trim();
  const rawDate = (r["Date"] || "").trim();
  return {
    sid: (r["Session ID"] || "").trim(),
    date: rawDate,
    _day: toCentral(rawDate)?.day || "",
    week: weekEndingThursday(rawDate) || "",
    q: (r["Question"] || "").trim(),
    a: (r["Answer"] || "").trim(),
    type: (r["Answer Type"] || "").trim(),
    cust: (r["Customer ID"] || "").trim(),
    res: (r["Session Resolution"] || "").trim(),
    end: (r["Session End Reason"] || "").trim(),
    rating: (r["Rating"] || "").trim(),
    comment: (r["Comment"] || "").trim(),
    arts,
    conv: (r["Conversation URL"] || "").trim(),
    src: src || "session",
    beacon,
    site: siteForBeacon(beacon),
  };
}

// Rows matching the current time-period scope, ignoring the site filter so the
// switcher can show per-site totals within that scope:
//   - Overview: every filter on the Overview tab except site (date / type / res /
//     search / chips). Switching sites updates a count for "how much of the
//     currently-filtered data lives under each site".
//   - Weekly: the primary selected week.
function siteCountScope() {
  if (state.view === "weekly") {
    const wk = state.weekly.primary;
    if (!wk) return [];                // no weekly selection yet
    return state.rows.filter((r) => r.week === wk);
  }
  const { from, to, type, res, q, chips } = state.filters;
  const qlc = q.toLowerCase().trim();
  return state.rows.filter((r) => {
    if (from && r._day && r._day < from) return false;
    if (to && r._day && r._day > to) return false;
    if (type && r.type !== type) return false;
    if (res && r.res !== res) return false;
    if (chips.size) {
      for (const c of chips) {
        const [cat, val] = c.split(":");
        if (cat === "type" && r.type !== val) return false;
        if (cat === "res" && r.res !== val) return false;
        if (cat === "rating" && r.rating !== val) return false;
        if (cat === "end" && r.end !== val) return false;
      }
    }
    if (qlc) {
      const hay = (r.q + " " + r.a + " " + r.comment + " " + r.cust).toLowerCase();
      if (!hay.includes(qlc)) return false;
    }
    return true;
  });
}

function renderSiteSwitch() {
  const el = document.getElementById("site-switch");
  const scope = siteCountScope();
  // Counts within the current time-period / filter scope, per site. Sites with
  // zero rows in this scope still appear (greyed via the ordinary 0 count) so
  // the switcher doesn't jump around as users move through weeks.
  const counts = new Map();
  for (const r of scope) counts.set(r.site, (counts.get(r.site) || 0) + 1);
  const totalInScope = scope.length;

  // Site presence uses the full dataset so the switcher layout stays stable
  // across filter/week changes.
  const seen = new Set();
  for (const r of state.rows) if (r.site) seen.add(r.site);
  const presentSites = state.sites.filter((s) => seen.has(s));
  // Include any unknown sites that showed up in data but aren't in canonical list.
  for (const s of seen) {
    if (!presentSites.includes(s) && s) presentSites.push(s);
  }
  const options = [{ key: "ALL", label: "All sites", count: totalInScope, color: "#e8eaf6" }]
    .concat(presentSites.map((s) => ({
      key: s, label: s, count: counts.get(s) || 0, color: SITE_COLORS[s] || "#8b93c4"
    })));

  el.innerHTML = options.map((o) => {
    const active = state.site === o.key ? "active" : "";
    const dot = o.key === "ALL"
      ? `<span class="dot" style="background:linear-gradient(135deg,#7c6cff,#22d3ee);"></span>`
      : `<span class="dot" style="background:${o.color};"></span>`;
    return `<button class="${active}" data-site="${esc(o.key)}">${dot}${esc(o.label)}<span class="count">${fmt.int(o.count)}</span></button>`;
  }).join("");

  el.querySelectorAll("button").forEach((b) => {
    b.onclick = () => {
      state.site = b.dataset.site;
      state.page = 0;
      // Weekly extras may not all exist in the newly-scoped site; drop them.
      // Keep the primary as-is — renderWeekPicker auto-selects the latest
      // available week if the current one has no data under the new site.
      state.weekly.extras = new Set();
      populateSelectors();
      updateDateBounds();
      renderHeroMeta();
      // Run the active view first so Weekly can validate/re-init its primary
      // week, then refresh the site-switch counts against the new scope.
      if (state.view === "weekly") renderWeekly();
      else if (state.view === "trends") renderTrends();
      else render();
      renderSiteSwitch();
    };
  });
}

function initViewTabs() {
  document.querySelectorAll("#view-tabs button").forEach((b) => {
    b.onclick = () => setView(b.dataset.view);
  });
}

function initWeeklyControls() {
  document.getElementById("wk-primary").onchange = (e) => {
    state.weekly.primary = e.target.value || null;
    // Drop the primary from extras if it happens to be there.
    state.weekly.extras.delete(state.weekly.primary);
    renderSiteSwitch();   // Weekly site counts follow the primary week.
    renderWeekly();
  };
  document.getElementById("wk-compare").onchange = (e) => {
    state.weekly.compareTo = e.target.value || "__prev";
    renderWeekly();
  };
}

function initTrendsControls() {
  document.querySelectorAll("#tr-range [data-range]").forEach((b) => {
    b.onclick = () => {
      state.trends.range = b.dataset.range;
      renderTrends();
    };
  });
}

function populateSelectors() {
  const scope = siteRows();
  const types = [...new Set(scope.map((r) => r.type).filter(Boolean))].sort();
  const resolutions = [...new Set(scope.map((r) => r.res).filter(Boolean))].sort();
  const typeSel = document.getElementById("f-type");
  const resSel = document.getElementById("f-res");
  typeSel.innerHTML = '<option value="">All</option>' + types.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join("");
  resSel.innerHTML = '<option value="">All</option>' + resolutions.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join("");
}

function renderHeroMeta() {
  const scope = siteRows();
  const dates = scope.map((r) => r._day).filter(Boolean).sort();
  const from = dates[0], to = dates[dates.length - 1];
  const days = from && to ? daysBetween(from, to) : 0;
  document.getElementById("sub-period").innerHTML = from
    ? `<b>${from}</b> → <b>${to}</b> (${days} days)`
    : "no data";
  const matchingReports = state.reports.filter(
    (r) => state.site === "ALL" || !r.sites || r.sites.includes(state.site)
  );
  const siteLbl = state.site === "ALL" ? "all sites" : state.site;
  document.getElementById("sub-reports").innerHTML =
    `<b>${matchingReports.length}</b> report${matchingReports.length === 1 ? "" : "s"} · <b>${fmt.int(scope.length)}</b> interactions · <span style="color:var(--muted);">${esc(siteLbl)}</span>`;
}


function updateDateBounds() {
  const from = document.getElementById("f-from");
  const to = document.getElementById("f-to");
  const dates = siteRows().map((r) => r._day).filter(Boolean).sort();
  if (dates.length) {
    from.min = to.min = dates[0];
    from.max = to.max = dates[dates.length - 1];
  } else {
    from.min = from.max = to.min = to.max = "";
  }
}

function initFilters() {
  const from = document.getElementById("f-from");
  const to = document.getElementById("f-to");
  updateDateBounds();

  const bumpScope = () => { renderSiteSwitch(); render(); };

  from.oninput = (e) => { state.filters.from = e.target.value || null; bumpScope(); };
  to.oninput = (e) => { state.filters.to = e.target.value || null; bumpScope(); };
  document.getElementById("f-type").oninput = (e) => { state.filters.type = e.target.value; bumpScope(); };
  document.getElementById("f-res").oninput = (e) => { state.filters.res = e.target.value; bumpScope(); };

  let qDeb;
  document.getElementById("f-q").oninput = (e) => {
    clearTimeout(qDeb);
    qDeb = setTimeout(() => { state.filters.q = e.target.value; bumpScope(); }, 150);
  };
  document.getElementById("f-reset").onclick = () => {
    state.filters = { from: null, to: null, type: "", res: "", q: "", chips: new Set() };
    from.value = to.value = ""; document.getElementById("f-type").value = "";
    document.getElementById("f-res").value = ""; document.getElementById("f-q").value = "";
    renderChipset(); bumpScope();
  };
}

function initTable() {
  document.getElementById("pag-prev").onclick = () => { state.page--; renderTable(applyFilters()); };
  document.getElementById("pag-next").onclick = () => { state.page++; renderTable(applyFilters()); };
  document.getElementById("tbody").addEventListener("click", (e) => {
    const tr = e.target.closest("tr.clickable");
    if (!tr) return;
    const k = tr.dataset.key;
    if (state.expanded.has(k)) state.expanded.delete(k); else state.expanded.add(k);
    renderTable(applyFilters());
  });
}

function initActions() {
  document.getElementById("link-top").onclick = (e) => { e.preventDefault(); window.scrollTo({ top: 0, behavior: "smooth" }); };
}

/* --------------------------------------------------------------------------
   IndexedDB cache (stale-while-revalidate)
   --------------------------------------------------------------------------
   Boot reads the last payload + ETag from IDB, renders immediately, then
   revalidates against the server in the background. If the server 304s,
   we're done. If it returns 200, we replace state and re-render in place.

   Cache key includes a schema version (`payload_v1`); bump it when the
   /payload response shape changes so old caches are silently orphaned.
   -------------------------------------------------------------------------- */
const IDB_NAME = 'aiad';
const IDB_VERSION = 1;
const IDB_STORE = 'cache';
const IDB_KEY = 'payload_v1';

function idbOpen() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(IDB_NAME, IDB_VERSION);
    req.onupgradeneeded = () => req.result.createObjectStore(IDB_STORE);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
async function idbGet(key) {
  const db = await idbOpen();
  return new Promise((resolve, reject) => {
    const req = db.transaction(IDB_STORE, 'readonly').objectStore(IDB_STORE).get(key);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
async function idbSet(key, value) {
  const db = await idbOpen();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(IDB_STORE, 'readwrite');
    tx.objectStore(IDB_STORE).put(value, key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

/* --- boot --- */
function hydrateState(payload) {
  state.beaconMap = payload.beacon_map || {};
  state.sites = payload.sites || [];
  state.rows = (payload.rows || []).map((r) => normalizeRow(r));
  state.reports = (payload.reports || []).slice();
}

async function fetchPayload(etag) {
  const headers = { 'X-WP-Nonce': LSHSAID.nonce };
  if (etag) headers['If-None-Match'] = etag;
  const res = await fetch(LSHSAID.rest + 'dashboard', { credentials: 'include', headers });
  return res;
}

async function revalidate(cachedEtag) {
  try {
    const res = await fetchPayload(cachedEtag);
    if (res.status === 304 || !res.ok) return;
    // Defensive ETag comparison: if a proxy stripped `If-None-Match` on the
    // way to the server, we'd get a 200 with the body even though nothing
    // changed. The server still returns the fresh ETag in headers, so if it
    // matches our cached one we know the data is identical and skip the
    // banner. Strip a possible `-gzip` / `-br` suffix the wire-compression
    // layer might have stamped onto the response ETag.
    const newEtag = (res.headers.get('etag') || '').replace(/-(?:gzip|br|deflate)("?)$/, '$1');
    const cachedNorm = (cachedEtag || '').replace(/-(?:gzip|br|deflate)("?)$/, '$1');
    if (newEtag && newEtag === cachedNorm) return;

    const fresh = await res.json();
    if (newEtag) idbSet(IDB_KEY, { etag: newEtag, payload: fresh }).catch(() => {});
    if (!fresh || !Array.isArray(fresh.rows)) return;
    // Don't swap state under a user mid-interaction — show an "Apply now"
    // banner instead. The fresh payload is already in IDB so a refresh would
    // pick it up regardless; the banner is the explicit, in-place path.
    showFreshDataBanner(fresh);
  } catch (err) {
    // Network failure during background revalidation: silently keep showing cached view.
    console.warn('[aiad] revalidate failed', err);
  }
}

function showFreshDataBanner(fresh) {
  const shell = document.querySelector('.aiad-shell');
  if (!shell) return;
  // If a previous banner is still showing, just upgrade its payload — no
  // need to stack multiple banners across overlapping revalidations.
  let banner = document.getElementById('aiad-fresh-banner');
  if (banner) {
    banner.dataset.payloadKey = String(Date.now());
    banner._aiadFresh = fresh;
    return;
  }
  banner = document.createElement('div');
  banner.id = 'aiad-fresh-banner';
  banner.className = 'aiad-fresh-banner';
  banner.innerHTML =
    '<span>📊 New data is available since you opened this page. ' +
    '<a href="#" id="aiad-fresh-apply">Apply now</a> · ' +
    '<a href="#" id="aiad-fresh-dismiss">Dismiss</a></span>';
  banner._aiadFresh = fresh;
  shell.insertBefore(banner, shell.firstChild);
  document.getElementById('aiad-fresh-apply').onclick = (e) => {
    e.preventDefault();
    const f = banner._aiadFresh;
    banner.remove();
    if (!f) return;
    hydrateState(f);
    DATA = f;
    renderSiteSwitch();
    populateSelectors();
    renderHeroMeta();
    render();
  };
  document.getElementById('aiad-fresh-dismiss').onclick = (e) => {
    e.preventDefault();
    banner.remove();
    // Cache is already updated; next dashboard load will start with the fresh data.
  };
}

async function boot() {
  // 1. Try IDB cache first. Render immediately if present.
  let cachedEtag = null;
  if (!DATA || !DATA.rows) {
    try {
      const cached = await idbGet(IDB_KEY);
      if (cached && cached.payload && Array.isArray(cached.payload.rows)) {
        DATA = cached.payload;
        cachedEtag = cached.etag || null;
      }
    } catch (err) {
      console.warn('[aiad] cache read failed', err);
    }
  }

  // 2. No cache → fetch fresh inline (this path is only first ever load).
  if (!DATA || !DATA.rows) {
    try {
      const res = await fetchPayload(null);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      DATA = await res.json();
      const etag = res.headers.get('etag');
      if (etag) idbSet(IDB_KEY, { etag, payload: DATA }).catch(() => {});
    } catch (err) {
      document.querySelector(".aiad-shell").innerHTML =
        '<div class="empty" style="font-size:16px;">Failed to load dashboard data: ' + (err && err.message || err) + '</div>';
      return;
    }
  }
  if (!DATA || !Array.isArray(DATA.rows) || !DATA.rows.length) {
    document.querySelector(".aiad-shell").innerHTML =
      '<div class="empty" style="font-size:16px;">No interactions yet. Upload a CSV from the <a href="' + (LSHSAID.reports_url || '#') + '">Reports page</a>.</div>';
    return;
  }

  hydrateState(DATA);

  // Discard any form values Chrome restored across the refresh — the only
  // source of truth for filter state is `state`, which always starts clean.
  ["f-from", "f-to", "f-q"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
  const fType = document.getElementById("f-type");
  const fRes = document.getElementById("f-res");
  if (fType) fType.value = "";
  if (fRes)  fRes.value  = "";

  renderSiteSwitch();
  populateSelectors();
  renderHeroMeta();
  initFilters();
  initTable();
  initActions();
  initViewTabs();
  initWeeklyControls();
  initTrendsControls();
  render();

  // 3. If we rendered from cache, revalidate in the background.
  if (cachedEtag) revalidate(cachedEtag);
}

boot();
})();
