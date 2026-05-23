<?php
/**
 * Dashboard admin page — embeds the existing single-file dashboard markup
 * inside a scoped #aiad-dashboard-root container. Data is fetched at boot
 * from /wp-json/leastudios-helpscout-ai-dashboard/v1/dashboard by
 * assets/js/dashboard.js.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
	:root {
	/* CG Cookie-inspired palette: warm ink navy + amber + cream */
	--bg: #15120d;            /* deep warm ink */
	--bg-2: #1e1a12;
	--panel: rgba(44, 36, 26, 0.55);
	--panel-solid: #221c14;
	--panel-border: rgba(245, 158, 78, 0.10);
	--text: #f6eedc;          /* warm cream */
	--muted: #a89782;
	--dim: #6c614e;
	--accent: #f59a2f;        /* CG Cookie amber */
	--accent-2: #f5c26b;      /* butter amber */
	--accent-3: #e57134;      /* burnt orange */
	--good: #9ec14a;          /* olive green */
	--warn: #f0b140;
	--bad: #e05d41;
	--grid: rgba(245, 158, 78, 0.06);
	--shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
	}
	/* Scope box-sizing reset to dashboard subtree only — leaving WP admin alone. */
	#aiad-dashboard-root, #aiad-dashboard-root *, #aiad-dashboard-root *::before, #aiad-dashboard-root *::after { box-sizing: border-box; }

	/* The dark dashboard "viewport". Contained, rounded, sits inside WP admin. */
	#aiad-dashboard-root {
	background: var(--bg);
	background-image:
		radial-gradient(900px 500px at 5% 0%, rgba(245, 154, 47, 0.16), transparent 60%),
		radial-gradient(700px 400px at 100% 0%, rgba(229, 113, 52, 0.10), transparent 60%);
	color: var(--text);
	font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
	font-size: 14px;
	line-height: 1.5;
	-webkit-font-smoothing: antialiased;
	border-radius: 14px;
	box-shadow: var(--shadow);
	overflow: hidden;
	margin-top: 14px;
	}
	.aiad-shell { padding: 24px 28px 36px; }

	/* WP-native chrome above the dark panel. */
	.aiad-page-meta { color: #50575e; margin: 6px 0 4px; font-size: 13px; }
	.aiad-page-meta b { color: #1d2327; font-weight: 600; }
	.aiad-page .nav-tab-wrapper { margin-bottom: 0; }
	.aiad-page .nav-tab { cursor: pointer; }

	/* Top bar inside the dark panel: site switcher + actions. */
	.aiad-top-bar {
	display: flex; align-items: center; justify-content: space-between;
	gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
	}
	.actions { display: flex; gap: 10px; flex-wrap: wrap; }
	.btn {
	display: inline-flex; align-items: center; gap: 8px;
	padding: 9px 14px; border-radius: 10px; cursor: pointer;
	border: 1px solid var(--panel-border);
	background: var(--panel); color: var(--text);
	font: inherit; font-size: 13px; font-weight: 500;
	backdrop-filter: blur(10px);
	transition: transform .15s ease, background .15s ease, border-color .15s ease;
	}
	.btn:hover { background: rgba(245,158,78,.06); border-color: rgba(255,255,255,.14); transform: translateY(-1px); }
	.btn.primary {
	background: linear-gradient(135deg, #f59a2f 0%, #e57134 120%);
	border-color: transparent; color: #15120d; font-weight: 600;
	}
	.btn.primary:hover { filter: brightness(1.1); }
	.btn svg { width: 14px; height: 14px; }

	/* KPI strip */
	.kpis {
	display: grid; grid-template-columns: repeat(6, minmax(0, 1fr));
	gap: 14px; margin-bottom: 24px;
	}
	@media (max-width: 1100px) { .kpis { grid-template-columns: repeat(3, 1fr); } }
	@media (max-width: 640px)  { .kpis { grid-template-columns: repeat(2, 1fr); } }
	.kpi {
	background: var(--panel); border: 1px solid var(--panel-border); border-radius: 14px;
	padding: 16px 18px; backdrop-filter: blur(12px);
	position: relative; overflow: hidden;
	}
	.kpi::after {
	content: ""; position: absolute; inset: 0;
	background: linear-gradient(135deg, rgba(245,154,47,.10), transparent 50%);
	pointer-events: none;
	}
	.kpi .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-weight: 500; }
	.kpi .val {
	font-size: 28px; font-weight: 700; margin-top: 6px; letter-spacing: -0.02em;
	font-variant-numeric: tabular-nums;
	}
	.kpi .delta { font-size: 11px; color: var(--muted); margin-top: 2px; }
	.kpi .delta .up { color: var(--good); }
	.kpi .delta .down { color: var(--bad); }

	/* Filters */
	.filters {
	background: var(--panel); border: 1px solid var(--panel-border); border-radius: 14px;
	padding: 16px; margin-bottom: 20px; backdrop-filter: blur(12px);
	display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 2fr auto; gap: 12px; align-items: end;
	}
	@media (max-width: 1100px) { .filters { grid-template-columns: 1fr 1fr 1fr; } }
	@media (max-width: 640px) { .filters { grid-template-columns: 1fr 1fr; } }
	.field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
	.field label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 500; }
	.field input, .field select {
	width: 100%;
	padding: 8px 10px; border-radius: 8px; background: rgba(0,0,0,.25);
	border: 1px solid var(--panel-border); color: var(--text); font: inherit; font-size: 13px;
	outline: none;
	}
	.field input:focus, .field select:focus { border-color: var(--accent); }
	.field.search input { padding-left: 30px; background-position: 10px center; background-repeat: no-repeat;
	background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%238a8fb5' stroke-width='2' viewBox='0 0 24 24'><circle cx='11' cy='11' r='7'/><path d='m21 21-5-5'/></svg>"); }
	/* Site switcher */
	.site-switch {
	display: inline-flex; padding: 4px; border-radius: 12px;
	background: rgba(0,0,0,.25); border: 1px solid var(--panel-border);
	backdrop-filter: blur(10px); gap: 2px; flex-wrap: wrap;
	}
	.site-switch button {
	border: 0; background: transparent; color: var(--muted); cursor: pointer;
	font: inherit; font-size: 12.5px; font-weight: 500; padding: 7px 12px;
	border-radius: 8px; transition: all .15s ease;
	display: inline-flex; align-items: center; gap: 6px;
	}
	.site-switch button:hover { color: var(--text); background: rgba(255,255,255,.04); }
	.site-switch button.active {
	background: linear-gradient(135deg, rgba(245,154,47,.28), rgba(229,113,52,.18));
	color: #fff; box-shadow: inset 0 0 0 1px rgba(245,154,47,.45);
	}
	.site-switch .dot {
	width: 7px; height: 7px; border-radius: 50%; display: inline-block;
	}
	.site-switch .count {
	font-size: 10.5px; color: var(--dim); font-variant-numeric: tabular-nums;
	padding: 1px 6px; border-radius: 999px; background: rgba(255,255,255,.04);
	}
	.site-switch button.active .count { color: var(--text); background: rgba(245,154,47,.2); }

	.chipset { display: flex; gap: 6px; flex-wrap: wrap; }
	.chip {
	padding: 5px 10px; font-size: 12px; border-radius: 999px; cursor: pointer;
	border: 1px solid var(--panel-border); background: rgba(0,0,0,.25); color: var(--muted);
	user-select: none; transition: all .12s ease;
	}
	.chip:hover { color: var(--text); border-color: rgba(255,255,255,.2); }
	.chip.active { background: rgba(245,154,47,.2); border-color: var(--accent); color: #fff; }

	/* Grid */
	/* `minmax(0, 1fr)` is the canonical "fill the row, but don't blow up if a
	child has intrinsic content wider than the track" idiom. Without the
	explicit template, an implicit auto-sized track shrinks to its
	children's max-content — which was clipping the timeseries chart,
	top-articles list, and interactions table to ~half width inside WP
	admin. */
	.grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); width: 100%; }
	.grid > * { min-width: 0; width: 100%; }
	.row {
	display: grid; gap: 16px;
	}
	/* WP admin styles `.wrap > section` etc. in unexpected ways — defensively
	force every block inside the dashboard's view containers to fill their
	row. This is the belt-and-suspenders for the bare-`.grid` collapse. */
	#view-overview, #view-weekly { display: block; width: 100%; }
	#view-overview > *, #view-weekly > * { width: 100%; }
	.aiad-panel { width: 100%; }
	.aiad-panel {
	background: var(--panel); border: 1px solid var(--panel-border); border-radius: 16px;
	padding: 18px; backdrop-filter: blur(12px); min-width: 0; position: relative;
	}
	.aiad-panel h3 {
	margin: 0 0 14px; font-size: 13px; font-weight: 600;
	text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted);
	display: flex; align-items: center; justify-content: space-between;
	}
	.aiad-panel h3 .count { font-size: 11px; color: var(--dim); font-weight: 500; }
	.aiad-panel .chart-wrap { position: relative; height: 260px; }
	.aiad-panel.tall .chart-wrap { height: 340px; }
	.aiad-panel.short .chart-wrap { height: 200px; }

	/* Legend */
	.legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; }
	.legend .item { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); cursor: pointer; }
	.legend .dot { width: 8px; height: 8px; border-radius: 2px; }

	/* Table */
	table { width: 100%; border-collapse: collapse; font-size: 13px; }
	th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--panel-border); vertical-align: top; }
	th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 600; background: rgba(0,0,0,.15); position: sticky; top: 0; z-index: 1; }
	tr.clickable { cursor: pointer; }
	tr.clickable:hover { background: rgba(255,255,255,.025); }
	tr.expanded-row td { background: rgba(245,154,47,.05); padding: 14px 16px; }
	.answer-preview { white-space: pre-wrap; font-size: 12.5px; line-height: 1.55; color: var(--text); max-height: 400px; overflow-y: auto; }
	.q-cell { max-width: 360px; }
	.q-cell .q { font-weight: 500; color: var(--text); }
	.q-cell .meta { font-size: 11px; color: var(--muted); margin-top: 3px; font-family: "JetBrains Mono", monospace; }

	.pill {
	display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500;
	border: 1px solid var(--panel-border);
	}
	.pill.answer { background: rgba(245,154,47,.15); color: #fbc98a; border-color: rgba(245,154,47,.3); }
	.pill.clar { background: rgba(245,194,107,.12); color: #f6d69a; border-color: rgba(245,194,107,.25); }
	.pill.no_answer { background: rgba(224,93,65,.14); color: #f3a389; border-color: rgba(224,93,65,.28); }
	.pill.help { background: rgba(240,177,64,.12); color: #f5d07a; border-color: rgba(240,177,64,.25); }
	.pill.human { background: rgba(193,63,91,.16); color: #eda2b4; border-color: rgba(193,63,91,.32); }
	.pill.other { background: rgba(245,158,78,.06); color: var(--muted); }
	.pill.helped { background: rgba(158,193,74,.14); color: #c4db87; border-color: rgba(158,193,74,.3); }
	.pill.nothelped { background: rgba(224,93,65,.14); color: #f3a389; border-color: rgba(224,93,65,.28); }
	.pill.esc { background: rgba(240,177,64,.14); color: #f5d07a; border-color: rgba(240,177,64,.28); }
	.rating-great { color: var(--good); }
	.rating-okay { color: var(--warn); }
	.rating-bad { color: var(--bad); }


	/* Pagination / footer */
	.pag { display: flex; align-items: center; gap: 10px; justify-content: flex-end; padding: 12px 4px; color: var(--muted); font-size: 12px; }
	.pag button { background: transparent; border: 1px solid var(--panel-border); color: var(--text); border-radius: 6px; padding: 4px 10px; cursor: pointer; font: inherit; }
	.pag button:disabled { color: var(--dim); cursor: not-allowed; }

	.empty { color: var(--muted); text-align: center; padding: 40px; font-size: 13px; }

	/* Article ranking list */
	.article-list { display: flex; flex-direction: column; gap: 2px; }
	.article-item {
	display: grid; grid-template-columns: 28px 1fr auto; column-gap: 14px;
	align-items: center; padding: 10px 4px; border-bottom: 1px solid rgba(245,158,78,.06);
	}
	.article-item:last-child { border-bottom: 0; }
	.article-rank {
	font-family: "JetBrains Mono", monospace; font-size: 11px;
	color: var(--dim); text-align: right; font-variant-numeric: tabular-nums;
	}
	.article-body { min-width: 0; display: flex; flex-direction: column; gap: 6px; }
	.article-title {
	font-size: 13px; color: var(--text); font-weight: 500; line-height: 1.4;
	overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
	}
	.article-bar-track {
	height: 6px; border-radius: 999px; background: rgba(245,158,78,.08);
	overflow: hidden;
	}
	.article-bar {
	height: 100%; border-radius: 999px;
	background: linear-gradient(90deg, rgba(245,194,107,.85), rgba(245,154,47,.85));
	transition: width .4s cubic-bezier(.2,.7,.2,1);
	}
	.article-count {
	font-family: "JetBrains Mono", monospace; font-size: 13px; font-weight: 500;
	color: var(--text); font-variant-numeric: tabular-nums; min-width: 40px; text-align: right;
	}

	/* Comments list */
	.comments { display: flex; flex-direction: column; gap: 10px; max-height: 380px; overflow-y: auto; padding-right: 6px; }
	.comment {
	padding: 10px 12px; border-radius: 10px; background: rgba(0,0,0,.2);
	border-left: 3px solid var(--bad);
	}
	.comment.okay { border-left-color: var(--warn); }
	.comment.great { border-left-color: var(--good); }
	.comment .head { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; font-size: 11px; color: var(--muted); font-family: "JetBrains Mono", monospace; }
	.comment .body { color: var(--text); font-size: 13px; white-space: pre-wrap; }
	.comment .q { color: var(--muted); font-size: 12px; margin-top: 6px; font-style: italic; }

	.two-col { grid-template-columns: 1fr 1fr; }
	.three-col { grid-template-columns: 1fr 1fr 1fr; }
	@media (max-width: 980px) { .two-col, .three-col { grid-template-columns: 1fr; } }

	.section-title {
	font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
	color: var(--muted); font-weight: 600;
	margin: 24px 4px 12px; display: flex; align-items: center; gap: 10px;
	}
	.section-title::after {
	content: ""; flex: 1; height: 1px;
	background: linear-gradient(90deg, var(--panel-border), transparent);
	}

	/* Weekly view controls */
	.wk-controls {
	background: var(--panel); border: 1px solid var(--panel-border); border-radius: 14px;
	padding: 16px; margin-bottom: 20px; backdrop-filter: blur(12px);
	display: flex; gap: 18px; flex-wrap: wrap; align-items: flex-end;
	}
	.wk-controls .field { min-width: 180px; }
	.wk-controls .spacer { flex: 1; }

	.wk-kpi .val { font-size: 26px; }
	.wk-kpi .delta-pill {
	display: inline-flex; align-items: center; gap: 4px;
	font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px;
	margin-top: 8px; font-variant-numeric: tabular-nums;
	}
	.wk-kpi .delta-pill.up   { background: rgba(158,193,74,.14); color: var(--good); border: 1px solid rgba(158,193,74,.3); }
	.wk-kpi .delta-pill.down { background: rgba(224,93,65,.14);  color: var(--bad);  border: 1px solid rgba(224,93,65,.3); }
	.wk-kpi .delta-pill.flat { background: rgba(168,151,130,.10); color: var(--muted); border: 1px solid var(--panel-border); }
	.wk-kpi .delta-pill.na   { background: transparent; color: var(--dim); border: 1px dashed var(--panel-border); }
	.wk-kpi .cmp-lbl { font-size: 10.5px; color: var(--dim); margin-top: 4px; }

	/* Weekly comparison table */
	.wk-compare-wrap { overflow: auto; }
	table.wk-compare { min-width: 720px; }
	table.wk-compare th, table.wk-compare td { white-space: nowrap; }
	table.wk-compare td.metric { color: var(--muted); font-weight: 500; }
	table.wk-compare td.num { font-variant-numeric: tabular-nums; font-family: "JetBrains Mono", monospace; font-size: 12.5px; }
	table.wk-compare td.primary { background: rgba(245,154,47,.06); color: #fff; font-weight: 600; }
	table.wk-compare td.delta-up   { color: var(--good); }
	table.wk-compare td.delta-down { color: var(--bad); }
	table.wk-compare td.delta-flat { color: var(--muted); }

	.wk-picker-chips {
	display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; max-height: 110px; overflow-y: auto;
	padding: 4px 2px;
	}
	.wk-picker-chips .chip.primary {
	background: rgba(245,154,47,.28); border-color: var(--accent); color: #fff;
	}
	.wk-picker-chips .chip.compare {
	background: rgba(245,194,107,.15); border-color: rgba(245,194,107,.5); color: #f5d69a;
	}
	.wk-hint { font-size: 11px; color: var(--dim); margin-top: 6px; }

	::-webkit-scrollbar { width: 10px; height: 10px; }
	::-webkit-scrollbar-track { background: transparent; }
	::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 5px; }
	::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.15); }


	/* Session banner inside dark panel — explains drag-drop limits. */
	.aiad-session-banner {
	display: flex; align-items: center; gap: 10px;
	padding: 10px 14px; margin: 0 0 18px;
	border-radius: 10px;
	background: rgba(240, 177, 64, 0.08);
	border: 1px solid rgba(240, 177, 64, 0.25);
	color: var(--accent-2); font-size: 12.5px;
	}
	.aiad-session-banner svg { width: 16px; height: 16px; flex-shrink: 0; }
	.aiad-session-banner a { color: inherit; text-decoration: underline; }

	/* Background-revalidation banner — only injected when fresh data is in cache
	but not yet applied to the rendered view. Greener than the amber session
	banner so it reads as actionable, not informational. */
	.aiad-fresh-banner {
	display: flex; align-items: center; gap: 10px;
	padding: 10px 14px; margin: 0 0 18px;
	border-radius: 10px;
	background: rgba(158, 193, 74, 0.10);
	border: 1px solid rgba(158, 193, 74, 0.30);
	color: var(--good); font-size: 12.5px;
	}
	.aiad-fresh-banner a { color: var(--good); text-decoration: underline; font-weight: 500; }
	.aiad-fresh-banner a:hover { text-decoration: none; }

	@media print {
	.filters .reset, .aiad-session-banner, .aiad-fresh-banner { display: none !important; }
	.aiad-panel, .kpi, .filters { break-inside: avoid; box-shadow: none; backdrop-filter: none; }
	}
</style>

<?php
// Menu slugs are defined as private constants on Admin/Admin.php; reproduce
// them here verbatim so the template stays self-contained (it's `require`d
// from Admin::render_dashboard_page() in an admin context).
$reports_url  = admin_url( 'admin.php?page=leastudios-helpscout-ai-dashboard-reports' );
$settings_url = admin_url( 'admin.php?page=leastudios-helpscout-ai-dashboard-settings' );
?>
<div class="wrap aiad-page">
	<h1 class="wp-heading-inline">AI Answers</h1>
	<a href="<?php echo esc_url( $reports_url ); ?>" class="page-title-action">Reports</a>
	<a href="<?php echo esc_url( $settings_url ); ?>" class="page-title-action">Settings</a>
	<hr class="wp-header-end">

	<p class="aiad-page-meta">
	<span id="sub-period"></span> ·
	<span id="sub-reports"></span>
	</p>

	<nav class="nav-tab-wrapper" id="view-tabs" aria-label="Dashboard views">
	<button type="button" class="nav-tab nav-tab-active" data-view="overview">Overview</button>
	<button type="button" class="nav-tab" data-view="weekly">Weekly</button>
	</nav>

	<div id="aiad-dashboard-root">
	<div class="aiad-shell">

	<!-- Top bar: site switcher -->
	<div class="aiad-top-bar">
		<div class="site-switch" id="site-switch"></div>
	</div>

	<div class="aiad-session-banner">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
		<span><strong>Upload reports:</strong> Help Scout AI Beacon CSVs are imported on the <a href="<?php echo esc_url( $reports_url ); ?>">Reports page</a>.</span>
	</div>

	<div id="view-overview">
	<!-- KPIs -->
	<section class="kpis" id="kpis"></section>

	<!-- FILTERS -->
	<section class="filters">
	<div class="field">
		<label>From</label>
		<input type="date" id="f-from" autocomplete="off" />
	</div>
	<div class="field">
		<label>To</label>
		<input type="date" id="f-to" autocomplete="off" />
	</div>
	<div class="field">
		<label>Answer type</label>
		<select id="f-type"><option value="">All</option></select>
	</div>
	<div class="field">
		<label>Resolution</label>
		<select id="f-res"><option value="">All</option></select>
	</div>
	<div class="field search">
		<label>Search</label>
		<input id="f-q" placeholder="Search questions, answers, comments…" />
	</div>
	<button class="btn reset" id="f-reset">Reset</button>
	</section>

	<div class="chipset" id="quick-chips" style="margin-bottom: 20px;"></div>

	<!-- TIME SERIES -->
	<div class="section-title">Activity over time</div>
	<section class="grid">
	<div class="aiad-panel tall">
		<h3>Interactions per day <span class="count" id="ts-meta"></span></h3>
		<div class="chart-wrap"><canvas id="c-timeseries"></canvas></div>
	</div>
	</section>

	<!-- DISTRIBUTIONS -->
	<div class="section-title">Distributions</div>
	<section class="grid row three-col">
	<div class="aiad-panel">
		<h3>Session resolution</h3>
		<div class="chart-wrap"><canvas id="c-resolution"></canvas></div>
	</div>
	<div class="aiad-panel">
		<h3>Answer type</h3>
		<div class="chart-wrap"><canvas id="c-answertype"></canvas></div>
	</div>
	<div class="aiad-panel">
		<h3>Customer rating</h3>
		<div class="chart-wrap"><canvas id="c-rating"></canvas></div>
	</div>
	</section>

	<!-- BEHAVIOR -->
	<div class="section-title">Behavior</div>
	<section class="grid row three-col">
	<div class="aiad-panel tall">
		<h3>Session end reasons</h3>
		<div class="chart-wrap"><canvas id="c-endreasons"></canvas></div>
	</div>
	<div class="aiad-panel tall">
		<h3>By hour of day <span class="count" id="c-hour-meta"></span></h3>
		<div class="chart-wrap"><canvas id="c-hour"></canvas></div>
	</div>
	<div class="aiad-panel tall">
		<h3>By day of week <span class="count" id="c-dow-meta"></span></h3>
		<div class="chart-wrap"><canvas id="c-dow"></canvas></div>
	</div>
	</section>

	<!-- REFERENCES -->
	<div class="section-title">Knowledge</div>
	<section class="grid">
	<div class="aiad-panel">
		<h3>Top referenced articles <span class="count" id="articles-meta"></span></h3>
		<div id="articles-list" class="article-list"></div>
	</div>
	</section>

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

	<!-- TABLE -->
	<div class="section-title">Interactions <span id="table-meta" style="color: var(--dim);"></span></div>
	<section class="grid">
	<div class="aiad-panel" style="padding: 0;">
		<div style="overflow: auto; max-height: 600px;">
		<table id="table">
			<thead>
			<tr>
				<th style="width: 140px;">When</th>
				<th style="width: 140px;">Site</th>
				<th>Question</th>
				<th style="width: 110px;">Type</th>
				<th style="width: 140px;">Resolution</th>
				<th style="width: 110px;">Rating</th>
			</tr>
			</thead>
			<tbody id="tbody"></tbody>
		</table>
		</div>
		<div class="pag">
		<span id="pag-info"></span>
		<button id="pag-prev">Prev</button>
		<button id="pag-next">Next</button>
		</div>
	</div>
	</section>

	</div><!-- /#view-overview -->

	<div id="view-weekly" style="display:none;">
	<section class="wk-controls">
		<div class="field">
		<label>Week ending (Thu)</label>
		<select id="wk-primary"></select>
		</div>
		<div class="field">
		<label>Compare to</label>
		<select id="wk-compare">
			<option value="__prev">Prior week</option>
		</select>
		</div>
		<div class="field" style="flex:1; min-width: 260px;">
		<label>Pick weeks to compare (multi-select)</label>
		<div class="wk-picker-chips" id="wk-picker"></div>
		<div class="wk-hint">Primary is highlighted; click any other week to toggle it into the comparison.</div>
		</div>
	</section>

	<section class="kpis" id="wk-kpis"></section>

	<div class="section-title">Daily activity · selected week</div>
	<section class="grid">
		<div class="aiad-panel tall">
		<h3>Interactions per day <span class="count" id="wk-ts-meta"></span></h3>
		<div class="chart-wrap"><canvas id="wk-c-timeseries"></canvas></div>
		</div>
	</section>

	<div class="section-title">Distributions · selected week</div>
	<section class="grid row three-col">
		<div class="aiad-panel">
		<h3>Session resolution</h3>
		<div class="chart-wrap"><canvas id="wk-c-resolution"></canvas></div>
		</div>
		<div class="aiad-panel">
		<h3>Answer type</h3>
		<div class="chart-wrap"><canvas id="wk-c-answertype"></canvas></div>
		</div>
		<div class="aiad-panel">
		<h3>Customer rating</h3>
		<div class="chart-wrap"><canvas id="wk-c-rating"></canvas></div>
		</div>
	</section>

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

	<div class="section-title">Comparison <span id="wk-compare-meta" style="color: var(--dim);"></span></div>
	<section class="grid">
		<div class="aiad-panel" style="padding: 0;">
		<div class="wk-compare-wrap">
			<table class="wk-compare" id="wk-compare-table"></table>
		</div>
		</div>
	</section>

	<div class="section-title">Customer feedback · selected week</div>
	<section class="grid">
		<div class="aiad-panel">
		<h3>Comments <span class="count" id="wk-comments-meta"></span></h3>
		<div class="comments" id="wk-comments-list"></div>
		</div>
	</section>
	</div><!-- /#view-weekly -->

	<div style="text-align: center; margin-top: 40px; color: var(--dim); font-size: 12px;">
		<a href="#" id="link-top" style="color: var(--muted);">Back to top</a>
	</div>
	</div><!-- /.aiad-shell -->
	</div><!-- /#aiad-dashboard-root -->
</div><!-- /.wrap.aiad-page -->
