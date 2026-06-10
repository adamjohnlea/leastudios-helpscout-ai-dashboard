# Trends Tab — Design

**Date:** 2026-06-10
**Plugin:** leastudios-helpscout-ai-dashboard
**Status:** Approved (design), pending spec review

## Problem

The dashboard's six headline KPIs (Interactions, Identified customers, Resolution
rate, Escalation rate, Satisfaction score, Avg turns / session) show a single
aggregate number for the selected range. There is no way to see how an individual
metric has moved over time. Three of the six are partially trended today, but the
trends are scattered, capped at 12 weeks, and tied to the week-picker:

- Weekly view → "Interactions by week" bar chart (`wk-c-trend`).
- Weekly view → "Resolution & escalation rate by week" line chart (`wk-c-rates`).
- Overview view → "Resolution rate by week" line chart (`c-resrate`).

The other three (Identified customers, Satisfaction score, Avg turns / session)
are trended nowhere.

## Goal

A single **Trends** tab that shows all six KPIs as weekly trend charts at once,
over a user-chosen range, scoped by the existing site switcher. It becomes the
canonical home for "metric over time," and the scattered duplicate charts are
removed from the Overview and Weekly views.

## Constraints & key facts

- **Frontend-only.** No REST, schema, or PHP data changes. `GET /dashboard`
  already returns every interaction row with its `week_ending` bucket, and
  `computeKpis(rows)` ([assets/js/dashboard.js](../../../assets/js/dashboard.js))
  already derives all six metrics from any row subset.
- All work lives in `assets/js/dashboard.js`, `templates/dashboard.php` (markup +
  scoped CSS), and possibly a CSS touch-up. No `src/` PHP changes beyond none.
- The site switcher is a single global selection (`state.site` = `"ALL"` or one
  site). Everything filters by it; Trends inherits this — single-select parity,
  no multi-site overlay.

## Design

### 1. Navigation

Add a third tab, **Trends**, to the `nav-tab-wrapper` beside Overview / Weekly in
[templates/dashboard.php](../../../templates/dashboard.php). Add a sibling
`<div id="view-trends" style="display:none;">` block. The existing tab-switch
handler (`data-view` buttons toggling `#view-*` containers) drives visibility —
extend it to recognize `trends` and call `renderTrends()` on activation.

### 2. Range control

One shared segmented control at the top of the Trends view: `8w · 12w · 26w · All`.
Default **All**. State on `state.trends = { range: "all" }`. Changing it
re-renders all six charts. A context line to the right shows the visible-week
count and date span (e.g. "52 weeks · Apr 2025 – Jun 2026"). No per-card controls.

The visible weeks are `availableWeeks()` (already sorted ascending) sliced to the
range: `8`, `12`, `26`, or all.

### 3. The six trend cards (2×3 grid)

One card per KPI, in this order: Interactions, Identified customers, Resolution
rate, Escalation rate, Satisfaction score, Avg turns / session.

Each card contains:

- **Label** (uppercase, muted) — reuses `.kpi .lbl` styling idiom.
- **Headline value** — the metric computed over the **entire visible range**, using
  the same aggregation as the Overview KPI cards. This guarantees the big number
  on a Trends card equals the Overview KPI card for the same site + range. It is
  *not* the latest week's value.
- **Delta** — last populated week vs first populated week in the visible range.
  Green when improving, red when worsening, muted when flat/unavailable.
  **Escalation rate** is the only lower-is-better metric, so its colour sign is
  inverted (a falling line is green). **Avg turns / session** has no inherent good
  direction (more turns can mean engagement or friction) — its delta is shown in a
  neutral/muted colour, magnitude only, never green/red.
- **One-line descriptor** — mirrors today's KPI `delta` text (e.g. "customers
  helped", "routed to humans · lower is better").
- **Chart** — a Chart.js line chart of the per-week metric value across the visible
  weeks. All six are line charts for a uniform grid.

#### Per-week series construction

For each visible week `w`, compute `computeKpis(rowsForWeek(w))` and read the
relevant field:

| Card | Per-week value | Headline (range aggregate) |
|---|---|---|
| Interactions | `k.total` | sum of rows in range |
| Identified customers | `k.customers` (unique IDs that week) | unique IDs across range |
| Resolution rate | `k.resRate` (`null` if no resolution events) | `computeKpis(rangeRows).resRate` |
| Escalation rate | `k.escRate` (`null` if none) | `computeKpis(rangeRows).escRate` |
| Satisfaction score | `k.ratingScore` (`null` if no ratings) | `computeKpis(rangeRows).ratingScore` |
| Avg turns / session | `k.total / k.sessions` (`null` if no sessions) | range `total / sessions` |

