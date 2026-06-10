# Trends Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third "Trends" tab to the Help Scout AI Dashboard that plots all six headline KPIs as weekly trend charts over a user-chosen range, and remove the now-duplicate trend charts from the Overview and Weekly views.

**Architecture:** Pure frontend change. The `GET /dashboard` payload already ships every interaction row with a `week_ending` bucket, and the existing `computeKpis(rows)` derives all six metrics from any row subset. The new tab reuses `availableWeeks()`, `rowsForWeek()`, `weeklyScopeRows()`, `computeKpis()`, `formatWeekLabel()`, `destroy()`, `mkCanvas()`, and `esc()` — no new data plumbing. One metric-definition table drives all six cards (DRY).

**Tech Stack:** Vanilla JS (IIFE module in `assets/js/dashboard.js`), Chart.js (already loaded as `Chart`), PHP partial markup + scoped CSS in `templates/dashboard.php`. No build step (assets are hand-authored, per the plugin's "no Node build" convention).

---

## Verification model (read first)

This dashboard's JS has **no unit-test harness** in the suite (no jest/vitest, no `package.json` build — `composer test` is PHPUnit against `src/`). Adding a JS test runner is out of scope and against the plugin's no-build convention. So tasks here are **build-increment + explicit verification**, not red-green TDD. Each task ends with a concrete check (browser observation and/or `composer lint`) and a commit. The PHP touched is template markup/CSS only, so `composer lint` and `composer test` must stay green throughout.

**To view changes locally:** the dashboard is at `https://leastudios-plugins.test/wp-admin/admin.php?page=leastudios-helpscout-ai-dashboard-dashboard`. Assets are cache-busted by `LEASTUDIOS_HELPSCOUT_AI_DASHBOARD_VERSION`; during dev, hard-refresh (Cmd-Shift-R) to bypass the browser cache. Do **not** bump the plugin version in these tasks — versioning + readme changelog is the separate release ritual the user runs.

## File structure

- **`templates/dashboard.php`** — add the `Trends` nav-tab button, the `#view-trends` markup block (range control + grid container), and scoped `.tr-*` CSS. Remove the Weekly "Week-over-week trends" section and the Overview "Resolution rate by week" panel (reflowing its row to single-column).
- **`assets/js/dashboard.js`** — add `state.trends`, the `TREND_METRICS` definition table, `renderTrends()` + helpers (`visibleTrendWeeks`, `trendDelta`, `trendChart`), `initTrendsControls()`, and wire `trends` into `setView()`, the site-switch handler, and `boot()`. Remove `renderResRate()` + its call and `renderWeeklyTrend()` + its call.

Two files only. No `src/` logic changes.

---

## Task 1: Scaffold the Trends tab (nav button, empty view, switching)

**Files:**
- Modify: `templates/dashboard.php` (nav tabs ~line 395; add view block after `#view-weekly` closes ~line 611)
- Modify: `assets/js/dashboard.js` (`state` object ~line 19; `setView()` ~line 1000; site-switch handler ~line 1168)

- [ ] **Step 1: Add the Trends nav-tab button**

In `templates/dashboard.php`, the nav currently reads:

```html
	<nav class="nav-tab-wrapper" id="view-tabs" aria-label="Dashboard views">
	<button type="button" class="nav-tab nav-tab-active" data-view="overview">Overview</button>
	<button type="button" class="nav-tab" data-view="weekly">Weekly</button>
	</nav>
```

Add a third button:

```html
	<nav class="nav-tab-wrapper" id="view-tabs" aria-label="Dashboard views">
	<button type="button" class="nav-tab nav-tab-active" data-view="overview">Overview</button>
	<button type="button" class="nav-tab" data-view="weekly">Weekly</button>
	<button type="button" class="nav-tab" data-view="trends">Trends</button>
	</nav>
```

- [ ] **Step 2: Add the `#view-trends` markup block**

In `templates/dashboard.php`, immediately after the `</div><!-- /#view-weekly -->` line, add:

```html
	<div id="view-trends" style="display:none;">
		<section class="tr-controls">
		<div class="tr-range" id="tr-range" role="group" aria-label="Trend range">
			<button type="button" data-range="8">8w</button>
			<button type="button" data-range="12">12w</button>
			<button type="button" data-range="26">26w</button>
			<button type="button" data-range="all" class="on">All</button>
		</div>
		<div class="tr-context" id="tr-context"></div>
		</section>

		<section class="grid tr-grid" id="tr-grid"></section>
	</div><!-- /#view-trends -->
```

- [ ] **Step 3: Add scoped CSS for the Trends tab**

In `templates/dashboard.php`, inside the existing `<style>` block (before its closing `</style>` at ~line 375), add:

```css
	/* Trends tab */
	.tr-controls {
	display: flex; align-items: center; justify-content: space-between;
	gap: 12px; flex-wrap: wrap; margin-bottom: 20px;
	}
	.tr-range {
	display: inline-flex; padding: 4px; border-radius: 10px;
	background: rgba(0,0,0,.25); border: 1px solid var(--panel-border); gap: 2px;
	}
	.tr-range button {
	border: 0; background: transparent; color: var(--muted); cursor: pointer;
	font: inherit; font-size: 12.5px; font-weight: 500; padding: 7px 14px;
	border-radius: 8px; transition: all .15s ease;
	}
	.tr-range button:hover { color: var(--text); background: rgba(255,255,255,.04); }
	.tr-range button.on {
	background: linear-gradient(135deg, rgba(245,154,47,.28), rgba(229,113,52,.18));
	color: #fff; box-shadow: inset 0 0 0 1px rgba(245,154,47,.45);
	}
	.tr-context { font-size: 11.5px; color: var(--dim); font-variant-numeric: tabular-nums; }
	.tr-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
	@media (max-width: 980px) { .tr-grid { grid-template-columns: 1fr 1fr; } }
	@media (max-width: 640px) { .tr-grid { grid-template-columns: 1fr; } }
	.tr-card {
	background: var(--panel); border: 1px solid var(--panel-border); border-radius: 14px;
	padding: 16px 18px; backdrop-filter: blur(12px); min-width: 0;
	}
	.tr-card .l { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 500; }
	.tr-card .tr-row { display: flex; align-items: baseline; gap: 10px; margin: 6px 0 2px; }
	.tr-card .v { font-size: 24px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
	.tr-card .tr-sub { font-size: 10.5px; color: var(--dim); }
	.tr-card .tr-d { font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
	.tr-card .tr-d.up { color: var(--good); }
	.tr-card .tr-d.down { color: var(--bad); }
	.tr-card .tr-d.flat { color: var(--muted); }
	.tr-card .chart-wrap { height: 90px; margin-top: 10px; }
```

- [ ] **Step 4: Add `state.trends` and update the view comment**

In `assets/js/dashboard.js`, the `state` object has:

```js
  view: "overview",     // "overview" | "weekly"
  weekly: {
```

Change to:

```js
  view: "overview",     // "overview" | "weekly" | "trends"
  trends: {
    range: "all",       // "8" | "12" | "26" | "all"
  },
  weekly: {
```

- [ ] **Step 5: Wire `trends` into `setView()`**

In `assets/js/dashboard.js`, `setView()` currently reads:

```js
function setView(view) {
  state.view = view;
  document.getElementById("view-overview").style.display = (view === "overview") ? "" : "none";
  document.getElementById("view-weekly").style.display   = (view === "weekly") ? "" : "none";
  document.querySelectorAll("#view-tabs button").forEach((b) => {
    b.classList.toggle("nav-tab-active", b.dataset.view === view);
  });
  if (view === "weekly") renderWeekly(); else render();
  renderSiteSwitch();
}
```

Replace with:

```js
function setView(view) {
  state.view = view;
  document.getElementById("view-overview").style.display = (view === "overview") ? "" : "none";
  document.getElementById("view-weekly").style.display   = (view === "weekly") ? "" : "none";
  document.getElementById("view-trends").style.display   = (view === "trends") ? "" : "none";
  document.querySelectorAll("#view-tabs button").forEach((b) => {
    b.classList.toggle("nav-tab-active", b.dataset.view === view);
  });
  if (view === "weekly") renderWeekly();
  else if (view === "trends") renderTrends();
  else render();
  renderSiteSwitch();
}
```

- [ ] **Step 6: Make the site switcher re-render Trends when active**

In `assets/js/dashboard.js`, the site-switch `onclick` handler (~line 1168) has:

```js
      if (state.view === "weekly") renderWeekly(); else render();
      renderSiteSwitch();
```

Replace with:

```js
      if (state.view === "weekly") renderWeekly();
      else if (state.view === "trends") renderTrends();
      else render();
      renderSiteSwitch();
```

- [ ] **Step 7: Add a temporary stub so the page boots**

`renderTrends()` doesn't exist yet; Task 2 adds the real one. To verify scaffolding in isolation, add a stub directly above `setView()`:

```js
function renderTrends() { /* implemented in Task 2 */ }
```

- [ ] **Step 8: Verify the tab switches**

Hard-refresh the dashboard. Expected: a **Trends** tab appears beside Overview / Weekly. Clicking it hides the other views and shows the range control (`8w · 12w · 26w · All`, "All" highlighted) with an empty grid below. No console errors. Clicking back to Overview/Weekly works as before.

- [ ] **Step 9: Commit**

```bash
git add templates/dashboard.php assets/js/dashboard.js
git commit -m "feat: scaffold Trends tab (nav, empty view, switching)"
```

---

## Task 2: Render the six trend cards and charts

**Files:**
- Modify: `assets/js/dashboard.js` (replace the Task 1 stub `renderTrends()`; add `TREND_METRICS`, `visibleTrendWeeks`, `trendDelta`, `trendChart`)

- [ ] **Step 1: Add the metric-definition table**

In `assets/js/dashboard.js`, immediately above the `renderTrends` stub from Task 1 Step 7, add. Each entry is the single source of truth for one card. `pick(k)` reads a per-week value from a `computeKpis` result (returning `null` where the metric is undefined that week); `headline(agg)` formats the range-aggregate value; `yPct` means the metric is a 0–100 percentage; `integer` means the y-axis ticks should be whole numbers; `lowerIsBetter` inverts delta colouring; `neutral` disables good/bad colouring entirely.

```js
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
```

- [ ] **Step 2: Add `visibleTrendWeeks()`**

Below `TREND_METRICS`, add:

```js
function visibleTrendWeeks() {
  const all = availableWeeks();              // ascending by date
  const r = state.trends.range;
  if (r === "all") return all;
  const n = parseInt(r, 10);
  return Number.isFinite(n) ? all.slice(-n) : all;
}
```

- [ ] **Step 3: Add `trendDelta()`**

`points` is the per-week value array (with `null` gaps). The delta compares the first and last populated weeks. `pt` = percentage points (for `yPct` metrics, whose points are already in 0–100 units); counts use a relative `%`; avg-turns uses an absolute magnitude and is always muted (neutral).

```js
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
```

- [ ] **Step 4: Add `trendChart()`**

```js
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
        tooltip: { callbacks: { label: (c) => c.parsed.y == null ? "—" : (m.yPct ? c.parsed.y.toFixed(1) + "%" : fmt.int(c.parsed.y)) } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 14 } },
        y: yScale,
      },
    },
  });
}
```

- [ ] **Step 5: Replace the `renderTrends()` stub with the real implementation**

Replace `function renderTrends() { /* implemented in Task 2 */ }` with:

```js
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
```

- [ ] **Step 6: Verify the cards render**

Hard-refresh, open the Trends tab. Expected: a 2×3 (3-wide) grid of six cards — Interactions, Identified customers, Resolution rate, Escalation rate, Satisfaction score, Avg turns / session — each with a headline value, a coloured delta (Escalation green when its line falls; Avg turns muted), a descriptor, and a weekly line chart. The context line shows the week count, date span, and "All sites". No console errors.

- [ ] **Step 7: Verify headline values match the Overview KPIs**

Set the range to **All** and the site switcher to **All sites**. Compare each Trends card's headline against the corresponding Overview KPI card (Overview tab, with its date/type filters cleared via the Reset button). They must be equal (Interactions count, Resolution/Escalation %, Satisfaction %, Avg turns). Repeat for one specific site (e.g. CG Cookie). If any differ, stop and reconcile before continuing — the headline must equal `computeKpis` over the same row set.

- [ ] **Step 8: Commit**

```bash
git add assets/js/dashboard.js
git commit -m "feat: render six weekly trend charts on the Trends tab"
```

---

## Task 3: Wire the range control

**Files:**
- Modify: `assets/js/dashboard.js` (add `initTrendsControls()`; call it from `boot()` ~line 1451)

- [ ] **Step 1: Add `initTrendsControls()`**

In `assets/js/dashboard.js`, directly after `initWeeklyControls()` (ends ~line 1192), add:

```js
function initTrendsControls() {
  document.querySelectorAll("#tr-range [data-range]").forEach((b) => {
    b.onclick = () => {
      state.trends.range = b.dataset.range;
      renderTrends();
    };
  });
}
```

- [ ] **Step 2: Call it from `boot()`**

In `boot()`, the init block reads:

```js
  initViewTabs();
  initWeeklyControls();
  render();
```

Change to:

```js
  initViewTabs();
  initWeeklyControls();
  initTrendsControls();
  render();
```

- [ ] **Step 3: Verify the range control works**

Hard-refresh, open Trends. Click `8w` — all six charts shrink to the last 8 weeks, the active button highlights, and the context line updates to "8 weeks · …". Click `26w`, `12w`, `All` — each rescales every chart and updates the context line. Headlines recompute for the visible range (e.g. a shorter range may change Resolution %). No console errors.

- [ ] **Step 4: Commit**

```bash
git add assets/js/dashboard.js
git commit -m "feat: wire the Trends range control (8w/12w/26w/all)"
```

---

## Task 4: Consolidate — remove the duplicate trend charts

**Files:**
- Modify: `templates/dashboard.php` (Overview feedback row ~lines 498–507; Weekly trends section ~lines 583–593)
- Modify: `assets/js/dashboard.js` (remove `renderResRate` ~lines 399–432 and its call ~line 1036; remove `renderWeeklyTrend` ~lines 847–898 and its call ~line 995)

- [ ] **Step 1: Remove the Overview "Resolution rate by week" panel and reflow its row**

In `templates/dashboard.php`, the Customer feedback row currently is:

```html
	<!-- FEEDBACK -->
	<div class="section-title">Customer feedback</div>
	<section class="grid row two-col">
	<div class="aiad-panel">
		<h3>Comments from rated sessions <span class="count" id="comments-meta"></span></h3>
		<div class="comments" id="comments-list"></div>
	</div>
	<div class="aiad-panel">
		<h3>Resolution rate by week</h3>
		<div class="chart-wrap"><canvas id="c-resrate"></canvas></div>
	</div>
	</section>
```

Replace with (drop the chart panel; remove `row two-col` so comments span full width):

```html
	<!-- FEEDBACK -->
	<div class="section-title">Customer feedback</div>
	<section class="grid">
	<div class="aiad-panel">
		<h3>Comments from rated sessions <span class="count" id="comments-meta"></span></h3>
		<div class="comments" id="comments-list"></div>
	</div>
	</section>
```

- [ ] **Step 2: Remove the Weekly "Week-over-week trends" section**

In `templates/dashboard.php`, delete this entire block (inside `#view-weekly`):

```html
		<div class="section-title">Week-over-week trends</div>
		<section class="grid row two-col">
			<div class="aiad-panel tall">
			<h3>Interactions by week</h3>
			<div class="chart-wrap"><canvas id="wk-c-trend"></canvas></div>
			</div>
			<div class="aiad-panel tall">
			<h3>Resolution &amp; escalation rate by week</h3>
			<div class="chart-wrap"><canvas id="wk-c-rates"></canvas></div>
			</div>
		</section>
```

- [ ] **Step 3: Remove `renderResRate()` and its call**

In `assets/js/dashboard.js`, delete the entire `renderResRate` function (the block starting `/* --- resolution rate by week line --- */` / `function renderResRate(rows) {` through its closing `}`, ~lines 399–432). Then in `render()`, delete the line:

```js
  renderResRate(rows);
```

- [ ] **Step 4: Remove `renderWeeklyTrend()` and its call**

In `assets/js/dashboard.js`, delete the entire `function renderWeeklyTrend() { … }` block (~lines 847–898). Then in `renderWeekly()`, delete the line:

```js
  renderWeeklyTrend();
```

The `destroy("wk-trend")`/`destroy("wk-rates")`/`destroy("resrate")` calls lived **inside** the deleted functions, so no orphan cleanup remains. Those chart keys are never created again, so leaving them absent from `state.charts` is correct.

- [ ] **Step 5: Verify the Overview and Weekly views**

Hard-refresh. **Overview:** the "Resolution rate by week" chart is gone; the comments panel now spans full width under "Customer feedback"; no console errors; the daily timeseries and donuts still render. **Weekly:** the "Week-over-week trends" section (two charts) is gone; the KPIs, daily chart, donuts, comparison table, and comments still render; no console errors. Grep to confirm no dangling references remain:

```bash
grep -n "renderResRate\|renderWeeklyTrend\|c-resrate\|wk-c-trend\|wk-c-rates" assets/js/dashboard.js templates/dashboard.php
```

Expected: **no output** (all references removed).

- [ ] **Step 6: Commit**

```bash
git add templates/dashboard.php assets/js/dashboard.js
git commit -m "refactor: move weekly trends into the Trends tab, remove duplicates"
```

---

## Task 5: Lint, regression check, and final verification

**Files:** none (verification only)

- [ ] **Step 1: Run PHP lint**

Run: `composer lint`
Expected: PASS (phpcs + phpstan clean). Only template markup/CSS changed; if phpcs flags whitespace/indent in the edited PHP regions, run `composer phpcbf` and re-run `composer lint`.

- [ ] **Step 2: Run the PHPUnit suite**

Run: `composer test`
Expected: PASS — unchanged from before (no `src/` logic touched).

- [ ] **Step 3: Cross-view regression sweep (browser)**

Hard-refresh and confirm, with no console errors in any view:
- **Overview** — KPIs, daily timeseries, three donuts, end-reasons/hour/day-of-week charts, top articles, comments (full width), interactions table + pagination, filters + Reset, quick chips.
- **Weekly** — week picker + multi-select chips, KPIs with delta pills, daily chart, three donuts, comparison table, comments.
- **Trends** — all six cards, range control, site-switcher parity (pick CG Cookie → all six recompute; pick All sites → combined), empty state when a site has no weekly rows.

- [ ] **Step 4: Final structural confirmation**

Run: `grep -n "view-trends\|renderTrends\|TREND_METRICS\|initTrendsControls" assets/js/dashboard.js templates/dashboard.php`
Expected: references present in both files (tab wired end-to-end). And re-run the Task 4 Step 5 grep — still no output.

No commit (verification only). The feature is complete across the Task 1–4 commits.

---

## Release (separate, user-run — not part of execution)

When the user is ready to ship, follow the plugin's release ritual (see `CLAUDE.md` "Releases" and the suite release-ritual memory): bump the `Version:` header in `leastudios-helpscout-ai-dashboard.php`, update `readme.txt` changelog (a `feat`-style "Added: Trends tab" line and a `refactor`-style "Changed:" line; backfill any stale prior entries), commit `release: bump version to X.Y.Z`, then `git tag vX.Y.Z && git push origin vX.Y.Z`. Do not do this as part of implementing the feature.
