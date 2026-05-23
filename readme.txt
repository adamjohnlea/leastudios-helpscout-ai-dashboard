=== leaStudios Help Scout AI Dashboard ===
Contributors: leastudios
Tags: helpscout, csv, dashboard, reporting
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Help Scout AI Beacon CSV exports and render a weekly dashboard of customer interactions across Beacons.

== Description ==

leaStudios Help Scout AI Dashboard ingests the CSV exports produced by Help Scout's AI Beacon and turns them into a single weekly dashboard view across every Beacon you run. Upload one file or a folder of files, map each Beacon ID to a friendly site name once, and the dashboard rolls everything up into per-site weekly totals you can review at a glance.

= Who it's for =

Teams running multiple Help Scout AI Beacons across a portfolio of sites who want a unified view of customer interactions without manually merging spreadsheets every week. If you only have one Beacon, Help Scout's own reporting is probably enough — this plugin earns its keep when you're stitching together five, ten, or fifty.

= How it works =

CSV uploads stream through a parser that buckets every row into the ISO week it occurred in. Rows are deduped at two levels: each uploaded file is hashed (so re-uploading the same export is a no-op), and within a file each interaction is keyed by `(session_id, occurred_at)` so partial or overlapping exports merge cleanly instead of double-counting. Article references attached to each interaction (typically 1–3 per row) are normalized into a separate table so a single article surfaced across many interactions only stores its metadata once.

The dashboard renders a per-site weekly rollup with totals, trends, and a drill-down into the article references that powered each week's answers. All datetimes are stored in UTC and converted to your WordPress display timezone in the admin UI.

= Settings =

Under **Help Scout AI → Settings** you assign each Beacon ID (a string like `abc123`) a human-readable site name. The Beacon → site map is the only configuration the plugin needs — once it's populated, every subsequent upload lands in the right bucket.

= WP-CLI =

For larger ingests or scripted backfills, two WP-CLI commands are available:

* `wp lshsai import-file <path>` — import one CSV file.
* `wp lshsai import-folder <path>` — import every CSV in a folder (non-recursive).

Both honor the same dedupe rules as the admin upload, so re-runs are idempotent.

== Installation ==

1. Upload the `leastudios-helpscout-ai-dashboard` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Run `composer install --no-dev` in the plugin folder to install autoloader files.
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Visit **Help Scout AI → Settings** and add your Beacon IDs with friendly site names.
5. Visit **Help Scout AI → Reports** and upload a CSV export from Help Scout.
6. Visit **Help Scout AI → Dashboard** to see the weekly rollup.

== Frequently Asked Questions ==

= Where do I get the CSV exports? =

From the Help Scout admin under each AI Beacon's reporting screen. The plugin expects the standard Help Scout AI Beacon CSV format — no transformation required.

= What happens if I upload the same file twice? =

Nothing — the file hash is recorded on first import and subsequent uploads of identical content are skipped. Partial overlaps between two different files are also handled: rows already present (matched on session ID + timestamp) are skipped, new rows are added.

= How do I delete a bad upload? =

From **Help Scout AI → Reports**, find the source file row and click Delete. All interactions and article references linked to that upload are removed in a cascade. Other uploads are untouched.

= Where is data stored? =

In three custom tables prefixed `{$wpdb->prefix}leastudios_helpscout_ai_dashboard_`: `interactions`, `article_refs`, and `reports`. The schema version is tracked in the `leastudios_helpscout_ai_dashboard_db_version` option.

= How do I uninstall? =

Deleting the plugin via the Plugins screen runs the uninstaller, which drops all three custom tables and removes plugin options.

== Changelog ==

= 1.0.0 =
* Initial public release. CSV ingestion with file-hash + per-row dedupe, three-table schema, Beacon → site Settings page, weekly per-site dashboard, and `wp lshsai import-file` / `wp lshsai import-folder` WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First public release.