`computeKpis` already returns `null` for rates/scores when the denominator is
zero. Null per-week points render as **gaps** in the line (Chart.js `spanGaps:
false`), never as misleading zeros. This keeps low-volume weeks honest.

The headline value is computed once from the full range row set (one
`computeKpis` call over `rowsForWeek`-union), not by averaging weekly points —
averaging rates would be statistically wrong (unweighted mean of ratios).

### 4. Consolidation

Trends is now the single home for "metric over time." Remove the redundant charts:

- **Weekly view:** delete the "Interactions by week" (`wk-c-trend`) and "Resolution
  & escalation rate by week" (`wk-c-rates`) panels from
  [templates/dashboard.php](../../../templates/dashboard.php) and the
  `renderWeeklyTrend()` function from `dashboard.js`, plus its call site and the
  `"wk-trend"` / `"wk-rates"` entries in any chart cleanup/teardown. Weekly keeps
  its single-week snapshot
  (donuts, daily timeseries, KPIs) and the multi-week **comparison table**, which
  is a distinct tabular tool driven by user-picked weeks.
- **Overview view:** delete the standalone "Resolution rate by week" (`c-resrate`)
  panel and its render logic. The "Customer feedback" row currently holds the
  comments panel (left) and that chart (right) as a `two-col` grid; with the chart
  gone, change that row to a single column so the comments panel widens to full
  width.

No data or behaviour the user relies on is lost — every removed trend reappears,
unified and range-controlled, on the Trends tab.

### 5. Styling

Reuse the existing dark amber palette and panel idioms already in
`templates/dashboard.php`. The 2×3 grid uses the established `.grid`/`.three-col`
responsive pattern (collapses to 2-up then 1-up). Chart colours follow the
existing per-metric conventions: amber/butter for volume, olive `--good` for
resolution, amber `--warn` for escalation, accent amber for satisfaction. New CSS
is scoped under `#aiad-dashboard-root`.

## Components & boundaries

- `renderTrends()` — entry point; reads `state.site` + `state.trends.range`,
  derives visible weeks, renders the range control context line and all six cards.
  Depends on `availableWeeks()`, `rowsForWeek()`, `computeKpis()`, `fmt`, Chart.js.
- `trendCard(metricDef, weeks)` — builds one card's value/delta/chart from a metric
  definition (`{ key, label, descriptor, lowerIsBetter, pick(k), color, format }`).
  Pure given its inputs; one definition table drives all six cards.
- Range control handler — sets `state.trends.range`, calls `renderTrends()`.
- Tab-switch handler — extended to route `trends` → `renderTrends()`.

The six-metric definition table is the single source of truth for labels,
colours, formatting, and direction — no per-card copy-paste.

## Error / edge handling

- **No data / empty range:** show the existing `.empty` placeholder inside each
  chart area; headline shows "—". Surfaced, never silent.
- **Single week of data:** charts render one point; delta shows "—" (no prior week).
- **Low-volume rate weeks:** null points → line gaps, as above.
- **Site with no rows:** site switcher already zero-counts; Trends shows empty state.

## Testing

The dashboard JS has no unit-test harness in the suite today, so verification is:

1. `composer lint` stays clean (only template markup/CSS PHP touched; no `src/`
   logic changes) — must pass.
2. `composer test` (PHPUnit) unaffected — confirm still green.
3. Manual: load the dashboard against real imported data. For each metric and a
   couple of site scopes, confirm the Trends card headline equals the Overview KPI
   card for the same range, and the latest-week line point matches the latest week
   shown in the (pre-removal) Weekly trend. Confirm range control rescales all six.
4. Confirm the removed Overview/Weekly panels are gone and those views still
   render without console errors (no dangling chart references).

## Out of scope (YAGNI)

Multi-site overlay (one line per site), daily granularity, CSV/PNG export of
trends, custom date-range pickers, per-card range controls, and any new REST
endpoint or schema change.

## Release

Follows the plugin's tag-triggered release ritual. The user-facing change is a
new feature + a view cleanup: commit the feature as `feat:` (→ Added) and the
consolidation as `refactor:` (→ Changed), bump the `Version:` header, update
`readme.txt` changelog, then tag.
